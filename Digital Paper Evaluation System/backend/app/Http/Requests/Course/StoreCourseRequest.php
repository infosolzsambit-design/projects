<?php

namespace App\Http\Requests\Course;

use App\Models\Course;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreCourseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            // Not unique on its own — name + code + type together must be
            // (see after() and Course::hasDuplicate()).
            'code' => ['required', 'string', 'max:50'],
            // e.g. T / P / Theory / Practical — free text.
            'type' => ['required', 'string', 'max:50'],
            'status' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Name + code + type must be unique together among non-deleted
     * courses. Flagged on all three fields so the form outlines each one.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->hasAny(['name', 'code', 'type'])) {
                    return;
                }
                if (Course::hasDuplicate((string) $this->input('name'), (string) $this->input('code'), (string) $this->input('type'))) {
                    foreach (['name', 'code', 'type'] as $field) {
                        $validator->errors()->add($field, Course::DUPLICATE_MESSAGE);
                    }
                }
            },
        ];
    }
}
