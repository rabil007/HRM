<?php

namespace App\Http\Requests\Settings\MasterData;

use App\Http\Requests\Settings\MasterData\Concerns\ValidatesNestedHotelRoomTypes;
use App\Models\Hotel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateHotelRequest extends FormRequest
{
    use ValidatesNestedHotelRoomTypes;

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
        $hotel = $this->route('hotel');
        $hotelId = $hotel instanceof Hotel ? (int) $hotel->id : null;

        return [
            'name' => [
                'required',
                'string',
                'max:120',
                Rule::unique('hotels', 'name')
                    ->where(fn ($query) => $query->where('company_id', $companyId))
                    ->ignore($hotelId),
            ],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['nullable', 'boolean'],
            ...$this->nestedHotelRoomTypeRules($hotelId, allowRemovals: true),
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $hotel = $this->route('hotel');

            if ($hotel instanceof Hotel) {
                $this->validateNestedHotelRoomTypes($validator, $hotel);
            }
        });
    }
}
