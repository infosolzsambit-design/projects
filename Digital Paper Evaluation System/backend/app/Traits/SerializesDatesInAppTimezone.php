<?php

namespace App\Traits;

use App\Helpers\JsonDate;
use DateTimeInterface;

/**
 * Model dates in JSON / toArray() in the app's own timezone, e.g.
 * "2026-10-23T18:44:26+05:30" — instead of Laravel's default, which always
 * converts to UTC ("…T13:14:26.000000Z", via Carbon::toJSON()) whatever
 * config('app.timezone') is. See App\Helpers\JsonDate.
 */
trait SerializesDatesInAppTimezone
{
    protected function serializeDate(DateTimeInterface $date): string
    {
        return JsonDate::format($date);
    }
}
