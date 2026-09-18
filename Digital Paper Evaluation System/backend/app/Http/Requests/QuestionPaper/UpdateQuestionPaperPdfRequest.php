<?php

namespace App\Http\Requests\QuestionPaper;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Replaces only the stored PDF on an already-set-up paper — e.g. swapping
 * in a cleaner scan after a bad one was uploaded — without touching the
 * structure tree/marks at all. Deliberately its own tiny request rather
 * than reusing Store/UpdateQuestionPaperRequest's much larger rule set,
 * and deliberately allowed even once evaluation_started has otherwise
 * locked the paper (see QuestionPaperController::updatePdf()) — a plain
 * file swap can never orphan draft_marks_breakdown's node-id references
 * the way a structure edit would.
 */
class UpdateQuestionPaperPdfRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Same size ceiling as the original upload — see
            // StoreQuestionPaperRequest's own comment on why 20MB.
            'pdf' => ['required', 'file', 'mimes:pdf', 'max:20480'],
        ];
    }
}
