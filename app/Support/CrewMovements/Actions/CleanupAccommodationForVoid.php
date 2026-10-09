<?php

namespace App\Support\CrewMovements\Actions;

use App\Models\CrewAccommodationStay;
use Illuminate\Support\Collection;

/**
 * Hard-deletes assignment-linked accommodation stays during privileged void.
 *
 * CrewAccommodationStay does not use SoftDeletes. Callers must persist the
 * returned audit snapshot on the void activity record.
 */
final class CleanupAccommodationForVoid
{
    /**
     * @param  list<int>  $assignmentIds
     * @return array{
     *     records_deleted: int,
     *     snapshots_by_assignment: array<int, list<array{
     *         id: int,
     *         stay_type: string|null,
     *         accommodation_status: string|null,
     *         hotel_id: int|null,
     *         hotel_name: string|null,
     *         room_type_id: int|null,
     *         room_type_name: string|null,
     *         check_in_date: string|null,
     *         check_out_date: string|null,
     *         was_open: bool
     *     }>>,
     *     counts_by_assignment: array<int, int>
     * }
     */
    public function handle(int $companyId, array $assignmentIds): array
    {
        $assignmentIds = array_values(array_unique(array_map('intval', $assignmentIds)));

        if ($assignmentIds === []) {
            return [
                'records_deleted' => 0,
                'snapshots_by_assignment' => [],
                'counts_by_assignment' => [],
            ];
        }

        /** @var Collection<int, CrewAccommodationStay> $stays */
        $stays = CrewAccommodationStay::query()
            ->where('company_id', $companyId)
            ->whereIn('crew_assignment_id', $assignmentIds)
            ->with(['hotel:id,name', 'roomType:id,name'])
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $snapshotsByAssignment = [];
        $countsByAssignment = [];

        foreach ($stays as $stay) {
            $assignmentId = (int) $stay->crew_assignment_id;
            $snapshotsByAssignment[$assignmentId] ??= [];
            $countsByAssignment[$assignmentId] = ($countsByAssignment[$assignmentId] ?? 0) + 1;

            $snapshotsByAssignment[$assignmentId][] = [
                'id' => (int) $stay->id,
                'stay_type' => $stay->stay_type?->value,
                'accommodation_status' => $stay->accommodation_status?->value,
                'hotel_id' => $stay->hotel_id !== null ? (int) $stay->hotel_id : null,
                'hotel_name' => $stay->hotel?->name,
                'room_type_id' => $stay->room_type_id !== null ? (int) $stay->room_type_id : null,
                'room_type_name' => $stay->roomType?->name,
                'check_in_date' => $stay->check_in_date?->toDateString(),
                'check_out_date' => $stay->check_out_date?->toDateString(),
                'was_open' => $stay->check_out_date === null
                    && $stay->accommodation_status?->value === 'hotel',
            ];

            $stay->delete();
        }

        return [
            'records_deleted' => $stays->count(),
            'snapshots_by_assignment' => $snapshotsByAssignment,
            'counts_by_assignment' => $countsByAssignment,
        ];
    }
}
