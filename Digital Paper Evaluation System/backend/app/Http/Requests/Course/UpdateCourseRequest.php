<?php

namespace App\Http\Requests\Course;

use App\Models\Course;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateCourseRequest extends FormRequest
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
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            // Unique only together with name + type — see after().
            'code' => ['sometimes', 'required', 'string', 'max:50'],
            'type' => ['sometimes', 'required', 'string', 'max:50'],
            'status' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Same combined name + code + type check as StoreCourseRequest, using
     * the course's current value for any of the three not sent, and
     * ignoring the course itself.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $course = $this->route('course');
                if (! $course instanceof Course || ! $this->hasAny(['name', 'code', 'type'])
                    || $validator->errors()->hasAny(['name', 'code', 'type'])) {
                    return;
                }
                $name = (string) $this->input('name', $course->name);
                $code = (string) $this->input('code', $course->code);
                $type = $this->has('type') ? (string) $this->input('type') : $course->type;

                if (Course::hasDuplicate($name, $code, $type, $course->id)) {
                    foreach (['name', 'code', 'type'] as $field) {
                        $validator->errors()->add($field, Course::DUPLICATE_MESSAGE);
                    }
                }
            },
        ];
    }
}
