<?php

namespace App\Http\Requests\Settings\MasterData;

use App\Http\Requests\Settings\MasterData\Concerns\ValidatesNestedHotelRoomTypes;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreHotelRequest extends FormRequest
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

        return [
            'name' => [
                'required',
                'string',
                'max:120',
                Rule::unique('hotels', 'name')
                    ->where(fn ($query) => $query->where('company_id', $companyId)),
            ],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['nullable', 'boolean'],
            ...$this->nestedHotelRoomTypeRules(),
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->validateNestedHotelRoomTypes($validator);
        });
    }

    /**
     * @return list<array{
     *     id?: int|null,
     *     name: string,
     *     description?: string|null,
     *     is_active?: bool|null
     * }>
     */
    public function validatedRoomTypes(): array
    {
        $rows = $this->validated('room_types') ?? [];

        return is_array($rows) ? array_values($rows) : [];
    }
}
