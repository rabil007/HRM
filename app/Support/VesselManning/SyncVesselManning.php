<?php

namespace App\Support\VesselManning;

use App\Models\Company;
use App\Models\Vessel;
use App\Models\VesselManning;
use Illuminate\Support\Collection;

final class SyncVesselManning
{
    /**
     * @param  list<array{rank_id: int, required_count: int}>  $requirements
     */
    public static function sync(Company $company, Vessel $vessel, array $requirements): void
    {
        $companyId = (int) $company->id;
        $vesselId = (int) $vessel->id;

        /** @var Collection<int, array{rank_id: int, required_count: int}> $incoming */
        $incoming = collect($requirements)
            ->map(fn (array $row) => [
                'rank_id' => (int) $row['rank_id'],
                'required_count' => (int) $row['required_count'],
            ])
            ->keyBy('rank_id');

        $existing = VesselManning::query()
            ->withTrashed()
            ->where('company_id', $companyId)
            ->where('vessel_id', $vesselId)
            ->get()
            ->keyBy('rank_id');

        foreach ($incoming as $rankId => $row) {
            $record = $existing->get($rankId);

            if ($record instanceof VesselManning) {
                if ($record->trashed()) {
                    $record->restore();
                }

                $record->update(['required_count' => $row['required_count']]);

                continue;
            }

            VesselManning::query()->create([
                'company_id' => $companyId,
                'vessel_id' => $vesselId,
                'rank_id' => $rankId,
                'required_count' => $row['required_count'],
            ]);
        }

        $incomingRankIds = $incoming->keys()->all();

        VesselManning::query()
            ->where('company_id', $companyId)
            ->where('vessel_id', $vesselId)
            ->when($incomingRankIds !== [], fn ($query) => $query->whereNotIn('rank_id', $incomingRankIds))
            ->delete();
    }
}
