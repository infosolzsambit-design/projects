<?php

namespace App\Models;

use App\Traits\HasDateTimeTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One successful "Sync …" run from the college's main database (see
 * MainDbSyncService). The latest row per entity is that list page's
 * "Last sync".
 */
class MainDbSync extends Model
{
    use HasDateTimeTimestamps;

    protected $fillable = [
        'entity',
        'summary',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'summary' => 'array',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** The latest run for $entity, shaped for the list pages (null if never synced). */
    public static function lastFor(string $entity): ?array
    {
        $sync = static::with('creator:id,name')->where('entity', $entity)->latest('id')->first();

        return $sync ? [
            'synced_at' => $sync->created_at?->format('Y-m-d H:i:s'),
            'synced_by' => $sync->creator?->name,
            'summary' => $sync->summary,
        ] : null;
    }
}
