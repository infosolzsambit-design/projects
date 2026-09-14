<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The logged-in user's own extended profile — deliberately separate from
 * UserResource (used for the admin Users list) so `photo` (a base64 data
 * URL, potentially fairly large) is never sent back on every row of that
 * list, only for the one profile a person is actually looking at.
 * `face_descriptor` itself is never exposed here at all — see
 * ProfileController::verifyFace() for why.
 *
 * gender/location/about/photo/esign/emp_code/designation/department all
 * live on teacher_details, not users (see the migration) — a plain (e.g.
 * Super Admin) user with no teacherDetail simply has none of these.
 *
 * @mixin User
 */
class ProfileResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $detail = $this->teacherDetail;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'username' => $this->username,
            'email' => $this->email,
            'phone_no' => $this->phone_no,
            'is_active' => (bool) $this->is_active,
            // First role is enough here — this app assigns exactly one
            // role per user in practice (see the role-by-numeric-id
            // convention elsewhere), just displayed, never editable.
            'role' => $this->whenLoaded('roles', fn () => $this->roles->first()?->name),
            'gender' => $detail?->gender,
            'location' => $detail?->location,
            'about' => $detail?->about,
            'photo' => $detail?->photo,
            'has_face_profile' => ! empty($detail?->face_descriptor),
            'esign' => $detail?->esign,
            'emp_code' => $detail?->emp_code,
            'designation' => $detail?->designation,
            'department' => $detail?->department,
            'created_at' => $this->created_at,
        ];
    }
}
