<?php

namespace App\Support\CrewMovements\Historical;

use App\Models\Client;
use App\Models\CrewAssignment;
use App\Models\Employee;
use App\Models\EmployeeSeaService;
use App\Models\Rank;
use App\Models\Vessel;
use Illuminate\Support\Collection;

/**
 * Preloaded master-data / history for bulk historical workbook validation.
 * Authoritative writes must still revalidate under locks without relying solely on this cache.
 */
final class HistoricalCrewBulkValidationContext
{
    /**
     * @param  array<int, Employee>  $employeesById
     * @param  array<int, Vessel>  $vesselsById
     * @param  array<int, Rank>  $ranksById
     * @param  array<int, Client>  $clientsById
     * @param  array<int, Collection<int, CrewAssignment>>  $assignmentsByEmployeeId
     * @param  array<int, Collection<int, EmployeeSeaService>>  $seaServicesByEmployeeId
     */
    public function __construct(
        public readonly array $employeesById,
        public readonly array $vesselsById,
        public readonly array $ranksById,
        public readonly array $clientsById,
        public readonly array $assignmentsByEmployeeId,
        public readonly array $seaServicesByEmployeeId,
        public readonly bool $seaServiceSyncEnabled,
    ) {}

    public function employee(int $id): ?Employee
    {
        return $this->employeesById[$id] ?? null;
    }

    public function vessel(int $id): ?Vessel
    {
        return $this->vesselsById[$id] ?? null;
    }

    public function rank(int $id): ?Rank
    {
        return $this->ranksById[$id] ?? null;
    }

    public function client(int $id): ?Client
    {
        return $this->clientsById[$id] ?? null;
    }

    /**
     * @return Collection<int, CrewAssignment>
     */
    public function assignmentsForEmployee(int $employeeId): Collection
    {
        return $this->assignmentsByEmployeeId[$employeeId] ?? collect();
    }

    /**
     * @return Collection<int, EmployeeSeaService>
     */
    public function seaServicesForEmployee(int $employeeId): Collection
    {
        return $this->seaServicesByEmployeeId[$employeeId] ?? collect();
    }
}
