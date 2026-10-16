<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Wraps a User (with teacherDetail loaded) — a "teacher" isn't its own
 * table, it's a user with the Teacher role plus a teacher_details row.
 *
 * @mixin User
 */
class TeacherResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'username' => $this->username,
            'email' => $this->email,
            'phone_no' => $this->phone_no,
            'is_active' => (bool) $this->is_active,
            'emp_code' => $this->teacherDetail?->emp_code,
            // A teacher can be in several departments (teacher_department_mappings):
            // `department` is their names joined ("Commerce, Law") for display/search.
            'department' => $this->departmentNames(),
            'department_ids' => $this->departments->pluck('id')->all(),
            'departments' => $this->departments->map(fn ($d) => ['id' => $d->id, 'name' => $d->name])->values(),
            'designation' => $this->teacherDetail?->designation,
            // Set by "Sync Teacher" — the college main database's own id / delete flag.
            'inserted_id' => $this->teacherDetail?->inserted_id,
            'deleted_from_main_db' => (bool) ($this->teacherDetail?->deleted_from_main_db ?? false),
            // Cheap boolean, safe on every list row — the photo itself
            // isn't included here (would bloat the paginated list
            // response); fetched on demand via TeacherFaceController::show()
            // only when an admin actually opens it.
            'has_face_profile' => ! empty($this->teacherDetail?->face_descriptor),
            // Whether this teacher is even expected to have a face scan on
            // file — set via the Teachers list's "Face Scan Applicable" row
            // action, independent of has_face_profile above (whether one
            // has actually been captured yet).
            'face_scan_applicable' => (bool) ($this->teacherDetail?->face_scan_applicable ?? true),
            // Same reasoning — the signature image itself isn't included
            // here; fetched on demand via TeacherEsignController::show()
            // only when the edit form actually opens.
            'has_esign' => ! empty($this->teacherDetail?->esign),
            // Set via ->withCount('assignedAnswerSheets') on
            // TeacherController::index()'s query — the "Already Allocated"
            // number on AssignTeacherView.vue's teacher table. Falls back
            // to 0 (not null) when the count wasn't requested, so callers
            // never need a null-check.
            'allocated_answer_sheet_count' => $this->assigned_answer_sheets_count ?? 0,
            // Set via ->withCount()'s second alias on the same query — the
            // subset of the count above that already has marks recorded.
            // Only meaningful alongside allocated_answer_sheet_count (same
            // has_assignments=yes listing); falls back to 0 like it does.
            'completed_answer_sheet_count' => $this->assigned_answer_sheets_completed_count ?? 0,
            // Third alias on the same query — not evaluated and no open
            // problem (AnswerSheet::pendingSql()), same as the allocation
            // modal's Pending column.
            'pending_answer_sheet_count' => $this->assigned_answer_sheets_pending_count ?? 0,
            // Shared pools: of the allocated sheets, how many were taken from
            // a pool; and how many sheets still wait (unstarted) in pools
            // this teacher shares.
            'from_pool_answer_sheet_count' => (int) ($this->assigned_answer_sheets_from_pool_count ?? 0),
            'pool_waiting_answer_sheet_count' => (int) ($this->pool_waiting_count ?? 0),
            // Set via ->addSelect()'s correlated subquery on the same
            // query — the number of *distinct* courses those allocated
            // sheets span. AssignedTeachersView.vue's own "Courses" column;
            // clicking it opens TeacherCoursesModal.vue (GET
            // /teachers/{id}/courses), the plain course-only counterpart to
            // TeacherAllocationModal.vue's packet-level breakdown.
            'allocated_course_count' => $this->assigned_courses_count ?? 0,
            'created_by' => $this->created_by,
            'updated_by' => $this->updated_by,
            'deleted_by' => $this->when($this->trashed(), $this->deleted_by),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'deleted_at' => $this->when($this->trashed(), $this->deleted_at),
        ];
    }
}
