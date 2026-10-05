<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One in-app notification for one user (header bell). Created by
 * App\Services\UserNotificationService, never by hand.
 */
class UserNotification extends Model
{
    public const ASSIGNED = 'answer_sheets_assigned';

    public const REASSIGNED = 'answer_sheets_reassigned';

    public const ISSUE_RAISED = 'issue_raised';

    public const ISSUE_RESOLVED = 'issue_resolved';

    public const EVALUATION_RESET = 'evaluation_reset';

    protected $fillable = ['user_id', 'type', 'title', 'message', 'link', 'data', 'read_at'];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'read_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
