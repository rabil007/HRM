<?php

namespace App\Support\MasterData;

use App\Models\Hotel;
use App\Models\RoomType;
use Illuminate\Validation\ValidationException;

final class SyncHotelRoomTypes
{
    /**
     * @param  list<array{
     *     id?: int|null,
     *     name: string,
     *     description?: string|null,
     *     is_active?: bool|null
     * }>  $roomTypes
     * @param  list<int>  $removedRoomTypeIds
     */
    public static function handle(
        Hotel $hotel,
        array $roomTypes,
        array $removedRoomTypeIds,
        int $companyId,
    ): void {
        $hotel = Hotel::query()
            ->whereKey($hotel->id)
            ->where('company_id', $companyId)
            ->lockForUpdate()
            ->firstOrFail();

        $removedRoomTypeIds = array_values(array_unique(array_filter(
            $removedRoomTypeIds,
            fn (mixed $id): bool => is_numeric($id) && (int) $id > 0,
        )));

        $submittedIds = collect($roomTypes)
            ->pluck('id')
            ->filter(fn (mixed $id): bool => is_numeric($id) && (int) $id > 0)
            ->map(fn (mixed $id): int => (int) $id)
            ->values()
            ->all();

        $overlap = array_intersect($submittedIds, $removedRoomTypeIds);

        if ($overlap !== []) {
            throw ValidationException::withMessages([
                'removed_room_type_ids' => 'A room type cannot be updated and removed in the same request.',
            ]);
        }

        foreach ($removedRoomTypeIds as $roomTypeId) {
            $roomType = RoomType::query()
                ->whereKey($roomTypeId)
                ->where('company_id', $companyId)
                ->where('hotel_id', $hotel->id)
                ->lockForUpdate()
                ->first();

            if ($roomType === null) {
                throw ValidationException::withMessages([
                    'removed_room_type_ids' => 'One or more room types do not belong to this hotel.',
                ]);
            }

            MasterDataUsage::assertDeletable($roomType, $companyId);
            $roomType->delete();
        }

        foreach ($roomTypes as $index => $row) {
            $name = trim((string) ($row['name'] ?? ''));
            $description = isset($row['description']) && $row['description'] !== ''
                ? (string) $row['description']
                : null;
            $isActive = array_key_exists('is_active', $row)
                ? (bool) $row['is_active']
                : true;
            $roomTypeId = isset($row['id']) ? (int) $row['id'] : null;

            if ($roomTypeId !== null && $roomTypeId > 0) {
                $roomType = RoomType::query()
                    ->whereKey($roomTypeId)
                    ->where('company_id', $companyId)
                    ->where('hotel_id', $hotel->id)
                    ->lockForUpdate()
                    ->first();

                if ($roomType === null) {
                    throw ValidationException::withMessages([
                        "room_types.{$index}.id" => 'Room type does not belong to this hotel.',
                    ]);
                }

                $roomType->update([
                    'name' => $name,
                    'description' => $description,
                    'is_active' => $isActive,
                ]);

                continue;
            }

            $hotel->roomTypes()->create([
                'company_id' => $companyId,
                'name' => $name,
                'description' => $description,
                'is_active' => $isActive,
            ]);
        }
    }

    public static function deleteAllForHotel(Hotel $hotel, int $companyId): void
    {
        $roomTypes = $hotel->roomTypes()
            ->where('company_id', $companyId)
            ->lockForUpdate()
            ->get();

        foreach ($roomTypes as $roomType) {
            MasterDataUsage::assertDeletable($roomType, $companyId);
            $roomType->delete();
        }
    }
}
