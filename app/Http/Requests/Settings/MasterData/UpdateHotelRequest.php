<?php

namespace App\Http\Requests\Settings\MasterData;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateHotelRequest extends FormRequest
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
        $hotelId = (int) $this->route('hotel')?->id;

        return [
            'name' => [
                'required',
                'string',
                'max:120',
                Rule::unique('hotels', 'name')
                    ->where(fn ($query) => $query->where('company_id', $companyId))
                    ->whereNull('deleted_at')
                    ->ignore($hotelId),
            ],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
