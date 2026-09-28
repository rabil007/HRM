<?php

namespace App\Support\CrewMovements\Historical;

use App\Models\EmployeeSeaService;
use App\Models\Rank;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Resolves exact Employee/Vessel/date Sea Service matches for historical P4 linking
 * and detects overlapping completed/open Sea Service intervals.
 *
 * An existing Sea Service with end_date = null is treated as an open-ended interval.
 */
final class HistoricalSeaServiceMatchResolver
{
    /**
     * @param  Collection<int, EmployeeSeaService>|null  $existingForEmployee
     * @return array{
     *     status: 'will_create'|'will_create_ongoing'|'will_link'|'conflict',
     *     existing_id: ?int,
     *     message: string,
     *     error: ?string
     * }
     */
    public function resolveExactMatch(
        HistoricalCrewAssignmentData $data,
        string $seaStartDate,
        ?string $seaEndDate,
        int $seaDays,
        ?string $proposedRankName = null,
        ?Collection $existingForEmployee = null,
        bool $lockForUpdate = false,
    ): array {
        $isOngoing = $seaEndDate === null;

        $query = EmployeeSeaService::query()
            ->where('company_id', $data->companyId)
            ->where('employee_id', $data->employeeId)
            ->where('vessel_id', $data->vesselId)
            ->whereDate('start_date', $seaStartDate);

        if ($isOngoing) {
            $query->whereNull('end_date');
        } else {
            $query->whereDate('end_date', $seaEndDate);
        }

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        $exactMatches = $existingForEmployee !== null
            ? $existingForEmployee
                ->filter(function (EmployeeSeaService $record) use ($data, $seaStartDate, $seaEndDate, $isOngoing): bool {
                    $recordStart = $record->start_date?->toDateString();
                    $recordEnd = $record->end_date?->toDateString();

                    if ((int) $record->vessel_id !== $data->vesselId || $recordStart !== $seaStartDate) {
                        return false;
                    }

                    return $isOngoing
                        ? $recordEnd === null
                        : $recordEnd === $seaEndDate;
                })
                ->values()
            : $query->get();

        if ($exactMatches->isEmpty()) {
            return [
                'status' => $isOngoing ? 'will_create_ongoing' : 'will_create',
                'existing_id' => null,
                'message' => $isOngoing
                    ? 'An ongoing Sea Service record will be synchronized from this Onsite period.'
                    : "{$seaDays} days will be recorded/synchronized to Sea Service.",
                'error' => null,
            ];
        }

        $linked = $exactMatches->filter(fn (EmployeeSeaService $record): bool => $record->crew_assignment_phase_id !== null);

        if ($linked->isNotEmpty()) {
            $ids = $linked->pluck('id')->map(fn ($id): string => '#'.$id)->implode(', ');
            $error = $linked->count() === 1
                ? "Matches existing Sea Service record {$ids} which is already linked to another assignment phase."
                : "Multiple Sea Service records match this Employee, Vessel and service period and are already linked ({$ids}).";

            return [
                'status' => 'conflict',
                'existing_id' => null,
                'message' => $error,
                'error' => $error,
            ];
        }

        foreach ($exactMatches as $record) {
            if ($record->rank_id !== null && (int) $record->rank_id !== $data->rankId) {
                $existingRankName = Rank::query()->find($record->rank_id)?->name ?? '#'.$record->rank_id;
                $proposed = $proposedRankName ?? '#'.$data->rankId;
                $error = "Matches existing Sea Service record #{$record->id} with conflicting rank ({$existingRankName} vs {$proposed}). Cannot automatically overwrite HR history.";

                return [
                    'status' => 'conflict',
                    'existing_id' => null,
                    'message' => $error,
                    'error' => $error,
                ];
            }

            if ($record->client_id !== null && $data->clientId !== null && (int) $record->client_id !== $data->clientId) {
                $error = "Matches existing Sea Service record #{$record->id} with conflicting client.";

                return [
                    'status' => 'conflict',
                    'existing_id' => null,
                    'message' => $error,
                    'error' => $error,
                ];
            }
        }

        if ($exactMatches->count() > 1) {
            $ids = $exactMatches->pluck('id')->map(fn ($id): string => '#'.$id)->implode(', ');
            $error = "Multiple Sea Service records match this Employee, Vessel and service period. Matching records: {$ids}. Resolve the duplicate Sea Service records before adding this historical assignment.";

            return [
                'status' => 'conflict',
                'existing_id' => null,
                'message' => $error,
                'error' => $error,
            ];
        }

        /** @var EmployeeSeaService $match */
        $match = $exactMatches->first();

        return [
            'status' => 'will_link',
            'existing_id' => (int) $match->id,
            'message' => $isOngoing
                ? "Matches existing unlinked ongoing Sea Service record #{$match->id} and will link safely without duplicating."
                : "Matches existing unlinked Sea Service record #{$match->id} ({$seaDays} days) and will link safely without duplicating.",
            'error' => null,
        ];
    }

    /**
     * Find an overlapping Sea Service that is not the exact match being linked.
     *
     * Open-ended records (end_date null) are treated as extending indefinitely.
     *
     * @param  Collection<int, EmployeeSeaService>  $existingForEmployee
     */
    public function firstOverlappingConflictMessage(
        Collection $existingForEmployee,
        HistoricalCrewAssignmentData $data,
        string $seaStartDate,
        ?string $seaEndDate,
        ?int $exactMatchIdToIgnore = null,
    ): ?string {
        $proposedEndCmp = $seaEndDate ?? '9999-12-31';

        foreach ($existingForEmployee as $record) {
            if ($exactMatchIdToIgnore !== null && (int) $record->id === $exactMatchIdToIgnore) {
                continue;
            }

            $recordStart = $record->start_date?->toDateString();
            $recordEnd = $record->end_date?->toDateString();

            if ($recordStart === null) {
                continue;
            }

            // Skip the exact same interval — that is handled by resolveExactMatch linking.
            if ((int) $record->vessel_id === $data->vesselId
                && $recordStart === $seaStartDate
                && $recordEnd === $seaEndDate) {
                continue;
            }

            $recordEndCmp = $recordEnd ?? '9999-12-31';

            if ($recordStart <= $proposedEndCmp && $seaStartDate <= $recordEndCmp) {
                $conflictVesselName = $record->vessel?->name ?? 'another vessel';
                $recStartFormatted = Carbon::parse($recordStart)->format('d M Y');
                $recEndFormatted = $recordEnd !== null
                    ? Carbon::parse($recordEnd)->format('d M Y')
                    : 'Current';

                return sprintf(
                    'Overlaps existing Sea Service record #%d (%s, %s -> %s).',
                    $record->id,
                    $conflictVesselName,
                    $recStartFormatted,
                    $recEndFormatted,
                );
            }
        }

        return null;
    }
}
