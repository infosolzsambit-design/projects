<?php

namespace App\Models;

use App\Traits\HasAuditContext;
use App\Traits\HasDateTimeTimestamps;
use App\Traits\HasUserstamps;
use Database\Factories\AnswerSheetFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * One student's row from an uploaded CSV mapping, belonging to one
 * question_answer_sheet_mappings packet — see that migration's docblock.
 * Created together with the rest of its packet, but carries the same full
 * audit trail as every other top-level model in this app (unlike
 * QuestionPaperNode) — each row represents a real physical answer sheet.
 */
class AnswerSheet extends Model implements AuditableContract
{
    /** @use HasFactory<AnswerSheetFactory> */
    use Auditable, HasAuditContext, HasDateTimeTimestamps, HasFactory, HasUserstamps, SoftDeletes {
        HasAuditContext::transformAudit insteadof Auditable;
    }

    /**
     * @var list<string>
     */
    protected $fillable = [
        'question_answer_sheet_mapping_id',
        'teacher_id',
        'evaluation_start_date',
        'evaluation_end_date',
        'evaluation_time_per_sheet',
        'consumed_time',
        'draft_marks',
        'draft_marks_breakdown',
        'draft_annotations',
        'branch_code',
        'branch_name',
        'subject_code',
        'subject_name',
        'semester',
        'subject_barcode',
        'fi_code',
        'roll_no',
        'name',
        'registration_no',
        'absent',
        'locked_time',
        'packet_no',
        'barcode',
        'marks',
        'top_sheet',
        'pdf_name',
        'pdf_path',
    ];

    /**
     * Deliberately excluded from every audit snapshot — pdf_name/pdf_path
     * for the same reasoning as QuestionPaper's own pdf_path (a storage
     * path isn't meaningful change history); the draft_* fields because
     * they're autosaved every few seconds while a teacher is working (see
     * MyPendingCourseController::saveDraft(), which also disables
     * auditing entirely around that call) and would otherwise flood the
     * audit trail with noise no one will ever want to read — only the
     * final `marks` (set once, via "Complete") is real change history.
     *
     * @var list<string>
     */
    protected $auditExclude = [
        'pdf_name',
        'pdf_path',
        'consumed_time',
        'draft_marks',
        'draft_marks_breakdown',
        'draft_annotations',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'semester' => 'integer',
            'absent' => 'boolean',
            'marks' => 'decimal:2',
            'evaluation_start_date' => 'datetime',
            'evaluation_end_date' => 'datetime',
            'evaluation_time_per_sheet' => 'integer',
            'consumed_time' => 'integer',
            'draft_marks' => 'decimal:2',
            'draft_marks_breakdown' => 'array',
            'draft_annotations' => 'array',
        ];
    }

    public function mapping(): BelongsTo
    {
        return $this->belongsTo(QuestionAnswerSheetMapping::class, 'question_answer_sheet_mapping_id');
    }

    /**
     * The teacher this physical answer sheet has been assigned to for
     * evaluation — null means still pending. Set via
     * AssignTeacherService::assign(), never by hand elsewhere.
     */
    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teacher_id');
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
