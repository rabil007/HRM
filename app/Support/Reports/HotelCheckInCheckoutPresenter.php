<?php

namespace App\Support\Reports;

use App\Models\CrewAccommodationStay;
use App\Support\CrewAccommodation\CrewAccommodationService;
use Carbon\Carbon;

final class HotelCheckInCheckoutPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function toArray(CrewAccommodationStay $stay, string $timezone): array
    {
        $today = Carbon::now($timezone)->toDateString();
        $checkInStr = $stay->check_in_date?->toDateString();
        $checkOutStr = $stay->check_out_date?->toDateString();
        $statusInfo = self::deriveStayStatus($checkInStr, $checkOutStr, $today);

        $startingCheckpoint = $stay->startedFromPhase?->phase_code !== null
            ? strtoupper($stay->startedFromPhase->phase_code->value).' · '.$stay->startedFromPhase->phase_code->label()
            : null;

        $currentPhase = $stay->assignment?->currentPhase?->phase_code !== null
            ? strtoupper($stay->assignment->currentPhase->phase_code->value).' · '.$stay->assignment->currentPhase->phase_code->label()
            : null;

        $stayDays = CrewAccommodationService::calculateStayDays(
            $stay->check_in_date,
            $stay->check_out_date,
            $timezone,
        );

        return [
            'id' => (int) $stay->id,
            'stay_type' => $stay->stay_type?->value,
            'stay_type_label' => $stay->stay_type?->label() ?? '—',
            'accommodation_status' => $stay->accommodation_status?->value,
            'accommodation_status_label' => $stay->accommodation_status?->label() ?? '—',
            'check_in_date' => $checkInStr,
            'check_out_date' => $checkOutStr,
            'is_open' => $stay->check_out_date === null,
            'stay_days' => $stayDays,
            'stay_status' => $statusInfo['code'],
            'stay_status_label' => $statusInfo['label'],
            'hotel' => [
                'id' => $stay->hotel_id !== null ? (int) $stay->hotel_id : null,
                'name' => $stay->hotel?->name ?? '—',
            ],
            'room_type' => [
                'id' => $stay->room_type_id !== null ? (int) $stay->room_type_id : null,
                'name' => $stay->roomType?->name ?? '—',
            ],
            'starting_checkpoint' => $startingCheckpoint,
            'current_phase' => $currentPhase,
            'assignment' => [
                'id' => (int) $stay->crew_assignment_id,
                'assignment_no' => $stay->assignment?->assignment_no ?? '—',
                'status' => $stay->assignment?->status?->value,
                'status_label' => $stay->assignment?->status?->label() ?? '—',
                'vessel_id' => $stay->assignment?->vessel_id !== null ? (int) $stay->assignment->vessel_id : null,
                'vessel_name' => $stay->assignment?->vessel?->name ?? '—',
                'rank_id' => $stay->assignment?->rank_id !== null ? (int) $stay->assignment->rank_id : null,
                'rank_name' => $stay->assignment?->rank?->name ?? '—',
                'client_id' => $stay->assignment?->client_id !== null ? (int) $stay->assignment->client_id : null,
                'client_name' => $stay->assignment?->client?->name ?? '—',
            ],
            'employee' => [
                'id' => $stay->assignment?->employee_id !== null ? (int) $stay->assignment->employee_id : null,
                'employee_no' => $stay->assignment?->employee?->employee_no ?? '—',
                'name' => $stay->assignment?->employee?->name ?? '—',
            ],
        ];
    }

    /**
     * @return array{code: string, label: string}
     */
    public static function deriveStayStatus(
        ?string $checkInDate,
        ?string $checkOutDate,
        string $today,
    ): array {
        if ($checkOutDate !== null && $checkOutDate < $today) {
            return [
                'code' => 'checked_out',
                'label' => 'Checked Out',
            ];
        }

        if ($checkOutDate !== null && $checkOutDate === $today) {
            return [
                'code' => 'checking_out_today',
                'label' => 'Checking Out Today',
            ];
        }

        if ($checkInDate !== null && $checkInDate > $today) {
            return [
                'code' => 'upcoming',
                'label' => 'Upcoming',
            ];
        }

        if ($checkInDate !== null && $checkInDate === $today) {
            return [
                'code' => 'check_in_today',
                'label' => 'Check-In Today',
            ];
        }

        if ($checkInDate !== null && $checkInDate < $today && ($checkOutDate === null || $checkOutDate > $today)) {
            return [
                'code' => 'currently_checked_in',
                'label' => 'Currently Checked In',
            ];
        }

        return [
            'code' => 'unknown',
            'label' => '—',
        ];
    }
}
