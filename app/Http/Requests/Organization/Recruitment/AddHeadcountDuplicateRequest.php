<?php

namespace App\Http\Requests\Organization\Recruitment;

use Illuminate\Foundation\Http\FormRequest;

class AddHeadcountDuplicateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('recruitment.requirements.update') ?? false;
    }

    protected function prepareForValidation(): void
    {
        $inputLines = $this->input('lines', $this->input('positions', []));
        if (is_array($inputLines)) {
            $normalized = [];
            foreach ($inputLines as $item) {
                $normalized[] = [
                    'position_id' => $item['position_id'] ?? null,
                    'additional_headcount' => $item['additional_headcount'] ?? $item['added_headcount'] ?? null,
                    'line_notes' => $item['line_notes'] ?? null,
                ];
            }
            $this->merge(['lines' => $normalized]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.position_id' => ['required', 'integer'],
            'lines.*.additional_headcount' => ['required', 'integer', 'min:1'],
            'lines.*.line_notes' => ['nullable', 'string', 'max:1000'],
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ];
    }
}
