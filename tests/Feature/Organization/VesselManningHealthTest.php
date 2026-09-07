<?php

use App\Enums\CrewAssignmentStatus;
use App\Enums\CrewPhaseCode;
use App\Enums\CrewPhaseStatus;
use App\Enums\VesselManningHealthStatus;
use App\Models\CrewAssignment;
use App\Models\CrewPlanningAssignment;
use App\Models\Employee;
use App\Models\Rank;
use App\Models\VesselManning;
use App\Support\CrewPlanning\CreateCrewAssignmentFromPlanning;
use App\Support\VesselManning\VesselManningHealthQuery;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

function grantVesselManningHealthPermissions($user, $company, array $extra = []): void
{
    grantCompanyPermissions($user, $company, array_values(array_unique([
        'crew_operations.vessels.view',
        'crew_operations.vessel_manning.view',
        ...$extra,
    ])));
}

function makeManningHealthContext(int $required = 4, ?string $vesselName = null): array
{
    $fixtures = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel($vesselName ?? 'Health Vessel');

    VesselManning::query()->create([
        'company_id' => $fixtures['company']->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $fixtures['rank']->id,
        'required_count' => $required,
    ]);

    return [
        ...$fixtures,
        'vessel' => $vessel,
    ];
}

function vesselManningHealth(array $ctx, $user = null): ?array
{
    return (new VesselManningHealthQuery)->forVessel(
        (int) $ctx['company']->id,
        (int) $ctx['vessel']->id,
        $user ?? $ctx['user'],
    );
}

function vesselOnboardAssignmentId(array $ctx): int
{
    return (int) CrewAssignment::query()
        ->where('company_id', $ctx['company']->id)
        ->where('vessel_id', $ctx['vessel']->id)
        ->value('id');
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-07 08:00:00', 'Asia/Dubai'));
});

it('reports not_configured when the vessel has no VesselManning', function () {
    $fixtures = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Unconfigured Health Vessel');
    grantVesselManningHealthPermissions($fixtures['user'], $fixtures['company']);

    $health = (new VesselManningHealthQuery)->forVessel(
        (int) $fixtures['company']->id,
        (int) $vessel->id,
        $fixtures['user'],
    );

    expect($health['status'])->toBe(VesselManningHealthStatus::NotConfigured->value)
        ->and($health['ranks'])->toBe([]);
});

it('reports critical when current onboard is below required', function () {
    $ctx = makeManningHealthContext(4);
    grantVesselManningHealthPermissions($ctx['user'], $ctx['company'], [
        'crew_operations.assignments.view',
    ]);

    makeActiveOnVesselAssignment($ctx['company'], $ctx['employee'], $ctx['rank'], $ctx['vessel']);
    $second = Employee::factory()->forCompany($ctx['company'])->create([
        'rank_id' => $ctx['rank']->id,
        'status' => 'active',
    ]);
    $third = Employee::factory()->forCompany($ctx['company'])->create([
        'rank_id' => $ctx['rank']->id,
        'status' => 'active',
    ]);
    makeActiveOnVesselAssignment($ctx['company'], $second, $ctx['rank'], $ctx['vessel']);
    makeActiveOnVesselAssignment($ctx['company'], $third, $ctx['rank'], $ctx['vessel']);

    $health = vesselManningHealth($ctx);

    expect($health['status'])->toBe(VesselManningHealthStatus::Critical->value)
        ->and($health['required'])->toBe(4)
        ->and($health['onboard'])->toBe(3)
        ->and($health['current_gap'])->toBe(1)
        ->and($health['ranks'][0]['status'])->toBe(VesselManningHealthStatus::Critical->value);
});

it('reports at_risk when currently covered but projected coverage falls within 30 days', function () {
    $ctx = makeManningHealthContext(4);
    grantVesselManningHealthPermissions($ctx['user'], $ctx['company'], [
        'crew_operations.assignments.view',
    ]);

    foreach (range(1, 4) as $i) {
        $employee = $i === 1
            ? $ctx['employee']
            : Employee::factory()->forCompany($ctx['company'])->create([
                'rank_id' => $ctx['rank']->id,
                'status' => 'active',
            ]);
        $signoff = $i === 1 ? '2026-09-18 00:00:00' : '2026-12-01 00:00:00';
        makeActiveOnVesselAssignment($ctx['company'], $employee, $ctx['rank'], $ctx['vessel'], [
            'planned_signoff_at' => $signoff,
        ]);
    }

    $health = vesselManningHealth($ctx);

    expect($health['status'])->toBe(VesselManningHealthStatus::AtRisk->value)
        ->and($health['onboard'])->toBe(4)
        ->and($health['current_gap'])->toBe(0)
        ->and($health['future_gap'])->toBe(1)
        ->and($health['next_gap_date'])->toBe('2026-09-18')
        ->and($health['ranks'][0]['status'])->toBe(VesselManningHealthStatus::AtRisk->value);
});

it('reports healthy when onboard and projected coverage stay at required', function () {
    $ctx = makeManningHealthContext(4);
    grantVesselManningHealthPermissions($ctx['user'], $ctx['company']);

    foreach (range(1, 4) as $i) {
        $employee = $i === 1
            ? $ctx['employee']
            : Employee::factory()->forCompany($ctx['company'])->create([
                'rank_id' => $ctx['rank']->id,
                'status' => 'active',
            ]);
        makeActiveOnVesselAssignment($ctx['company'], $employee, $ctx['rank'], $ctx['vessel'], [
            'planned_signoff_at' => '2026-12-01 00:00:00',
        ]);
    }

    $health = vesselManningHealth($ctx);

    expect($health['status'])->toBe(VesselManningHealthStatus::Healthy->value)
        ->and($health['current_gap'])->toBe(0)
        ->and($health['future_gap'])->toBe(0)
        ->and($health['ranks'][0]['status'])->toBe(VesselManningHealthStatus::Healthy->value);
});

it('keeps planned sign-off from reducing current onboard today', function () {
    $ctx = makeManningHealthContext(1);
    grantVesselManningHealthPermissions($ctx['user'], $ctx['company']);
    makeActiveOnVesselAssignment($ctx['company'], $ctx['employee'], $ctx['rank'], $ctx['vessel'], [
        'planned_signoff_at' => '2026-09-01 00:00:00',
    ]);

    $health = vesselManningHealth($ctx);

    expect($health['onboard'])->toBe(1)
        ->and($health['status'])->toBe(VesselManningHealthStatus::AtRisk->value)
        ->and($health['current_gap'])->toBe(0);
});

it('does not count planned relief as actual onboard before P4 join', function () {
    $ctx = makeManningHealthContext(1);
    grantVesselManningHealthPermissions($ctx['user'], $ctx['company']);
    $relief = Employee::factory()->forCompany($ctx['company'])->create([
        'rank_id' => $ctx['rank']->id,
        'status' => 'active',
    ]);
    CrewPlanningAssignment::query()->create([
        'company_id' => $ctx['company']->id,
        'vessel_id' => $ctx['vessel']->id,
        'rank_id' => $ctx['rank']->id,
        'employee_id' => $relief->id,
        'planned_join_date' => '2026-09-20',
        'planned_leave_date' => '2026-12-20',
    ]);

    $health = vesselManningHealth($ctx);

    expect($health['status'])->toBe(VesselManningHealthStatus::Critical->value)
        ->and($health['onboard'])->toBe(0)
        ->and($health['required'])->toBe(1);
});

it('marks a rank healthy at 1/1 and critical at 2 required with 1 onboard', function () {
    $ctx = makeManningHealthContext(1);
    grantVesselManningHealthPermissions($ctx['user'], $ctx['company']);
    makeActiveOnVesselAssignment($ctx['company'], $ctx['employee'], $ctx['rank'], $ctx['vessel']);

    $covered = vesselManningHealth($ctx);
    expect($covered['ranks'][0]['onboard'])->toBe(1)
        ->and($covered['ranks'][0]['status'])->toBe(VesselManningHealthStatus::Healthy->value);

    VesselManning::query()
        ->where('company_id', $ctx['company']->id)
        ->where('vessel_id', $ctx['vessel']->id)
        ->update(['required_count' => 2]);

    $short = vesselManningHealth($ctx);
    expect($short['ranks'][0]['status'])->toBe(VesselManningHealthStatus::Critical->value)
        ->and($short['ranks'][0]['onboard'])->toBe(1)
        ->and($short['ranks'][0]['required'])->toBe(2);
});

it('treats a same-day planned replacement as covering the projected gap', function () {
    $ctx = makeManningHealthContext(1);
    grantVesselManningHealthPermissions($ctx['user'], $ctx['company']);
    makeActiveOnVesselAssignment($ctx['company'], $ctx['employee'], $ctx['rank'], $ctx['vessel'], [
        'planned_signoff_at' => '2026-09-18 00:00:00',
    ]);
    $relief = Employee::factory()->forCompany($ctx['company'])->create([
        'rank_id' => $ctx['rank']->id,
        'status' => 'active',
    ]);
    CrewPlanningAssignment::query()->create([
        'company_id' => $ctx['company']->id,
        'vessel_id' => $ctx['vessel']->id,
        'rank_id' => $ctx['rank']->id,
        'employee_id' => $relief->id,
        'relieves_crew_assignment_id' => vesselOnboardAssignmentId($ctx),
        'planned_join_date' => '2026-09-18',
        'planned_leave_date' => '2026-12-18',
    ]);

    $health = vesselManningHealth($ctx);

    expect($health['status'])->toBe(VesselManningHealthStatus::Healthy->value)
        ->and($health['future_gap'])->toBe(0);
});

it('surfaces mobilising and ready-to-join relief without overriding projected coverage', function () {
    $ctx = makeManningHealthContext(1);
    grantVesselManningHealthPermissions($ctx['user'], $ctx['company'], [
        'crew_operations.assignments.view',
        'crew_operations.planning.view',
        'crew_operations.assignments.create',
    ]);
    $source = makeActiveOnVesselAssignment($ctx['company'], $ctx['employee'], $ctx['rank'], $ctx['vessel'], [
        'planned_signoff_at' => '2026-09-20 00:00:00',
    ]);
    $reliefEmployee = Employee::factory()->forCompany($ctx['company'])->create([
        'rank_id' => $ctx['rank']->id,
        'status' => 'active',
    ]);
    $plan = CrewPlanningAssignment::query()->create([
        'company_id' => $ctx['company']->id,
        'vessel_id' => $ctx['vessel']->id,
        'rank_id' => $ctx['rank']->id,
        'employee_id' => $reliefEmployee->id,
        'relieves_crew_assignment_id' => $source->id,
        'planned_join_date' => '2026-09-25',
        'planned_leave_date' => '2026-12-25',
    ]);
    $linked = app(CreateCrewAssignmentFromPlanning::class)->handle($plan, $ctx['user']->id);
    $linked->update(['status' => CrewAssignmentStatus::Active]);
    $linked->currentPhase->update([
        'phase_code' => CrewPhaseCode::TravelIn,
        'status' => CrewPhaseStatus::Active,
    ]);

    $mobilising = vesselManningHealth($ctx);
    expect($mobilising['status'])->toBe(VesselManningHealthStatus::AtRisk->value)
        ->and($mobilising['ranks'][0]['relief_status'])->toBe('mobilising');

    $linked->currentPhase->update([
        'phase_code' => CrewPhaseCode::ReadyToJoin,
        'status' => CrewPhaseStatus::Active,
    ]);

    $ready = vesselManningHealth($ctx);
    expect($ready['ranks'][0]['relief_status'])->toBe('ready_to_join')
        ->and($ready['status'])->toBe(VesselManningHealthStatus::AtRisk->value);
});

it('keeps overlap as supporting information rather than a shortage', function () {
    $ctx = makeManningHealthContext(1);
    grantVesselManningHealthPermissions($ctx['user'], $ctx['company']);
    makeActiveOnVesselAssignment($ctx['company'], $ctx['employee'], $ctx['rank'], $ctx['vessel'], [
        'planned_signoff_at' => '2026-09-25 00:00:00',
    ]);
    $early = Employee::factory()->forCompany($ctx['company'])->create([
        'rank_id' => $ctx['rank']->id,
        'status' => 'active',
    ]);
    CrewPlanningAssignment::query()->create([
        'company_id' => $ctx['company']->id,
        'vessel_id' => $ctx['vessel']->id,
        'rank_id' => $ctx['rank']->id,
        'employee_id' => $early->id,
        'planned_join_date' => '2026-09-18',
        'planned_leave_date' => '2026-12-18',
    ]);

    $health = vesselManningHealth($ctx);

    expect($health['status'])->toBe(VesselManningHealthStatus::Healthy->value)
        ->and($health['overlap_excess'])->toBeGreaterThan(0)
        ->and($health['ranks'][0]['overlap_excess'])->toBeGreaterThan(0);
});

it('evaluates multiple ranks on one vessel independently', function () {
    $ctx = makeManningHealthContext(1);
    grantVesselManningHealthPermissions($ctx['user'], $ctx['company']);
    $oiler = Rank::query()->create(['name' => 'Oiler Health '.uniqid(), 'is_active' => true]);
    VesselManning::query()->create([
        'company_id' => $ctx['company']->id,
        'vessel_id' => $ctx['vessel']->id,
        'rank_id' => $oiler->id,
        'required_count' => 2,
    ]);
    makeActiveOnVesselAssignment($ctx['company'], $ctx['employee'], $ctx['rank'], $ctx['vessel']);

    $health = vesselManningHealth($ctx);
    $byRank = collect($health['ranks'])->keyBy('rank_id');

    expect($health['status'])->toBe(VesselManningHealthStatus::Critical->value)
        ->and($byRank[$ctx['rank']->id]['status'])->toBe(VesselManningHealthStatus::Healthy->value)
        ->and($byRank[$oiler->id]['status'])->toBe(VesselManningHealthStatus::Critical->value)
        ->and($byRank[$oiler->id]['onboard'])->toBe(0);
});

it('hides manning health without vessel manning view and forbids the vessel without vessels view', function () {
    $ctx = makeManningHealthContext(1);
    grantCompanyPermissions($ctx['user'], $ctx['company'], ['crew_operations.vessels.view']);

    $this->actingAs($ctx['user'])
        ->get(route('organization.vessels.show', $ctx['vessel']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/vessels/show', false)
            ->where('manning_health', null)
        );

    grantCompanyPermissions($ctx['user'], $ctx['company'], []);

    $this->actingAs($ctx['user'])
        ->get(route('organization.vessels.show', $ctx['vessel']))
        ->assertForbidden();
});

it('shows aggregate health without crew names when assignments view is missing', function () {
    $ctx = makeManningHealthContext(1);
    grantVesselManningHealthPermissions($ctx['user'], $ctx['company']);
    makeActiveOnVesselAssignment($ctx['company'], $ctx['employee'], $ctx['rank'], $ctx['vessel'], [
        'planned_signoff_at' => '2026-09-18 00:00:00',
    ]);

    $this->actingAs($ctx['user'])
        ->get(route('organization.vessels.show', $ctx['vessel']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/vessels/show', false)
            ->where('manning_health.status', VesselManningHealthStatus::AtRisk->value)
            ->where('manning_health.include_crew_details', false)
            ->where('manning_health.ranks.0.signoffs', [])
            ->where('can.view_planning', false)
        );
});

it('does not leak another company health metrics current crew or requirements', function () {
    $a = makeManningHealthContext(1, 'Company A Health Vessel');
    $b = makeCrewAssignmentFixtures();
    $foreignVessel = makeCrewMovementVessel('Company B Health Vessel', $b['company']);
    VesselManning::query()->create([
        'company_id' => $b['company']->id,
        'vessel_id' => $foreignVessel->id,
        'rank_id' => $b['rank']->id,
        'required_count' => 9,
    ]);
    makeActiveOnVesselAssignment($b['company'], $b['employee'], $b['rank'], $foreignVessel);

    grantVesselManningHealthPermissions($b['user'], $b['company']);
    grantVesselManningHealthPermissions($a['user'], $a['company'], [
        'crew_operations.assignments.view',
    ]);

    $aHealth = (new VesselManningHealthQuery)->forVessel(
        (int) $a['company']->id,
        (int) $foreignVessel->id,
        $a['user'],
    );

    expect($aHealth)->toBeNull();

    $this->actingAs($a['user'])
        ->get(route('organization.vessels.show', $foreignVessel))
        ->assertNotFound();

    $this->actingAs($a['user'])
        ->get(route('organization.vessels.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/vessels/index', false)
            ->has('vessels', 1)
            ->where('vessels.0.id', $a['vessel']->id)
            ->where('vessels.0.manning_health.required', 1)
        );
});

it('filters the vessel index by health on the server and sorts critical first', function () {
    $fixtures = makeCrewAssignmentFixtures();
    grantVesselManningHealthPermissions($fixtures['user'], $fixtures['company']);

    $critical = makeCrewMovementVessel('Zebra Critical');
    $healthy = makeCrewMovementVessel('Alpha Healthy');
    $pending = makeCrewMovementVessel('Mid Unconfigured');

    VesselManning::query()->create([
        'company_id' => $fixtures['company']->id,
        'vessel_id' => $critical->id,
        'rank_id' => $fixtures['rank']->id,
        'required_count' => 2,
    ]);
    VesselManning::query()->create([
        'company_id' => $fixtures['company']->id,
        'vessel_id' => $healthy->id,
        'rank_id' => $fixtures['rank']->id,
        'required_count' => 1,
    ]);
    makeActiveOnVesselAssignment($fixtures['company'], $fixtures['employee'], $fixtures['rank'], $healthy);

    $this->actingAs($fixtures['user'])
        ->get(route('organization.vessels.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/vessels/index', false)
            ->has('vessels', 3)
            ->where('vessels.0.id', $critical->id)
            ->where('vessels.0.manning_health.status', 'critical')
            ->where('vessels.1.manning_health.status', 'healthy')
            ->where('vessels.2.manning_health.status', 'not_configured')
        );

    $this->actingAs($fixtures['user'])
        ->get(route('organization.vessels.index', ['health' => 'critical']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('vessels', 1)
            ->where('vessels.0.id', $critical->id)
            ->where('filters.health', 'critical')
        );

    expect($pending->name)->not->toBeEmpty();
});
