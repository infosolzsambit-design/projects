<?php

namespace App\Helpers;

use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * How every date goes out in API JSON: in the app's own timezone
 * (config('app.timezone'), Asia/Kolkata), e.g. "2026-10-23T18:44:26+05:30" —
 * not Laravel's default UTC "2026-10-23T13:14:26.000000Z", which the frontend
 * showed 5½ hours early. Still ISO 8601 with the offset, so it's unambiguous.
 *
 * Used for model dates (App\Traits\SerializesDatesInAppTimezone) and for any
 * other Carbon value in a response (AppServiceProvider, Date::serializeUsing).
 */
class JsonDate
{
    public static function format(DateTimeInterface $date): string
    {
        return Carbon::instance($date)->setTimezone(config('app.timezone'))->format('Y-m-d\TH:i:sP');
    }
}
