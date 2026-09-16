<?php

namespace App\Support\CrewAccommodation;

use App\Enums\CrewAccommodationStatus;
use App\Models\CrewAccommodationStay;
use App\Models\CrewAssignment;
use App\Models\CrewAssignmentPhase;
use App\Models\Hotel;
use App\Models\RoomType;
use Illuminate\Validation\ValidationException;

final class CrewAccommodationStayIntegrity
{
    public static function assertValid(CrewAccommodationStay $stay): void
    {
        self::assertDateRangeValid($stay);
        self::assertAccommodationStatusFields($stay);
        self::assertTenantRelationships($stay);
    }

    private static function assertDateRangeValid(CrewAccommodationStay $stay): void
    {
        if (
            $stay->check_in_date !== null
            && $stay->check_out_date !== null
            && $stay->check_out_date->lt($stay->check_in_date)
        ) {
            throw ValidationException::withMessages([
                'check_out_date' => 'Check-out date cannot be before check-in date.',
            ]);
        }
    }

    private static function assertAccommodationStatusFields(CrewAccommodationStay $stay): void
    {
        $status = $stay->accommodation_status instanceof CrewAccommodationStatus
            ? $stay->accommodation_status
            : CrewAccommodationStatus::tryFrom((string) $stay->accommodation_status);

        if ($status === CrewAccommodationStatus::Hotel) {
            if ($stay->hotel_id === null) {
                throw ValidationException::withMessages([
                    'hotel_id' => 'Hotel is required when accommodation status is hotel.',
                ]);
            }

            if ($stay->check_in_date === null) {
                throw ValidationException::withMessages([
                    'check_in_date' => 'Check-in date is required when accommodation status is hotel.',
                ]);
            }

            return;
        }

        if ($status === CrewAccommodationStatus::NoAccommodation) {
            $errors = [];

            if ($stay->hotel_id !== null) {
                $errors['hotel_id'] = 'Hotel must be empty when accommodation status is no accommodation.';
            }

            if ($stay->room_type_id !== null) {
                $errors['room_type_id'] = 'Room type must be empty when accommodation status is no accommodation.';
            }

            if ($stay->check_in_date !== null) {
                $errors['check_in_date'] = 'Check-in date must be empty when accommodation status is no accommodation.';
            }

            if ($stay->check_out_date !== null) {
                $errors['check_out_date'] = 'Check-out date must be empty when accommodation status is no accommodation.';
            }

            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }
        }
    }

    private static function assertTenantRelationships(CrewAccommodationStay $stay): void
    {
        $companyId = (int) $stay->company_id;

        if ($stay->crew_assignment_id !== null) {
            $assignmentBelongsToCompany = CrewAssignment::query()
                ->whereKey($stay->crew_assignment_id)
                ->where('company_id', $companyId)
                ->exists();

            if (! $assignmentBelongsToCompany) {
                throw ValidationException::withMessages([
                    'crew_assignment_id' => 'Crew assignment must belong to the current company.',
                ]);
            }
        }

        if ($stay->hotel_id !== null) {
            $hotelBelongsToCompany = Hotel::query()
                ->whereKey($stay->hotel_id)
                ->where('company_id', $companyId)
                ->exists();

            if (! $hotelBelongsToCompany) {
                throw ValidationException::withMessages([
                    'hotel_id' => 'Hotel must belong to the current company.',
                ]);
            }
        }

        if ($stay->room_type_id !== null) {
            $roomTypeBelongsToCompany = RoomType::query()
                ->whereKey($stay->room_type_id)
                ->where('company_id', $companyId)
                ->exists();

            if (! $roomTypeBelongsToCompany) {
                throw ValidationException::withMessages([
                    'room_type_id' => 'Room type must belong to the current company.',
                ]);
            }
        }

        if ($stay->started_from_phase_id !== null) {
            $phase = CrewAssignmentPhase::query()
                ->whereKey($stay->started_from_phase_id)
                ->first(['id', 'company_id', 'crew_assignment_id']);

            if ($phase === null || (int) $phase->company_id !== $companyId) {
                throw ValidationException::withMessages([
                    'started_from_phase_id' => 'Phase must belong to the current company.',
                ]);
            }

            if (
                $stay->crew_assignment_id !== null
                && (int) $phase->crew_assignment_id !== (int) $stay->crew_assignment_id
            ) {
                throw ValidationException::withMessages([
                    'started_from_phase_id' => 'Phase must belong to the same crew assignment.',
                ]);
            }
        }
    }
}
