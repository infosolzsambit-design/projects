<?php

namespace App\Services;

use App\Models\Department;
use App\Models\Program;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Backs the "Bulk Upload" flow on the Programs list — same shape as
 * CourseBulkUploadService/DepartmentBulkUploadService/TeacherBulkUploadService:
 * a CSV is parsed client-side into plain rows (Name, Label, Department, Code — no
 * courses; those are mapped afterward from each program's own edit page,
 * same as Program's course_ids being optional at creation time), validated
 * here (same rules StoreProgramRequest enforces one-by-one — including
 * name + code + label being unique together — plus the same combination
 * repeated within the upload itself, which a single-row request could
 * never hit), and — once every row is valid — created here too, inside a
 * single transaction.
 */
class ProgramBulkUploadService
{
    /**
     * @param  list<array<string, mixed>>  $rows  each: {name, label, department_id, code}
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

    /** Case-insensitive name|code|label key for the within-upload check. */
    private function comboKey(array $row): string
    {
        return collect(['name', 'code', 'label'])
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
        $label = trim((string) ($row['label'] ?? ''));
        $departmentId = $row['department_id'] ?? null;
        $code = trim((string) ($row['code'] ?? ''));

        if ($name === '') {
            $errors['name'] = 'Name is required.';
        } elseif (mb_strlen($name) > 255) {
            $errors['name'] = 'Name may not be longer than 255 characters.';
        }

        if ($label === '') {
            $errors['label'] = 'Label is required.';
        } elseif (mb_strlen($label) > 20) {
            $errors['label'] = 'Label may not be longer than 20 characters.';
        }

        if (empty($departmentId)) {
            $errors['department_id'] = 'Select a department.';
        } elseif (! Department::whereNull('deleted_at')->whereKey($departmentId)->exists()) {
            $errors['department_id'] = 'Select a valid department from the list.';
        }

        if ($code === '') {
            $errors['code'] = 'Code is required.';
        } elseif (mb_strlen($code) > 50) {
            $errors['code'] = 'Code may not be longer than 50 characters.';
        }

        // Name + code + label together — only once each is individually
        // valid, flagged on all three like the single-program form.
        if (! array_intersect_key($errors, array_flip(['name', 'code', 'label']))) {
            $duplicate = match (true) {
                $comboCounts->get($this->comboKey($row), 0) > 1 => 'Same Name, Code and Label appear more than once in this upload.',
                Program::hasDuplicate($name, $code, $label) => Program::DUPLICATE_MESSAGE,
                default => null,
            };
            if ($duplicate) {
                $errors['name'] = $errors['code'] = $errors['label'] = $duplicate;
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
     * validateRows() and for wrapping this in a DB transaction. Courses
     * are intentionally left unmapped — added afterward from each
     * program's own edit page.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<Program>
     */
    public function createRows(array $rows): array
    {
        return collect($rows)->values()->map(function ($row) {
            $department = Department::find($row['department_id']);

            return Program::create([
                'name' => trim((string) ($row['name'] ?? '')),
                'label' => trim((string) ($row['label'] ?? '')),
                'department_id' => $department->id,
                'department' => $department->name,
                'code' => trim((string) ($row['code'] ?? '')),
                'status' => true,
            ]);
        })->all();
    }
}
