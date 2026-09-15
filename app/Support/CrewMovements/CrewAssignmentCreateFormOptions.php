<?php

namespace App\Support\CrewMovements;

use App\Models\Client;
use App\Models\Course;
use App\Models\Employee;
use App\Models\Rank;
use App\Models\User;
use App\Support\Settings\CompanyTimezone;
use App\Support\Vessels\ResolvesCompanyVessels;

final class CrewAssignmentCreateFormOptions
{
    /**
     * Shared Start Assignment / Bulk Add Crew form options.
     *
     * Restricted assignment details stay hidden without assignments.view.
     *
     * @return array{
     *     employees: list<array{id: int, name: string, employee_no: string|null, rank_id: int|null}>,
     *     active_on_vessel_by_employee: array<int, array<string, mixed>>,
     *     employee_status_by_employee: array<int, array<string, mixed>>,
     *     ranks: list<array{id: int, name: string}>,
     *     vessels: list<array{id: int, name: string, client_id: int|null, is_active: bool}>,
     *     clients: list<array{id: int, name: string}>,
     *     courses: list<array{id: int, name: string}>,
     *     company_timezone: string
     * }
     */
    public static function for(int $companyId, ?User $user): array
    {
        $canView = $user?->can('crew_operations.assignments.view') ?? false;
        $canTransfer = CrewAssignmentPagePermissions::canTransfer($user);

        $employeeModels = Employee::query()
            ->where('company_id', $companyId)
            ->active()
            ->orderBy('name')
            ->get(['id', 'name', 'employee_no', 'rank_id']);

        $employeeIds = $employeeModels->pluck('id')->map(fn ($id) => (int) $id)->all();

        $activeOnVessel = [];

        if ($canView) {
            foreach (app(ActiveOnVesselAssignmentFinder::class)->forCompany($companyId, $employeeIds) as $employeeId => $current) {
                $activeOnVessel[(int) $employeeId] = [
                    ...$current,
                    'can_transfer' => $canTransfer,
                ];
            }
        }

        return [
            'employees' => $employeeModels
                ->map(fn (Employee $employee) => [
                    'id' => $employee->id,
                    'name' => $employee->name,
                    'employee_no' => $employee->employee_no,
                    'rank_id' => $employee->rank_id,
                ])
                ->values()
                ->all(),
            'active_on_vessel_by_employee' => $activeOnVessel,
            'employee_status_by_employee' => app(CrewAssignmentStatusResolver::class)
                ->forEmployeeIds($companyId, $employeeIds, includeRestrictedFields: $canView, today: null),
            'ranks' => self::activeRanks(),
            'vessels' => self::activeVessels($companyId),
            'clients' => self::activeClients(),
            'courses' => self::activeCourses(),
            'company_timezone' => CompanyTimezone::forCompanyId($companyId),
        ];
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    private static function activeRanks(): array
    {
        return Rank::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Rank $rank) => ['id' => $rank->id, 'name' => $rank->name])
            ->values()
            ->all();
    }

    /**
     * @return list<array{id: int, name: string, client_id: int|null, is_active: bool}>
     */
    private static function activeVessels(int $companyId): array
    {
        return array_map(
            static fn (array $option): array => [...$option, 'is_active' => true],
            ResolvesCompanyVessels::activeOptions($companyId, requireAssignedClient: true),
        );
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    private static function activeClients(): array
    {
        return Client::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Client $client) => ['id' => $client->id, 'name' => $client->name])
            ->values()
            ->all();
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    private static function activeCourses(): array
    {
        return Course::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Course $course) => ['id' => $course->id, 'name' => $course->name])
            ->values()
            ->all();
    }
}
