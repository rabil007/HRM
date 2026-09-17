<?php

namespace App\Http\Requests\Settings\MasterData\Concerns;

use App\Models\Hotel;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

trait ValidatesNestedHotelRoomTypes
{
    /**
     * @return array<string, mixed>
     */
    protected function nestedHotelRoomTypeRules(?int $hotelId = null): array
    {
        $companyId = (int) $this->attributes->get('current_company_id');

        return [
            'room_types' => ['nullable', 'array'],
            'room_types.*.id' => [
                'nullable',
                'integer',
                Rule::exists('room_types', 'id')->where(function ($query) use ($companyId, $hotelId): void {
                    $query->where('company_id', $companyId);

                    if ($hotelId !== null) {
                        $query->where('hotel_id', $hotelId);
                    }
                }),
            ],
            'room_types.*.name' => ['required', 'string', 'max:120'],
            'room_types.*.description' => ['nullable', 'string', 'max:2000'],
            'room_types.*.is_active' => ['nullable', 'boolean'],
        ];
    }

    protected function validateNestedHotelRoomTypes(Validator $validator, ?Hotel $hotel = null): void
    {
        if ($validator->errors()->isNotEmpty()) {
            return;
        }

        $companyId = (int) $this->attributes->get('current_company_id');
        $hotelId = $hotel?->id;
        $rows = $this->input('room_types', []);

        if (! is_array($rows)) {
            return;
        }

        $normalizedNames = [];

        foreach ($rows as $index => $row) {
            if (! is_array($row)) {
                continue;
            }

            $name = mb_strtolower(trim((string) ($row['name'] ?? '')));

            if ($name === '') {
                continue;
            }

            if (isset($normalizedNames[$name])) {
                $validator->errors()->add(
                    "room_types.{$index}.name",
                    'Room type names must be unique within the hotel.',
                );

                continue;
            }

            $normalizedNames[$name] = $index;

            if ($hotelId === null) {
                continue;
            }

            $roomTypeId = isset($row['id']) ? (int) $row['id'] : null;

            $uniqueRule = Rule::unique('room_types', 'name')
                ->where(fn ($query) => $query
                    ->where('company_id', $companyId)
                    ->where('hotel_id', $hotelId));

            if ($roomTypeId !== null && $roomTypeId > 0) {
                $uniqueRule = $uniqueRule->ignore($roomTypeId);
            }

            $duplicateExists = \Illuminate\Support\Facades\Validator::make(
                ['name' => $row['name']],
                ['name' => ['required', 'string', 'max:120', $uniqueRule]],
            )->fails();

            if ($duplicateExists) {
                $validator->errors()->add(
                    "room_types.{$index}.name",
                    'A room type with this name already exists for this hotel.',
                );
            }
        }
    }
}
