<?php

namespace App\Services;

use App\Models\GeneralSetting;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The site title / logo every outbound email (AnswerSheetAssignedMail,
 * IssueRaisedMail, ...) stamps itself with — pulled from General Settings'
 * "Branding & Icons" group (see stores/branding.js) instead of being
 * hardcoded, so a whitelabeled deployment's own name/logo shows up in mail
 * too, not just the app's own UI.
 */
class MailBrandingService
{
    /**
     * @return array{site_title: string, logo_url: ?string}
     */
    public function resolve(): array
    {
        $siteTitle = GeneralSetting::where('status', true)
            ->where('field_name', 'site_title')
            ->value('value') ?: 'Paper Check';

        // Its `value` is either the seeded default — a relative path meant
        // to be served by the frontend's own public/ folder, e.g.
        // '/images/footer_logo.png' — or, once an admin has uploaded a
        // custom logo, a '/storage/...' path served by this backend's
        // public disk (see GeneralSettingController::update()). Same
        // distinction resolveStorageUrl() makes client-side, just resolved
        // against whichever origin actually serves that path.
        //
        // Deliberately 'footer_logo', not 'header_logo_full' — the emails'
        // header banner uses the app's own colored brand gradient (see the
        // email views), and header_logo_full is the dark-text-on-white
        // variant meant for the sidebar's white background; its text
        // blends into a colored background and becomes unreadable.
        // footer_logo is the white-on-transparent variant made for exactly
        // this kind of colored background.
        $logoPath = GeneralSetting::where('status', true)
            ->where('field_name', 'footer_logo')
            ->value('value');
        return ['site_title' => $siteTitle, 'logo_url' => $this->publicUrl($logoPath)];
    }

    /**
     * Logo links for the email header (white background): the CJ logo
     * top-left — "Sidebar Logo (Expanded)", the dark-on-white variant the
     * white sidebar shows — and General Settings' "Organization Logo"
     * centred (null when none is set or its switch is off). Full URLs, not
     * data: URIs — mail clients like Gmail block embedded images.
     *
     * @return array{logo_url: ?string, organization_logo_url: ?string}
     */
    public function emailHeader(): array
    {
        $value = fn (string $field) => GeneralSetting::where('status', true)->where('field_name', $field)->value('value');

        return [
            'logo_url' => $this->publicUrl($value('header_logo_full')) ?? rtrim(config('app.frontend_url'), '/').'/images/header_logo.png',
            'organization_logo_url' => $this->publicUrl($value('organization_logo')),
        ];
    }

    /**
     * A setting's stored path as a full URL — '/storage/...' uploads are
     * served by this backend, the seeded '/images/...' defaults by the
     * frontend's own public/ folder.
     */
    private function publicUrl(?string $path): ?string
    {
        return match (true) {
            ! $path => null,
            str_starts_with($path, '/storage/') => rtrim(config('app.url'), '/').$path,
            default => rtrim(config('app.frontend_url'), '/').$path,
        };
    }

    /**
     * The top-left logo on every downloaded report (Excel/PDF), as a
     * self-contained data: URI. Report headers have a WHITE background, so
     * this uses the dark-on-white variant — an uploaded "Sidebar Logo
     * (Expanded)" (header_logo_full, the same logo the white sidebar shows) —
     * not footer_logo, which is white-on-transparent and would vanish.
     * Falls back to the bundled resources/images/report-logo.png (the seeded
     * defaults live in the frontend's public/ folder, which this backend
     * can't read).
     */
    public function reportLogoDataUri(): ?string
    {
        $uploaded = $this->storageDataUri(
            GeneralSetting::where('status', true)->where('field_name', 'header_logo_full')->value('value'),
        );
        if ($uploaded) {
            return $uploaded;
        }

        $fallback = resource_path('images/report-logo.png');

        return is_file($fallback)
            ? 'data:image/png;base64,'.base64_encode(file_get_contents($fallback))
            : null;
    }

    /**
     * General Settings → "Organization Logo", shown top-centre on every
     * downloaded report. Null when none is uploaded or its switch is off —
     * the header then just has the logo on the left.
     */
    public function organizationLogoDataUri(): ?string
    {
        return $this->storageDataUri(
            GeneralSetting::where('status', true)->where('field_name', 'organization_logo')->value('value'),
        );
    }

    /** A '/storage/...' setting value read off the public disk as a data: URI. */
    private function storageDataUri(?string $path): ?string
    {
        if (! $path || ! str_contains($path, '/storage/')) {
            return null;
        }
        $relative = Str::after($path, '/storage/');
        if (! Storage::disk('public')->exists($relative)) {
            return null;
        }
        $mime = Storage::disk('public')->mimeType($relative) ?: 'image/png';

        return 'data:'.$mime.';base64,'.base64_encode(Storage::disk('public')->get($relative));
    }
}
