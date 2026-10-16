<?php

namespace App\Http\Requests\Organization;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCrewAccommodationStayRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('crew_operations.corrections.override');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'hotel_id' => ['required', 'integer'],
            'room_type_id' => ['nullable', 'integer'],
            'check_in_date' => ['required', 'date'],
            'check_out_date' => ['nullable', 'date'],
            'reason' => ['required', 'string', 'max:5000'],
            'expected_edit_version' => ['required', 'integer', 'min:0'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'hotel_id.required' => 'Hotel is required.',
            'check_in_date.required' => 'Check-in date is required.',
            'reason.required' => 'A reason for the edit is required.',
            'expected_edit_version.required' => 'This accommodation record version is missing. Refresh and try again.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('reason')) {
            $this->merge([
                'reason' => trim((string) $this->input('reason')),
            ]);
        }

        if ($this->has('room_type_id') && $this->input('room_type_id') === '') {
            $this->merge(['room_type_id' => null]);
        }

        if ($this->has('check_out_date') && $this->input('check_out_date') === '') {
            $this->merge(['check_out_date' => null]);
        }
    }
}
