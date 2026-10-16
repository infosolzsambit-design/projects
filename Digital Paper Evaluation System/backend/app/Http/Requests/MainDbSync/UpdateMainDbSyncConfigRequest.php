<?php

namespace App\Http\Requests\MainDbSync;

use App\Models\MainDbSyncConfig;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** PUT /main-db-sync/config — the Sync modal's auto-saved request settings. */
class UpdateMainDbSyncConfigRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        // "/DataApi/course/" → "DataApi/course"; method in capitals.
        $this->merge([
            'path' => trim((string) $this->input('path'), " \t\n\r\0\x0B/"),
            'method' => strtoupper((string) $this->input('method')),
        ]);
    }

    public function rules(): array
    {
        return [
            'entity' => ['required', 'string', Rule::in(array_keys(MainDbSyncConfig::DEFAULT_PATHS))],
            'method' => ['required', Rule::in(MainDbSyncConfig::METHODS)],
            // Only a path under the base URL (letters, digits, - _ and /) —
            // no "..", "?", "@" or another host, since the security code
            // goes with the request.
            'path' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9_\-]+(\/[A-Za-z0-9_\-]+)*$/'],
            'params' => ['present', 'array', 'max:20'],
            'params.*.key' => ['nullable', 'string', 'max:100'],
            'params.*.value' => ['nullable', 'string', 'max:255'],
            'params.*.enabled' => ['required', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'path.regex' => 'The path may only contain letters, numbers, -, _ and /, e.g. DataApi/course.',
        ];
    }
}
