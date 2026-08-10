<?php

namespace App\Http\Resources;

use App\Models\Audit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Audit
 */
class AuditResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'event' => $this->event,
            'module' => $this->module,
            'description' => $this->description,
            'auditable_type' => $this->auditable_type,
            'auditable_id' => $this->auditable_id,
            'old_values' => $this->old_values,
            'new_values' => $this->new_values,
            'url' => $this->url,
            'route' => $this->route,
            'http_method' => $this->http_method,
            'ip_address' => $this->ip_address,
            'user_agent' => $this->user_agent,
            'tags' => $this->tags,
            'request_id' => $this->request_id,
            // Meaningful user details instead of a bare ID, when resolvable.
            'user' => $this->when($this->user_id && $this->relationLoaded('user') && $this->user, fn () => [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'username' => $this->user->username,
                'email' => $this->user->email,
            ]),
            'created_at' => $this->created_at,
        ];
    }
}
