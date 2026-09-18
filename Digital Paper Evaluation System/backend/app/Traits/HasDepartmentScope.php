<?php

namespace App\Traits;

use Illuminate\Http\Request;

/**
 * Shared department-scoping rule for the two pages that need it — Assign
 * Teacher's own teacher picker and Assigned Teacher List (see
 * AssignTeacherView.vue / AssignedTeachersView.vue, both of which send
 * ?department_scope=self on their GET /teachers calls). Deliberately not
 * applied to the plain Teachers master list (/teachers without that param)
 * — that stays a full directory for anyone who can already see it.
 *
 * A super admin (role id in config('roles.super_admin_id') — by id, never
 * by name) sees every department; anyone else only ever sees teachers in
 * their OWN department, resolved from their own teacher_details.
 * department_id — a department head is expected to have been created
 * through the Teacher module too (giving them that row), even if their
 * current role has since changed to something else. Enforced server-side
 * regardless of what the frontend sends, same reasoning as
 * HasExamYearScope.
 */
trait HasDepartmentScope
{
    /**
     * @return int|false|null int = restrict to exactly this department id;
     *                        false = a non-super-admin whose own account
     *                        has no department set — matches nothing (the
     *                        safe default; never silently "show everyone");
     *                        null = super admin, no restriction at all.
     */
    protected function departmentScope(Request $request): int|false|null
    {
        $isSuperAdmin = $request->user()?->hasRole(config('roles.super_admin_id')) ?? false;

        if ($isSuperAdmin) {
            return null;
        }

        $departmentId = $request->user()?->teacherDetail?->department_id;

        return $departmentId !== null ? (int) $departmentId : false;
    }
}
