<?php

namespace App\Models;

use App\Traits\HasDateTimeTimestamps;
use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

class PersonalAccessToken extends SanctumPersonalAccessToken
{
    use HasDateTimeTimestamps;
}
