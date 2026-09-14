<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// Admin-side counterpart to ProfileController::updateEsign() — lets an
// admin upload/replace a *specific teacher's* e-signature from the
// Teachers list/edit form (see TeacherFormView.vue), the same way
// TeacherFaceController handles that teacher's face scan versus
// ProfileController's own self-service face endpoints. No permission
// gating yet, same as TeacherController.
class TeacherEsignController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly AuditLogService $auditLog) {}

    /**
     * Returns the stored signature for this teacher — fetched only when an
     * admin actually opens the edit form (see TeacherResource's has_esign
     * for the cheap per-row flag the list itself would show).
     */
    public function show(User $teacher): JsonResponse
    {
        if (! $teacher->teacherDetail) {
            return $this->notFound('No teacher found.');
        }

        return $this->success([
            'esign' => $teacher->teacherDetail->esign,
        ], 'E-signature retrieved successfully.');
    }

    /**
     * Saves a freshly cropped signature image for this teacher — same
     * shape as ProfileController::updateEsign(), just targeting whichever
     * teacher the admin is editing instead of $request->user() themself.
     */
    public function update(Request $request, User $teacher): JsonResponse
    {
        if (! $teacher->teacherDetail) {
            return $this->notFound('No teacher found.');
        }

        $data = $request->validate([
            'esign' => ['required', 'string', 'max:8000000'],
        ]);

        $wasAlreadyRegistered = ! empty($teacher->teacherDetail->esign);

        $teacher->teacherDetail->update(['esign' => $data['esign']]);

        $this->auditLog->log(
            event: $wasAlreadyRegistered ? 'esign-updated' : 'esign-registered',
            module: 'Teacher Management',
            description: ($wasAlreadyRegistered ? 'E-signature updated' : 'E-signature uploaded').' for teacher from the Teachers list.',
            auditable: $teacher,
        );

        return $this->success([
            'has_esign' => true,
            'esign' => $data['esign'],
        ], 'E-signature saved successfully.');
    }
}
