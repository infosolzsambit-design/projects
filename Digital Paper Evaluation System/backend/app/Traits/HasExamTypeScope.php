<?php

namespace App\Traits;

use App\Models\ExamType;
use Illuminate\Http\Request;

/**
 * Exam-type counterpart to HasExamYearScope (see that trait's own
 * docblock for the full reasoning — this mirrors it exactly) — applied to
 * the same set of controllers that one already is (My Pending Course, My
 * Completed Course, Assigned Teacher List, Admin Dashboard — see
 * AppHeader.vue's "Examination" picker / stores/examType.js).
 *
 * A super admin (role id in config('roles.super_admin_id') — by id, never
 * by name, matching AuthController::withEffectivePermissions()'s own
 * is_super_admin flag) sees every exam type unless they deliberately ask
 * for one via ?exam_type_id=; anyone else is always pinned to a single
 * exam type — whichever one they asked for, or (if they didn't say) the
 * most recently added active one, the exact same fallback the header
 * picker itself defaults its own selection to (see examType.js's own
 * `types` getter) so a non-super-admin's implicit server-side scope always
 * matches what's showing as selected in their own header. Enforced here,
 * server-side, for the same reason HasExamYearScope is — the frontend
 * sending it is a convenience, not the actual gate.
 */
trait HasExamTypeScope
{
    /**
     * @return int|null null means "no exam-type filter" — only ever the
     *                  case for a super admin who didn't ask for one.
     */
    protected function examTypeScope(Request $request): ?int
    {
        if ($request->filled('exam_type_id')) {
            return $request->integer('exam_type_id');
        }

        // hasRole() accepts a list of ids and matches on any of them — no
        // (int) cast here, config('roles.super_admin_id') is itself a list.
        $isSuperAdmin = $request->user()?->hasRole(config('roles.super_admin_id')) ?? false;

        if ($isSuperAdmin) {
            return null;
        }

        return ExamType::where('status', true)->orderByDesc('id')->value('id');
    }
}
