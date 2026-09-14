<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// Admin-side counterpart to ProfileController's face endpoints — this lets
// an admin capture/view/retake a *specific teacher's* face scan from the
// Teachers list (see TeachersView.vue), the same way ProfileController
// handles the logged-in user's own. No permission gating yet, same as
// TeacherController.
class TeacherFaceController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly AuditLogService $auditLog) {}

    /**
     * Returns the stored photo for this teacher — fetched only when an
     * admin actually opens it (see TeacherResource's has_face_profile for
     * the cheap per-row status the list itself shows).
     */
    public function show(User $teacher): JsonResponse
    {
        if (! $teacher->teacherDetail) {
            return $this->notFound('No teacher found.');
        }

        if (empty($teacher->teacherDetail->face_descriptor)) {
            return $this->error('No face scan is registered for this teacher yet.', 422);
        }

        return $this->success([
            'photo' => $teacher->teacherDetail->photo,
            'has_face_profile' => true,
        ], 'Face scan retrieved successfully.');
    }

    /**
     * Saves a freshly-captured photo + its face-api.js descriptor for this
     * teacher — same shape as ProfileController::updateFace(), just
     * targeting whichever teacher the admin is viewing instead of
     * $request->user() themself.
     */
    public function update(Request $request, User $teacher): JsonResponse
    {
        if (! $teacher->teacherDetail) {
            return $this->notFound('No teacher found.');
        }

        $data = $request->validate([
            'photo' => ['required', 'string', 'max:8000000'],
            'descriptor' => ['required', 'array', 'size:128'],
            'descriptor.*' => ['numeric'],
        ]);

        $wasAlreadyRegistered = ! empty($teacher->teacherDetail->face_descriptor);

        $teacher->teacherDetail->update([
            'photo' => $data['photo'],
            'face_descriptor' => $data['descriptor'],
        ]);

        $this->auditLog->log(
            event: $wasAlreadyRegistered ? 'face-profile-retaken' : 'face-profile-registered',
            module: 'Teacher Management',
            description: ($wasAlreadyRegistered ? 'Face scan retaken' : 'Face scan registered').' for teacher from the Teachers list.',
            auditable: $teacher,
        );

        return $this->success([
            'has_face_profile' => true,
        ], 'Face scan saved successfully.');
    }
}
