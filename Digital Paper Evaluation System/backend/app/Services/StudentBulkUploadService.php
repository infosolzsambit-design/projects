<?php

namespace App\Services;

use App\Models\Program;
use App\Models\Student;
use Illuminate\Support\Str;

/**
 * Backs the "Bulk Upload" flow on the Students list — same shape as
 * CourseBulkUploadService/DepartmentBulkUploadService: a CSV is parsed
 * client-side into plain rows (Name, Roll No, Semester, Program), validated
 * here (same rules StoreStudentRequest enforces one-by-one, plus a
 * duplicate-roll-no-within-the-same-upload check a single-row request
 * could never hit), and — once every row is valid — created here too,
 * inside a single transaction.
 */
class StudentBulkUploadService
{
    /**
     * @param  list<array<string, mixed>>  $rows  each: {name, roll_no, semester, program_name}
     * @return list<array{row:int,valid:bool,errors:array<string,string>}>
     */
    public function validateRows(array $rows): array
    {
        $rollNoCounts = collect($rows)->countBy(fn ($row) => Str::lower(trim((string) ($row['roll_no'] ?? ''))));

        // One query for every program name actually referenced, instead of
        // one Program::where(...)->exists() per row.
        $programNames = collect($rows)
            ->pluck('program_name')
            ->map(fn ($name) => Str::lower(trim((string) $name)))
            ->filter()
            ->unique();

        $existingProgramNames = Program::whereNull('deleted_at')
            ->get('name')
            ->pluck('name')
            ->map(fn ($name) => Str::lower($name))
            ->flip();

        return collect($rows)
            ->values()
            ->map(fn ($row, $index) => $this->validateRow($row, $index + 1, $rollNoCounts, $existingProgramNames))
            ->all();
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{row:int,valid:bool,errors:array<string,string>}
     */
    private function validateRow(array $row, int $rowNumber, $rollNoCounts, $existingProgramNames): array
    {
        $errors = [];
        $name = trim((string) ($row['name'] ?? ''));
        $rollNo = trim((string) ($row['roll_no'] ?? ''));
        $semester = trim((string) ($row['semester'] ?? ''));
        $programName = trim((string) ($row['program_name'] ?? ''));

        if ($name === '') {
            $errors['name'] = 'Name is required.';
        }

        if ($rollNo === '') {
            $errors['roll_no'] = 'Roll number is required.';
        } elseif ($rollNoCounts->get(Str::lower($rollNo), 0) > 1) {
            $errors['roll_no'] = 'Duplicate roll number within this upload.';
        } elseif (Student::whereNull('deleted_at')->whereRaw('LOWER(roll_no) = ?', [Str::lower($rollNo)])->exists()) {
            $errors['roll_no'] = 'This roll number is already in use.';
        }

        if ($semester === '') {
            $errors['semester'] = 'Semester is required.';
        } elseif (! ctype_digit($semester) || (int) $semester < 1) {
            $errors['semester'] = 'Semester must be a whole number of at least 1.';
        }

        // Program is optional (see StoreStudentRequest's own nullable
        // rule) — only checked against the programs table when a row
        // actually named one.
        if ($programName !== '' && ! $existingProgramNames->has(Str::lower($programName))) {
            $errors['program_name'] = 'No program with this exact name exists.';
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
     * @return list<Student>
     */
    public function createRows(array $rows): array
    {
        return collect($rows)->values()->map(fn ($row) => Student::create([
            'name' => trim((string) ($row['name'] ?? '')),
            'roll_no' => trim((string) ($row['roll_no'] ?? '')),
            'semester' => (int) trim((string) ($row['semester'] ?? '')),
            'program_name' => trim((string) ($row['program_name'] ?? '')) ?: null,
            'status' => true,
        ]))->all();
    }
}
