<?php

namespace App\Traits;

/**
 * Explicit, boot-driven created_at/updated_at handling using plain DATETIME
 * columns (see migrations — no $table->timestamps()), instead of relying on
 * Eloquent's implicit TIMESTAMP-oriented automatic timestamp management.
 */
trait HasDateTimeTimestamps
{
    public function initializeHasDateTimeTimestamps(): void
    {
        $this->timestamps = false;

        // Disabling $timestamps also disables Eloquent's automatic
        // created_at/updated_at date casting (see HasAttributes::getDates()),
        // so it has to be restored explicitly here.
        $this->casts['created_at'] = 'datetime';
        $this->casts['updated_at'] = 'datetime';
    }

    public static function bootHasDateTimeTimestamps(): void
    {
        static::creating(function ($model): void {
            $now = now();
            $model->created_at = $model->created_at ?? $now;
            $model->updated_at = $model->updated_at ?? $now;
        });

        static::updating(function ($model): void {
            $model->updated_at = now();
        });
    }
}
