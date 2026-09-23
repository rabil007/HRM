<?php

namespace App\Http\Requests\Organization\Recruitment;

use Illuminate\Foundation\Http\FormRequest;

class ChangeHeadcountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('recruitment.requirements.update') ?? false;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('requirement_line_id') && $this->has('new_headcount') && ! $this->has('lines')) {
            $this->merge([
                'lines' => [
                    [
                        'id' => (int) $this->input('requirement_line_id'),
                        'required_headcount' => (int) $this->input('new_headcount'),
                    ],
                ],
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.id' => ['required', 'integer'],
            'lines.*.required_headcount' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ];
    }
}
