<?php

use App\Enums\CrewAssignmentStatus;
use App\Enums\CrewPhaseCode;
use App\Enums\CrewPhaseStatus;
use App\Enums\CrewTimesheetPayCategory;
use App\Models\Client;
use App\Models\Company;
use App\Models\CrewAssignment;
use App\Models\Employee;
use App\Models\EmployeeSeaService;
use App\Models\Rank;
use App\Support\CrewMovements\Actions\BulkStartCrewAssignments;
use App\Support\Payroll\CrewTimeline\CrewPhasePayCategoryResolver;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

function bulkAddPermissions(array $extra = []): array
{
    return array_values(array_unique(array_merge([
        'crew_operations.assignments.view',
        'crew_operations.assignments.create',
        'crew_operations.movements.perform',
    ], $extra)));
}

function actingBulkAddCrewUser(array $permissions = []): array
{
    $fixtures = makeCrewAssignmentFixtures();
    grantCompanyPermissions($fixtures['user'], $fixtures['company'], bulkAddPermissions($permissions));
    $fixtures['user']->update(['current_company_id' => $fixtures['company']->id]);

    return $fixtures;
}

function extraCrewEmployee(Company $company, Rank $rank, string $name): Employee
{
    return Employee::factory()
        ->forCompany($company)
        ->create([
            'name' => $name,
            'rank_id' => $rank->id,
            'status' => 'active',
        ]);
}

function bulkAddPayload(array $overrides = []): array
{
    return array_merge([
        'client_id' => null,
        'vessel_id' => null,
        'planned_join_at' => '2026-09-20',
        'current_stage' => 'p1',
        'remarks' => 'Bulk mobilisation',
        'crew' => [],
    ], $overrides);
}

function unifiedBulkCreateUrl(): string
{
    return route('organization.crew-assignments.create', ['mode' => 'bulk']);
}

test('unified create defaults to one crew row', function () {
    ['user' => $user, 'company' => $company] = makeCrewAssignmentFixtures();
    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.view',
        'crew_operations.assignments.create',
    ]);
    $user->update(['current_company_id' => $company->id]);

    $this->actingAs($user)
        ->get(route('organization.crew-assignments.create'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/crew/create')
            ->where('initial_row_count', 1));
});

test('legacy bulk create route redirects to unified create in bulk mode', function () {
    ['user' => $user] = actingBulkAddCrewUser();

    $this->actingAs($user)
        ->get(route('organization.crew-assignments.bulk-create'))
        ->assertRedirect(unifiedBulkCreateUrl());
});

test('user with create and movement permission can open bulk mode on unified create', function () {
    ['user' => $user] = actingBulkAddCrewUser();

    $this->actingAs($user)
        ->get(unifiedBulkCreateUrl())
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/crew/create')
            ->where('initial_row_count', 2)
            ->where('can.start', true)
            ->where('can.create', true)
            ->where('can.perform_movement', true));
});

test('bulk add page is forbidden without assignments create permission', function () {
    ['user' => $user, 'company' => $company] = makeCrewAssignmentFixtures();
    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.view',
        'crew_operations.movements.perform',
    ]);
    $user->update(['current_company_id' => $company->id]);

    $this->actingAs($user)
        ->get(unifiedBulkCreateUrl())
        ->assertForbidden();

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.bulk-store'), bulkAddPayload([
            'crew' => [['employee_id' => 1, 'rank_id' => 1]],
        ]))
        ->assertForbidden();
});

test('bulk mode create page is available with create permission but bulk store requires movement permission', function () {
    ['user' => $user, 'company' => $company] = makeCrewAssignmentFixtures();
    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.view',
        'crew_operations.assignments.create',
    ]);
    $user->update(['current_company_id' => $company->id]);

    $this->actingAs($user)
        ->get(unifiedBulkCreateUrl())
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/crew/create')
            ->where('initial_row_count', 1)
            ->where('can.create', true)
            ->where('can.start', false));

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.bulk-store'), bulkAddPayload([
            'crew' => [['employee_id' => 1, 'rank_id' => 1]],
        ]))
        ->assertForbidden();
});

test('bulk store rejects an incomplete row and creates zero assignments', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = actingBulkAddCrewUser();
    $employeeB = extraCrewEmployee($company, $rank, 'Ahmed Ali');

    $this->actingAs($user)
        ->from(unifiedBulkCreateUrl())
        ->post(route('organization.crew-assignments.bulk-store'), bulkAddPayload([
            'crew' => [
                ['employee_id' => $employee->id, 'rank_id' => $rank->id],
                ['employee_id' => null, 'rank_id' => null],
                ['employee_id' => $employeeB->id, 'rank_id' => $rank->id],
            ],
        ]))
        ->assertRedirect(unifiedBulkCreateUrl())
        ->assertSessionHasErrors('crew.1.employee_id');

    expect(CrewAssignment::query()->where('company_id', $company->id)->count())->toBe(0);
});

test('current crew can.start flag matches bulk add capability', function () {
    ['user' => $starter] = actingBulkAddCrewUser();

    $this->actingAs($starter)
        ->get(route('organization.crew-assignments.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/crew/index')
            ->where('can.start', true)
            ->where('can.create', true));

    ['user' => $draftOnly, 'company' => $company] = makeCrewAssignmentFixtures();
    grantCompanyPermissions($draftOnly, $company, [
        'crew_operations.assignments.view',
        'crew_operations.assignments.create',
    ]);
    $draftOnly->update(['current_company_id' => $company->id]);

    $this->actingAs($draftOnly)
        ->get(route('organization.crew-assignments.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('can.start', false)
            ->where('can.create', true));
});

test('bulk add starts one employee as a normal active assignment', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = actingBulkAddCrewUser();
    $vessel = makeCrewMovementVessel('Bulk Vessel A', $company);
    Carbon::setTestNow(Carbon::parse('2026-09-15 15:45:12', $company->timezone));

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.bulk-store'), bulkAddPayload([
            'vessel_id' => $vessel->id,
            'client_id' => $vessel->client_id,
            'crew' => [
                ['employee_id' => $employee->id, 'rank_id' => $rank->id],
            ],
        ]))
        ->assertRedirect(route('organization.crew-assignments.index'))
        ->assertSessionHas('success', '1 crew assignment started successfully.');

    $assignment = CrewAssignment::query()
        ->where('company_id', $company->id)
        ->with(['currentPhase', 'phases'])
        ->first();

    expect($assignment)->not->toBeNull()
        ->and($assignment->employee_id)->toBe($employee->id)
        ->and($assignment->status)->toBe(CrewAssignmentStatus::Active)
        ->and($assignment->source)->toBe('manual')
        ->and($assignment->company_id)->toBe($company->id)
        ->and($assignment->vessel_id)->toBe($vessel->id)
        ->and($assignment->client_id)->toBe($vessel->client_id)
        ->and($assignment->rank_id)->toBe($rank->id)
        ->and($assignment->planned_join_at?->toDateString())->toBe('2026-09-20')
        ->and($assignment->remarks)->toBe('Bulk mobilisation')
        ->and($assignment->phases)->toHaveCount(1)
        ->and($assignment->currentPhase?->phase_code)->toBe(CrewPhaseCode::PreMobilisation)
        ->and($assignment->currentPhase?->status)->toBe(CrewPhaseStatus::Active)
        ->and($assignment->currentPhase?->actual_start_at?->equalTo($assignment->started_at))->toBeTrue()
        ->and($assignment->started_at?->timezone($company->timezone)->format('Y-m-d H:i:s'))->toBe('2026-09-15 15:45:12')
        ->and(EmployeeSeaService::query()->where('employee_id', $employee->id)->count())->toBe(0)
        ->and((new CrewPhasePayCategoryResolver)->resolve($assignment->currentPhase->phase_code))->toBe(CrewTimesheetPayCategory::Excluded);
});

test('bulk add starts multiple employees in one batch with a shared server timestamp', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employeeA, 'rank' => $rank] = actingBulkAddCrewUser();
    $rankB = Rank::query()->create(['name' => 'Bosun '.uniqid(), 'is_active' => true]);
    $employeeB = extraCrewEmployee($company, $rankB, 'John Mathew');
    $vessel = makeCrewMovementVessel('Bulk Shared Vessel', $company);
    Carbon::setTestNow(Carbon::parse('2026-09-15 15:45:12', $company->timezone));

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.bulk-store'), bulkAddPayload([
            'vessel_id' => $vessel->id,
            'crew' => [
                ['employee_id' => $employeeA->id, 'rank_id' => $rank->id],
                ['employee_id' => $employeeB->id, 'rank_id' => $rankB->id],
            ],
        ]))
        ->assertRedirect(route('organization.crew-assignments.index'))
        ->assertSessionHas('success', '2 crew assignments started successfully.');

    $assignments = CrewAssignment::query()
        ->where('company_id', $company->id)
        ->with(['currentPhase', 'phases'])
        ->orderBy('employee_id')
        ->get();

    expect($assignments)->toHaveCount(2)
        ->and($assignments->pluck('employee_id')->sort()->values()->all())->toBe(
            collect([$employeeA->id, $employeeB->id])->sort()->values()->all()
        )
        ->and($assignments[0]->started_at?->equalTo($assignments[1]->started_at))->toBeTrue()
        ->and($assignments[0]->started_at?->timezone($company->timezone)->format('Y-m-d H:i:s'))->toBe('2026-09-15 15:45:12');

    foreach ($assignments as $assignment) {
        expect($assignment->status)->toBe(CrewAssignmentStatus::Active)
            ->and($assignment->vessel_id)->toBe($vessel->id)
            ->and($assignment->client_id)->toBe($vessel->client_id)
            ->and($assignment->planned_join_at?->toDateString())->toBe('2026-09-20')
            ->and($assignment->remarks)->toBe('Bulk mobilisation')
            ->and($assignment->phases)->toHaveCount(1)
            ->and($assignment->currentPhase?->phase_code)->toBe(CrewPhaseCode::PreMobilisation)
            ->and($assignment->currentPhase?->actual_start_at?->equalTo($assignment->started_at))->toBeTrue();
    }

    expect($assignments->firstWhere('employee_id', $employeeA->id)?->rank_id)->toBe($rank->id)
        ->and($assignments->firstWhere('employee_id', $employeeB->id)?->rank_id)->toBe($rankB->id);
});

test('omitted bulk current stage defaults every row to travel in', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = actingBulkAddCrewUser();
    $employeeB = extraCrewEmployee($company, $rank, 'Ahmed Ali');

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.bulk-store'), [
            'crew' => [
                ['employee_id' => $employee->id, 'rank_id' => $rank->id],
                ['employee_id' => $employeeB->id, 'rank_id' => $rank->id],
            ],
        ])
        ->assertRedirect();

    $codes = CrewAssignment::query()
        ->where('company_id', $company->id)
        ->with('currentPhase')
        ->get()
        ->map(fn (CrewAssignment $assignment) => $assignment->currentPhase?->phase_code)
        ->all();

    expect($codes)->toHaveCount(2)
        ->and($codes)->each->toBe(CrewPhaseCode::PreMobilisation);
});

test('explicit bulk p0 starts every row at active pre-mobilisation', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = actingBulkAddCrewUser();
    $employeeB = extraCrewEmployee($company, $rank, 'Ravi Kumar');

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.bulk-store'), bulkAddPayload([
            'current_stage' => 'p0',
            'crew' => [
                ['employee_id' => $employee->id, 'rank_id' => $rank->id],
                ['employee_id' => $employeeB->id, 'rank_id' => $rank->id],
            ],
        ]))
        ->assertRedirect();

    $assignments = CrewAssignment::query()->where('company_id', $company->id)->with(['currentPhase', 'phases'])->get();

    expect($assignments)->toHaveCount(2);

    foreach ($assignments as $assignment) {
        expect($assignment->status)->toBe(CrewAssignmentStatus::Active)
            ->and($assignment->currentPhase?->phase_code)->toBe(CrewPhaseCode::PreMobilisation)
            ->and($assignment->currentPhase?->status)->toBe(CrewPhaseStatus::Active)
            ->and($assignment->phases)->toHaveCount(1)
            ->and((new CrewPhasePayCategoryResolver)->resolve($assignment->currentPhase->phase_code))->toBe(CrewTimesheetPayCategory::Excluded);
    }
});

test('duplicate employee ids in a bulk batch are rejected', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = actingBulkAddCrewUser();

    $this->actingAs($user)
        ->from(unifiedBulkCreateUrl())
        ->post(route('organization.crew-assignments.bulk-store'), bulkAddPayload([
            'crew' => [
                ['employee_id' => $employee->id, 'rank_id' => $rank->id],
                ['employee_id' => $employee->id, 'rank_id' => $rank->id],
            ],
        ]))
        ->assertRedirect(unifiedBulkCreateUrl())
        ->assertSessionHasErrors('crew.1.employee_id');

    expect(CrewAssignment::query()->where('company_id', $company->id)->count())->toBe(0);
});

test('empty crew list is rejected', function () {
    ['user' => $user, 'company' => $company] = actingBulkAddCrewUser();

    $this->actingAs($user)
        ->from(unifiedBulkCreateUrl())
        ->post(route('organization.crew-assignments.bulk-store'), bulkAddPayload([
            'crew' => [],
        ]))
        ->assertRedirect(unifiedBulkCreateUrl())
        ->assertSessionHasErrors('crew');

    expect(CrewAssignment::query()->where('company_id', $company->id)->count())->toBe(0);
});

test('cross-company employee cannot be bulk added', function () {
    ['user' => $user, 'company' => $company, 'rank' => $rank] = actingBulkAddCrewUser();
    ['employee' => $foreignEmployee] = makeCrewAssignmentFixtures();

    $this->actingAs($user)
        ->from(unifiedBulkCreateUrl())
        ->post(route('organization.crew-assignments.bulk-store'), bulkAddPayload([
            'crew' => [
                ['employee_id' => $foreignEmployee->id, 'rank_id' => $rank->id],
            ],
        ]))
        ->assertRedirect(unifiedBulkCreateUrl())
        ->assertSessionHasErrors('crew.0.employee_id');

    expect(CrewAssignment::query()->where('company_id', $company->id)->count())->toBe(0);
});

test('inactive employee cannot be bulk added', function () {
    ['user' => $user, 'company' => $company, 'rank' => $rank] = actingBulkAddCrewUser();
    $inactive = Employee::factory()->forCompany($company)->inactive()->create([
        'rank_id' => $rank->id,
    ]);

    $this->actingAs($user)
        ->from(unifiedBulkCreateUrl())
        ->post(route('organization.crew-assignments.bulk-store'), bulkAddPayload([
            'crew' => [
                ['employee_id' => $inactive->id, 'rank_id' => $rank->id],
            ],
        ]))
        ->assertRedirect(unifiedBulkCreateUrl())
        ->assertSessionHasErrors('crew.0.employee_id');

    expect(CrewAssignment::query()->where('company_id', $company->id)->count())->toBe(0);
});

test('cross-company vessel cannot be used in bulk add', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = actingBulkAddCrewUser();
    ['company' => $otherCompany] = makeCrewAssignmentFixtures();
    $foreignVessel = makeCrewMovementVessel('Foreign Vessel', $otherCompany);

    $this->actingAs($user)
        ->from(unifiedBulkCreateUrl())
        ->post(route('organization.crew-assignments.bulk-store'), bulkAddPayload([
            'vessel_id' => $foreignVessel->id,
            'crew' => [
                ['employee_id' => $employee->id, 'rank_id' => $rank->id],
            ],
        ]))
        ->assertRedirect(unifiedBulkCreateUrl())
        ->assertSessionHasErrors('vessel_id');

    expect(CrewAssignment::query()->where('company_id', $company->id)->count())->toBe(0);
});

test('mismatched client and vessel relationship is rejected', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = actingBulkAddCrewUser();
    $clientA = Client::query()->create(['name' => 'Client A '.uniqid(), 'is_active' => true]);
    $clientB = Client::query()->create(['name' => 'Client B '.uniqid(), 'is_active' => true]);
    $vessel = makeCrewMovementVessel('Client A Vessel', $company, $clientA);

    $this->actingAs($user)
        ->from(unifiedBulkCreateUrl())
        ->post(route('organization.crew-assignments.bulk-store'), bulkAddPayload([
            'client_id' => $clientB->id,
            'vessel_id' => $vessel->id,
            'crew' => [
                ['employee_id' => $employee->id, 'rank_id' => $rank->id],
            ],
        ]))
        ->assertRedirect(unifiedBulkCreateUrl())
        ->assertSessionHasErrors('client_id');

    expect(CrewAssignment::query()->where('company_id', $company->id)->count())->toBe(0);
});

test('browser supplied current stage in bulk add is ignored and rows start in p0', function (string $stage) {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = actingBulkAddCrewUser();

    $this->actingAs($user)
        ->from(unifiedBulkCreateUrl())
        ->post(route('organization.crew-assignments.bulk-store'), bulkAddPayload([
            'current_stage' => $stage,
            'crew' => [
                ['employee_id' => $employee->id, 'rank_id' => $rank->id],
            ],
        ]))
        ->assertRedirect(route('organization.crew-assignments.index'));

    $assignment = CrewAssignment::query()->where('company_id', $company->id)->first();
    expect($assignment?->currentPhase?->phase_code)->toBe(CrewPhaseCode::PreMobilisation);
})->with([
    'p2a' => ['p2a'],
    'p3' => ['p3'],
    'p4' => ['p4'],
]);

test('client supplied stage started at cannot alter the bulk start time', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = actingBulkAddCrewUser();
    Carbon::setTestNow(Carbon::parse('2026-09-15 15:45:12', $company->timezone));

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.bulk-store'), bulkAddPayload([
            'stage_started_at' => '2026-09-01 08:00:00',
            'started_at' => '2026-09-01 08:00:00',
            'company_id' => 999999,
            'crew' => [
                ['employee_id' => $employee->id, 'rank_id' => $rank->id],
            ],
        ]))
        ->assertRedirect();

    $assignment = CrewAssignment::query()->where('company_id', $company->id)->first();

    expect($assignment)->not->toBeNull()
        ->and($assignment->company_id)->toBe($company->id)
        ->and($assignment->started_at?->timezone($company->timezone)->format('Y-m-d H:i:s'))->toBe('2026-09-15 15:45:12')
        ->and($assignment->currentPhase?->actual_start_at?->equalTo($assignment->started_at))->toBeTrue();
});

test('an employee with an active assignment blocks the whole bulk batch', function () {
    ['user' => $user, 'company' => $company, 'employee' => $blocked, 'rank' => $rank] = actingBulkAddCrewUser();
    $ready = extraCrewEmployee($company, $rank, 'Ahmed Ali');
    $vessel = makeCrewMovementVessel('Conflict Vessel', $company);
    $existing = makeActiveOnVesselAssignment($company, $blocked, $rank, $vessel);
    $existingUpdatedAt = $existing->updated_at?->toDateTimeString();

    $this->actingAs($user)
        ->from(unifiedBulkCreateUrl())
        ->post(route('organization.crew-assignments.bulk-store'), bulkAddPayload([
            'vessel_id' => $vessel->id,
            'crew' => [
                ['employee_id' => $ready->id, 'rank_id' => $rank->id],
                ['employee_id' => $blocked->id, 'rank_id' => $rank->id],
            ],
        ]))
        ->assertRedirect(unifiedBulkCreateUrl())
        ->assertSessionHasErrors('crew.1.employee_id');

    expect(CrewAssignment::query()->where('company_id', $company->id)->count())->toBe(1)
        ->and(CrewAssignment::query()->where('employee_id', $ready->id)->count())->toBe(0)
        ->and($existing->fresh()->updated_at?->toDateTimeString())->toBe($existingUpdatedAt)
        ->and($existing->fresh()->status)->toBe(CrewAssignmentStatus::Active)
        ->and($existing->fresh()->currentPhase?->phase_code)->toBe(CrewPhaseCode::OnVessel);
});

test('a valid first row still rolls back when a later bulk row is blocked', function () {
    ['user' => $user, 'company' => $company, 'employee' => $first, 'rank' => $rank] = actingBulkAddCrewUser();
    $second = extraCrewEmployee($company, $rank, 'John Mathew');
    $blocked = extraCrewEmployee($company, $rank, 'Mohammed Sameer');
    $vessel = makeCrewMovementVessel('Atomic Vessel', $company);
    makeActiveOnVesselAssignment($company, $blocked, $rank, $vessel);

    $this->actingAs($user)
        ->from(unifiedBulkCreateUrl())
        ->post(route('organization.crew-assignments.bulk-store'), bulkAddPayload([
            'vessel_id' => $vessel->id,
            'crew' => [
                ['employee_id' => $first->id, 'rank_id' => $rank->id],
                ['employee_id' => $second->id, 'rank_id' => $rank->id],
                ['employee_id' => $blocked->id, 'rank_id' => $rank->id],
            ],
        ]))
        ->assertRedirect(unifiedBulkCreateUrl())
        ->assertSessionHasErrors('crew.2.employee_id');

    expect(CrewAssignment::query()->where('company_id', $company->id)->count())->toBe(1)
        ->and(CrewAssignment::query()->whereIn('employee_id', [$first->id, $second->id])->count())->toBe(0);
});

test('bulk add create page keeps restricted operational details hidden without view permission', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.create',
        'crew_operations.movements.perform',
    ]);
    $user->update(['current_company_id' => $company->id]);
    $vessel = makeCrewMovementVessel('Restricted Bulk Vessel', $company);
    makeActiveOnVesselAssignment($company, $employee, $rank, $vessel);

    $this->actingAs($user)
        ->get(unifiedBulkCreateUrl())
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/crew/create')
            ->where('initial_row_count', 2)
            ->where("form_options.employee_status_by_employee.{$employee->id}.has_active_assignment", true)
            ->where("form_options.employee_status_by_employee.{$employee->id}.assignment_id", null)
            ->where("form_options.employee_status_by_employee.{$employee->id}.assignment_no", null)
            ->where("form_options.employee_status_by_employee.{$employee->id}.vessel_name", null)
            ->missing("form_options.active_on_vessel_by_employee.{$employee->id}")
        );
});

test('one company cannot bulk add another company employee or vessel', function () {
    ['user' => $userA, 'company' => $companyA, 'rank' => $rankA] = actingBulkAddCrewUser();
    ['company' => $companyB, 'employee' => $employeeB] = makeCrewAssignmentFixtures();
    $vesselB = makeCrewMovementVessel('Company B Vessel', $companyB);

    $this->actingAs($userA)
        ->post(route('organization.crew-assignments.bulk-store'), bulkAddPayload([
            'company_id' => $companyB->id,
            'vessel_id' => $vesselB->id,
            'crew' => [
                ['employee_id' => $employeeB->id, 'rank_id' => $rankA->id],
            ],
        ]))
        ->assertSessionHasErrors(['vessel_id', 'crew.0.employee_id']);

    expect(CrewAssignment::query()->where('company_id', $companyA->id)->count())->toBe(0)
        ->and(CrewAssignment::query()->where('company_id', $companyB->id)->count())->toBe(0);
});

test('bulk start coordinator shares one server timestamp and rolls back on a later failure', function () {
    ['company' => $company, 'employee' => $first, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $second = extraCrewEmployee($company, $rank, 'John Mathew');
    $blocked = extraCrewEmployee($company, $rank, 'Mohammed Sameer');
    $vessel = makeCrewMovementVessel('Coordinator Vessel', $company);
    makeActiveOnVesselAssignment($company, $blocked, $rank, $vessel);
    Carbon::setTestNow(Carbon::parse('2026-09-15 15:45:12', $company->timezone));

    expect(fn () => app(BulkStartCrewAssignments::class)->handle($company->id, [
        'vessel_id' => $vessel->id,
        'current_stage' => 'p1',
        'crew' => [
            ['employee_id' => $first->id, 'rank_id' => $rank->id],
            ['employee_id' => $second->id, 'rank_id' => $rank->id],
            ['employee_id' => $blocked->id, 'rank_id' => $rank->id],
        ],
    ]))->toThrow(ValidationException::class);

    expect(CrewAssignment::query()->where('company_id', $company->id)->count())->toBe(1)
        ->and(CrewAssignment::query()->whereIn('employee_id', [$first->id, $second->id])->count())->toBe(0);
});
