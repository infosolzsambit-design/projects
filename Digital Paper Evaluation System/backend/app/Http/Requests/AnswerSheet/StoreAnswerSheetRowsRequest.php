<?php

namespace App\Http\Requests\AnswerSheet;

use App\Models\AnswerSheet;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * One *chunk* of an answer-sheet upload — a real batch runs into the
 * thousands of rows/PDFs, which can't go through in a single request (PHP's
 * own max_file_uploads/post_max_size caps it well below that), so
 * AnswerSheetUploadView.vue splits the validated rows/PDFs into small
 * batches and POSTs them here one at a time against an already-created
 * mapping (see QuestionAnswerSheetMappingController::store() for that
 * first step) — see submitToServer()'s own docblock there for the exact
 * chunking/progress-bar mechanics.
 *
 * Backend counterpart to AnswerSheetUploadView.vue's own Check step — see
 * that page (CSV column presence, Subject Barcode/Roll No uniqueness,
 * row-count-vs-PDF-count, every PDF having a readable QR code, every
 * barcode matching one), but none of that is trustworthy from the
 * server's point of view — a direct API call could skip the Check step
 * entirely. Re-validated here as far as is practical without re-decoding
 * every PDF's QR code server-side (no PHP QR-reading dependency exists in
 * this project yet, and that felt like a genuinely separate, bigger
 * addition rather than part of "store this to the database") — the
 * barcode<->PDF correspondence itself is trusted from how the frontend
 * keys the `pdfs` array (see prepareForValidation() below and the
 * controller), not re-verified against each PDF's actual QR content here.
 * Uniqueness is checked both *within* this one chunk (the `distinct` rules
 * below) and against every row already stored for this mapping from an
 * earlier chunk (withValidator() below) — a duplicate three chunks back
 * would otherwise sail through unnoticed.
 *
 * `rows` arrives as a JSON-encoded string (multipart/form-data can't carry
 * a large nested array as compactly as one JSON field can) — same
 * prepareForValidation() pattern StoreQuestionPaperRequest already uses
 * for its own `groups` field. Each row is expected already normalized to
 * the answer_sheets table's own snake_case column names (see
 * AnswerSheetUploadView.vue's ROW_FIELD_MAP), not the CSV's original
 * "Proper Case" headers.
 */
class StoreAnswerSheetRowsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('rows'))) {
            $decoded = json_decode($this->input('rows'), true);
            $this->merge(['rows' => is_array($decoded) ? $decoded : []]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'rows' => ['required', 'array', 'min:1'],
            'rows.*.branch_code' => ['nullable', 'string', 'max:100'],
            'rows.*.branch_name' => ['nullable', 'string', 'max:255'],
            'rows.*.subject_code' => ['nullable', 'string', 'max:100'],
            'rows.*.subject_name' => ['nullable', 'string', 'max:255'],
            'rows.*.semester' => ['nullable', 'integer', 'min:1', 'max:12'],
            // distinct — no two rows in this chunk may share a Subject
            // Barcode or Roll No; cross-chunk duplicates are caught in
            // withValidator() below instead, since `distinct` only ever
            // sees the current request's own array.
            'rows.*.subject_barcode' => ['required', 'string', 'max:255', 'distinct'],
            'rows.*.fi_code' => ['nullable', 'string', 'max:100'],
            'rows.*.roll_no' => ['required', 'string', 'max:100', 'distinct'],
            'rows.*.name' => ['nullable', 'string', 'max:255'],
            'rows.*.registration_no' => ['nullable', 'string', 'max:100'],
            'rows.*.absent' => ['nullable', 'boolean'],
            'rows.*.locked_time' => ['nullable', 'string', 'max:100'],
            'rows.*.packet_no' => ['nullable', 'string', 'max:100'],
            'rows.*.barcode' => ['nullable', 'string', 'max:255'],
            'rows.*.marks' => ['nullable', 'numeric', 'min:0'],
            'rows.*.top_sheet' => ['nullable', 'string', 'max:255'],

            // Keyed by each PDF's own decoded QR value (see the
            // controller) — not a plain indexed list, so this is checked
            // as a whole in withValidator() below rather than per-index.
            // A chunk this size (see AnswerSheetUploadView.vue's
            // UPLOAD_BATCH_SIZE) comfortably fits PHP's own per-request
            // upload_max_filesize/post_max_size/max_file_uploads caps —
            // raised well past their small defaults specifically for this
            // feature (see php.ini locally / public/.user.ini in
            // production). 50MB/file is generous for even a very
            // high-resolution multi-page scanned booklet, while still
            // being a real, finite cap rather than none at all.
            'pdfs' => ['required', 'array', 'min:1'],
            'pdfs.*' => ['file', 'mimes:pdf', 'max:51200'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $rows = (array) $this->input('rows', []);
            $pdfs = (array) $this->file('pdfs', []);

            if (! $rows || ! $pdfs) {
                return; // already reported by the plain rules above
            }

            if (count($rows) !== count($pdfs)) {
                $v->errors()->add('rows', sprintf(
                    'This chunk has %d row(s) but %d PDF(s) — these must match.',
                    count($rows),
                    count($pdfs),
                ));

                return;
            }

            $rowBarcodes = collect($rows)->pluck('subject_barcode')->filter()->all();
            $pdfBarcodes = array_keys($pdfs);

            $missingPdf = array_diff($rowBarcodes, $pdfBarcodes);
            if ($missingPdf) {
                $v->errors()->add('pdfs', 'No matching PDF was uploaded for barcode(s): '.implode(', ', $missingPdf).'.');
            }

            $extraPdf = array_diff($pdfBarcodes, $rowBarcodes);
            if ($extraPdf) {
                $v->errors()->add('pdfs', 'Uploaded PDF(s) don\'t match any CSV row: '.implode(', ', $extraPdf).'.');
            }

            $mapping = $this->route('question_answer_sheet_mapping');
            if (! $mapping) {
                return;
            }

            $rollNos = collect($rows)->pluck('roll_no')->filter()->all();

            $existingBarcodes = AnswerSheet::withTrashed()
                ->where('question_answer_sheet_mapping_id', $mapping->id)
                ->whereIn('subject_barcode', $rowBarcodes)
                ->pluck('subject_barcode')
                ->all();
            if ($existingBarcodes) {
                $v->errors()->add('rows', 'Already used earlier in this same upload: '.implode(', ', $existingBarcodes).'.');
            }

            $existingRollNos = AnswerSheet::withTrashed()
                ->where('question_answer_sheet_mapping_id', $mapping->id)
                ->whereIn('roll_no', $rollNos)
                ->pluck('roll_no')
                ->all();
            if ($existingRollNos) {
                $v->errors()->add('rows', 'Roll no already used earlier in this same upload: '.implode(', ', $existingRollNos).'.');
            }
        });
    }
}
