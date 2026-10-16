<?php

namespace App\Http\Resources;

use App\Models\ExamSubType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ExamSubType
 */
class ExamSubTypeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'inserted_id' => $this->inserted_id,
            'name' => $this->name,
            'exam_type_id' => $this->exam_type_id,
            'exam_type' => $this->examType?->name,
            'exam_term_id' => $this->exam_term_id,
            'exam_term' => $this->examTerm?->name,
            'deleted_from_main_db' => (bool) $this->deleted_from_main_db,
            'status' => (bool) $this->status,
            'created_by' => $this->created_by,
            'updated_by' => $this->updated_by,
            'deleted_by' => $this->when($this->trashed(), $this->deleted_by),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'deleted_at' => $this->when($this->trashed(), $this->deleted_at),
        ];
    }
}
