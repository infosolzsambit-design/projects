<?php

namespace App\Models;

use App\Traits\HasAuditContext;
use App\Traits\HasDateTimeTimestamps;
use App\Traits\HasUserstamps;
use Database\Factories\QuestionPaperFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

class QuestionPaper extends Model implements AuditableContract
{
    /** @use HasFactory<QuestionPaperFactory> */
    use Auditable, HasAuditContext, HasDateTimeTimestamps, HasFactory, HasUserstamps, SoftDeletes {
        HasAuditContext::transformAudit insteadof Auditable;
    }

    /**
     * @var list<string>
     */
    protected $fillable = [
        'exam_year',
        'course_id',
        'semester',
        'exam_term_id',
        'pdf_path',
        'full_marks',
        'time_allotted',
        'status',
    ];

    /**
     * Deliberately excluded from every audit snapshot — pdf_path is a
     * storage path, not meaningful change history (same reasoning as
     * TeacherDetail's photo/face_descriptor).
     *
     * @var list<string>
     */
    protected $auditExclude = [
        'pdf_path',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'exam_year' => 'integer',
            'semester' => 'integer',
            'full_marks' => 'integer',
        ];
    }

    /**
     * Only ever hard-deletes the child structure tree/PDF on a *force*
     * delete — a regular (soft) delete just hides the paper itself, same
     * as everything else in this app; the structure stays intact in case
     * of a restore.
     */
    protected static function booted(): void
    {
        static::deleted(function (QuestionPaper $paper): void {
            if (! $paper->isForceDeleting()) {
                return;
            }

            QuestionPaperNode::where('question_paper_id', $paper->id)->delete();

            if ($paper->pdf_path && str_starts_with($paper->pdf_path, '/storage/')) {
                Storage::disk('public')->delete(substr($paper->pdf_path, strlen('/storage/')));
            }
        });
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function examTerm(): BelongsTo
    {
        return $this->belongsTo(ExamTerm::class);
    }

    /**
     * Top-level nodes only ("groups") — each node's own children() relation
     * (see QuestionPaperNode) walks the rest of the tree from there.
     */
    public function groups(): HasMany
    {
        return $this->hasMany(QuestionPaperNode::class)->whereNull('parent_id')->orderBy('sort_order');
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
