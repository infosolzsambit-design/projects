<?php

namespace App\Services;

use App\Models\MainDbSync;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Brings one master table in line with the college's main database (rows
 * from MainDbApiService::fetch()), matched by inserted_id — the main
 * database's own id:
 *  - an inserted_id we don't have yet → a new row (active);
 *  - one we already have → its fields updated (and restored, if an earlier
 *    sync had removed it);
 *  - a synced row whose inserted_id is no longer sent at all → soft-deleted.
 * Rows added by hand here (no inserted_id) are never touched. Used by the
 * "Sync …" buttons (DepartmentController::sync(), CourseController::sync()).
 * Every run is recorded in main_db_syncs (the page's "Last sync").
 */
class MainDbSyncService
{
    public function __construct(private AuditLogService $auditLog) {}

    /**
     * The main database's own "deleted" flag on an API row. Its
     * delete_status is 0 = deleted, 1 = not deleted (a row without one
     * counts as not deleted) — stored here the other way round, as
     * deleted_from_main_db (1 = deleted).
     */
    public static function isDeletedInMainDb(array $row): bool
    {
        return trim((string) ($row['delete_status'] ?? '1')) === '0';
    }

    /**
     * @param  class-string<Model>  $modelClass  a SoftDeletes model with an inserted_id column
     * @param  array<int, array<string, mixed>>  $remote  the API's rows
     * @param  callable(array): ?array  $map  API row → column values, or null to skip it
     *                                        (bad data — it's then left as it is here)
     * @param  string  $label  plural, e.g. "departments" (audit log)
     * @param  array<string, mixed>  $createDefaults  extra columns for new rows only (e.g. a
     *                                              required column the API doesn't send)
     * @param  (callable(Model, array): bool)|null  $afterSave  runs for every kept row with its
     *                                                         API row (e.g. relations); returns
     *                                                         whether it changed anything
     * @param  string|null  $matchColumn  e.g. "registration_no": a new inserted_id first links to
     *                                    a row added here by hand (no inserted_id yet) with the
     *                                    same value, instead of creating a duplicate
     * @return array{fetched: int, created: int, updated: int, restored: int, unchanged: int, deleted: int, skipped: int}
     */
    public function sync(string $modelClass, array $remote, callable $map, string $label, array $createDefaults = [], ?callable $afterSave = null, ?string $matchColumn = null): array
    {
        $rows = [];
        $seenIds = [];
        $skipped = 0;
        foreach ($remote as $row) {
            $id = $row['inserted_id'] ?? null;
            if (! is_numeric($id)) {
                $skipped++;

                continue;
            }
            $seenIds[] = (int) $id;
            $values = $map($row);
            if ($values === null) {
                $skipped++;

                continue;
            }
            $rows[(int) $id] = ['values' => $values, 'row' => $row];
        }

        $summary = DB::transaction(function () use ($modelClass, $rows, $seenIds, $createDefaults, $afterSave, $matchColumn) {
            $counts = ['created' => 0, 'updated' => 0, 'restored' => 0, 'unchanged' => 0, 'deleted' => 0];
            $existing = $modelClass::withTrashed()->whereNotNull('inserted_id')->get()->keyBy('inserted_id');
            $handAdded = $matchColumn
                ? $modelClass::whereNull('inserted_id')->whereNotNull($matchColumn)->get()->keyBy(fn ($m) => mb_strtolower((string) $m->{$matchColumn}))
                : collect();

            foreach ($rows as $insertedId => ['values' => $values, 'row' => $row]) {
                $model = $existing->get($insertedId);
                // Not synced yet, but added here by hand with the same value → link it.
                if (! $model && $matchColumn && ($linked = $handAdded->pull(mb_strtolower((string) ($values[$matchColumn] ?? ''))))) {
                    $model = $linked;
                    $model->inserted_id = $insertedId;
                }
                if (! $model) {
                    $model = $modelClass::create(['inserted_id' => $insertedId, 'status' => true] + $values + $createDefaults);
                    if ($afterSave) {
                        $afterSave($model, $row);
                    }
                    $counts['created']++;

                    continue;
                }
                $restored = false;
                if ($model->trashed()) {
                    $model->restore();
                    $restored = true;
                    $counts['restored']++;
                }
                $model->fill($values);
                $changed = $model->isDirty();
                if ($changed) {
                    $model->save();
                }
                if ($afterSave && $afterSave($model, $row)) {
                    $changed = true;
                }
                if ($changed) {
                    $counts['updated']++;
                } elseif (! $restored) {
                    $counts['unchanged']++;
                }
            }

            // Synced rows the main database no longer sends at all.
            $modelClass::whereNotNull('inserted_id')
                ->whereNotIn('inserted_id', $seenIds)
                ->get()
                ->each(function (Model $model) use (&$counts) {
                    $model->delete();
                    $counts['deleted']++;
                });

            return $counts;
        });
        $summary = ['fetched' => count($remote)] + $summary + ['skipped' => $skipped];
        $this->record($label, $summary);

        return $summary;
    }

    /**
     * Records a finished sync: the list page's "Last sync"
     * (MainDbSync::lastFor()) and the audit log. Also used by syncs that
     * don't go through sync() above (TeacherSyncService).
     */
    public function record(string $label, array $summary): void
    {
        MainDbSync::create(['entity' => $label, 'summary' => $summary, 'created_by' => Auth::id()]);

        $module = ucwords(str_replace(['-', '_'], ' ', $label)); // "exam-terms" → "Exam Terms"
        $this->auditLog->log(
            event: "{$label}-synced",
            module: $module,
            description: 'Synced '.strtolower($module)." from the main database: {$summary['created']} added, {$summary['updated']} updated, {$summary['restored']} restored, {$summary['deleted']} removed.",
            newValues: $summary,
            tags: [$label, 'sync'],
        );
    }
}
