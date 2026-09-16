<?php

namespace App\Support\CrewAccommodation;

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
        if (
            $stay->check_in_date !== null
            && $stay->check_out_date !== null
            && $stay->check_out_date->lt($stay->check_in_date)
        ) {
            throw ValidationException::withMessages([
                'check_out_date' => 'Check-out date cannot be before check-in date.',
            ]);
        }

        $companyId = (int) $stay->company_id;

        if ($stay->crew_assignment_id !== null) {
            $assignment = $stay->relationLoaded('assignment')
                ? $stay->assignment
                : CrewAssignment::query()->find($stay->crew_assignment_id);

            if ($assignment === null || (int) $assignment->company_id !== $companyId) {
                throw ValidationException::withMessages([
                    'crew_assignment_id' => 'Crew assignment must belong to the current company.',
                ]);
            }
        }

        if ($stay->hotel_id !== null) {
            $hotel = $stay->relationLoaded('hotel')
                ? $stay->hotel
                : Hotel::query()->find($stay->hotel_id);

            if ($hotel === null || (int) $hotel->company_id !== $companyId) {
                throw ValidationException::withMessages([
                    'hotel_id' => 'Hotel must belong to the current company.',
                ]);
            }
        }

        if ($stay->room_type_id !== null) {
            $roomType = $stay->relationLoaded('roomType')
                ? $stay->roomType
                : RoomType::query()->find($stay->room_type_id);

            if ($roomType === null || (int) $roomType->company_id !== $companyId) {
                throw ValidationException::withMessages([
                    'room_type_id' => 'Room type must belong to the current company.',
                ]);
            }
        }

        if ($stay->started_from_phase_id !== null) {
            $phase = $stay->relationLoaded('startedFromPhase')
                ? $stay->startedFromPhase
                : CrewAssignmentPhase::query()->find($stay->started_from_phase_id);

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
