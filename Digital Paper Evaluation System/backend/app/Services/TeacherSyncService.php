<?php

namespace App\Services;

use App\Models\Department;
use App\Models\Role;
use App\Models\TeacherDetail;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * "Sync Teacher" — brings teachers (users + teacher_details + their
 * departments) in line with the college's main database, matched by
 * teacher_details.inserted_id (the main database's own id). Same rules as
 * MainDbSyncService, adapted to a teacher being a user account:
 *  - a new inserted_id → a new teacher (Teacher role, no password — they
 *    use "Forgot password?", same as the Add Teacher form without one; no
 *    email is sent). A teacher already here (added by hand) with the same
 *    email is linked instead of a second account being made;
 *  - an existing one → name, email, phone, emp code, designation and
 *    departments updated (and restored if an earlier sync removed it);
 *  - a synced teacher no longer sent at all → soft-deleted (can't sign in);
 *  - teachers added by hand and never linked are never touched.
 * API fields: roll_name (one or several, "Assistant Professor, HOD") →
 * designation (roll_id isn't used); department_id is a comma list of the
 * main database's department ids → our departments by their inserted_id
 * (sync Departments first); an empty one leaves a teacher's departments as
 * they are. Email is the login, so it must be unique: a row whose email
 * already belongs to another account is skipped. Phone numbers needn't be
 * unique and are stored as sent. Deleted-in-main-database teachers
 * (delete_status 0) are flagged and blocked from signing in (inactive) —
 * switched back on if un-deleted there; an admin's own activate/deactivate
 * otherwise stands.
 */
class TeacherSyncService
{
    public function __construct(private MainDbSyncService $syncer) {}

    /**
     * @param  list<array<string, mixed>>  $remote  the API's rows
     * @return array{fetched: int, created: int, updated: int, restored: int, unchanged: int, deleted: int, skipped: int}
     */
    public function sync(array $remote): array
    {
        $departmentIds = Department::withTrashed()->whereNotNull('inserted_id')->pluck('id', 'inserted_id');
        $teacherRole = Role::where('name', 'Teacher')->first();

        $summary = DB::transaction(function () use ($remote, $departmentIds, $teacherRole) {
            $counts = ['created' => 0, 'updated' => 0, 'restored' => 0, 'unchanged' => 0, 'deleted' => 0, 'skipped' => 0];
            $existing = TeacherDetail::withTrashed()->whereNotNull('inserted_id')->get()->keyBy('inserted_id');
            $seenIds = [];

            foreach ($remote as $row) {
                $insertedId = $row['inserted_id'] ?? null;
                $values = is_numeric($insertedId) ? $this->values($row, $departmentIds) : null;
                if ($values === null) {
                    $counts['skipped']++;
                    if (is_numeric($insertedId)) {
                        $seenIds[] = (int) $insertedId; // bad data — left as it is, not removed
                    }

                    continue;
                }
                $insertedId = (int) $insertedId;
                $seenIds[] = $insertedId;

                $detail = $existing->get($insertedId) ?? $this->linkByEmail($values['email'], $insertedId);
                if ($detail === false) {
                    $counts['skipped']++; // email already used by another account

                    continue;
                }
                if ($detail === null) {
                    $this->create($insertedId, $values, $teacherRole);
                    $counts['created']++;

                    continue;
                }

                $result = $this->update($detail, $values);
                if ($result === 'skipped') {
                    $counts['skipped']++;
                } else {
                    $counts[$result]++;
                }
            }

            // Synced teachers the main database no longer sends at all.
            TeacherDetail::whereNotNull('inserted_id')->whereNotIn('inserted_id', $seenIds)->with('user')->get()
                ->each(function (TeacherDetail $detail) use (&$counts) {
                    $detail->delete();
                    $detail->user?->delete();
                    $counts['deleted']++;
                });

            return $counts;
        });

        $skipped = $summary['skipped'];
        unset($summary['skipped']);
        $summary = ['fetched' => count($remote)] + $summary + ['skipped' => $skipped];
        $this->syncer->record('teachers', $summary);

        return $summary;
    }

    /** API row → what we store, or null if it can't be used (no valid email). */
    private function values(array $row, $departmentIds): ?array
    {
        $email = trim((string) ($row['email'] ?? ''));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }
        $name = trim(preg_replace('/\s+/', ' ', (string) ($row['name'] ?? '')));
        $phone = trim((string) ($row['phone_no'] ?? ''));
        $empCode = trim((string) ($row['emp_code'] ?? ''));
        $designation = trim(preg_replace('/\s+/', ' ', (string) ($row['roll_name'] ?? '')));
        $remoteDepartments = (string) ($row['department_id'] ?? '') !== '' ? $row['department_id'] : ($row['dept_id'] ?? '');
        $remoteDepartments = array_filter(array_map('trim', explode(',', (string) $remoteDepartments)), 'is_numeric');

        return [
            'name' => Str::limit($name !== '' ? $name : Str::before($email, '@'), 255, ''),
            'email' => Str::limit($email, 255, ''),
            'phone_no' => $phone === '' ? null : Str::limit($phone, 20, ''),
            'emp_code' => $empCode === '' ? null : Str::limit($empCode, 255, ''),
            'designation' => $designation === '' ? null : Str::limit($designation, 255, ''),
            'deleted_from_main_db' => MainDbSyncService::isDeletedInMainDb($row),
            // null = the API sent no departments → leave ours as they are
            'department_ids' => $remoteDepartments === [] ? null
                : collect($remoteDepartments)->map(fn ($id) => $departmentIds->get((int) $id))->filter()->unique()->values()->all(),
        ];
    }

    /**
     * No synced teacher with this inserted_id yet: a teacher added here by
     * hand with the same email gets linked (returns its detail); false if
     * the email belongs to some other account (an admin, or another synced
     * teacher); null if it's free — a new teacher is created.
     */
    private function linkByEmail(string $email, int $insertedId): TeacherDetail|false|null
    {
        $user = User::where('email', $email)->with('teacherDetail')->first();
        if (! $user) {
            return null;
        }
        $detail = $user->teacherDetail;
        if (! $detail || $detail->inserted_id !== null) {
            return false;
        }
        $detail->update(['inserted_id' => $insertedId]);

        return $detail;
    }

    private function emailTaken(string $email, ?int $exceptUserId = null): bool
    {
        return User::where('email', $email)->when($exceptUserId, fn ($q) => $q->whereKeyNot($exceptUserId))->exists();
    }

    private function create(int $insertedId, array $values, ?Role $teacherRole): void
    {
        $user = User::create([
            'name' => $values['name'],
            'username' => TeacherBulkUploadService::generateUsername($values['name'], $values['email']),
            'email' => $values['email'],
            'phone_no' => $values['phone_no'],
            'password' => null,
            'is_active' => ! $values['deleted_from_main_db'],
        ]);
        if ($teacherRole) {
            $user->assignRole($teacherRole);
        }
        TeacherDetail::create([
            'inserted_id' => $insertedId,
            'user_id' => $user->id,
            'emp_code' => $values['emp_code'],
            'designation' => $values['designation'],
            'deleted_from_main_db' => $values['deleted_from_main_db'],
        ]);
        if ($values['department_ids']) {
            $user->departments()->sync($values['department_ids']);
        }
    }

    /** @return 'updated'|'restored'|'unchanged'|'skipped' */
    private function update(TeacherDetail $detail, array $values): string
    {
        $user = User::withTrashed()->find($detail->user_id);
        if (! $user) {
            return 'skipped';
        }
        // The new email belongs to another account — leave this teacher as is.
        if (strcasecmp($user->email, $values['email']) !== 0 && $this->emailTaken($values['email'], $user->id)) {
            return 'skipped';
        }

        $restored = false;
        if ($user->trashed() || $detail->trashed()) {
            $user->restore();
            $detail->restore();
            $restored = true;
        }

        $user->fill(['name' => $values['name'], 'email' => $values['email'], 'phone_no' => $values['phone_no']]);
        // Deleted / un-deleted in the main database → block / allow sign-in.
        if ((bool) $detail->deleted_from_main_db !== $values['deleted_from_main_db']) {
            $user->is_active = ! $values['deleted_from_main_db'];
        }
        $detail->fill(['emp_code' => $values['emp_code'], 'designation' => $values['designation'], 'deleted_from_main_db' => $values['deleted_from_main_db']]);

        $changed = $user->isDirty() || $detail->isDirty();
        $user->save();
        $detail->save();
        if ($values['department_ids'] !== null) {
            $changes = $user->departments()->sync($values['department_ids']);
            $changed = $changed || $changes['attached'] !== [] || $changes['detached'] !== [];
        }

        if ($restored) {
            return 'restored';
        }

        return $changed ? 'updated' : 'unchanged';
    }
}
