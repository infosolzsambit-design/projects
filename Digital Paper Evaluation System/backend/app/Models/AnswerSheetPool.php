<?php

namespace App\Models;

use App\Traits\HasAuditContext;
use App\Traits\HasDateTimeTimestamps;
use App\Traits\HasUserstamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * A pool of pending answer sheets shared by several teachers (Assign
 * Teacher → "Pool") — see the create_answer_sheet_pools_table migration.
 * Every pool teacher sees every sheet still waiting in the pool
 * (teacher_id NULL); the first to click Start Evaluate claims it.
 */
class AnswerSheetPool extends Model implements AuditableContract
{
    use Auditable, HasAuditContext, HasDateTimeTimestamps, HasUserstamps, SoftDeletes {
        HasAuditContext::transformAudit insteadof Auditable;
    }

    /**
     * @var list<string>
     */
    protected $fillable = [
        'program_name',
        'course_id',
        'exam_term_id',
        'exam_type_id',
        'semester',
        'exam_year',
        'department_id',
        'evaluation_start_date',
        'evaluation_end_date',
        'evaluation_time_per_sheet',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'semester' => 'integer',
            'exam_year' => 'integer',
            'evaluation_start_date' => 'datetime',
            'evaluation_end_date' => 'datetime',
            'evaluation_time_per_sheet' => 'integer',
        ];
    }

    /** The teachers sharing this pool. */
    public function teachers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'answer_sheet_pool_teachers', 'answer_sheet_pool_id', 'teacher_id')->withTimestamps();
    }

    /** Every sheet put in this pool — waiting (teacher_id NULL) or already claimed. */
    public function sheets(): HasMany
    {
        return $this->hasMany(AnswerSheet::class, 'answer_sheet_pool_id');
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function examTerm(): BelongsTo
    {
        return $this->belongsTo(ExamTerm::class);
    }

    public function examType(): BelongsTo
    {
        return $this->belongsTo(ExamType::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
