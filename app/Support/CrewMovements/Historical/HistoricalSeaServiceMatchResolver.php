<?php

namespace App\Support\CrewMovements\Historical;

use App\Models\EmployeeSeaService;
use App\Models\Rank;
use Illuminate\Support\Collection;

/**
 * Resolves exact Employee/Vessel/date Sea Service matches for historical P4 linking.
 */
final class HistoricalSeaServiceMatchResolver
{
    /**
     * @param  Collection<int, EmployeeSeaService>|null  $existingForEmployee
     * @return array{
     *     status: 'will_create'|'will_link'|'conflict',
     *     existing_id: ?int,
     *     message: string,
     *     error: ?string
     * }
     */
    public function resolveExactMatch(
        HistoricalCrewAssignmentData $data,
        string $seaStartDate,
        string $seaEndDate,
        int $seaDays,
        ?string $proposedRankName = null,
        ?Collection $existingForEmployee = null,
        bool $lockForUpdate = false,
    ): array {
        $query = EmployeeSeaService::query()
            ->where('company_id', $data->companyId)
            ->where('employee_id', $data->employeeId)
            ->where('vessel_id', $data->vesselId)
            ->whereDate('start_date', $seaStartDate)
            ->whereDate('end_date', $seaEndDate);

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        $exactMatches = $existingForEmployee !== null
            ? $existingForEmployee
                ->filter(function (EmployeeSeaService $record) use ($data, $seaStartDate, $seaEndDate): bool {
                    $recordStart = $record->start_date?->toDateString();
                    $recordEnd = $record->end_date?->toDateString();

                    return (int) $record->vessel_id === $data->vesselId
                        && $recordStart === $seaStartDate
                        && $recordEnd === $seaEndDate;
                })
                ->values()
            : $query->get();

        if ($exactMatches->isEmpty()) {
            return [
                'status' => 'will_create',
                'existing_id' => null,
                'message' => "{$seaDays} days will be recorded/synchronized to Sea Service.",
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
            'message' => "Matches existing unlinked Sea Service record #{$match->id} ({$seaDays} days) and will link safely without duplicating.",
            'error' => null,
        ];
    }
}
