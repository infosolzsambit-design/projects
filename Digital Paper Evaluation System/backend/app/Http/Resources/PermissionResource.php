<?php

namespace App\Http\Resources;

use App\Models\Permission;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Permission
 */
class PermissionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'guard_name' => $this->guard_name,
            'permission_group_id' => $this->permission_group_id,
            'permission_sub_group_id' => $this->permission_sub_group_id,
            'permission_group' => $this->whenLoaded('group', fn () => $this->group ? ['id' => $this->group->id, 'name' => $this->group->name, 'sort_order' => $this->group->sort_order] : null),
            'permission_sub_group' => $this->whenLoaded('subGroup', fn () => $this->subGroup ? ['id' => $this->subGroup->id, 'name' => $this->subGroup->name, 'sort_order' => $this->subGroup->sort_order] : null),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'deleted_at' => $this->when($this->trashed(), $this->deleted_at),
        ];
    }
}
