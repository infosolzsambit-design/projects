<?php

namespace App\Services;

use App\Models\GeneralSetting;

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
        // '/images/header_logo.png' — or, once an admin has uploaded a
        // custom logo, a '/storage/...' path served by this backend's
        // public disk (see GeneralSettingController::update()). Same
        // distinction resolveStorageUrl() makes client-side, just resolved
        // against whichever origin actually serves that path.
        $logoPath = GeneralSetting::where('status', true)
            ->where('field_name', 'header_logo_full')
            ->value('value');
        $logoUrl = match (true) {
            ! $logoPath => null,
            str_starts_with($logoPath, '/storage/') => rtrim(config('app.url'), '/').$logoPath,
            default => rtrim(config('app.frontend_url'), '/').$logoPath,
        };

        return ['site_title' => $siteTitle, 'logo_url' => $logoUrl];
    }
}
