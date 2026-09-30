<?php

namespace App\Support\VesselManning;

use App\Models\Company;
use App\Models\Vessel;
use App\Models\VesselManning;
use App\Support\Positions\RankPositionBridge;
use Illuminate\Support\Collection;

final class SyncVesselManning
{
    /**
     * @param  list<array{position_id: int, required_count: int}>  $requirements
     */
    public static function sync(Company $company, Vessel $vessel, array $requirements): void
    {
        $companyId = (int) $company->id;
        $vesselId = (int) $vessel->id;

        abort_unless((int) $vessel->company_id === $companyId, 404);

        $normalized = RankPositionBridge::normalizeManningRequirements($companyId, $requirements);

        /** @var Collection<int, array{position_id: int, rank_id: int|null, required_count: int}> $incoming */
        $incoming = collect($normalized)->keyBy('position_id');

        $existing = VesselManning::query()
            ->withTrashed()
            ->where('company_id', $companyId)
            ->where('vessel_id', $vesselId)
            ->get()
            ->keyBy(fn (VesselManning $line): int => (int) (
                RankPositionBridge::resolvedPositionId($companyId, $line->position_id, $line->rank_id) ?? 0
            ))
            ->filter(fn (VesselManning $line, int $positionId): bool => $positionId > 0);

        foreach ($incoming as $positionId => $row) {
            $rankId = $row['rank_id'];

            if ($rankId === null) {
                continue;
            }

            $record = $existing->get((int) $positionId);

            if ($record instanceof VesselManning) {
                if ($record->trashed()) {
                    $record->restore();
                }

                $record->update([
                    'position_id' => (int) $positionId,
                    'rank_id' => $rankId,
                    'required_count' => $row['required_count'],
                ]);

                continue;
            }

            VesselManning::query()->create([
                'company_id' => $companyId,
                'vessel_id' => $vesselId,
                'position_id' => (int) $positionId,
                'rank_id' => $rankId,
                'required_count' => $row['required_count'],
            ]);
        }

        $incomingPositionIds = $incoming->keys()->map(fn ($id): int => (int) $id)->all();

        VesselManning::query()
            ->where('company_id', $companyId)
            ->where('vessel_id', $vesselId)
            ->get()
            ->each(function (VesselManning $line) use ($companyId, $incomingPositionIds): void {
                $resolved = RankPositionBridge::resolvedPositionId(
                    $companyId,
                    $line->position_id !== null ? (int) $line->position_id : null,
                    $line->rank_id !== null ? (int) $line->rank_id : null,
                );

                if ($resolved === null || ! in_array($resolved, $incomingPositionIds, true)) {
                    $line->delete();
                }
            });
    }
}
