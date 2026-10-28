<?php

namespace App\Models;

use App\Traits\SerializesDatesInAppTimezone;
use App\Traits\HasDateTimeTimestamps;
use Illuminate\Database\Eloquent\Model;

/**
 * The request a master's "Sync …" makes to the college's main database API
 * (method, path after the base URL, and Postman-style query params) — edited
 * and auto-saved in MainDbSyncModal.vue. A master with no saved row yet uses
 * DEFAULT_PATHS with POST and no params.
 */
class MainDbSyncConfig extends Model
{
    // JSON dates in the app timezone, not UTC — see the trait.
    use SerializesDatesInAppTimezone;

    use HasDateTimeTimestamps;

    /** Every master that can be synced → its default API path. */
    public const DEFAULT_PATHS = [
        'departments' => 'DataApi/department',
        'courses' => 'DataApi/course',
        'programs' => 'DataApi/program',
        'exam-terms' => 'DataApi/examTerm',
        'exam-types' => 'DataApi/examType',
        'exam-sub-types' => 'DataApi/examSubType',
        'teachers' => 'DataApi/teacher',
        'students' => 'DataApi/student',
    ];

    /** Params a master starts with (still editable in the Sync modal). */
    public const DEFAULT_PARAMS = [
        // Only exam sub types not deleted in the main database (delete_status 1).
        'exam-sub-types' => [['key' => 'delete_status', 'value' => '1', 'enabled' => true]],
        // Only teachers not deleted in the main database (delete_status 1).
        'teachers' => [['key' => 'delete_status', 'value' => '1', 'enabled' => true]],
        // Same for students.
        'students' => [['key' => 'delete_status', 'value' => '1', 'enabled' => true]],
    ];

    public const METHODS = ['GET', 'POST'];

    protected $fillable = [
        'entity',
        'method',
        'path',
        'params',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'params' => 'array',
        ];
    }

    /** The saved config for $entity, or an unsaved one with the defaults. */
    public static function forEntity(string $entity): self
    {
        return static::firstWhere('entity', $entity)
            ?? new static(['entity' => $entity, 'method' => 'POST', 'path' => self::DEFAULT_PATHS[$entity] ?? '', 'params' => self::DEFAULT_PARAMS[$entity] ?? []]);
    }

    /** Only the ticked params with a key, as [key => value]. */
    public function queryParams(): array
    {
        $query = [];
        foreach ($this->params ?? [] as $param) {
            $key = trim((string) ($param['key'] ?? ''));
            if (($param['enabled'] ?? true) && $key !== '') {
                $query[$key] = (string) ($param['value'] ?? '');
            }
        }

        return $query;
    }

    public function toPayload(): array
    {
        return [
            'method' => $this->method,
            'path' => $this->path,
            'params' => array_values($this->params ?? []),
        ];
    }
}
