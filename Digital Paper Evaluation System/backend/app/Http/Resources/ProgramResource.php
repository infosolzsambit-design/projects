<?php

namespace App\Http\Resources;

use App\Models\Program;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Program
 */
class ProgramResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'department' => $this->department,
            'department_id' => $this->department_id,
            'code' => $this->code,
            'status' => (bool) $this->status,
            // Always eager-loaded as courses:id,name,code (see
            // ProgramController) — kept to just those fields here too, so
            // this never shows a misleading null for a column that was
            // deliberately never selected.
            'courses' => $this->whenLoaded('courses', fn () => $this->courses->map(fn ($course) => [
                'id' => $course->id,
                'name' => $course->name,
                'code' => $course->code,
            ])),
            'created_by' => $this->created_by,
            'updated_by' => $this->updated_by,
            'deleted_by' => $this->when($this->trashed(), $this->deleted_by),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'deleted_at' => $this->when($this->trashed(), $this->deleted_at),
        ];
    }
}
