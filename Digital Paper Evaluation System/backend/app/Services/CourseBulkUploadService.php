<?php

namespace App\Services;

use App\Models\Course;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Backs the "Bulk Upload" flow on the Courses list — same shape as
 * DepartmentBulkUploadService/TeacherBulkUploadService: a CSV is parsed
 * client-side into plain rows (Name, Code, Type), validated here (same rules
 * StoreCourseRequest enforces one-by-one — including name + code + type
 * being unique together — plus the same combination repeated within the
 * upload itself, which a single-row request could never hit), and — once
 * every row is valid — created here too, inside a single transaction.
 */
class CourseBulkUploadService
{
    /**
     * @param  list<array<string, mixed>>  $rows  each: {name, code, type}
     * @return list<array{row:int,valid:bool,errors:array<string,string>}>
     */
    public function validateRows(array $rows): array
    {
        $comboCounts = collect($rows)->countBy(fn ($row) => $this->comboKey($row));

        return collect($rows)
            ->values()
            ->map(fn ($row, $index) => $this->validateRow($row, $index + 1, $comboCounts))
            ->all();
    }

    /** Case-insensitive name|code|type key for the within-upload check. */
    private function comboKey(array $row): string
    {
        return collect(['name', 'code', 'type'])
            ->map(fn ($field) => Str::lower(trim((string) ($row[$field] ?? ''))))
            ->join('|');
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{row:int,valid:bool,errors:array<string,string>}
     */
    private function validateRow(array $row, int $rowNumber, Collection $comboCounts): array
    {
        $errors = [];
        $name = trim((string) ($row['name'] ?? ''));
        $code = trim((string) ($row['code'] ?? ''));
        $type = trim((string) ($row['type'] ?? ''));

        if ($name === '') {
            $errors['name'] = 'Name is required.';
        } elseif (mb_strlen($name) > 255) {
            $errors['name'] = 'Name may not be longer than 255 characters.';
        }

        if ($type === '') {
            $errors['type'] = 'Type is required.';
        } elseif (mb_strlen($type) > 50) {
            $errors['type'] = 'Type may not be longer than 50 characters.';
        }

        if ($code === '') {
            $errors['code'] = 'Code is required.';
        } elseif (mb_strlen($code) > 50) {
            $errors['code'] = 'Code may not be longer than 50 characters.';
        }

        // Name + code + type together — only once each is individually
        // valid, flagged on all three like the single-course form.
        if (! array_intersect_key($errors, array_flip(['name', 'code', 'type']))) {
            $duplicate = match (true) {
                $comboCounts->get($this->comboKey($row), 0) > 1 => 'Same Name, Code and Type appear more than once in this upload.',
                Course::hasDuplicate($name, $code, $type) => Course::DUPLICATE_MESSAGE,
                default => null,
            };
            if ($duplicate) {
                $errors['name'] = $errors['code'] = $errors['type'] = $duplicate;
            }
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
            'type' => trim((string) ($row['type'] ?? '')),
            'status' => true,
        ]))->all();
    }
}
