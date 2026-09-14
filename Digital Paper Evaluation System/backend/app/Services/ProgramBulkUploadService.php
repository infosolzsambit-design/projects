<?php

namespace App\Services;

use App\Models\Department;
use App\Models\Program;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Backs the "Bulk Upload" flow on the Programs list — same shape as
 * CourseBulkUploadService/DepartmentBulkUploadService/TeacherBulkUploadService:
 * a CSV is parsed client-side into plain rows (Name, Department, Code — no
 * courses; those are mapped afterward from each program's own edit page,
 * same as Program's course_ids being optional at creation time), validated
 * here (same rules StoreProgramRequest enforces one-by-one, plus a
 * duplicate-code-within-the-same-upload check a single-row request could
 * never hit), and — once every row is valid — created here too, inside a
 * single transaction.
 */
class ProgramBulkUploadService
{
    /**
     * @param  list<array<string, mixed>>  $rows  each: {name, department_id, code}
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
        $departmentId = $row['department_id'] ?? null;
        $code = trim((string) ($row['code'] ?? ''));

        if ($name === '') {
            $errors['name'] = 'Name is required.';
        }

        if (empty($departmentId)) {
            $errors['department_id'] = 'Select a department.';
        } elseif (! Department::whereNull('deleted_at')->whereKey($departmentId)->exists()) {
            $errors['department_id'] = 'Select a valid department from the list.';
        }

        if ($code === '') {
            $errors['code'] = 'Code is required.';
        } elseif ($codeCounts->get(Str::lower($code), 0) > 1) {
            $errors['code'] = 'Duplicate code within this upload.';
        } elseif (Program::whereNull('deleted_at')->whereRaw('LOWER(code) = ?', [Str::lower($code)])->exists()) {
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
                'department_id' => $department->id,
                'department' => $department->name,
                'code' => trim((string) ($row['code'] ?? '')),
                'status' => true,
            ]);
        })->all();
    }
}
