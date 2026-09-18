<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One node in a question paper's structure tree — see the migration's
 * docblock for what `mode` means. Self-referencing (`parent`/`children`)
 * instead of a fixed Group/Question split, because real papers nest
 * arbitrarily deep (a question can branch into worded alternatives, and
 * one of those alternatives can branch again into lettered sub-parts).
 * Same no-independent-lifecycle reasoning as before this replaced the old
 * two-table shape — no SoftDeletes/Auditable/HasUserstamps, since a
 * paper's whole tree is always replaced wholesale, never edited node by
 * node outside its parent question paper's structure-save.
 */
class QuestionPaperNode extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'question_paper_id',
        'parent_id',
        'label',
        'instruction',
        'mode',
        'choose_count',
        'slots_override',
        'marks',
        'bloom_level',
        'co',
        'sort_order',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'choose_count' => 'integer',
            'slots_override' => 'integer',
            'marks' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function questionPaper(): BelongsTo
    {
        return $this->belongsTo(QuestionPaper::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order');
    }
}
