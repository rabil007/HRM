<?php

namespace App\Support\VesselManning;

use App\Models\Company;
use App\Models\Vessel;
use App\Models\VesselManning;

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

        /** @var array<int, array{position_id: int, required_count: int}> $incoming */
        $incoming = [];
        foreach ($requirements as $row) {
            $positionId = (int) ($row['position_id'] ?? 0);
            if ($positionId > 0) {
                $incoming[$positionId] = [
                    'position_id' => $positionId,
                    'required_count' => (int) ($row['required_count'] ?? 0),
                ];
            }
        }

        $existing = VesselManning::query()
            ->withTrashed()
            ->where('company_id', $companyId)
            ->where('vessel_id', $vesselId)
            ->whereNotNull('position_id')
            ->get()
            ->keyBy(fn (VesselManning $line): int => (int) $line->position_id);

        foreach ($incoming as $positionId => $row) {
            $record = $existing->get($positionId);

            if ($record instanceof VesselManning) {
                if ($record->trashed()) {
                    $record->restore();
                }

                $record->update([
                    'position_id' => $positionId,
                    'required_count' => $row['required_count'],
                ]);

                continue;
            }

            VesselManning::query()->create([
                'company_id' => $companyId,
                'vessel_id' => $vesselId,
                'position_id' => $positionId,
                'required_count' => $row['required_count'],
            ]);
        }

        $incomingPositionIds = array_keys($incoming);

        VesselManning::query()
            ->where('company_id', $companyId)
            ->where('vessel_id', $vesselId)
            ->get()
            ->each(function (VesselManning $line) use ($incomingPositionIds): void {
                $positionId = $line->position_id !== null ? (int) $line->position_id : null;

                if ($positionId === null || ! in_array($positionId, $incomingPositionIds, true)) {
                    $line->delete();
                }
            });
    }
}
