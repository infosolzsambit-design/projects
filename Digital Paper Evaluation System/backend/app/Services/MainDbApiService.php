<?php

namespace App\Services;

use App\Models\GeneralSetting;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Talks to the college's own main-database API, used to sync master data
 * (departments, courses, …). The request itself (method, path, params) is
 * per master — MainDbSyncConfig. Everything it needs lives
 * in General Settings:
 *  - is_need_api_from_main_db (yes / no) — whether syncing is used at all;
 *  - sync_base_url — e.g. https://campus.example.com/api/V1;
 *  - sync_base_url_security_code — sent as the "Security-Code" header.
 * A setting whose own on/off switch is off counts as not set.
 */
class MainDbApiService
{
    public function isEnabled(): bool
    {
        return strtolower((string) $this->setting('is_need_api_from_main_db')) === 'yes';
    }

    /**
     * {method} {base}/{path}?{query} → its "data" rows. The query params go
     * in the URL for GET and POST alike, as Postman's "Params" tab does —
     * that's where the college API reads them (e.g. delete_status=0).
     *
     * @return list<array<string, mixed>>
     *
     * @throws RuntimeException with a message fit to show the admin.
     */
    public function fetch(string $path, string $method = 'POST', array $query = []): array
    {
        if (! $this->isEnabled()) {
            throw new RuntimeException('Syncing from the main database is turned off in General Settings.');
        }
        $base = rtrim((string) $this->setting('sync_base_url'), '/');
        $code = (string) $this->setting('sync_base_url_security_code');
        if ($base === '' || $code === '') {
            throw new RuntimeException('Set the API Base URL and API Security Code in General Settings first.');
        }

        try {
            $request = Http::withHeaders(['Security-Code' => $code])
                ->acceptJson()
                ->timeout(60)
                ->withQueryParameters($query);
            $url = $base.'/'.ltrim($path, '/');
            $response = strtoupper($method) === 'GET' ? $request->get($url) : $request->post($url);
        } catch (Throwable $e) {
            throw new RuntimeException('Could not reach the main database API: '.$e->getMessage());
        }

        if (! $response->successful()) {
            throw new RuntimeException("The main database API returned an error (HTTP {$response->status()}).");
        }
        $body = $response->json();
        if (! is_array($body) || ($body['status'] ?? false) !== true || ! is_array($body['data'] ?? null)) {
            throw new RuntimeException($body['message'] ?? 'The main database API returned an unexpected response.');
        }

        return array_values($body['data']);
    }

    private function setting(string $field): ?string
    {
        return GeneralSetting::where('field_name', $field)->where('status', true)->value('value');
    }
}
