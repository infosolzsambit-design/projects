<?php

namespace App\Services;

use App\Models\Course;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Backs the "Bulk Upload" flow on the Courses list — same shape as
 * DepartmentBulkUploadService/TeacherBulkUploadService: a CSV is parsed
 * client-side into plain rows (Name, Code), validated here (same rules
 * StoreCourseRequest enforces one-by-one, plus a duplicate-within-the-
 * same-upload check a single-row request could never hit), and — once
 * every row is valid — created here too, inside a single transaction.
 */
class CourseBulkUploadService
{
    /**
     * @param  list<array<string, mixed>>  $rows  each: {name, code}
     * @return list<array{row:int,valid:bool,errors:array<string,string>}>
     */
    public function validateRows(array $rows): array
    {
        $codeCounts = collect($rows)->countBy(fn ($row) => Str::lower(trim((string) ($row['code'] ?? ''))));

        return collect($rows)
            ->values()
            ->map(fn ($row, $index) => $this->validateRow($row, $index + 1, $codeCounts))
            ->all();
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{row:int,valid:bool,errors:array<string,string>}
     */
    private function validateRow(array $row, int $rowNumber, Collection $codeCounts): array
    {
        $errors = [];
        $name = trim((string) ($row['name'] ?? ''));
        $code = trim((string) ($row['code'] ?? ''));

        if ($name === '') {
            $errors['name'] = 'Name is required.';
        }

        if ($code === '') {
            $errors['code'] = 'Code is required.';
        } elseif ($codeCounts->get(Str::lower($code), 0) > 1) {
            $errors['code'] = 'Duplicate code within this upload.';
        } elseif (Course::whereNull('deleted_at')->whereRaw('LOWER(code) = ?', [Str::lower($code)])->exists()) {
            $errors['code'] = 'This code is already in use.';
        }

        return [
            'row' => $rowNumber,
            'valid' => $errors === [],
            'errors' => $errors,
        ];
    }

    /**
     * Caller is responsible for confirming every row already passed
     * validateRows() and for wrapping this in a DB transaction.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<Course>
     */
    public function createRows(array $rows): array
    {
        return collect($rows)->values()->map(fn ($row) => Course::create([
            'name' => trim((string) ($row['name'] ?? '')),
            'code' => trim((string) ($row['code'] ?? '')),
            'status' => true,
        ]))->all();
    }
}
