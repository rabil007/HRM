<?php

use App\Enums\CrewAssignmentStatus;
use App\Enums\CrewPhaseCode;
use App\Enums\CrewPhaseStatus;
use App\Models\Client;
use App\Models\Company;
use App\Models\Country;
use App\Models\CrewAssignment;
use App\Models\CrewAssignmentPhase;
use App\Models\CrewPlanningAssignment;
use App\Models\Currency;
use App\Models\Employee;
use App\Models\Rank;
use App\Models\User;
use App\Models\Vessel;
use App\Models\VesselType;
use App\Support\CrewMovements\CrewMovementService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * @return array{user: User, company: Company, employee: Employee, rank: Rank}
 */
function makeCrewAssignmentFixtures(): array
{
    $user = User::factory()->create();

    $country = Country::query()->create([
        'code' => 'CA'.fake()->unique()->numerify('##'),
        'name' => 'Crew Assignment Land',
        'dial_code' => '+001',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'CA'.fake()->unique()->numerify('##'),
        'name' => 'Crew Assignment Currency',
        'symbol' => 'C$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Crew Assignment Co',
        'slug' => 'crew-assignment-'.Str::lower(Str::random(6)),
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    DB::table('company_user')->insert([
        'company_id' => $company->id,
        'user_id' => $user->id,
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $rank = Rank::query()->create([
        'name' => 'CA Rank '.Str::uuid()->toString(),
        'is_active' => true,
    ]);

    $employee = Employee::factory()
        ->forCompany($company)
        ->create([
            'rank_id' => $rank->id,
            'status' => 'active',
        ]);

    return compact('user', 'company', 'employee', 'rank');
}

function makeCrewMovementVessel(string $name, ?Company $company = null, ?Client $client = null): Vessel
{
    $companyId = $company?->id ?? Company::query()->value('id');

    if ($companyId === null) {
        throw new RuntimeException('makeCrewMovementVessel requires an existing company.');
    }

    $clientId = $client?->id ?? Client::query()->create([
        'name' => 'CM Client '.Str::uuid()->toString(),
        'is_active' => true,
    ])->id;

    return Vessel::query()->create([
        'company_id' => $companyId,
        'name' => $name.' '.Str::uuid()->toString(),
        'vessel_type_id' => VesselType::query()->create([
            'name' => 'CM VT '.Str::uuid()->toString(),
            'is_active' => true,
        ])->id,
        'client_id' => $clientId,
        'is_active' => true,
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function makeActiveOnVesselAssignment(
    Company $company,
    Employee $employee,
    Rank $rank,
    Vessel $vessel,
    array $overrides = [],
): CrewAssignment {
    $started = CarbonImmutable::parse('2026-01-01 08:00:00', $company->timezone ?? 'UTC');

    $assignment = CrewAssignment::query()->create(array_merge([
        'company_id' => $company->id,
        'assignment_no' => 'CA-2026-'.Str::upper(Str::random(6)),
        'employee_id' => $employee->id,
        'rank_id' => $rank->id,
        'vessel_id' => $vessel->id,
        'status' => CrewAssignmentStatus::Active,
        'started_at' => $started,
        'source' => 'manual',
    ], $overrides));

    $phase = CrewAssignmentPhase::query()->create([
        'company_id' => $company->id,
        'crew_assignment_id' => $assignment->id,
        'phase_code' => CrewPhaseCode::OnVessel,
        'sequence' => 1,
        'status' => CrewPhaseStatus::Active,
        'actual_start_at' => $started->addDays(2),
    ]);

    $assignment->update(['current_phase_id' => $phase->id]);

    return $assignment->fresh(['currentPhase', 'vessel', 'employee']);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function makeCurrentCrewPhaseAssignment(
    Company $company,
    Employee $employee,
    Rank $rank,
    Vessel $vessel,
    CrewPhaseCode $phaseCode,
    array $overrides = [],
): CrewAssignment {
    $started = CarbonImmutable::parse('2026-01-01 08:00:00', $company->timezone ?? 'UTC');

    $assignment = CrewAssignment::query()->create(array_merge([
        'company_id' => $company->id,
        'assignment_no' => 'CA-VV-'.Str::upper(Str::random(6)),
        'employee_id' => $employee->id,
        'rank_id' => $rank->id,
        'vessel_id' => $vessel->id,
        'status' => CrewAssignmentStatus::Active,
        'started_at' => $started,
        'source' => 'manual',
    ], $overrides));

    $phase = CrewAssignmentPhase::query()->create([
        'company_id' => $company->id,
        'crew_assignment_id' => $assignment->id,
        'phase_code' => $phaseCode,
        'sequence' => 1,
        'status' => CrewPhaseStatus::Active,
        'actual_start_at' => $started,
    ]);

    $assignment->update(['current_phase_id' => $phase->id]);

    return $assignment->fresh(['currentPhase', 'vessel', 'employee', 'rank']);
}

/**
 * @param  list<string>  $permissions
 * @return array{user: User, company: Company, employee: Employee, rank: Rank, vessel: Vessel}
 */
function makeCurrentCrewVesselViewFixtures(array $permissions = ['crew_operations.assignments.view']): array
{
    $fixtures = makeCrewAssignmentFixtures();
    grantCompanyPermissions($fixtures['user'], $fixtures['company'], $permissions);
    $fixtures['user']->update(['current_company_id' => $fixtures['company']->id]);
    $fixtures['vessel'] = makeCrewMovementVessel('HAI DUONG 08', $fixtures['company']);

    return $fixtures;
}

/**
 * Test-only helper that replaces the deleted CreateCrewAssignmentFromPlanning service.
 *
 * Creates a draft CrewAssignment from planning context and links the planning row.
 * If the planning slot is vacant, pass $employeeId explicitly.
 */
function createAssignmentFromPlanning(
    CrewPlanningAssignment $planning,
    ?int $actorId = null,
    ?int $employeeId = null,
): CrewAssignment {
    $resolvedEmployeeId = $employeeId ?? ($planning->employee_id !== null ? (int) $planning->employee_id : null);

    if ($resolvedEmployeeId === null) {
        throw new RuntimeException('Planning slot must have an employee to create an assignment.');
    }

    $service = app(CrewMovementService::class);

    $assignment = $service->createDraft(
        (int) $planning->company_id,
        $resolvedEmployeeId,
        [
            'rank_id' => $planning->rank_id,
            'vessel_id' => $planning->vessel_id,
            'planned_join_at' => $planning->planned_join_date?->toDateString().' 00:00:00',
            'planned_signoff_at' => $planning->planned_leave_date?->toDateString().' 00:00:00',
            'relieves_crew_assignment_id' => $planning->relieves_crew_assignment_id,
            'source' => 'crew_planning',
            'remarks' => $planning->notes,
        ],
        $actorId,
    );

    $planning->update(['crew_assignment_id' => $assignment->id]);

    return $assignment->fresh(['phases', 'currentPhase', 'planningAssignment']) ?? $assignment;
}

/**
 * Test-only helper that replaces the deleted SyncPlanningAssignmentFromCrewAssignment service.
 *
 * Creates or updates a CrewPlanningAssignment linked to the given CrewAssignment,
 * using planned dates from the assignment.
 */
function syncPlanningFromAssignment(CrewAssignment $assignment): CrewPlanningAssignment
{
    $timezone = $assignment->company?->timezone ?? 'UTC';

    $joinDate = $assignment->phases
        ?->where('phase_code', CrewPhaseCode::OnVessel)
        ->sortByDesc('sequence')
        ->first()
        ?->actual_start_at
        ?->timezone($timezone)
        ->toDateString()
        ?? $assignment->planned_join_at?->timezone($timezone)->toDateString();

    $leaveDate = $assignment->phases
        ?->where('phase_code', CrewPhaseCode::OnVessel)
        ->sortByDesc('sequence')
        ->first()
        ?->actual_end_at
        ?->timezone($timezone)
        ->toDateString()
        ?? $assignment->planned_signoff_at?->timezone($timezone)->toDateString();

    return CrewPlanningAssignment::query()->updateOrCreate(
        ['crew_assignment_id' => $assignment->id],
        [
            'company_id' => $assignment->company_id,
            'vessel_id' => $assignment->vessel_id,
            'rank_id' => $assignment->rank_id ?? $assignment->employee?->rank_id,
            'employee_id' => $assignment->employee_id,
            'planned_join_date' => $joinDate,
            'planned_leave_date' => $leaveDate,
            'relieves_crew_assignment_id' => $assignment->relieves_crew_assignment_id,
        ],
    );
}
