<?php

namespace App\Support\MasterData;

use App\Models\CrewAccommodationStay;
use App\Models\Hotel;
use App\Models\RoomType;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ReconcileLegacyRoomTypes
{
    /**
     * Automatically reconcile legacy room types with null hotel_id.
     *
     * Case A: referenced by exactly one hotel → assign hotel_id.
     * Case B: unused → leave unassigned.
     * Case C: referenced by multiple hotels → split by historical hotel usage.
     */
    public static function autoReconcile(): void
    {
        RoomType::query()
            ->whereNull('hotel_id')
            ->orderBy('id')
            ->each(function (RoomType $roomType): void {
                self::reconcileRoomType($roomType);
            });
    }

    /**
     * @return list<array{
     *     id: int,
     *     name: string,
     *     description: string|null,
     *     is_active: bool,
     *     status: string,
     *     usage_count: int,
     *     usage_label: string|null,
     *     hotel_ids: list<int>
     * }>
     */
    public static function unresolvedForCompany(int $companyId): array
    {
        return RoomType::query()
            ->where('company_id', $companyId)
            ->whereNull('hotel_id')
            ->orderBy('name')
            ->get()
            ->map(fn (RoomType $roomType): array => self::presentUnresolved($roomType, $companyId))
            ->values()
            ->all();
    }

    public static function assignToHotel(RoomType $roomType, Hotel $hotel, int $companyId): void
    {
        DB::transaction(function () use ($roomType, $hotel, $companyId): void {
            $lockedRoomType = RoomType::query()
                ->whereKey($roomType->id)
                ->where('company_id', $companyId)
                ->whereNull('hotel_id')
                ->lockForUpdate()
                ->firstOrFail();

            $lockedHotel = Hotel::query()
                ->whereKey($hotel->id)
                ->where('company_id', $companyId)
                ->lockForUpdate()
                ->firstOrFail();

            self::assertAssignableToHotel($lockedRoomType, $lockedHotel, $companyId);

            $lockedRoomType->update(['hotel_id' => $lockedHotel->id]);
        });
    }

    public static function splitByHotelUsage(RoomType $roomType, int $companyId): void
    {
        DB::transaction(function () use ($roomType, $companyId): void {
            $legacyRoomType = RoomType::query()
                ->whereKey($roomType->id)
                ->where('company_id', $companyId)
                ->whereNull('hotel_id')
                ->lockForUpdate()
                ->firstOrFail();

            $hotelIds = self::referencedHotelIds($legacyRoomType, $companyId);

            if (count($hotelIds) < 2) {
                throw ValidationException::withMessages([
                    'room_type_id' => 'Split reconciliation is only required when historical usage spans multiple hotels.',
                ]);
            }

            $hotels = Hotel::query()
                ->where('company_id', $companyId)
                ->whereIn('id', $hotelIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($hotels->count() !== count($hotelIds)) {
                throw ValidationException::withMessages([
                    'room_type_id' => 'Historical usage references hotels outside the current company.',
                ]);
            }

            $legacyAssignHotelId = self::resolveLegacyAssignmentHotelId($legacyRoomType, $hotelIds, $companyId);

            $hotelsNeedingReassignment = $legacyAssignHotelId === null
                ? $hotelIds
                : array_values(array_filter(
                    $hotelIds,
                    fn (int $hotelId): bool => $hotelId !== $legacyAssignHotelId,
                ));

            foreach ($hotelsNeedingReassignment as $hotelId) {
                $destinationRoomType = self::resolveDestinationRoomType(
                    $legacyRoomType,
                    $hotelId,
                    $companyId,
                );

                self::reassignStaysForHotel(
                    $legacyRoomType,
                    $destinationRoomType,
                    $hotelId,
                    $companyId,
                );
            }

            if ($legacyAssignHotelId !== null) {
                $legacyHotel = $hotels->get($legacyAssignHotelId);

                if ($legacyHotel === null) {
                    throw ValidationException::withMessages([
                        'room_type_id' => 'Historical usage references hotels outside the current company.',
                    ]);
                }

                self::assertUniqueNameForHotel($legacyRoomType, $legacyHotel, $companyId);

                $legacyRoomType->update(['hotel_id' => $legacyAssignHotelId]);
            }
        });
    }

    private static function reconcileRoomType(RoomType $roomType): void
    {
        if ($roomType->hotel_id !== null) {
            return;
        }

        $companyId = (int) $roomType->company_id;
        $hotelIds = self::referencedHotelIds($roomType, $companyId);

        if ($hotelIds === []) {
            return;
        }

        if (count($hotelIds) === 1) {
            $hotelId = $hotelIds[0];
            $hotelBelongsToCompany = Hotel::query()
                ->whereKey($hotelId)
                ->where('company_id', $companyId)
                ->exists();

            if ($hotelBelongsToCompany) {
                try {
                    DB::transaction(function () use ($roomType, $hotelId, $companyId): void {
                        $lockedRoomType = RoomType::query()
                            ->whereKey($roomType->id)
                            ->where('company_id', $companyId)
                            ->whereNull('hotel_id')
                            ->lockForUpdate()
                            ->first();

                        if ($lockedRoomType === null) {
                            return;
                        }

                        $lockedHotel = Hotel::query()
                            ->whereKey($hotelId)
                            ->where('company_id', $companyId)
                            ->lockForUpdate()
                            ->first();

                        if ($lockedHotel === null) {
                            return;
                        }

                        self::assertAssignableToHotel($lockedRoomType, $lockedHotel, $companyId);

                        $lockedRoomType->update(['hotel_id' => $lockedHotel->id]);
                    });
                } catch (ValidationException) {
                    // Leave ambiguous rows for manual reconciliation in Hotels settings.
                }
            }

            return;
        }

        try {
            self::splitByHotelUsage($roomType, $companyId);
        } catch (ValidationException) {
            // Leave ambiguous rows for manual reconciliation in Hotels settings.
        }
    }

    private static function assertAssignableToHotel(RoomType $roomType, Hotel $hotel, int $companyId): void
    {
        if ((int) $roomType->company_id !== $companyId || (int) $hotel->company_id !== $companyId) {
            throw ValidationException::withMessages([
                'hotel_id' => 'Hotel must belong to the current company.',
            ]);
        }

        if ($roomType->hotel_id !== null) {
            throw ValidationException::withMessages([
                'room_type_id' => 'This room type is already assigned to a hotel.',
            ]);
        }

        $hotelIds = self::referencedHotelIds($roomType, $companyId);

        if (count($hotelIds) > 1) {
            throw ValidationException::withMessages([
                'room_type_id' => 'This room type was used across multiple hotels. Run reconciliation to split it by hotel before assigning.',
            ]);
        }

        if (count($hotelIds) === 1 && $hotelIds[0] !== $hotel->id) {
            throw ValidationException::withMessages([
                'hotel_id' => 'Assign this room type to the hotel recorded in accommodation history.',
            ]);
        }

        self::assertUniqueNameForHotel($roomType, $hotel, $companyId);
    }

    /**
     * @param  list<int>  $hotelIds
     */
    private static function resolveLegacyAssignmentHotelId(
        RoomType $legacyRoomType,
        array $hotelIds,
        int $companyId,
    ): ?int {
        foreach ($hotelIds as $hotelId) {
            $existingRoomType = self::findExistingSameNameRoomType($legacyRoomType, $hotelId, $companyId);

            if ($existingRoomType === null) {
                return $hotelId;
            }
        }

        return null;
    }

    private static function resolveDestinationRoomType(
        RoomType $legacyRoomType,
        int $hotelId,
        int $companyId,
    ): RoomType {
        $existingRoomType = self::findExistingSameNameRoomType($legacyRoomType, $hotelId, $companyId);

        if ($existingRoomType !== null) {
            return $existingRoomType;
        }

        return RoomType::query()->create([
            'company_id' => $companyId,
            'hotel_id' => $hotelId,
            'name' => $legacyRoomType->name,
            'description' => $legacyRoomType->description,
            'is_active' => $legacyRoomType->is_active,
        ]);
    }

    private static function findExistingSameNameRoomType(
        RoomType $reference,
        int $hotelId,
        int $companyId,
    ): ?RoomType {
        return RoomType::query()
            ->where('company_id', $companyId)
            ->where('hotel_id', $hotelId)
            ->where('name', $reference->name)
            ->whereKeyNot($reference->id)
            ->lockForUpdate()
            ->first();
    }

    private static function reassignStaysForHotel(
        RoomType $legacyRoomType,
        RoomType $destinationRoomType,
        int $hotelId,
        int $companyId,
    ): void {
        if ((int) $destinationRoomType->company_id !== $companyId) {
            throw ValidationException::withMessages([
                'room_type_id' => 'Destination room type must belong to the current company.',
            ]);
        }

        if ((int) $destinationRoomType->hotel_id !== $hotelId) {
            throw ValidationException::withMessages([
                'room_type_id' => 'Destination room type must belong to the historical hotel.',
            ]);
        }

        $stays = CrewAccommodationStay::query()
            ->where('company_id', $companyId)
            ->where('room_type_id', $legacyRoomType->id)
            ->where('hotel_id', $hotelId)
            ->lockForUpdate()
            ->get();

        foreach ($stays as $stay) {
            if ((int) $stay->room_type_id === (int) $destinationRoomType->id) {
                continue;
            }

            $stay->activityLogContext = [
                'reason' => 'legacy_room_type_reconciliation',
                'legacy_room_type_id' => $legacyRoomType->id,
                'hotel_id' => $hotelId,
            ];
            $stay->room_type_id = $destinationRoomType->id;
            $stay->save();
        }
    }

    /**
     * @return list<int>
     */
    private static function referencedHotelIds(RoomType $roomType, int $companyId): array
    {
        return CrewAccommodationStay::query()
            ->where('company_id', $companyId)
            ->where('room_type_id', $roomType->id)
            ->whereNotNull('hotel_id')
            ->distinct()
            ->orderBy('hotel_id')
            ->pluck('hotel_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->values()
            ->all();
    }

    /**
     * @return array{
     *     id: int,
     *     name: string,
     *     description: string|null,
     *     is_active: bool,
     *     status: string,
     *     usage_count: int,
     *     usage_label: string|null,
     *     hotel_ids: list<int>
     * }
     */
    private static function presentUnresolved(RoomType $roomType, int $companyId): array
    {
        $hotelIds = self::referencedHotelIds($roomType, $companyId);
        $usageCount = CrewAccommodationStay::query()
            ->where('company_id', $companyId)
            ->where('room_type_id', $roomType->id)
            ->count();

        $status = match (true) {
            $hotelIds === [] => 'unused',
            count($hotelIds) === 1 => 'single_hotel',
            default => 'multiple_hotels',
        };

        $usageLabel = match ($status) {
            'unused' => 'Not used',
            'single_hotel' => self::singleHotelUsageLabel($hotelIds[0], $companyId),
            default => 'Multiple hotels',
        };

        return [
            'id' => (int) $roomType->id,
            'name' => $roomType->name,
            'description' => $roomType->description,
            'is_active' => (bool) $roomType->is_active,
            'status' => $status,
            'usage_count' => $usageCount,
            'usage_label' => $usageLabel,
            'hotel_ids' => $hotelIds,
        ];
    }

    private static function singleHotelUsageLabel(int $hotelId, int $companyId): string
    {
        $hotelName = Hotel::query()
            ->whereKey($hotelId)
            ->where('company_id', $companyId)
            ->value('name');

        return $hotelName !== null
            ? "Used by {$hotelName}"
            : 'Used by one hotel';
    }

    private static function assertUniqueNameForHotel(RoomType $roomType, Hotel $hotel, int $companyId): void
    {
        $duplicateExists = RoomType::query()
            ->where('company_id', $companyId)
            ->where('hotel_id', $hotel->id)
            ->where('name', $roomType->name)
            ->whereKeyNot($roomType->id)
            ->exists();

        if ($duplicateExists) {
            throw ValidationException::withMessages([
                'name' => 'A room type with this name already exists for the selected hotel.',
            ]);
        }
    }
}
