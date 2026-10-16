<?php

namespace App\Services;

use App\Models\Program;
use App\Models\Student;
use Illuminate\Support\Str;

/**
 * Backs the "Bulk Upload" flow on the Students list — same shape as
 * CourseBulkUploadService/DepartmentBulkUploadService: a CSV is parsed
 * client-side into plain rows (Name, Registration No, Batch, Program), validated
 * here (same rules StoreStudentRequest enforces one-by-one, plus a
 * duplicate-registration-no-within-the-same-upload check a single-row request
 * could never hit), and — once every row is valid — created here too,
 * inside a single transaction.
 */
class StudentBulkUploadService
{
    /**
     * @param  list<array<string, mixed>>  $rows  each: {name, registration_no, batch, program_name}
     * @return list<array{row:int,valid:bool,errors:array<string,string>}>
     */
    public function validateRows(array $rows): array
    {
        $registrationNoCounts = collect($rows)->countBy(fn ($row) => Str::lower(trim((string) ($row['registration_no'] ?? ''))));

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
            ->map(fn ($row, $index) => $this->validateRow($row, $index + 1, $registrationNoCounts, $existingProgramNames))
            ->all();
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{row:int,valid:bool,errors:array<string,string>}
     */
    private function validateRow(array $row, int $rowNumber, $registrationNoCounts, $existingProgramNames): array
    {
        $errors = [];
        $name = trim((string) ($row['name'] ?? ''));
        $registrationNo = trim((string) ($row['registration_no'] ?? ''));
        $batch = trim((string) ($row['batch'] ?? ''));
        $programName = trim((string) ($row['program_name'] ?? ''));

        if ($name === '') {
            $errors['name'] = 'Name is required.';
        }

        if ($registrationNo === '') {
            $errors['registration_no'] = 'Registration number is required.';
        } elseif ($registrationNoCounts->get(Str::lower($registrationNo), 0) > 1) {
            $errors['registration_no'] = 'Duplicate registration number within this upload.';
        } elseif (Student::whereNull('deleted_at')->whereRaw('LOWER(registration_no) = ?', [Str::lower($registrationNo)])->exists()) {
            $errors['registration_no'] = 'This registration number is already in use.';
        }

        // Batch is optional (e.g. 2024).
        if (mb_strlen($batch) > 20) {
            $errors['batch'] = 'Batch may not be longer than 20 characters.';
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
            'registration_no' => trim((string) ($row['registration_no'] ?? '')),
            'batch' => trim((string) ($row['batch'] ?? '')) ?: null,
            'program_name' => trim((string) ($row['program_name'] ?? '')) ?: null,
            'status' => true,
        ]))->all();
    }
}
