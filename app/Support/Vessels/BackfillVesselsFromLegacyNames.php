<?php

namespace App\Support\Vessels;

use App\Models\EmployeeSeaService;
use App\Models\Vessel;
use App\Models\VesselType;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class BackfillVesselsFromLegacyNames
{
    /**
     * @return array{
     *     distinct_names: int,
     *     vessels_to_create: int,
     *     sea_services_linked: int,
     *     sea_services_unlinked: int,
     *     deployments_linked: int,
     *     type_conflicts: list<string>,
     *     grt_conflicts: list<string>,
     *     bhp_conflicts: list<string>,
     * }
     */
    public function preview(): array
    {
        return $this->run(dryRun: true);
    }

    /**
     * @return array{
     *     distinct_names: int,
     *     vessels_to_create: int,
     *     sea_services_linked: int,
     *     sea_services_unlinked: int,
     *     deployments_linked: int,
     *     type_conflicts: list<string>,
     *     grt_conflicts: list<string>,
     *     bhp_conflicts: list<string>,
     * }
     */
    public function execute(): array
    {
        return $this->run(dryRun: false);
    }

    /**
     * @return array{
     *     distinct_names: int,
     *     vessels_to_create: int,
     *     sea_services_linked: int,
     *     sea_services_unlinked: int,
     *     deployments_linked: int,
     *     type_conflicts: list<string>,
     *     grt_conflicts: list<string>,
     *     bhp_conflicts: list<string>,
     * }
     */
    private function run(bool $dryRun): array
    {
        if (! $this->legacyColumnsExist()) {
            return [
                'distinct_names' => 0,
                'vessels_to_create' => 0,
                'sea_services_linked' => 0,
                'sea_services_unlinked' => 0,
                'deployments_linked' => 0,
                'type_conflicts' => [],
                'grt_conflicts' => [],
                'bhp_conflicts' => [],
            ];
        }

        $seaServiceRows = DB::table('employee_sea_services')
            ->select(['id', 'employee_id', 'vessel_name', 'vessel_type_id', 'grt', 'bhp', 'end_date'])
            ->whereNotNull('vessel_name')
            ->where('vessel_name', '!=', '')
            ->orderByDesc('end_date')
            ->orderByDesc('id')
            ->get();

        $grouped = $seaServiceRows->groupBy(fn ($row) => Vessel::normalizeName((string) $row->vessel_name));

        $fallbackTypeId = VesselType::query()->where('is_active', true)->orderBy('id')->value('id');

        $typeConflicts = [];
        $grtConflicts = [];
        $bhpConflicts = [];
        $vesselIdByNormalizedName = [];
        $vesselsToCreate = 0;

        foreach ($grouped as $normalizedName => $rows) {
            if ($normalizedName === '') {
                continue;
            }

            /** @var Collection<int, object> $rows */
            $canonicalName = trim((string) $rows->first()->vessel_name);
            $typeId = $this->resolveMostFrequentTypeId($rows, $canonicalName, $typeConflicts);
            $grt = $this->resolveMostRecentDecimal($rows, 'grt', $canonicalName, $grtConflicts);
            $bhp = $this->resolveMostRecentInteger($rows, 'bhp', $canonicalName, $bhpConflicts);

            if ($typeId === null) {
                $typeId = $fallbackTypeId;
            }

            if ($typeId === null) {
                continue;
            }

            $existing = $this->findVesselByNormalizedName($normalizedName);

            if ($existing !== null) {
                $vesselIdByNormalizedName[$normalizedName] = $existing->id;

                continue;
            }

            $vesselsToCreate++;

            if ($dryRun) {
                $vesselIdByNormalizedName[$normalizedName] = -1;

                continue;
            }

            $vessel = Vessel::query()->create([
                'name' => $canonicalName,
                'vessel_type_id' => $typeId,
                'grt' => $grt,
                'bhp' => $bhp,
                'is_active' => true,
            ]);

            $vesselIdByNormalizedName[$normalizedName] = $vessel->id;
        }

        $seaServicesLinked = 0;
        $seaServicesUnlinked = 0;

        foreach ($seaServiceRows as $row) {
            $normalizedName = Vessel::normalizeName((string) $row->vessel_name);

            if ($normalizedName === '' || ! isset($vesselIdByNormalizedName[$normalizedName])) {
                $seaServicesUnlinked++;

                continue;
            }

            $seaServicesLinked++;

            if (! $dryRun) {
                DB::table('employee_sea_services')
                    ->where('id', $row->id)
                    ->update(['vessel_id' => $vesselIdByNormalizedName[$normalizedName]]);
            }
        }

        $deploymentsLinked = 0;
        $deploymentRows = DB::table('employee_deployments')
            ->select(['id', 'employee_id', 'vessel_name'])
            ->whereNotNull('vessel_name')
            ->where('vessel_name', '!=', '')
            ->get();

        foreach ($deploymentRows as $row) {
            $normalizedName = Vessel::normalizeName((string) $row->vessel_name);

            if ($normalizedName === '') {
                continue;
            }

            if (! isset($vesselIdByNormalizedName[$normalizedName])) {
                $canonicalName = trim((string) $row->vessel_name);
                $existing = $this->findVesselByNormalizedName($normalizedName);

                if ($existing !== null) {
                    $vesselIdByNormalizedName[$normalizedName] = $existing->id;
                } else {
                    $context = $this->resolveDeploymentContext((int) $row->employee_id, $canonicalName);
                    $typeId = $context['vessel_type_id'] ?? $fallbackTypeId;

                    if ($typeId === null) {
                        continue;
                    }

                    if ($dryRun) {
                        $vesselsToCreate++;
                        $vesselIdByNormalizedName[$normalizedName] = -1;
                    } else {
                        $vessel = Vessel::query()->create([
                            'name' => $canonicalName,
                            'vessel_type_id' => $typeId,
                            'grt' => $context['grt'],
                            'bhp' => $context['bhp'],
                            'is_active' => true,
                        ]);
                        $vesselIdByNormalizedName[$normalizedName] = $vessel->id;
                    }
                }
            }

            $deploymentsLinked++;

            if (! $dryRun && isset($vesselIdByNormalizedName[$normalizedName]) && $vesselIdByNormalizedName[$normalizedName] > 0) {
                DB::table('employee_deployments')
                    ->where('id', $row->id)
                    ->update(['vessel_id' => $vesselIdByNormalizedName[$normalizedName]]);
            }
        }

        return [
            'distinct_names' => $grouped->count(),
            'vessels_to_create' => $vesselsToCreate,
            'sea_services_linked' => $seaServicesLinked,
            'sea_services_unlinked' => $seaServicesUnlinked,
            'deployments_linked' => $deploymentsLinked,
            'type_conflicts' => $typeConflicts,
            'grt_conflicts' => $grtConflicts,
            'bhp_conflicts' => $bhpConflicts,
        ];
    }

    private function legacyColumnsExist(): bool
    {
        return DB::getSchemaBuilder()->hasColumn('employee_sea_services', 'vessel_name');
    }

    private function findVesselByNormalizedName(string $normalizedName): ?Vessel
    {
        return Vessel::query()
            ->whereRaw('LOWER(TRIM(name)) = ?', [$normalizedName])
            ->first();
    }

    /**
     * @param  Collection<int, object>  $rows
     */
    private function resolveMostFrequentTypeId(Collection $rows, string $canonicalName, array &$conflicts): ?int
    {
        $counts = $rows
            ->filter(fn ($row) => $row->vessel_type_id !== null)
            ->groupBy('vessel_type_id')
            ->map->count()
            ->sortDesc();

        if ($counts->isEmpty()) {
            return null;
        }

        if ($counts->keys()->count() > 1) {
            $parts = $counts->map(fn (int $count, $typeId) => "type {$typeId} ({$count} rows)")->values()->all();
            $conflicts[] = "\"{$canonicalName}\" used with ".implode(' and ', $parts).' — will use type '.$counts->keys()->first();
        }

        return (int) $counts->keys()->first();
    }

    /**
     * @param  Collection<int, object>  $rows
     */
    private function resolveMostRecentDecimal(Collection $rows, string $column, string $canonicalName, array &$conflicts): ?float
    {
        $values = $rows
            ->filter(fn ($row) => $row->{$column} !== null && $row->{$column} !== '')
            ->pluck($column)
            ->unique()
            ->values();

        if ($values->isEmpty()) {
            return null;
        }

        if ($values->count() > 1) {
            $conflicts[] = "\"{$canonicalName}\" has multiple {$column} values (".implode(', ', $values->map(fn ($v) => (string) $v)->all()).') — will use most recent';
        }

        $recent = $rows->first(fn ($row) => $row->{$column} !== null && $row->{$column} !== '');

        return $recent !== null ? (float) $recent->{$column} : null;
    }

    /**
     * @param  Collection<int, object>  $rows
     */
    private function resolveMostRecentInteger(Collection $rows, string $column, string $canonicalName, array &$conflicts): ?int
    {
        $values = $rows
            ->filter(fn ($row) => $row->{$column} !== null && $row->{$column} !== '')
            ->pluck($column)
            ->unique()
            ->values();

        if ($values->isEmpty()) {
            return null;
        }

        if ($values->count() > 1) {
            $conflicts[] = "\"{$canonicalName}\" has multiple {$column} values (".implode(', ', $values->map(fn ($v) => (string) $v)->all()).') — will use most recent';
        }

        $recent = $rows->first(fn ($row) => $row->{$column} !== null && $row->{$column} !== '');

        return $recent !== null ? (int) $recent->{$column} : null;
    }

    /**
     * @return array{vessel_type_id: int|null, grt: float|null, bhp: int|null}
     */
    private function resolveDeploymentContext(int $employeeId, string $vesselName): array
    {
        if (! DB::getSchemaBuilder()->hasColumn('employee_sea_services', 'vessel_name')) {
            $seaService = EmployeeSeaService::query()
                ->where('employee_id', $employeeId)
                ->with('vessel:id,vessel_type_id,grt,bhp')
                ->orderByDesc('end_date')
                ->orderByDesc('id')
                ->first();

            return [
                'vessel_type_id' => $seaService?->vessel_type_id ?? $seaService?->vessel?->vessel_type_id,
                'grt' => $seaService?->vessel?->grt !== null ? (float) $seaService->vessel->grt : null,
                'bhp' => $seaService?->vessel?->bhp,
            ];
        }

        $normalized = Vessel::normalizeName($vesselName);

        $seaService = DB::table('employee_sea_services')
            ->select(['vessel_name', 'vessel_type_id', 'grt', 'bhp', 'end_date', 'id'])
            ->where('employee_id', $employeeId)
            ->whereNotNull('vessel_name')
            ->where('vessel_name', '!=', '')
            ->orderByDesc('end_date')
            ->orderByDesc('id')
            ->get()
            ->first(fn ($row) => Vessel::normalizeName((string) $row->vessel_name) === $normalized);

        if ($seaService === null) {
            $seaService = DB::table('employee_sea_services')
                ->select(['vessel_type_id', 'grt', 'bhp'])
                ->where('employee_id', $employeeId)
                ->orderByDesc('end_date')
                ->orderByDesc('id')
                ->first();
        }

        return [
            'vessel_type_id' => $seaService?->vessel_type_id !== null ? (int) $seaService->vessel_type_id : null,
            'grt' => $seaService?->grt !== null ? (float) $seaService->grt : null,
            'bhp' => $seaService?->bhp !== null ? (int) $seaService->bhp : null,
        ];
    }
}
