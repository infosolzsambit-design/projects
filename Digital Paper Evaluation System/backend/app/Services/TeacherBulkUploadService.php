<?php

namespace App\Services;

use App\Models\Department;
use App\Models\TeacherDetail;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Backs the "Bulk Teacher Upload" flow (see TeacherBulkUploadController):
 * a CSV is parsed client-side into plain rows (Name, Email, Phone,
 * Department, Designation — the raw Department text is only ever used in
 * the browser to pre-select a real department in a dropdown, see
 * TeacherBulkUploadView.vue), validated here (same rules
 * TeacherController::store() enforces one-by-one, plus duplicate-within-
 * the-same-upload checks a single-row request could never hit), and — once
 * every row is valid — created here too, one User + TeacherDetail per row,
 * inside a single transaction.
 */
class TeacherBulkUploadService
{
    /**
     * @param  list<array<string, mixed>>  $rows  each: {name, email, phone_no, emp_code, department_id, designation}
     * @return list<array{row:int,valid:bool,errors:array<string,string>}>
     */
    public function validateRows(array $rows): array
    {
        $emailCounts = collect($rows)->countBy(fn ($row) => Str::lower(trim((string) ($row['email'] ?? ''))));
        $phoneCounts = collect($rows)->countBy(fn ($row) => trim((string) ($row['phone_no'] ?? '')));
        $empCodeCounts = collect($rows)->countBy(fn ($row) => Str::lower(trim((string) ($row['emp_code'] ?? ''))));

        return collect($rows)
            ->values()
            ->map(fn ($row, $index) => $this->validateRow($row, $index + 1, $emailCounts, $phoneCounts, $empCodeCounts))
            ->all();
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{row:int,valid:bool,errors:array<string,string>}
     */
    private function validateRow(array $row, int $rowNumber, Collection $emailCounts, Collection $phoneCounts, Collection $empCodeCounts): array
    {
        $errors = [];

        $name = trim((string) ($row['name'] ?? ''));
        $email = trim((string) ($row['email'] ?? ''));
        $phone = trim((string) ($row['phone_no'] ?? ''));
        $empCode = trim((string) ($row['emp_code'] ?? ''));
        $departmentId = $row['department_id'] ?? null;
        $designation = trim((string) ($row['designation'] ?? ''));

        if ($name === '') {
            $errors['name'] = 'Name is required.';
        }

        if ($email === '') {
            $errors['email'] = 'Email is required.';
        } elseif (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Enter a valid email address.';
        } elseif ($emailCounts->get(Str::lower($email), 0) > 1) {
            $errors['email'] = 'Duplicate email within this upload.';
        } elseif (User::where('email', $email)->exists()) {
            $errors['email'] = 'This email is already in use.';
        }

        if ($phone === '') {
            $errors['phone_no'] = 'Phone number is required.';
        } elseif (! preg_match('/^\d{10}$/', $phone)) {
            $errors['phone_no'] = 'Phone number must be exactly 10 digits, no other characters.';
        } elseif ($phoneCounts->get($phone, 0) > 1) {
            $errors['phone_no'] = 'Duplicate phone number within this upload.';
        } elseif (User::where('phone_no', $phone)->exists()) {
            $errors['phone_no'] = 'This phone number is already in use.';
        }

        if ($empCode === '') {
            $errors['emp_code'] = 'Employee code is required.';
        } elseif ($empCodeCounts->get(Str::lower($empCode), 0) > 1) {
            $errors['emp_code'] = 'Duplicate employee code within this upload.';
        } elseif (TeacherDetail::whereNull('deleted_at')->whereRaw('LOWER(emp_code) = ?', [Str::lower($empCode)])->exists()) {
            $errors['emp_code'] = 'This employee code is already in use.';
        }

        if (empty($departmentId)) {
            $errors['department_id'] = 'Select a department.';
        } elseif (! Department::whereNull('deleted_at')->whereKey($departmentId)->exists()) {
            $errors['department_id'] = 'Select a valid department from the list.';
        }

        if ($designation === '') {
            $errors['designation'] = 'Designation is required.';
        }

        return [
            'row' => $rowNumber,
            'valid' => $errors === [],
            'errors' => $errors,
        ];
    }

    /**
     * Creates one User (+ auto-assigned Teacher role) and one TeacherDetail
     * per row. Caller is responsible for confirming every row already
     * passed validateRows() and for wrapping this in a DB transaction.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<User>
     */
    public function createRows(array $rows): array
    {
        $teacherRoleId = (int) config('roles.teacher_id');
        $created = [];

        foreach (array_values($rows) as $row) {
            $name = trim((string) ($row['name'] ?? ''));
            $email = trim((string) ($row['email'] ?? ''));
            $department = Department::find($row['department_id']);

            $user = User::create([
                'name' => $name,
                'username' => $this->generateUsername($name, $email),
                'email' => $email,
                'phone_no' => trim((string) ($row['phone_no'] ?? '')),
                // Bulk-created teachers always start with no password — the
                // same as leaving it blank on the single Add Teacher form —
                // and use "Forgot password?" on their first login.
                'password' => null,
                'is_active' => true,
            ]);

            $user->assignRole($teacherRoleId);

            TeacherDetail::create([
                'user_id' => $user->id,
                'emp_code' => trim((string) ($row['emp_code'] ?? '')),
                'department_id' => $department->id,
                'department' => $department->name,
                'designation' => trim((string) ($row['designation'] ?? '')),
            ]);

            $created[] = $user;
        }

        return $created;
    }

    // Identical to TeacherController::generateUsername() — kept as its own
    // copy rather than a shared dependency between the two controllers,
    // since bulk-create's row shape isn't a StoreTeacherRequest.
    private function generateUsername(string $name, string $email): string
    {
        $base = Str::slug($name, '_');
        if ($base === '') {
            $base = Str::slug(Str::before($email, '@'), '_');
        }
        if ($base === '') {
            $base = 'teacher';
        }

        $username = $base;
        $suffix = 1;

        while (User::withTrashed()->where('username', $username)->exists()) {
            $suffix++;
            $username = "{$base}_{$suffix}";
        }

        return $username;
    }
}
