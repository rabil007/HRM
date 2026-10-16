<?php

namespace App\Support\CrewAccommodation;

use App\Enums\CrewAccommodationStatus;
use App\Exceptions\CrewMovementException;
use App\Models\CrewAccommodationStay;
use App\Models\CrewAssignment;
use App\Models\Hotel;
use App\Models\RoomType;
use App\Models\User;
use App\Support\Auth\PrivilegedTwoFactorPolicy;
use App\Support\Settings\CompanyTimezone;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class UpdateCrewAccommodationStay
{
    private readonly ValidateCrewAccommodationStayEdit $validator;

    public function __construct(
        ?ValidateCrewAccommodationStayEdit $validator = null,
    ) {
        $this->validator = $validator ?? app(ValidateCrewAccommodationStayEdit::class);
    }

    /**
     * @param  array{
     *     hotel_id: int,
     *     room_type_id?: int|null,
     *     check_in_date: string,
     *     check_out_date?: string|null,
     *     reason: string,
     *     expected_edit_version: int
     * }  $payload
     */
    public function handle(
        CrewAssignment $assignment,
        CrewAccommodationStay $stay,
        User $actor,
        int $companyId,
        array $payload,
    ): CrewAccommodationStay {
        $reason = trim((string) ($payload['reason'] ?? ''));

        if ($reason === '') {
            throw ValidationException::withMessages([
                'reason' => 'A reason for the edit is required.',
            ]);
        }

        if (! $actor->can('crew_operations.corrections.override')) {
            throw CrewMovementException::make(
                'You do not have permission to edit accommodation records.',
                'accommodation_edit_forbidden',
            );
        }

        PrivilegedTwoFactorPolicy::assertSatisfied($actor);

        return DB::transaction(function () use ($assignment, $stay, $actor, $companyId, $payload, $reason): CrewAccommodationStay {
            $assignment = CrewAssignment::query()
                ->whereKey($assignment->id)
                ->where('company_id', $companyId)
                ->lockForUpdate()
                ->firstOrFail();

            $lockedStay = CrewAccommodationStay::query()
                ->whereKey($stay->id)
                ->where('company_id', $companyId)
                ->where('crew_assignment_id', $assignment->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedStay->accommodation_status !== CrewAccommodationStatus::Hotel) {
                throw ValidationException::withMessages([
                    'hotel_id' => 'Only hotel accommodation records can be edited.',
                ]);
            }

            $this->assertExpectedEditVersion($lockedStay, $payload['expected_edit_version'] ?? null);

            $timezone = CompanyTimezone::forCompanyId($companyId);
            $hotelId = (int) $payload['hotel_id'];
            $roomTypeId = array_key_exists('room_type_id', $payload) && $payload['room_type_id'] !== null && $payload['room_type_id'] !== ''
                ? (int) $payload['room_type_id']
                : null;
            $checkInDate = $this->parseRequiredDate($payload['check_in_date'] ?? null, $timezone, 'check_in_date');
            $checkOutDate = $this->parseOptionalDate($payload['check_out_date'] ?? null, $timezone, 'check_out_date');

            $this->assertHotelSelection($companyId, $lockedStay, $hotelId);
            $this->assertRoomTypeSelection($companyId, $lockedStay, $hotelId, $roomTypeId);

            $this->validator->assert($assignment, $lockedStay, $checkInDate, $checkOutDate);

            $previous = [
                'hotel_id' => $lockedStay->hotel_id,
                'room_type_id' => $lockedStay->room_type_id,
                'check_in_date' => $lockedStay->check_in_date?->toDateString(),
                'check_out_date' => $lockedStay->check_out_date?->toDateString(),
                'stay_type' => $lockedStay->stay_type->value,
                'accommodation_status' => $lockedStay->accommodation_status->value,
                'edit_version' => (int) $lockedStay->edit_version,
            ];

            $nextVersion = (int) $lockedStay->edit_version + 1;

            $updated = [
                'hotel_id' => $hotelId,
                'room_type_id' => $roomTypeId,
                'check_in_date' => $checkInDate->toDateString(),
                'check_out_date' => $checkOutDate?->toDateString(),
                'stay_type' => $lockedStay->stay_type->value,
                'accommodation_status' => $lockedStay->accommodation_status->value,
                'edit_version' => $nextVersion,
            ];

            $lockedStay->activityLogContext = [
                'event' => 'accommodation_stay_edited',
                'reason' => $reason,
                'assignment_id' => $assignment->id,
                'accommodation_stay_id' => $lockedStay->id,
                'previous_values' => $previous,
                'updated_values' => $updated,
                'actor_id' => $actor->id,
            ];

            $lockedStay->fill([
                'hotel_id' => $hotelId,
                'room_type_id' => $roomTypeId,
                'check_in_date' => $checkInDate,
                'check_out_date' => $checkOutDate,
                'updated_by' => $actor->id,
                'edit_version' => $nextVersion,
            ]);

            $lockedStay->save();

            activity()
                ->performedOn($assignment)
                ->causedBy($actor)
                ->withProperties([
                    'event' => 'accommodation_stay_edited',
                    'company_id' => $companyId,
                    'assignment_id' => $assignment->id,
                    'accommodation_stay_id' => $lockedStay->id,
                    'actor_id' => $actor->id,
                    'previous_values' => $previous,
                    'updated_values' => $updated,
                    'reason' => $reason,
                ])
                ->tap(function ($activity) use ($companyId): void {
                    $activity->company_id = $companyId;
                })
                ->log('Crew accommodation stay edited');

            return $lockedStay->fresh(['hotel', 'roomType']) ?? $lockedStay;
        });
    }

    private function assertExpectedEditVersion(CrewAccommodationStay $stay, mixed $expectedEditVersion): void
    {
        if ($expectedEditVersion === null || $expectedEditVersion === '') {
            throw ValidationException::withMessages([
                'expected_edit_version' => 'This accommodation record version is missing. Refresh and try again.',
            ]);
        }

        if ((int) $expectedEditVersion !== (int) $stay->edit_version) {
            throw ValidationException::withMessages([
                'expected_edit_version' => 'This accommodation record was changed by someone else. Refresh and try again.',
            ]);
        }
    }

    private function assertHotelSelection(int $companyId, CrewAccommodationStay $stay, int $hotelId): void
    {
        if ($hotelId <= 0) {
            throw ValidationException::withMessages([
                'hotel_id' => 'Hotel is required.',
            ]);
        }

        $hotel = Hotel::query()
            ->whereKey($hotelId)
            ->where('company_id', $companyId)
            ->first();

        if ($hotel === null) {
            throw ValidationException::withMessages([
                'hotel_id' => 'Hotel must belong to the current company.',
            ]);
        }

        $hotelChanged = (int) $stay->hotel_id !== $hotelId;

        if ($hotelChanged && ! $hotel->is_active) {
            throw ValidationException::withMessages([
                'hotel_id' => 'The selected hotel is inactive.',
            ]);
        }
    }

    private function assertRoomTypeSelection(
        int $companyId,
        CrewAccommodationStay $stay,
        int $hotelId,
        ?int $roomTypeId,
    ): void {
        if ($roomTypeId === null) {
            return;
        }

        $roomType = RoomType::query()
            ->whereKey($roomTypeId)
            ->where('company_id', $companyId)
            ->first();

        if ($roomType === null) {
            throw ValidationException::withMessages([
                'room_type_id' => 'Room type must belong to the current company.',
            ]);
        }

        $roomTypeChanged = (int) ($stay->room_type_id ?? 0) !== $roomTypeId;
        $hotelChanged = (int) $stay->hotel_id !== $hotelId;
        $isHistoricalLegacy = ! $roomTypeChanged
            && ! $hotelChanged
            && $roomType->hotel_id === null;

        if (! $isHistoricalLegacy && (int) $roomType->hotel_id !== $hotelId) {
            throw ValidationException::withMessages([
                'room_type_id' => 'Room type must belong to the selected hotel.',
            ]);
        }

        if ($roomTypeChanged && ! $roomType->is_active) {
            throw ValidationException::withMessages([
                'room_type_id' => 'The selected room type is inactive.',
            ]);
        }
    }

    private function parseRequiredDate(mixed $value, string $timezone, string $field): CarbonInterface
    {
        $parsed = $this->parseOptionalDate($value, $timezone, $field);

        if ($parsed === null) {
            throw ValidationException::withMessages([
                $field => 'Check-in date is required.',
            ]);
        }

        return $parsed;
    }

    private function parseOptionalDate(mixed $value, string $timezone, string $field): ?CarbonInterface
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse((string) $value, $timezone)->startOfDay();
        } catch (\Throwable) {
            throw ValidationException::withMessages([
                $field => 'Enter a valid date.',
            ]);
        }
    }
}
