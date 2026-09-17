<?php

namespace App\Http\Requests\Settings\MasterData;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReconcileLegacyRoomTypeRequest extends FormRequest
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

        return [
            'room_type_id' => [
                'required',
                'integer',
                Rule::exists('room_types', 'id')->where(function ($query) use ($companyId): void {
                    $query->where('company_id', $companyId)->whereNull('hotel_id');
                }),
            ],
        ];
    }
}
