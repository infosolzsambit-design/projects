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
            'department' => $this->teacherDetail?->department,
            'department_id' => $this->teacherDetail?->department_id,
            'designation' => $this->teacherDetail?->designation,
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
            'created_by' => $this->created_by,
            'updated_by' => $this->updated_by,
            'deleted_by' => $this->when($this->trashed(), $this->deleted_by),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'deleted_at' => $this->when($this->trashed(), $this->deleted_at),
        ];
    }
}
