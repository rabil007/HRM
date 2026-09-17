<?php

namespace App\Support\MasterData;

use App\Models\Hotel;
use App\Models\RoomType;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SyncHotelRoomTypes
{
    /**
     * @param  list<array{
     *     id?: int|null,
     *     name: string,
     *     description?: string|null,
     *     is_active?: bool|null
     * }>  $rows
     */
    public static function sync(Hotel $hotel, array $rows, int $companyId): void
    {
        DB::transaction(function () use ($hotel, $rows, $companyId): void {
            foreach ($rows as $index => $row) {
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
        });
    }

    /**
     * @param  list<array{
     *     id?: int|null,
     *     name: string,
     *     description?: string|null,
     *     is_active?: bool|null
     * }>  $rows
     */
    public static function syncWithDeletions(Hotel $hotel, array $rows, int $companyId): void
    {
        DB::transaction(function () use ($hotel, $rows, $companyId): void {
            $submittedIds = collect($rows)
                ->pluck('id')
                ->filter(fn (mixed $id): bool => is_numeric($id) && (int) $id > 0)
                ->map(fn (mixed $id): int => (int) $id)
                ->values()
                ->all();

            $existingIds = $hotel->roomTypes()
                ->where('company_id', $companyId)
                ->pluck('id')
                ->map(fn (mixed $id): int => (int) $id)
                ->all();

            foreach (array_diff($existingIds, $submittedIds) as $roomTypeId) {
                $roomType = RoomType::query()
                    ->whereKey($roomTypeId)
                    ->where('company_id', $companyId)
                    ->where('hotel_id', $hotel->id)
                    ->first();

                if ($roomType === null) {
                    continue;
                }

                MasterDataUsage::assertDeletable($roomType, $companyId);
                $roomType->delete();
            }

            self::sync($hotel, $rows, $companyId);
        });
    }

    public static function deleteAllForHotel(Hotel $hotel, int $companyId): void
    {
        DB::transaction(function () use ($hotel, $companyId): void {
            $roomTypes = $hotel->roomTypes()
                ->where('company_id', $companyId)
                ->get();

            foreach ($roomTypes as $roomType) {
                MasterDataUsage::assertDeletable($roomType, $companyId);
                $roomType->delete();
            }
        });
    }
}
