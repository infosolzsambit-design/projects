<?php

namespace Tests\Feature;

use App\Helpers\PublicStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PublicStorageTest extends TestCase
{
    use RefreshDatabase;

    private function mode(string $dir): int
    {
        clearstatcache();

        return fileperms(Storage::disk('public')->path($dir)) & 0777;
    }

    public function test_open_folder_opens_the_folder_and_its_parents(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('answer-sheets/5/a.pdf', 'x');
        chmod(Storage::disk('public')->path('answer-sheets'), 0755);
        chmod(Storage::disk('public')->path('answer-sheets/5'), 0755);

        PublicStorage::openFolder('answer-sheets/5');

        $this->assertSame(0777, $this->mode('answer-sheets'));
        $this->assertSame(0777, $this->mode('answer-sheets/5'));
    }

    public function test_open_all_folders_fixes_existing_folders_once(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('answer-sheets/1/a.pdf', 'x');
        Storage::disk('public')->put('student-id-crops/1/12.png', 'x');
        foreach (['answer-sheets', 'answer-sheets/1', 'student-id-crops', 'student-id-crops/1'] as $dir) {
            chmod(Storage::disk('public')->path($dir), 0755);
        }

        chmod(Storage::disk('public')->path(''), 0755);

        // The four folders plus public/storage itself.
        $this->assertSame(5, PublicStorage::openAllFolders());
        $this->assertSame(0777, $this->mode(''));
        $this->assertSame(0777, $this->mode('student-id-crops/1'));
        $this->assertSame(0, PublicStorage::openAllFolders());
    }

    public function test_general_settings_upload_folder_is_opened(): void
    {
        \Laravel\Sanctum\Sanctum::actingAs(\App\Models\User::factory()->create());
        Storage::fake('public');
        $setting = \App\Models\GeneralSetting::create(['field_name' => 'header_logo', 'label' => 'Header Logo', 'type' => 'file', 'status' => true, 'sort_order' => 1]);
        Storage::disk('public')->makeDirectory('general-settings');
        chmod(Storage::disk('public')->path('general-settings'), 0755);

        $response = $this->withApiKey()->post('/api/v1/general-settings', [
            'header_logo' => \Illuminate\Http\UploadedFile::fake()->image('logo.png'),
        ]);

        $this->assertContains($response->status(), [200, 201], $response->getContent());
        $this->assertNotNull($setting->fresh()->value);
        $this->assertSame(0777, $this->mode('general-settings'));
    }

    public function test_answer_sheet_upload_folder_is_opened(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('answer-sheets/9/old.pdf', 'x');
        chmod(Storage::disk('public')->path('answer-sheets/9'), 0755);

        // Same call the upload controllers make right after store().
        $stored = Storage::disk('public')->putFile('answer-sheets/9', \Illuminate\Http\UploadedFile::fake()->create('s.pdf', 5, 'application/pdf'));
        PublicStorage::openFolder(dirname($stored));

        $this->assertSame(0777, $this->mode('answer-sheets/9'));
    }
}
