<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Models\GeneralSetting;
use App\Services\AuditLogService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Powers the single General Settings form (see GeneralSettingsView.vue) —
 * no list, no per-row CRUD from the UI. Each row in general_settings just
 * describes one field on that form (see the GeneralSetting model /
 * GeneralSettingSeeder for the seeded set — ported from ccu_nwdb's
 * general_settings table) and this controller reads/saves all of them
 * together in one request. No permission gating yet, same as
 * Teacher/Department/Course/Program.
 */
class GeneralSettingController extends Controller
{
    use ApiResponse;

    /**
     * @var list<string>
     */
    private const VISIBLE_COLUMNS = [
        'id', 'field_name', 'label', 'group_name', 'type', 'value', 'extra_value',
        'options', 'placeholder', 'help_text', 'is_required', 'sort_order',
    ];

    /**
     * The fixed set of fields GET /branding exposes — the icon fields from
     * GeneralSettingSeeder's "Branding & Icons" group, plus site_title
     * (shown in the browser tab, the footer, and anywhere else the app's
     * own name appears — see stores/branding.js). Deliberately a hardcoded
     * allowlist, not "every field", so nothing else in general_settings
     * (commission rates, payment mode, ...) is accidentally exposed on this
     * public, unauthenticated endpoint just by existing.
     *
     * @var list<string>
     */
    private const BRANDING_FIELDS = [
        'favicon', 'login_logo', 'header_logo_full', 'header_logo_icon', 'footer_logo', 'site_title',
    ];

    public function __construct(private readonly AuditLogService $auditLog) {}

    /**
     * GET /general-settings — every active field, in display order, each
     * carrying both its form metadata (type/options/...) and its current
     * value in one row (this doubles as "the schema" and "the data").
     */
    public function index(): JsonResponse
    {
        return $this->success(
            GeneralSetting::where('status', true)->orderBy('sort_order')->get(self::VISIBLE_COLUMNS),
        );
    }

    /**
     * GET /branding — public, unauthenticated (see routes/api.php's public
     * group, same as login/forgot-password). The app's own chrome (favicon,
     * login-page logo, sidebar logos, footer logo, and the site title shown
     * in the browser tab/footer — see stores/branding.js) needs to paint
     * before a visitor has logged in at all, so this can't live behind
     * auth:sanctum like the rest of general-settings does. Only ever
     * returns BRANDING_FIELDS's fixed, non-sensitive set — never anything
     * else from the table (commission rates, payment mode, ...).
     */
    public function branding(): JsonResponse
    {
        $values = GeneralSetting::where('status', true)
            ->whereIn('field_name', self::BRANDING_FIELDS)
            ->pluck('value', 'field_name');

        return $this->success(
            collect(self::BRANDING_FIELDS)->mapWithKeys(fn (string $field) => [$field => $values[$field] ?? null]),
        );
    }

    /**
     * POST /general-settings — saves every field in one submission (there's
     * a single Save button in GeneralSettingsView.vue, no per-field save).
     * multipart/form-data so file-type fields can upload alongside the rest
     * in the same request. Validated dynamically per row's own
     * type/is_required instead of a fixed rule set, since the fields
     * themselves are data-driven, not hardcoded.
     */
    public function update(Request $request): JsonResponse
    {
        $settings = GeneralSetting::where('status', true)->orderBy('sort_order')->get();

        [$rules, $manualFileErrors] = $this->buildRules($settings, $request);

        $validator = Validator::make($request->all(), $rules);
        $validator->after(function ($validator) use ($manualFileErrors) {
            foreach ($manualFileErrors as $field => $message) {
                $validator->errors()->add($field, $message);
            }
        });

        if ($validator->fails()) {
            return $this->validationError($validator->errors());
        }
        $validated = $validator->validated();

        // Every field saves together (see the docblock above — one Save
        // button, not per-field) — a failure partway through must not leave
        // some fields on the new value and others on the old one.
        $changed = DB::transaction(function () use ($settings, $request, $validated) {
            $changed = [];
            foreach ($settings as $setting) {
                $update = $this->resolveUpdate($setting, $request, $validated);
                if ($update) {
                    $setting->update($update);
                    $changed[] = $setting->field_name;
                }
            }

            $this->auditLog->log(
                event: 'general-settings-updated',
                module: 'General Settings',
                description: $changed
                    ? 'Updated general settings: '.implode(', ', $changed).'.'
                    : 'General settings form submitted with no changes.',
            );

            return $changed;
        });

        return $this->success(
            GeneralSetting::where('status', true)->orderBy('sort_order')->get(self::VISIBLE_COLUMNS),
            'Settings saved successfully.',
        );
    }

    /**
     * @param  \Illuminate\Support\Collection<int, GeneralSetting>  $settings
     * @return array{0: array<string, mixed>, 1: array<string, string>}
     */
    private function buildRules($settings, Request $request): array
    {
        $rules = [];
        $manualFileErrors = [];

        foreach ($settings as $setting) {
            $field = $setting->field_name;
            $required = (bool) $setting->is_required;

            $rules[$field] = match ($setting->type) {
                'email' => [$required ? 'required' : 'nullable', 'email'],
                'url' => [$required ? 'required' : 'nullable', 'url'],
                'date' => [$required ? 'required' : 'nullable', 'date'],
                'number' => [$required ? 'required' : 'nullable', 'numeric'],
                // A file's "required" doesn't mean "must be re-uploaded
                // every save" — an existing stored value already satisfies
                // it (see the manual check below instead).
                'file' => ['nullable', 'file', 'max:5120', 'mimes:jpg,jpeg,png,svg,webp,ico,gif'],
                'checkbox' => ['nullable', 'array'],
                default => [$required ? 'required' : 'nullable', 'string'],
            };

            if (in_array($setting->type, ['radio', 'selectbox'], true) && ! empty($setting->options)) {
                $rules[$field][] = Rule::in(array_column($setting->options, 'value'));
            }

            if ($setting->type === 'checkbox' && ! empty($setting->options)) {
                $rules[$field.'.*'] = [Rule::in(array_column($setting->options, 'value'))];
            }

            if ($this->hasExtraOption($setting)) {
                $rules[$field.'_extra'] = ['nullable', 'string', 'max:255'];
            }

            if ($setting->type === 'file' && $required && ! $setting->value && ! $request->hasFile($field)) {
                $manualFileErrors[$field] = "The {$setting->label} field is required.";
            }
        }

        return [$rules, $manualFileErrors];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function resolveUpdate(GeneralSetting $setting, Request $request, array $validated): array
    {
        $field = $setting->field_name;
        $update = [];

        if ($setting->type === 'file') {
            if ($request->hasFile($field)) {
                $this->deleteStoredFile($setting->value);
                $update['value'] = '/storage/'.$request->file($field)->store('general-settings', 'public');
            }
            // No new file this submission → existing value stays as-is.
        } elseif ($setting->type === 'checkbox') {
            $update['value'] = json_encode(array_values((array) $request->input($field, [])));
        } else {
            $update['value'] = $validated[$field] ?? null;
        }

        if ($this->hasExtraOption($setting)) {
            $update['extra_value'] = $validated[$field.'_extra'] ?? null;
        }

        return $update;
    }

    private function deleteStoredFile(?string $value): void
    {
        // Only ever deletes something *this controller* stored — a seeded
        // field can start out pointing at a static frontend asset (e.g.
        // "/images/logo-image.png", see GeneralSettingSeeder's icon
        // defaults) and that must never be touched, on disk or off.
        if (! $value || ! str_starts_with($value, '/storage/')) {
            return;
        }

        $path = substr($value, strlen('/storage/'));
        if (Storage::disk('public')->exists($path)) {
            Storage::disk('public')->delete($path);
        }
    }

    private function hasExtraOption(GeneralSetting $setting): bool
    {
        if ($setting->type !== 'radio' || empty($setting->options)) {
            return false;
        }

        return collect($setting->options)->contains(fn ($option) => ! empty($option['has_extra']));
    }
}
