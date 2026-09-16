<?php

namespace App\Http\Requests\Organization\Position;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;

class UpdatePositionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('positions.update');
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $companyId = (int) $this->attributes->get('current_company_id');

        return [
            'department_id' => [
                'nullable',
                'integer',
                Rule::exists('departments', 'id')
                    ->where('company_id', $companyId)
                    ->whereNull('deleted_at'),
            ],
            'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:5000'],
            'grade' => ['nullable', 'string', 'max:50'],
            'min_salary' => ['nullable', 'numeric', 'min:0'],
            'max_salary' => ['nullable', 'numeric', 'min:0'],
            'status' => ['nullable', 'in:active,inactive'],
            'attachment' => [
                'nullable',
                File::types(['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png', 'webp'])->max('10mb'),
                'extensions:pdf,doc,docx,jpg,jpeg,png,webp',
            ],
            'remove_attachment' => ['nullable', 'boolean'],
        ];
    }
}
