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
    protected function nestedHotelRoomTypeRules(?int $hotelId = null, bool $allowRemovals = false): array
    {
        $companyId = (int) $this->attributes->get('current_company_id');

        $rules = [
            'room_types' => ['nullable', 'array'],
            'room_types.*.id' => [
                'nullable',
                'integer',
                'distinct',
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

        if ($allowRemovals) {
            $rules['removed_room_type_ids'] = ['nullable', 'array'];
            $rules['removed_room_type_ids.*'] = [
                'integer',
                'distinct',
                Rule::exists('room_types', 'id')->where(function ($query) use ($companyId, $hotelId): void {
                    $query->where('company_id', $companyId);

                    if ($hotelId !== null) {
                        $query->where('hotel_id', $hotelId);
                    }
                }),
            ];
        } else {
            $rules['removed_room_type_ids'] = ['prohibited'];
        }

        return $rules;
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

        $this->validateRemovedRoomTypeOverlap($validator);
    }

    protected function validateRemovedRoomTypeOverlap(Validator $validator): void
    {
        if ($validator->errors()->isNotEmpty()) {
            return;
        }

        $rows = $this->input('room_types', []);
        $removedIds = $this->input('removed_room_type_ids', []);

        if (! is_array($rows) || ! is_array($removedIds)) {
            return;
        }

        $submittedIds = collect($rows)
            ->pluck('id')
            ->filter(fn (mixed $id): bool => is_numeric($id) && (int) $id > 0)
            ->map(fn (mixed $id): int => (int) $id)
            ->values()
            ->all();

        $removedIds = collect($removedIds)
            ->filter(fn (mixed $id): bool => is_numeric($id) && (int) $id > 0)
            ->map(fn (mixed $id): int => (int) $id)
            ->values()
            ->all();

        if (array_intersect($submittedIds, $removedIds) !== []) {
            $validator->errors()->add(
                'removed_room_type_ids',
                'A room type cannot be updated and removed in the same request.',
            );
        }
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
        if (! $this->has('room_types')) {
            return [];
        }

        $rows = $this->validated('room_types') ?? [];

        return is_array($rows) ? array_values($rows) : [];
    }

    /**
     * @return list<int>
     */
    public function validatedRemovedRoomTypeIds(): array
    {
        if (! $this->has('removed_room_type_ids')) {
            return [];
        }

        $ids = $this->validated('removed_room_type_ids') ?? [];

        if (! is_array($ids)) {
            return [];
        }

        return collect($ids)
            ->filter(fn (mixed $id): bool => is_numeric($id) && (int) $id > 0)
            ->map(fn (mixed $id): int => (int) $id)
            ->values()
            ->all();
    }

    public function shouldSyncRoomTypes(): bool
    {
        return $this->has('room_types') || $this->has('removed_room_type_ids');
    }
}
