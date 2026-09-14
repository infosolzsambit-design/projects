<?php

namespace Tests\Feature;

use App\Models\GeneralSetting;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GeneralSettingControllerTest extends TestCase
{
    use RefreshDatabase;

    private function actingAdmin(): User
    {
        $admin = User::factory()->create();
        Sanctum::actingAs($admin);

        return $admin;
    }

    public function test_index_returns_active_settings_in_sort_order(): void
    {
        $this->actingAdmin();
        GeneralSetting::factory()->create(['field_name' => 'b_field', 'sort_order' => 2, 'value' => 'B']);
        GeneralSetting::factory()->create(['field_name' => 'a_field', 'sort_order' => 1, 'value' => 'A']);

        $response = $this->withApiKey()->getJson('/api/v1/general-settings');

        $response->assertOk();
        $fieldNames = collect($response->json('data'))->pluck('field_name');
        $this->assertSame(['a_field', 'b_field'], $fieldNames->toArray());
    }

    public function test_index_excludes_inactive_settings(): void
    {
        $this->actingAdmin();
        GeneralSetting::factory()->inactive()->create(['field_name' => 'hidden_field']);

        $response = $this->withApiKey()->getJson('/api/v1/general-settings');

        $response->assertOk();
        $this->assertFalse(collect($response->json('data'))->pluck('field_name')->contains('hidden_field'));
    }

    public function test_index_excludes_soft_deleted_settings(): void
    {
        $this->actingAdmin();
        $setting = GeneralSetting::factory()->create(['field_name' => 'gone_field']);
        $setting->delete();

        $response = $this->withApiKey()->getJson('/api/v1/general-settings');

        $response->assertOk();
        $this->assertFalse(collect($response->json('data'))->pluck('field_name')->contains('gone_field'));
    }

    public function test_index_never_exposes_a_deleted_at_column(): void
    {
        $this->actingAdmin();
        GeneralSetting::factory()->create(['field_name' => 'a_field']);

        $response = $this->withApiKey()->getJson('/api/v1/general-settings');

        $response->assertOk();
        $this->assertArrayNotHasKey('deleted_at', $response->json('data.0'));
    }

    public function test_index_includes_each_fields_group_name(): void
    {
        $this->actingAdmin();
        GeneralSetting::factory()->group('Branding & Icons')->create(['field_name' => 'favicon', 'type' => 'file']);
        GeneralSetting::factory()->create(['field_name' => 'ungrouped_field']);

        $response = $this->withApiKey()->getJson('/api/v1/general-settings');

        $response->assertOk();
        $byName = collect($response->json('data'))->keyBy('field_name');
        $this->assertSame('Branding & Icons', $byName['favicon']['group_name']);
        $this->assertNull($byName['ungrouped_field']['group_name']);
    }

    public function test_branding_works_without_authentication(): void
    {
        GeneralSetting::factory()->group('Branding & Icons')->create(['field_name' => 'favicon', 'type' => 'file', 'value' => '/images/logo-image.png']);

        $response = $this->withApiKey()->getJson('/api/v1/branding');

        $response->assertOk()->assertJsonPath('data.favicon', '/images/logo-image.png');
    }

    public function test_branding_returns_null_for_an_unseeded_field_instead_of_erroring(): void
    {
        // None of the branding fields exist in the DB at all.
        $response = $this->withApiKey()->getJson('/api/v1/branding');

        $response->assertOk()->assertJson(['data' => [
            'favicon' => null,
            'login_logo' => null,
            'header_logo_full' => null,
            'header_logo_icon' => null,
            'footer_logo' => null,
            'site_title' => null,
        ]]);
    }

    public function test_branding_includes_the_site_title(): void
    {
        GeneralSetting::factory()->create(['field_name' => 'site_title', 'type' => 'text', 'value' => 'Acme Evaluation Portal']);

        $response = $this->withApiKey()->getJson('/api/v1/branding');

        $response->assertOk()->assertJsonPath('data.site_title', 'Acme Evaluation Portal');
    }

    public function test_branding_never_exposes_non_branding_settings(): void
    {
        GeneralSetting::factory()->create(['field_name' => 'club_commission', 'value' => '25']);

        $response = $this->withApiKey()->getJson('/api/v1/branding');

        $response->assertOk();
        $this->assertArrayNotHasKey('club_commission', $response->json('data'));
    }

    public function test_branding_only_returns_active_fields(): void
    {
        GeneralSetting::factory()->inactive()->group('Branding & Icons')->create(['field_name' => 'favicon', 'type' => 'file', 'value' => '/images/logo-image.png']);

        $response = $this->withApiKey()->getJson('/api/v1/branding');

        $response->assertOk()->assertJsonPath('data.favicon', null);
    }

    public function test_update_saves_plain_text_and_number_values(): void
    {
        $this->actingAdmin();
        GeneralSetting::factory()->create(['field_name' => 'site_title', 'type' => 'text', 'value' => 'Old']);
        GeneralSetting::factory()->create(['field_name' => 'club_commission', 'type' => 'number', 'value' => '5']);

        $response = $this->withApiKey()->postJson('/api/v1/general-settings', [
            'site_title' => 'New Title',
            'club_commission' => '15',
        ]);

        $response->assertOk()->assertJson(['status' => true, 'message' => 'Settings saved successfully.']);
        $this->assertDatabaseHas('general_settings', ['field_name' => 'site_title', 'value' => 'New Title']);
        $this->assertDatabaseHas('general_settings', ['field_name' => 'club_commission', 'value' => '15']);
    }

    public function test_update_requires_a_value_for_a_required_field(): void
    {
        $this->actingAdmin();
        GeneralSetting::factory()->required()->create(['field_name' => 'site_title', 'type' => 'text', 'value' => 'Existing']);

        $response = $this->withApiKey()->postJson('/api/v1/general-settings', ['site_title' => '']);

        $response->assertStatus(422)->assertJsonValidationErrors('site_title');
    }

    public function test_update_validates_email_type_fields(): void
    {
        $this->actingAdmin();
        GeneralSetting::factory()->create(['field_name' => 'support_email', 'type' => 'email']);

        $response = $this->withApiKey()->postJson('/api/v1/general-settings', ['support_email' => 'not-an-email']);

        $response->assertStatus(422)->assertJsonValidationErrors('support_email');
    }

    public function test_update_rejects_a_selectbox_value_outside_its_options(): void
    {
        $this->actingAdmin();
        GeneralSetting::factory()->selectbox([
            ['label' => 'Live', 'value' => 'live'],
            ['label' => 'Test', 'value' => 'test'],
        ])->create(['field_name' => 'payment_mode', 'value' => 'live']);

        $response = $this->withApiKey()->postJson('/api/v1/general-settings', ['payment_mode' => 'not-a-real-option']);

        $response->assertStatus(422)->assertJsonValidationErrors('payment_mode');
    }

    public function test_update_accepts_a_valid_selectbox_option(): void
    {
        $this->actingAdmin();
        GeneralSetting::factory()->selectbox([
            ['label' => 'Live', 'value' => 'live'],
            ['label' => 'Test', 'value' => 'test'],
        ])->create(['field_name' => 'payment_mode', 'value' => 'live']);

        $response = $this->withApiKey()->postJson('/api/v1/general-settings', ['payment_mode' => 'test']);

        $response->assertOk();
        $this->assertDatabaseHas('general_settings', ['field_name' => 'payment_mode', 'value' => 'test']);
    }

    public function test_update_saves_a_checkbox_field_as_a_json_array(): void
    {
        $this->actingAdmin();
        GeneralSetting::factory()->checkbox([
            ['label' => 'PDF', 'value' => 'pdf'],
            ['label' => 'JPG', 'value' => 'jpg'],
            ['label' => 'PNG', 'value' => 'png'],
        ])->create(['field_name' => 'allowed_upload_types']);

        $response = $this->withApiKey()->postJson('/api/v1/general-settings', [
            'allowed_upload_types' => ['jpg', 'png'],
        ]);

        $response->assertOk();
        $stored = GeneralSetting::where('field_name', 'allowed_upload_types')->first();
        $this->assertSame(['jpg', 'png'], json_decode($stored->value, true));
    }

    public function test_update_rejects_a_checkbox_value_outside_its_options(): void
    {
        $this->actingAdmin();
        GeneralSetting::factory()->checkbox([
            ['label' => 'PDF', 'value' => 'pdf'],
            ['label' => 'JPG', 'value' => 'jpg'],
        ])->create(['field_name' => 'allowed_upload_types']);

        $response = $this->withApiKey()->postJson('/api/v1/general-settings', [
            'allowed_upload_types' => ['pdf', 'exe'],
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('allowed_upload_types.1');
    }

    public function test_update_saves_a_radio_extra_value_when_the_option_has_extra(): void
    {
        $this->actingAdmin();
        GeneralSetting::factory()->radio([
            ['label' => 'Yes', 'value' => 'yes', 'has_extra' => true, 'extra_label' => 'Commission Value (%)'],
            ['label' => 'No', 'value' => 'no', 'has_extra' => false],
        ])->create(['field_name' => 'enable_club_commission', 'value' => 'no', 'extra_value' => null]);

        $response = $this->withApiKey()->postJson('/api/v1/general-settings', [
            'enable_club_commission' => 'yes',
            'enable_club_commission_extra' => '12.5',
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('general_settings', [
            'field_name' => 'enable_club_commission',
            'value' => 'yes',
            'extra_value' => '12.5',
        ]);
    }

    public function test_update_uploads_a_file_and_stores_its_path_as_the_value(): void
    {
        Storage::fake('public');
        $this->actingAdmin();
        GeneralSetting::factory()->file()->create(['field_name' => 'site_logo']);

        $response = $this->withApiKey()->post('/api/v1/general-settings', [
            'site_logo' => UploadedFile::fake()->image('logo.png'),
        ]);

        $response->assertOk();
        $stored = GeneralSetting::where('field_name', 'site_logo')->first();
        $this->assertStringStartsWith('/storage/general-settings/', $stored->value);
        Storage::disk('public')->assertExists(str_replace('/storage/', '', $stored->value));
    }

    public function test_update_without_a_new_file_keeps_the_existing_value(): void
    {
        Storage::fake('public');
        $this->actingAdmin();
        GeneralSetting::factory()->file()->create(['field_name' => 'site_logo', 'value' => '/storage/general-settings/existing.png']);

        $response = $this->withApiKey()->postJson('/api/v1/general-settings', []);

        $response->assertOk();
        $this->assertDatabaseHas('general_settings', ['field_name' => 'site_logo', 'value' => '/storage/general-settings/existing.png']);
    }

    public function test_update_replacing_a_file_deletes_the_old_one(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('general-settings/old.png', 'fake-old-content');
        $this->actingAdmin();
        GeneralSetting::factory()->file()->create(['field_name' => 'site_logo', 'value' => '/storage/general-settings/old.png']);

        $this->withApiKey()->post('/api/v1/general-settings', [
            'site_logo' => UploadedFile::fake()->image('new-logo.png'),
        ]);

        Storage::disk('public')->assertMissing('general-settings/old.png');
    }

    public function test_update_creates_an_audit_log_entry(): void
    {
        $this->actingAdmin();
        GeneralSetting::factory()->create(['field_name' => 'site_title', 'value' => 'Old']);

        $this->withApiKey()->postJson('/api/v1/general-settings', ['site_title' => 'New']);

        $this->assertDatabaseHas('audits', ['event' => 'general-settings-updated']);
    }
}
