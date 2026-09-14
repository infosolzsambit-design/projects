<?php

namespace App\Services;

use App\Models\Department;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Backs the "Bulk Upload" flow on the Departments list — same shape as
 * TeacherBulkUploadService: a CSV is parsed client-side into plain rows
 * (Name, Code), validated here (same rules StoreDepartmentRequest enforces
 * one-by-one, plus a duplicate-name-within-the-same-upload check a single
 * row request could never hit), and — once every row is valid — created
 * here too, inside a single transaction.
 */
class DepartmentBulkUploadService
{
    /**
     * @param  list<array<string, mixed>>  $rows  each: {name, code}
     * @return list<array{row:int,valid:bool,errors:array<string,string>}>
     */
    public function validateRows(array $rows): array
    {
        $nameCounts = collect($rows)->countBy(fn ($row) => Str::lower(trim((string) ($row['name'] ?? ''))));

        return collect($rows)
            ->values()
            ->map(fn ($row, $index) => $this->validateRow($row, $index + 1, $nameCounts))
            ->all();
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{row:int,valid:bool,errors:array<string,string>}
     */
    private function validateRow(array $row, int $rowNumber, Collection $nameCounts): array
    {
        $errors = [];
        $name = trim((string) ($row['name'] ?? ''));

        if ($name === '') {
            $errors['name'] = 'Name is required.';
        } elseif ($nameCounts->get(Str::lower($name), 0) > 1) {
            $errors['name'] = 'Duplicate name within this upload.';
        } elseif (Department::whereNull('deleted_at')->whereRaw('LOWER(name) = ?', [Str::lower($name)])->exists()) {
            $errors['name'] = 'This department name is already in use.';
        }

        // Code is optional and never unique — same as StoreDepartmentRequest.

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
     * @return list<Department>
     */
    public function createRows(array $rows): array
    {
        return collect($rows)->values()->map(fn ($row) => Department::create([
            'name' => trim((string) ($row['name'] ?? '')),
            'code' => trim((string) ($row['code'] ?? '')) ?: null,
            'status' => true,
        ]))->all();
    }
}
