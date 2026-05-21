<?php

namespace App\Http\Requests\Settings\MasterData;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCourseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $courseId = (int) $this->route('course')?->id;

        return [
            'name' => ['required', 'string', 'max:120', "unique:courses,name,{$courseId}"],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
