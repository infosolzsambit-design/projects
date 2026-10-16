<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\MainDbSync\UpdateMainDbSyncConfigRequest;
use App\Models\MainDbSync;
use App\Models\MainDbSyncConfig;
use App\Services\MainDbApiService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /main-db-sync/status?entity=departments — whether the "Sync …"
 * buttons should show (General Settings → Is Need API From Main DB = yes),
 * plus that entity's last sync (MainDbSync::lastFor()) and request settings
 * (MainDbSyncConfig). The base URL and
 * security code never leave the server.
 */
class MainDbSyncController extends Controller
{
    use ApiResponse;

    public function status(Request $request, MainDbApiService $mainDb): JsonResponse
    {
        $entity = $request->string('entity')->toString();

        return $this->success([
            'enabled' => $mainDb->isEnabled(),
            'last_sync' => $entity === '' ? null : MainDbSync::lastFor($entity),
            'config' => isset(MainDbSyncConfig::DEFAULT_PATHS[$entity]) ? MainDbSyncConfig::forEntity($entity)->toPayload() : null,
        ], 'Main database sync status.');
    }

    /**
     * PUT /main-db-sync/config — the Sync modal's request settings (method,
     * path after the base URL, query params), auto-saved as they're edited.
     */
    public function updateConfig(UpdateMainDbSyncConfigRequest $request): JsonResponse
    {
        $data = $request->validated();
        $params = array_map(fn (array $p) => [
            'key' => trim((string) ($p['key'] ?? '')),
            'value' => (string) ($p['value'] ?? ''),
            'enabled' => (bool) $p['enabled'],
        ], $data['params']);

        $config = MainDbSyncConfig::updateOrCreate(
            ['entity' => $data['entity']],
            ['method' => $data['method'], 'path' => $data['path'], 'params' => $params, 'updated_by' => $request->user()?->id],
        );

        return $this->success($config->toPayload(), 'Sync settings saved.');
    }
}
