<?php

namespace App\Support\CrewMovements;

use App\Models\CrewAssignment;
use App\Models\Vessel;
use App\Support\Vessels\ResolvesCompanyVessels;

/**
 * Snapshot-aware Client/Vessel filter options from stored CrewAssignment pairs,
 * merged with current assigned vessels so unused vessels remain selectable.
 */
final class CrewAssignmentSnapshotFilterOptions
{
    /**
     * Vessels for company filters.
     *
     * `client_ids` lists Clients that historically appear with the Vessel on
     * CrewAssignments, plus the Vessel's current Client when assigned.
     *
     * @return list<array{id: int, name: string, client_id: int|null, client_ids: list<int>}>
     */
    public static function vessels(int $companyId): array
    {
        $clientIdsByVessel = [];

        $pairs = CrewAssignment::query()
            ->where('company_id', $companyId)
            ->whereNotNull('vessel_id')
            ->select('vessel_id', 'client_id')
            ->distinct()
            ->get();

        foreach ($pairs as $pair) {
            $vesselId = (int) $pair->vessel_id;

            if (! isset($clientIdsByVessel[$vesselId])) {
                $clientIdsByVessel[$vesselId] = [];
            }

            if ($pair->client_id !== null) {
                $clientIdsByVessel[$vesselId][(int) $pair->client_id] = true;
            }
        }

        $active = ResolvesCompanyVessels::activeOptions($companyId, requireAssignedClient: true);
        $vesselIds = collect($active)->pluck('id')
            ->merge(array_keys($clientIdsByVessel))
            ->unique()
            ->values()
            ->all();

        if ($vesselIds === []) {
            return [];
        }

        $vessels = Vessel::query()
            ->where('company_id', $companyId)
            ->whereIn('id', $vesselIds)
            ->orderBy('name')
            ->get(['id', 'name', 'client_id']);

        $options = [];

        foreach ($vessels as $vessel) {
            $ids = $clientIdsByVessel[(int) $vessel->id] ?? [];

            if ($vessel->client_id !== null) {
                $ids[(int) $vessel->client_id] = true;
            }

            $clientIds = array_map('intval', array_keys($ids));
            sort($clientIds);

            $options[] = [
                'id' => (int) $vessel->id,
                'name' => (string) $vessel->name,
                'client_id' => $vessel->client_id !== null ? (int) $vessel->client_id : null,
                'client_ids' => $clientIds,
            ];
        }

        return $options;
    }
}
