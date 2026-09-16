<?php

namespace App\Http\Requests\Settings\MasterData;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRoomTypeRequest extends FormRequest
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
        $companyId = (int) $this->attributes->get('current_company_id');
        $roomTypeId = (int) $this->route('room_type')?->id;

        return [
            'name' => [
                'required',
                'string',
                'max:120',
                Rule::unique('room_types', 'name')
                    ->where(fn ($query) => $query->where('company_id', $companyId))
                    ->whereNull('deleted_at')
                    ->ignore($roomTypeId),
            ],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
