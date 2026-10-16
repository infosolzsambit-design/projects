<?php

namespace App\Models;

use App\Traits\HasAuditContext;
use App\Traits\HasDateTimeTimestamps;
use App\Traits\HasUserstamps;
use Database\Factories\TeacherDetailFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

class TeacherDetail extends Model implements AuditableContract
{
    /** @use HasFactory<TeacherDetailFactory> */
    use Auditable, HasAuditContext, HasDateTimeTimestamps, HasFactory, HasUserstamps, SoftDeletes {
        HasAuditContext::transformAudit insteadof Auditable;
    }

    /**
     * @var list<string>
     */
    protected $fillable = [
        // The college main database's own id (set by "Sync Teacher").
        'inserted_id',
        'user_id',
        'emp_code',
        'designation',
        // delete_status from the main database's API (set by "Sync Teacher").
        'deleted_from_main_db',
        'gender',
        'location',
        'about',
        'photo',
        'face_descriptor',
        'face_scan_applicable',
        'esign',
    ];

    /**
     * face_descriptor never leaves the server (see
     * ProfileController::verifyFace() — comparison happens server-side
     * against a freshly-scanned descriptor) — hidden here as a backstop in
     * case this model is ever serialized somewhere without going through a
     * resource that already omits it.
     *
     * @var list<string>
     */
    protected $hidden = [
        'face_descriptor',
    ];

    /**
     * photo/face_descriptor/esign are excluded from audit capture — base64
     * blobs would otherwise bloat every audit row for something that's
     * neither security-relevant to review nor meaningful as a diff (same
     * reasoning as User's password exclusion).
     *
     * @var list<string>
     */
    protected $auditExclude = [
        'photo',
        'face_descriptor',
        'esign',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // 128 floats in, 128 floats out — Eloquent handles the JSON
            // encode/decode, ProfileController just deals in plain arrays.
            'face_descriptor' => 'array',
            'face_scan_applicable' => 'boolean',
            'deleted_from_main_db' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function deleter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by');
    }
}
