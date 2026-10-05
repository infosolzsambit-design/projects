<?php

namespace App\Services;

use App\Helpers\PublicStorage;
use App\Models\AnswerSheet;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Reads the handwritten STUDENT'S ID NO. off answer sheets' cover pages
 * (via ocr/read_student_id.py, a batch per call so the model loads once)
 * and stores what was read. Only the reading outcome is stored — whether it
 * *matches* is decided against the sheet's current roll_no at query time
 * (see matchedSql()), so correcting a roll number later never leaves a
 * stale "mismatch" behind. The cropped ID image is saved alongside for
 * human review. Runs synchronously — the Generate Marksheet page drives it
 * batch by batch with a progress bar.
 */
class StudentIdCheckService
{
    public const READ = 'read';

    public const UNREADABLE = 'unreadable';

    public const ERROR = 'error';

    /**
     * SQL condition: the ID read off the sheet equals the sheet's current
     * roll number (digits only on both sides).
     */
    public static function matchedSql(string $table = 'answer_sheets'): string
    {
        return "({$table}.roll_no_check_status = '".self::READ."' AND {$table}.student_id_read = REGEXP_REPLACE(COALESCE({$table}.roll_no, ''), '[^0-9]', ''))";
    }

    /** SQL condition: checked, but not a match — needs a person to review. */
    public static function needsReviewSql(string $table = 'answer_sheets'): string
    {
        return "({$table}.roll_no_check_status IS NOT NULL AND NOT ".self::matchedSql($table).')';
    }

    public function check(AnswerSheet $sheet): string
    {
        return $this->checkMany(collect([$sheet]))[$sheet->id];
    }

    /**
     * Read a batch of sheets in one reader process.
     *
     * @param  Collection<int, AnswerSheet>  $sheets
     * @return array<int, string> answer sheet id => stored status
     */
    public function checkMany(Collection $sheets): array
    {
        $statuses = [];
        $items = [];
        foreach ($sheets as $sheet) {
            $pdf = $this->localPath($sheet->pdf_path);
            if (! $pdf || ! is_file($pdf)) {
                $statuses[$sheet->id] = $this->record($sheet, self::ERROR, null, null);

                continue;
            }
            $cropRelative = "student-id-crops/{$sheet->question_answer_sheet_mapping_id}/{$sheet->id}.png";
            $items[] = [
                'sheet' => $sheet,
                'crop_relative' => $cropRelative,
                'input' => ['pdf' => $pdf, 'crop' => Storage::disk('public')->path($cropRelative)],
            ];
        }
        if ($items === []) {
            return $statuses;
        }

        $result = Process::timeout((int) config('roll_check.timeout_seconds') * max(1, count($items)))
            ->input(json_encode(array_column($items, 'input')))
            ->run([config('roll_check.python'), config('roll_check.script'), '--batch']);

        // The reader creates student-id-crops/{packet}/ itself — open it up
        // so it can be removed over FTP too (see PublicStorage).
        foreach (array_unique(array_map(fn ($i) => dirname($i['crop_relative']), $items)) as $cropDir) {
            PublicStorage::openFolder($cropDir);
        }

        $outputs = json_decode(trim($result->output()), true);
        if (! is_array($outputs) || count($outputs) !== count($items)) {
            Log::warning('Student ID batch check failed.', [
                'answer_sheet_ids' => array_map(fn ($i) => $i['sheet']->id, $items),
                'exit_code' => $result->exitCode(),
                'error' => Str::limit($result->errorOutput(), 500),
            ]);
            foreach ($items as $item) {
                $statuses[$item['sheet']->id] = $this->record($item['sheet'], self::ERROR, null, null);
            }

            return $statuses;
        }

        foreach ($items as $i => $item) {
            $sheet = $item['sheet'];
            $data = $outputs[$i];
            $cropPath = is_file($item['input']['crop']) ? '/storage/'.$item['crop_relative'] : null;
            $read = ($data['status'] ?? null) === 'ok' ? self::digitsOnly($data['digits'] ?? '') : '';

            if (($data['status'] ?? null) === 'error') {
                $statuses[$sheet->id] = $this->record($sheet, self::ERROR, null, $cropPath);
            } elseif ($read === '') {
                $statuses[$sheet->id] = $this->record($sheet, self::UNREADABLE, null, $cropPath);
            } else {
                $statuses[$sheet->id] = $this->record($sheet, self::READ, $read, $cropPath);
            }
        }

        return $statuses;
    }

    public static function digitsOnly(?string $value): string
    {
        return preg_replace('/\D+/', '', (string) $value) ?? '';
    }

    private function record(AnswerSheet $sheet, string $status, ?string $read, ?string $cropPath): string
    {
        // A plain query update — this is machine bookkeeping, not a user
        // edit, so it shouldn't flood the audit log or bump updated_by.
        AnswerSheet::whereKey($sheet->id)->update([
            'student_id_read' => $read,
            'roll_no_check_status' => $status,
            'student_id_crop_path' => $cropPath,
            'roll_no_checked_at' => now(),
        ]);

        return $status;
    }

    /** '/storage/answer-sheets/1/x.pdf' -> absolute path on the public disk. */
    private function localPath(?string $pdfPath): ?string
    {
        if (! $pdfPath || ! str_starts_with($pdfPath, '/storage/')) {
            return null;
        }

        return Storage::disk('public')->path(Str::after($pdfPath, '/storage/'));
    }
}
