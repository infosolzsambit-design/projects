<?php

namespace App\Traits;

use Illuminate\Support\Facades\Auth;

/**
 * Populates created_by / updated_by / deleted_by from the authenticated
 * user. No database foreign key backs these columns (project-wide rule) —
 * they're plain indexed unsignedBigInteger columns resolved through the
 * user() relation on the owning model.
 *
 * deleted_by only has effect on models using SoftDeletes: the framework's
 * own soft-delete UPDATE query only touches deleted_at/updated_at, so it's
 * stamped via a quiet, explicit save before that query runs.
 */
trait HasUserstamps
{
    public static function bootHasUserstamps(): void
    {
        static::creating(function ($model): void {
            if (Auth::check() && array_key_exists('created_by', $model->getAttributes()) === false) {
                $model->created_by = Auth::id();
            }
        });

        static::updating(function ($model): void {
            if (Auth::check()) {
                $model->updated_by = Auth::id();
            }
        });

        static::deleting(function ($model): void {
            if (Auth::check() && method_exists($model, 'trashed') && ! $model->trashed()) {
                $model->deleted_by = Auth::id();
                $model->saveQuietly();
            }
        });
    }
}
