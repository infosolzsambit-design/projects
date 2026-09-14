<?php

namespace App\Http\Controllers\API\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\UpdateProfileRequest;
use App\Http\Resources\ProfileResource;
use App\Services\AuditLogService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// The logged-in user's own profile — separate from UserController (which
// is the admin-managed "manage any user" surface, permission-gated).
// Nothing here ever touches another user's row; every action operates on
// $request->user() only. gender/location/about/photo/face_descriptor all
// live on teacher_details (see ProfileResource) — a plain (e.g. Super
// Admin) user with no teacherDetail simply doesn't have those to save.
class ProfileController extends Controller
{
    use ApiResponse;

    // face-api.js's own recommended threshold for its 128-d descriptors —
    // kept identical to the frontend's MATCH_THRESHOLD in utils/face.js so
    // a "match" means the same thing whichever side computes it.
    private const FACE_MATCH_THRESHOLD = 0.6;

    public function __construct(private readonly AuditLogService $auditLog) {}

    public function show(Request $request): JsonResponse
    {
        return $this->success(
            new ProfileResource($request->user()->load(['roles', 'teacherDetail'])),
            'Profile retrieved successfully.',
        );
    }

    public function update(UpdateProfileRequest $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validated();

        // Spans two tables (users + teacher_details) — must not save one
        // half and leave the other on stale data if the second write fails.
        DB::transaction(function () use ($user, $data) {
            if (array_key_exists('name', $data)) {
                $user->update(['name' => $data['name']]);
            }

            $detailData = array_intersect_key($data, array_flip(['gender', 'location', 'about']));
            if ($detailData !== [] && $user->teacherDetail) {
                $user->teacherDetail->update($detailData);
            }
        });

        return $this->success(
            new ProfileResource($user->fresh()->load(['roles', 'teacherDetail'])),
            'Profile updated successfully.',
        );
    }

    /**
     * Saves a freshly-captured profile photo + its face-api.js descriptor —
     * called once after a successful scan on the Profile page. This is what
     * "registers" a face; verifyFace() below is what checks against it
     * later (e.g. before starting to check a paper).
     */
    public function updateFace(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->teacherDetail) {
            return $this->error('Face registration is only available for teacher accounts.', 422);
        }

        $data = $request->validate([
            'photo' => ['required', 'string', 'max:8000000'],
            'descriptor' => ['required', 'array', 'size:128'],
            'descriptor.*' => ['numeric'],
        ]);

        $user->teacherDetail->update([
            'photo' => $data['photo'],
            'face_descriptor' => $data['descriptor'],
        ]);

        $this->auditLog->log(
            event: 'face-profile-updated',
            module: 'Authentication',
            description: 'User captured/updated their profile face scan.',
            auditable: $user,
            causer: $user,
        );

        return $this->success(
            new ProfileResource($user->fresh()->load(['roles', 'teacherDetail'])),
            'Profile photo saved successfully.',
        );
    }

    /**
     * Saves an uploaded e-signature image — used to sign off on evaluated
     * answer sheets. Same base64-data-URL-as-a-plain-column approach as
     * updateFace()'s `photo` (no file-storage subsystem exists yet).
     */
    public function updateEsign(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->teacherDetail) {
            return $this->error('E-sign upload is only available for teacher accounts.', 422);
        }

        $data = $request->validate([
            'esign' => ['required', 'string', 'max:8000000'],
        ]);

        $user->teacherDetail->update(['esign' => $data['esign']]);

        $this->auditLog->log(
            event: 'esign-updated',
            module: 'Authentication',
            description: 'User uploaded/updated their e-signature.',
            auditable: $user,
            causer: $user,
        );

        return $this->success(
            new ProfileResource($user->fresh()->load(['roles', 'teacherDetail'])),
            'E-sign saved successfully.',
        );
    }

    /**
     * Compares a freshly-scanned descriptor against the one already stored
     * for this user — entirely server-side, so the stored descriptor
     * itself is never sent back down to the browser (see ProfileResource).
     */
    public function verifyFace(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->teacherDetail || empty($user->teacherDetail->face_descriptor)) {
            return $this->error('No face profile is registered for this account yet.', 422);
        }

        $data = $request->validate([
            'descriptor' => ['required', 'array', 'size:128'],
            'descriptor.*' => ['numeric'],
        ]);

        $distance = $this->euclideanDistance($data['descriptor'], $user->teacherDetail->face_descriptor);
        $matched = $distance <= self::FACE_MATCH_THRESHOLD;

        return $this->success([
            'matched' => $matched,
            'distance' => round($distance, 4),
        ], $matched ? 'Face matched.' : 'Face did not match.');
    }

    /**
     * @param  list<float>  $a
     * @param  list<float>  $b
     */
    private function euclideanDistance(array $a, array $b): float
    {
        $sum = 0.0;
        foreach ($a as $i => $value) {
            $diff = $value - ($b[$i] ?? 0);
            $sum += $diff * $diff;
        }

        return sqrt($sum);
    }
}
