<?php

namespace Database\Seeders;

use App\Models\GeneralSetting;
use Illuminate\Database\Seeder;

/**
 * Demo data for the General Settings form (see GeneralSettingsView.vue) —
 * ported from the soft-deleted rows (deleted_at IS NOT NULL) of
 * ccu_nwdb.general_settings, re-imported here as live/active fields.
 */
class GeneralSettingSeeder extends Seeder
{
    /**
     * @var list<array<string, mixed>>
     */
    public const SETTINGS = [
        [
            'field_name' => 'site_title',
            'label' => 'Site Title',
            'type' => 'text',
            'value' => 'CCU Sports Club',
            'placeholder' => 'Enter site title',
            'is_required' => true,
            'sort_order' => 1,
        ],
        [
            'field_name' => 'club_commission',
            'label' => 'Club Commission',
            'type' => 'number',
            'value' => '10',
            'placeholder' => 'Enter commission percentage',
            'help_text' => 'Commission percentage for club transactions.',
            'sort_order' => 2,
        ],
        [
            'field_name' => 'registration_start_date',
            'label' => 'Registration Start Date',
            'type' => 'date',
            'value' => '2026-06-01',
            'placeholder' => 'Select date',
            'sort_order' => 3,
        ],
        [
            'field_name' => 'support_email',
            'label' => 'Support Email',
            'type' => 'email',
            'value' => 'support@example.com',
            'placeholder' => 'Enter support email',
            'sort_order' => 4,
        ],
        [
            'field_name' => 'website_url',
            'label' => 'Website URL',
            'type' => 'url',
            'value' => 'https://example.com',
            'placeholder' => 'https://example.com',
            'sort_order' => 5,
        ],
        [
            'field_name' => 'maintenance_mode',
            'label' => 'Maintenance Mode',
            'type' => 'radio',
            'value' => 'no',
            'options' => [
                ['label' => 'Yes', 'value' => 'yes', 'has_extra' => false],
                ['label' => 'No', 'value' => 'no', 'has_extra' => false],
            ],
            'help_text' => 'Enable to put the site in maintenance mode.',
            'sort_order' => 6,
        ],
        [
            'field_name' => 'enable_club_commission',
            'label' => 'Enable Club Commission',
            'type' => 'radio',
            'value' => 'yes',
            'extra_value' => '10',
            'options' => [
                ['label' => 'Yes', 'value' => 'yes', 'has_extra' => true, 'extra_label' => 'Commission Value (%)'],
                ['label' => 'No', 'value' => 'no', 'has_extra' => false],
            ],
            'help_text' => 'When enabled, enter the commission percentage in the extra field.',
            'sort_order' => 7,
        ],
        [
            'field_name' => 'payment_mode',
            'label' => 'Payment Mode',
            'type' => 'selectbox',
            'value' => 'live',
            'options' => [
                ['label' => 'Live', 'value' => 'live'],
                ['label' => 'Test', 'value' => 'test'],
            ],
            'placeholder' => 'Select payment mode',
            'sort_order' => 8,
        ],
        [
            'field_name' => 'allowed_upload_types',
            'label' => 'Allowed Upload Types',
            'type' => 'checkbox',
            'value' => '["pdf","jpg"]',
            'options' => [
                ['label' => 'PDF', 'value' => 'pdf'],
                ['label' => 'JPG', 'value' => 'jpg'],
                ['label' => 'PNG', 'value' => 'png'],
            ],
            'help_text' => 'Select allowed file upload types.',
            'sort_order' => 9,
        ],
        [
            'field_name' => 'site_logo',
            'label' => 'Site Logo',
            'type' => 'file',
            'value' => null,
            'help_text' => 'Upload site logo (jpg, png, svg, webp, max 5MB).',
            'sort_order' => 10,
        ],
        [
            'field_name' => 'footer_text',
            'label' => 'Footer Text',
            'type' => 'textarea',
            'value' => 'Copyright 2026',
            'placeholder' => 'Enter footer text',
            'sort_order' => 11,
        ],

        // Global gate checked by MyPendingCourseController::startEvaluation()
        // before a teacher can begin marking an answer sheet — 'no' skips
        // the face scan for everyone regardless of any teacher's own
        // per-teacher face_scan_applicable flag (see TeacherDetail); 'yes'
        // defers to that per-teacher flag instead of forcing it for
        // everyone. See MyPendingCoursesView.vue's "Start Evaluate" flow.
        [
            'field_name' => 'face_scan_applicable',
            'label' => 'Is Face Scan Applicable',
            'group_name' => 'Evaluation',
            'type' => 'radio',
            'value' => 'yes',
            'options' => [
                ['label' => 'Yes', 'value' => 'yes', 'has_extra' => false],
                ['label' => 'No', 'value' => 'no', 'has_extra' => false],
            ],
            'help_text' => 'When enabled, teachers whose own "Face Scan Applicable" flag is Yes must pass a face scan before starting evaluation of an answer sheet.',
            'sort_order' => 17,
        ],

        // "Branding & Icons" group — the app's own chrome (favicon, login
        // logo, sidebar logos, footer logo), previously hardcoded to files
        // under frontend/public/images/. Seeded pointing at those same
        // static files so nothing changes visually until someone actually
        // uploads a replacement here (see GET /branding, consumed by
        // stores/branding.js). Not part of the ccu_nwdb import — these are
        // specific to this app, added alongside it.
        [
            'field_name' => 'favicon',
            'label' => 'Favicon',
            'group_name' => 'Branding & Icons',
            'type' => 'file',
            'value' => '/images/logo-image.png',
            'help_text' => 'Shown in the browser tab. Square image recommended (png, ico, svg).',
            'sort_order' => 12,
        ],
        [
            'field_name' => 'login_logo',
            'label' => 'Login Page Logo',
            'group_name' => 'Branding & Icons',
            'type' => 'file',
            'value' => '/images/logo-image.png',
            'help_text' => 'Shown above the sign-in form on the Login page.',
            'sort_order' => 13,
        ],
        [
            'field_name' => 'header_logo_full',
            'label' => 'Sidebar Logo (Expanded)',
            'group_name' => 'Branding & Icons',
            'type' => 'file',
            'value' => '/images/header_logo.png',
            'help_text' => 'The wide logo shown at the top of the sidebar when it\'s expanded.',
            'sort_order' => 14,
        ],
        [
            'field_name' => 'header_logo_icon',
            'label' => 'Sidebar Logo (Collapsed Icon)',
            'group_name' => 'Branding & Icons',
            'type' => 'file',
            'value' => '/images/logo-icon.png',
            'help_text' => 'The small icon shown at the top of the sidebar when it\'s collapsed to icons only.',
            'sort_order' => 15,
        ],
        [
            'field_name' => 'footer_logo',
            'label' => 'Footer Logo',
            'group_name' => 'Branding & Icons',
            'type' => 'file',
            'value' => '/images/footer_logo.png',
            'help_text' => 'Shown in the footer on every page.',
            'sort_order' => 16,
        ],
    ];

    public function run(): void
    {
        foreach (self::SETTINGS as $setting) {
            GeneralSetting::withTrashed()->firstOrCreate(
                ['field_name' => $setting['field_name']],
                $setting + ['status' => true],
            );
        }
    }
}
