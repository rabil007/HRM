<?php

use App\Enums\CrewAssignmentStatus;
use App\Enums\CrewMobilisationReadinessStatus;
use App\Enums\CrewPhaseCode;
use App\Enums\CrewPhaseStatus;
use App\Enums\CrewReliefRisk;
use App\Enums\CrewReliefStatus;
use App\Models\Company;
use App\Models\CrewAssignment;
use App\Models\CrewPlanningAssignment;
use App\Models\DocumentType;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\Rank;
use App\Models\User;
use App\Models\Vessel;
use App\Support\CrewPlanning\CreateCrewAssignmentFromPlanning;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * @return array{
 *     user: User,
 *     company: Company,
 *     rank: Rank,
 *     vessel: Vessel,
 *     today: CarbonImmutable
 * }
 */
function makeReliefDeskFixtures(array $permissions = [
    'crew_operations.planning.view',
    'crew_operations.planning.create',
    'crew_operations.assignments.view',
]): array
{
    $fixtures = makeCrewAssignmentFixtures();
    grantCompanyPermissions($fixtures['user'], $fixtures['company'], $permissions);
    $fixtures['user']->update(['current_company_id' => $fixtures['company']->id]);
    $fixtures['vessel'] = makeCrewMovementVessel('Relief Desk Vessel', $fixtures['company']);
    $timezone = $fixtures['company']->timezone ?? 'Asia/Dubai';
    $today = CarbonImmutable::parse('2026-09-07 08:00:00', $timezone);
    Carbon::setTestNow($today);
    CarbonImmutable::setTestNow($today);
    $fixtures['today'] = $today;

    return $fixtures;
}

function makeReliefDeskOnboard(
    Company $company,
    Rank $rank,
    Vessel $vessel,
    CarbonImmutable $today,
    int $daysUntilSignoff,
    string $name = 'Onboard Crew',
): CrewAssignment {
    $employee = Employee::factory()->forCompany($company)->create([
        'rank_id' => $rank->id,
        'status' => 'active',
        'name' => $name,
    ]);

    $signoff = $daysUntilSignoff < 0
        ? $today->subDays(abs($daysUntilSignoff))
        : $today->addDays($daysUntilSignoff);

    return makeActiveOnVesselAssignment($company, $employee, $rank, $vessel, [
        'planned_signoff_at' => $signoff->toDateTimeString(),
    ]);
}

function makeReliefPlanFor(
    CrewAssignment $source,
    Employee $reliefEmployee,
    CarbonImmutable $joinOn,
): CrewPlanningAssignment {
    return CrewPlanningAssignment::query()->create([
        'company_id' => $source->company_id,
        'vessel_id' => $source->vessel_id,
        'rank_id' => $source->rank_id,
        'employee_id' => $reliefEmployee->id,
        'relieves_crew_assignment_id' => $source->id,
        'planned_join_date' => $joinOn->toDateString(),
        'planned_leave_date' => $joinOn->addDays(90)->toDateString(),
    ]);
}

function makeReliefEmployee(Company $company, Rank $rank, string $name): Employee
{
    return Employee::factory()->forCompany($company)->create([
        'rank_id' => $rank->id,
        'status' => 'active',
        'name' => $name,
    ]);
}

afterEach(function () {
    Carbon::setTestNow();
    CarbonImmutable::setTestNow();
});

test('guests cannot open the relief desk', function () {
    $this->get(route('organization.crew-planning.index', ['view' => 'relief']))
        ->assertRedirect(route('login'));
});

test('users without planning view cannot open the relief desk', function () {
    $fixtures = makeCrewAssignmentFixtures();
    grantCompanyPermissions($fixtures['user'], $fixtures['company'], [
        'crew_operations.assignments.view',
    ]);
    $fixtures['user']->update(['current_company_id' => $fixtures['company']->id]);

    $this->actingAs($fixtures['user'])
        ->get(route('organization.crew-planning.index', ['view' => 'relief']))
        ->assertForbidden();
});

test('relief desk lists active p4 crew with no relief', function () {
    $fixtures = makeReliefDeskFixtures();
    $source = makeReliefDeskOnboard(
        $fixtures['company'],
        $fixtures['rank'],
        $fixtures['vessel'],
        $fixtures['today'],
        5,
        'Ahmed Ali',
    );

    $this->actingAs($fixtures['user'])
        ->get(route('organization.crew-planning.index', ['view' => 'relief']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/crew-planning/index')
            ->where('view', 'relief')
            ->has('rows', 0)
            ->has('relief_desk.rows', 1)
            ->where('relief_desk.rows.0.id', $source->id)
            ->where('relief_desk.rows.0.employee.name', 'Ahmed Ali')
            ->where('relief_desk.rows.0.relief_status', CrewReliefStatus::NoRelief->value)
            ->where('relief_desk.rows.0.relief_risk', CrewReliefRisk::Critical->value)
            ->where('relief_desk.rows.0.recommended_action.key', 'plan_relief')
            ->where('relief_desk.rows.0.recommended_action.label', 'Plan Relief')
            ->where('relief_desk.summary.needs_relief', 1)
        );
});

test('relief desk includes missing planned sign-off as attention', function () {
    $fixtures = makeReliefDeskFixtures();
    $employee = Employee::factory()->forCompany($fixtures['company'])->create([
        'rank_id' => $fixtures['rank']->id,
        'status' => 'active',
        'name' => 'Missing Signoff',
    ]);
    $source = makeActiveOnVesselAssignment(
        $fixtures['company'],
        $employee,
        $fixtures['rank'],
        $fixtures['vessel'],
        ['planned_signoff_at' => null],
    );

    $this->actingAs($fixtures['user'])
        ->get(route('organization.crew-planning.index', ['view' => 'relief']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('relief_desk.rows.0.id', $source->id)
            ->where('relief_desk.rows.0.missing_planned_signoff', true)
            ->where('relief_desk.rows.0.relief_status', CrewReliefStatus::NoRelief->value)
        );
});

test('relief desk excludes p4 sign-offs beyond the default 30 day horizon', function () {
    $fixtures = makeReliefDeskFixtures();
    makeReliefDeskOnboard($fixtures['company'], $fixtures['rank'], $fixtures['vessel'], $fixtures['today'], 45, 'Far Away');

    $this->actingAs($fixtures['user'])
        ->get(route('organization.crew-planning.index', ['view' => 'relief']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('relief_desk.rows', 0));

    $this->actingAs($fixtures['user'])
        ->get(route('organization.crew-planning.index', ['view' => 'relief', 'horizon' => 'all']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('relief_desk.rows', 1));
});

test('relief desk shows planning-only relief as open relief plan', function () {
    $fixtures = makeReliefDeskFixtures();
    $source = makeReliefDeskOnboard($fixtures['company'], $fixtures['rank'], $fixtures['vessel'], $fixtures['today'], 12, 'John Mathew');
    $relief = makeReliefEmployee($fixtures['company'], $fixtures['rank'], 'Sameer Khan');
    makeReliefPlanFor($source, $relief, $fixtures['today']->addDays(12));

    $this->actingAs($fixtures['user'])
        ->get(route('organization.crew-planning.index', ['view' => 'relief']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('relief_desk.rows.0.relief_status', CrewReliefStatus::ReliefPlanned->value)
            ->where('relief_desk.rows.0.relief_employee.name', 'Sameer Khan')
            ->where('relief_desk.rows.0.recommended_action.key', 'open_relief_plan')
            ->where('relief_desk.rows.0.recommended_action.label', 'Open Relief Plan')
        );
});

test('relief desk shows converted draft assignment as assignment created', function () {
    $fixtures = makeReliefDeskFixtures();
    $source = makeReliefDeskOnboard($fixtures['company'], $fixtures['rank'], $fixtures['vessel'], $fixtures['today'], 11, 'Source Draft');
    $plan = makeReliefPlanFor(
        $source,
        makeReliefEmployee($fixtures['company'], $fixtures['rank'], 'Draft Relief'),
        $fixtures['today']->addDays(11),
    );
    $linked = app(CreateCrewAssignmentFromPlanning::class)->handle($plan, $fixtures['user']->id);

    $this->actingAs($fixtures['user'])
        ->get(route('organization.crew-planning.index', ['view' => 'relief']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('relief_desk.rows.0.relief_status', CrewReliefStatus::AssignmentCreated->value)
            ->where('relief_desk.rows.0.relief_crew_assignment_id', $linked->id)
            ->where('relief_desk.rows.0.recommended_action.key', 'open_relief_assignment')
        );
});

test('relief desk maps linked pre-join phases to mobilising and ready to join', function (CrewPhaseCode $phase, CrewReliefStatus $status) {
    $fixtures = makeReliefDeskFixtures();
    $source = makeReliefDeskOnboard($fixtures['company'], $fixtures['rank'], $fixtures['vessel'], $fixtures['today'], 9, 'Phase Source');
    $plan = makeReliefPlanFor(
        $source,
        makeReliefEmployee($fixtures['company'], $fixtures['rank'], 'Phase Relief'),
        $fixtures['today']->addDays(9),
    );
    $linked = app(CreateCrewAssignmentFromPlanning::class)->handle($plan, $fixtures['user']->id);
    $linked->update(['status' => CrewAssignmentStatus::Active]);
    $linked->currentPhase->update([
        'phase_code' => $phase,
        'status' => CrewPhaseStatus::Active,
        'actual_start_at' => now(),
    ]);

    $this->actingAs($fixtures['user'])
        ->get(route('organization.crew-planning.index', ['view' => 'relief']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('relief_desk.rows.0.relief_status', $status->value)
            ->where('relief_desk.rows.0.relief_crew_assignment_id', $linked->id)
        );
})->with([
    'p0' => [CrewPhaseCode::PreMobilisation, CrewReliefStatus::Mobilising],
    'p1' => [CrewPhaseCode::TravelIn, CrewReliefStatus::Mobilising],
    'p2a' => [CrewPhaseCode::JoinStandby, CrewReliefStatus::Mobilising],
    'p2b' => [CrewPhaseCode::Training, CrewReliefStatus::Mobilising],
    'p3' => [CrewPhaseCode::ReadyToJoin, CrewReliefStatus::ReadyToJoin],
]);

test('relief desk shows relief onboard without closing the source assignment', function () {
    $fixtures = makeReliefDeskFixtures();
    $source = makeReliefDeskOnboard($fixtures['company'], $fixtures['rank'], $fixtures['vessel'], $fixtures['today'], 4, 'Still Onboard');
    $plan = makeReliefPlanFor(
        $source,
        makeReliefEmployee($fixtures['company'], $fixtures['rank'], 'Onboard Relief'),
        $fixtures['today']->addDays(4),
    );
    $linked = app(CreateCrewAssignmentFromPlanning::class)->handle($plan, $fixtures['user']->id);
    $linked->update(['status' => CrewAssignmentStatus::Active]);
    $linked->currentPhase->update([
        'phase_code' => CrewPhaseCode::OnVessel,
        'status' => CrewPhaseStatus::Active,
        'actual_start_at' => now(),
    ]);

    $this->actingAs($fixtures['user'])
        ->get(route('organization.crew-planning.index', ['view' => 'relief']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('relief_desk.rows.0.relief_status', CrewReliefStatus::ReliefOnboard->value)
            ->where('relief_desk.rows.0.recommended_action.key', 'review_source')
            ->where('relief_desk.rows.0.recommended_action.label', 'Review Source Assignment')
        );

    expect($source->fresh()->status)->toBe(CrewAssignmentStatus::Active)
        ->and($source->fresh()->currentPhase->phase_code)->toBe(CrewPhaseCode::OnVessel);
});

test('cancelled completed and soft-deleted reliefs do not count as operational relief', function () {
    $fixtures = makeReliefDeskFixtures();

    $cancelledSource = makeReliefDeskOnboard($fixtures['company'], $fixtures['rank'], $fixtures['vessel'], $fixtures['today'], 6, 'Cancelled Source');
    $cancelledPlan = makeReliefPlanFor($cancelledSource, makeReliefEmployee($fixtures['company'], $fixtures['rank'], 'Cancelled Relief'), $fixtures['today']->addDays(6));
    $cancelledLinked = app(CreateCrewAssignmentFromPlanning::class)->handle($cancelledPlan, $fixtures['user']->id);
    $cancelledLinked->update(['status' => CrewAssignmentStatus::Cancelled]);

    $completedSource = makeReliefDeskOnboard($fixtures['company'], $fixtures['rank'], makeCrewMovementVessel('Completed Vessel', $fixtures['company']), $fixtures['today'], 6, 'Completed Source');
    $completedPlan = makeReliefPlanFor($completedSource, makeReliefEmployee($fixtures['company'], $fixtures['rank'], 'Completed Relief'), $fixtures['today']->addDays(6));
    $completedLinked = app(CreateCrewAssignmentFromPlanning::class)->handle($completedPlan, $fixtures['user']->id);
    $completedLinked->update(['status' => CrewAssignmentStatus::Completed]);

    $deletedSource = makeReliefDeskOnboard($fixtures['company'], $fixtures['rank'], makeCrewMovementVessel('Deleted Plan Vessel', $fixtures['company']), $fixtures['today'], 6, 'Deleted Source');
    $deletedPlan = makeReliefPlanFor($deletedSource, makeReliefEmployee($fixtures['company'], $fixtures['rank'], 'Deleted Relief'), $fixtures['today']->addDays(6));
    $deletedPlan->delete();

    $this->actingAs($fixtures['user'])
        ->get(route('organization.crew-planning.index', ['view' => 'relief']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('relief_desk.rows', 3)
            ->where('relief_desk.summary.needs_relief', 3)
        );
});

test('relief desk risk follows existing readiness semantics', function (int $days, string $status, string $risk) {
    $fixtures = makeReliefDeskFixtures();
    $source = makeReliefDeskOnboard($fixtures['company'], $fixtures['rank'], $fixtures['vessel'], $fixtures['today'], $days, 'Risk Source');

    if ($status === CrewReliefStatus::ReadyToJoin->value) {
        $plan = makeReliefPlanFor($source, makeReliefEmployee($fixtures['company'], $fixtures['rank'], 'Ready Relief'), $fixtures['today']->addDays(max($days, 1)));
        $linked = app(CreateCrewAssignmentFromPlanning::class)->handle($plan, $fixtures['user']->id);
        $linked->update(['status' => CrewAssignmentStatus::Active]);
        $linked->currentPhase->update([
            'phase_code' => CrewPhaseCode::ReadyToJoin,
            'status' => CrewPhaseStatus::Active,
        ]);
    }

    $this->actingAs($fixtures['user'])
        ->get(route('organization.crew-planning.index', ['view' => 'relief']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('relief_desk.rows.0.relief_status', $status)
            ->where('relief_desk.rows.0.relief_risk', $risk)
        );
})->with([
    'far no relief' => [20, CrewReliefStatus::NoRelief->value, CrewReliefRisk::None->value],
    'warning window' => [10, CrewReliefStatus::NoRelief->value, CrewReliefRisk::Warning->value],
    'critical window' => [5, CrewReliefStatus::NoRelief->value, CrewReliefRisk::Critical->value],
    'due today' => [0, CrewReliefStatus::NoRelief->value, CrewReliefRisk::Critical->value],
    'overdue' => [-2, CrewReliefStatus::NoRelief->value, CrewReliefRisk::Critical->value],
    'ready to join' => [3, CrewReliefStatus::ReadyToJoin->value, CrewReliefRisk::None->value],
]);

test('relief desk surfaces mobilisation readiness for pre-join relief without blocking', function (string $case) {
    $fixtures = makeReliefDeskFixtures();
    $reliefEmployee = makeReliefEmployee($fixtures['company'], $fixtures['rank'], 'Readiness Relief');
    $source = makeReliefDeskOnboard($fixtures['company'], $fixtures['rank'], $fixtures['vessel'], $fixtures['today'], 8, 'Readiness Source');
    $plan = makeReliefPlanFor($source, $reliefEmployee, $fixtures['today']->addDays(8));
    app(CreateCrewAssignmentFromPlanning::class)->handle($plan, $fixtures['user']->id);

    if ($case !== 'none') {
        $type = DocumentType::query()->create([
            'title' => 'Seaman Book Desk '.uniqid(),
            'is_active' => true,
        ]);
        makeDocumentRequirement($fixtures['company']->id, $type->id, requiredForAll: true);

        if ($case !== 'missing') {
            EmployeeDocument::query()->create([
                'company_id' => $fixtures['company']->id,
                'employee_id' => $reliefEmployee->id,
                'document_type_id' => $type->id,
                'type' => 'other',
                'document_type' => (string) $type->id,
                'file_path' => 'employee-documents/test/seaman.pdf',
                'expiry_date' => match ($case) {
                    'ready' => '2027-01-01',
                    'attention' => '2026-09-20',
                    default => '2027-01-01',
                },
                'status' => match ($case) {
                    'ready' => 'valid',
                    'attention' => 'expiring_soon',
                    default => 'valid',
                },
            ]);
        }
    }

    $expectedStatus = match ($case) {
        'none' => CrewMobilisationReadinessStatus::Ready->value,
        'missing' => CrewMobilisationReadinessStatus::NotReady->value,
        'ready' => CrewMobilisationReadinessStatus::Ready->value,
        'attention' => CrewMobilisationReadinessStatus::Attention->value,
    };
    $expectedLabel = match ($case) {
        'none' => 'No Checks Configured',
        'missing' => 'Not Ready',
        'ready' => 'Ready',
        'attention' => 'Attention',
    };

    $this->actingAs($fixtures['user'])
        ->get(route('organization.crew-planning.index', ['view' => 'relief']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('relief_desk.rows.0.mobilisation_readiness.status', $expectedStatus)
            ->where('relief_desk.rows.0.mobilisation_readiness.status_label', $expectedLabel)
            ->where('relief_desk.rows.0.recommended_action.key', 'open_relief_assignment')
        );
})->with([
    'no checks configured' => ['none'],
    'missing' => ['missing'],
    'ready' => ['ready'],
    'attention' => ['attention'],
]);

test('plan relief is hidden without planning create permission', function () {
    $fixtures = makeReliefDeskFixtures([
        'crew_operations.planning.view',
        'crew_operations.assignments.view',
    ]);
    makeReliefDeskOnboard($fixtures['company'], $fixtures['rank'], $fixtures['vessel'], $fixtures['today'], 4, 'No Create');

    $this->actingAs($fixtures['user'])
        ->get(route('organization.crew-planning.index', ['view' => 'relief']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('relief_desk.rows.0.recommended_action.key', 'plan_relief')
            ->where('relief_desk.rows.0.recommended_action.href', null)
        );
});

test('open assignment actions are hidden without assignment view permission', function () {
    $fixtures = makeReliefDeskFixtures([
        'crew_operations.planning.view',
        'crew_operations.planning.create',
    ]);
    $source = makeReliefDeskOnboard($fixtures['company'], $fixtures['rank'], $fixtures['vessel'], $fixtures['today'], 9, 'Hidden Assignment');
    $plan = makeReliefPlanFor($source, makeReliefEmployee($fixtures['company'], $fixtures['rank'], 'Hidden Relief'), $fixtures['today']->addDays(9));
    app(CreateCrewAssignmentFromPlanning::class)->handle($plan, $fixtures['user']->id);

    $this->actingAs($fixtures['user'])
        ->get(route('organization.crew-planning.index', ['view' => 'relief']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('relief_desk.rows.0.recommended_action.href', null)
            ->where('relief_desk.rows.0.relief_crew_assignment_id', null)
            ->where('relief_desk.rows.0.source_href', null)
        );
});

test('plan relief href uses the existing planning create workflow', function () {
    $fixtures = makeReliefDeskFixtures();
    $source = makeReliefDeskOnboard($fixtures['company'], $fixtures['rank'], $fixtures['vessel'], $fixtures['today'], 5, 'Prefill Source');

    $this->actingAs($fixtures['user'])
        ->get(route('organization.crew-planning.index', ['view' => 'relief']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('relief_desk.rows.0.recommended_action.href', function (?string $value) use ($source) {
                expect($value)->toBeString()
                    ->and($value)->toContain('open_create=1')
                    ->and($value)->toContain('relieves_crew_assignment_id='.$source->id)
                    ->and($value)->not->toContain('view=relief');

                return true;
            })
        );
});

test('focus filters limit the desk to matching operational buckets', function () {
    $fixtures = makeReliefDeskFixtures();
    makeReliefDeskOnboard($fixtures['company'], $fixtures['rank'], $fixtures['vessel'], $fixtures['today'], 5, 'Needs Relief');
    $readySource = makeReliefDeskOnboard($fixtures['company'], $fixtures['rank'], makeCrewMovementVessel('Ready Vessel', $fixtures['company']), $fixtures['today'], 4, 'Ready Source');
    $plan = makeReliefPlanFor($readySource, makeReliefEmployee($fixtures['company'], $fixtures['rank'], 'Ready Relief'), $fixtures['today']->addDays(4));
    $linked = app(CreateCrewAssignmentFromPlanning::class)->handle($plan, $fixtures['user']->id);
    $linked->update(['status' => CrewAssignmentStatus::Active]);
    $linked->currentPhase->update([
        'phase_code' => CrewPhaseCode::ReadyToJoin,
        'status' => CrewPhaseStatus::Active,
    ]);

    $this->actingAs($fixtures['user'])
        ->get(route('organization.crew-planning.index', ['view' => 'relief', 'focus' => 'needs_relief']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('relief_desk.rows', 1)
            ->where('relief_desk.rows.0.relief_status', CrewReliefStatus::NoRelief->value)
            ->where('relief_desk.summary.needs_relief', 1)
            ->where('relief_desk.summary.ready', 1)
        );
});

test('another company source assignment cannot appear on the relief desk', function () {
    $fixtures = makeReliefDeskFixtures();
    $other = makeCrewAssignmentFixtures();
    $foreignVessel = makeCrewMovementVessel('Foreign Desk Vessel', $other['company']);
    makeReliefDeskOnboard($other['company'], $other['rank'], $foreignVessel, $fixtures['today'], 3, 'Foreign Crew');

    $this->actingAs($fixtures['user'])
        ->get(route('organization.crew-planning.index', ['view' => 'relief']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('relief_desk.rows', 0));
});

test('another company planning row is ignored for a local source assignment', function () {
    $fixtures = makeReliefDeskFixtures();
    $source = makeReliefDeskOnboard($fixtures['company'], $fixtures['rank'], $fixtures['vessel'], $fixtures['today'], 6, 'Local Source');
    $other = makeCrewAssignmentFixtures();
    CrewPlanningAssignment::query()->create([
        'company_id' => $other['company']->id,
        'vessel_id' => $source->vessel_id,
        'rank_id' => $source->rank_id,
        'employee_id' => Employee::factory()->forCompany($other['company'])->create([
            'rank_id' => $other['rank']->id,
            'status' => 'active',
        ])->id,
        'relieves_crew_assignment_id' => $source->id,
        'planned_join_date' => $fixtures['today']->addDays(6)->toDateString(),
        'planned_leave_date' => $fixtures['today']->addDays(90)->toDateString(),
    ]);

    $this->actingAs($fixtures['user'])
        ->get(route('organization.crew-planning.index', ['view' => 'relief']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('relief_desk.rows.0.relief_status', CrewReliefStatus::NoRelief->value)
            ->where('relief_desk.rows.0.relief_employee', null)
        );
});

test('linked relief assignment from another company is not exposed', function () {
    $fixtures = makeReliefDeskFixtures();
    $source = makeReliefDeskOnboard($fixtures['company'], $fixtures['rank'], $fixtures['vessel'], $fixtures['today'], 6, 'Leak Source');
    $other = makeCrewAssignmentFixtures();
    $foreignEmployee = Employee::factory()->forCompany($other['company'])->create([
        'rank_id' => $other['rank']->id,
        'status' => 'active',
        'name' => 'Foreign Relief',
    ]);
    $foreignAssignment = makeCurrentCrewPhaseAssignment(
        $other['company'],
        $foreignEmployee,
        $other['rank'],
        makeCrewMovementVessel('Foreign Linked', $other['company']),
        CrewPhaseCode::TravelIn,
    );
    CrewPlanningAssignment::query()->create([
        'company_id' => $fixtures['company']->id,
        'vessel_id' => $source->vessel_id,
        'rank_id' => $source->rank_id,
        'employee_id' => makeReliefEmployee($fixtures['company'], $fixtures['rank'], 'Local Plan Employee')->id,
        'crew_assignment_id' => $foreignAssignment->id,
        'relieves_crew_assignment_id' => $source->id,
        'planned_join_date' => $fixtures['today']->addDays(6)->toDateString(),
        'planned_leave_date' => $fixtures['today']->addDays(90)->toDateString(),
    ]);

    $this->actingAs($fixtures['user'])
        ->get(route('organization.crew-planning.index', ['view' => 'relief']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('relief_desk.rows.0.relief_crew_assignment_id', null)
            ->where('relief_desk.rows.0.relief_employee.name', 'Local Plan Employee')
        );
});

test('crew planning gantt view is unchanged when relief desk is unused', function () {
    $fixtures = makeReliefDeskFixtures();
    makeReliefDeskOnboard($fixtures['company'], $fixtures['rank'], $fixtures['vessel'], $fixtures['today'], 5, 'Gantt Source');

    $this->actingAs($fixtures['user'])
        ->get(route('organization.crew-planning.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('view', 'planning')
            ->has('relief_desk.rows', 0)
            ->has('bars')
        );
});
