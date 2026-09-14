<?php

namespace App\Models;

use App\Traits\HasAuditContext;
use App\Traits\HasDateTimeTimestamps;
use App\Traits\HasUserstamps;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements AuditableContract
{
    /** @use HasFactory<UserFactory> */
    use Auditable, HasApiTokens, HasAuditContext, HasDateTimeTimestamps, HasFactory, HasRoles, HasUserstamps, Notifiable, SoftDeletes {
        HasAuditContext::transformAudit insteadof Auditable;
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'username',
        'email',
        'phone_no',
        'password',
        'is_active',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Fields that must never appear in audit old_values/new_values.
     *
     * @var list<string>
     */
    protected $auditExclude = [
        'password',
        'remember_token',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }

    /**
     * Tells Spatie Permission which guard to resolve roles/permissions
     * against. Needed explicitly because Sanctum's auto-registered
     * "sanctum" guard has no "provider" in config(auth.guards), so Spatie's
     * own config-based guard detection can't infer it otherwise.
     */
    public function guardName(): string
    {
        return 'sanctum';
    }

    /**
     * Present only on users created through the Teacher module (see
     * TeacherController) — a plain user has none.
     */
    public function teacherDetail(): HasOne
    {
        return $this->hasOne(TeacherDetail::class);
    }

    /**
     * Answer sheets this teacher has been assigned to evaluate — see
     * AssignTeacherService's own docblock. Backs the "Already Allocated"
     * count/breakdown in AssignTeacherView.vue (TeacherController::index()'s
     * ->withCount() and TeacherController::assignments()).
     */
    public function assignedAnswerSheets(): HasMany
    {
        return $this->hasMany(AnswerSheet::class, 'teacher_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(self::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(self::class, 'updated_by');
    }

    public function deleter(): BelongsTo
    {
        return $this->belongsTo(self::class, 'deleted_by');
    }
}
