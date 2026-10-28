<?php

namespace App\Models;

use App\Traits\SerializesDatesInAppTimezone;
use App\Traits\HasDateTimeTimestamps;
use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

class PersonalAccessToken extends SanctumPersonalAccessToken
{
    // JSON dates in the app timezone, not UTC — see the trait.
    use SerializesDatesInAppTimezone;

    use HasDateTimeTimestamps;
}
