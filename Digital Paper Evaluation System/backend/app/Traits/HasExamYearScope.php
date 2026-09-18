<?php

namespace App\Traits;

use Illuminate\Http\Request;

/**
 * Shared exam-year scoping rule for the few tables that need it — Question
 * Papers, Assigned Teacher List, and My Pending Course (see AppHeader.vue's
 * Exam Year picker / stores/examYear.js). Deliberately not applied anywhere
 * else in the app (master data, teachers, students, configuration) — those
 * pages aren't exam-year-scoped at all.
 *
 * A super admin (role id in config('roles.super_admin_id') — by id, never
 * by name, matching AuthController::withEffectivePermissions()'s own
 * is_super_admin flag) sees every exam year unless they deliberately ask
 * for one via ?exam_year=; anyone else is always pinned to a single year —
 * whichever one they asked for, or the current calendar year if they
 * didn't say. This is enforced here, server-side, precisely so a non-
 * super-admin can't see other years' data just by omitting the query
 * param — the frontend sending it is a convenience, not the actual gate.
 */
trait HasExamYearScope
{
    /**
     * @return int|null null means "no exam-year filter" — only ever the
     *                  case for a super admin who didn't ask for one.
     */
    protected function examYearScope(Request $request): ?int
    {
        if ($request->filled('exam_year')) {
            return $request->integer('exam_year');
        }

        // hasRole() accepts a list of ids and matches on any of them — no
        // (int) cast here, config('roles.super_admin_id') is itself a list.
        $isSuperAdmin = $request->user()?->hasRole(config('roles.super_admin_id')) ?? false;

        return $isSuperAdmin ? null : (int) date('Y');
    }
}
