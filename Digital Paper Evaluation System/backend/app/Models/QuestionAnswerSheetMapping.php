<?php

namespace App\Models;

use App\Traits\HasAuditContext;
use App\Traits\HasDateTimeTimestamps;
use App\Traits\HasUserstamps;
use Database\Factories\QuestionAnswerSheetMappingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * One exam-center answer-sheet upload packet — see the migration's own
 * docblock. Its student rows (see AnswerSheet) are always created
 * together with it and replaced wholesale on any resubmission, never
 * edited row by row.
 */
class QuestionAnswerSheetMapping extends Model implements AuditableContract
{
    /** @use HasFactory<QuestionAnswerSheetMappingFactory> */
    use Auditable, HasAuditContext, HasDateTimeTimestamps, HasFactory, HasUserstamps, SoftDeletes {
        HasAuditContext::transformAudit insteadof Auditable;
    }

    /**
     * @var list<string>
     */
    protected $fillable = [
        'question_paper_id',
        'course_id',
        'semester',
        'exam_term_id',
        'program_name',
        'packet_code',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'semester' => 'integer',
        ];
    }

    /**
     * AnswerSheet now carries its own full audit trail (soft deletes
     * included — see that model's own docblock), so a packet's lifecycle
     * cascades to its rows the same way a "real" parent/child relationship
     * would: soft-deleting the packet soft-deletes its rows too (each row
     * gets its own deleted_by stamp — see HasUserstamps — which a bulk
     * query-builder delete wouldn't produce, hence looping actual model
     * instances below instead of one UPDATE), restoring un-does that, and
     * only a *force* delete actually removes the rows and their PDFs for
     * good.
     */
    protected static function booted(): void
    {
        static::deleted(function (QuestionAnswerSheetMapping $mapping): void {
            if ($mapping->isForceDeleting()) {
                // withTrashed() — an already-individually-soft-deleted row
                // still needs its file cleaned up and removing for good
                // once the whole packet goes; the default query would
                // silently skip it and leave an orphaned file behind.
                AnswerSheet::withTrashed()
                    ->where('question_answer_sheet_mapping_id', $mapping->id)
                    ->get()
                    ->each(function (AnswerSheet $sheet): void {
                        if ($sheet->pdf_path && str_starts_with($sheet->pdf_path, '/storage/')) {
                            Storage::disk('public')->delete(substr($sheet->pdf_path, strlen('/storage/')));
                        }
                        $sheet->forceDelete();
                    });

                return;
            }

            AnswerSheet::where('question_answer_sheet_mapping_id', $mapping->id)
                ->get()
                ->each(fn (AnswerSheet $sheet) => $sheet->delete());
        });

        static::restored(function (QuestionAnswerSheetMapping $mapping): void {
            AnswerSheet::onlyTrashed()
                ->where('question_answer_sheet_mapping_id', $mapping->id)
                ->get()
                ->each(fn (AnswerSheet $sheet) => $sheet->restore());
        });
    }

    public function questionPaper(): BelongsTo
    {
        return $this->belongsTo(QuestionPaper::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function examTerm(): BelongsTo
    {
        return $this->belongsTo(ExamTerm::class);
    }

    public function answerSheets(): HasMany
    {
        return $this->hasMany(AnswerSheet::class);
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
