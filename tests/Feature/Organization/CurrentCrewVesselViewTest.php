<?php

use App\Enums\CrewPhaseCode;
use App\Exports\CurrentCrewOnboardVesselsExport;
use App\Models\Employee;
use App\Models\Rank;
use App\Models\VesselManning;
use App\Support\CrewMovements\CurrentCrewVesselQuery;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Maatwebsite\Excel\Facades\Excel;

test('vessel view lists current active p4 crew under the vessel', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank, 'vessel' => $vessel] = makeCurrentCrewVesselViewFixtures();

    makeActiveOnVesselAssignment($company, $employee, $rank, $vessel);

    $this->actingAs($user)
        ->get(route('organization.crew-assignments.index', ['view' => 'vessel']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/crew/index')
            ->where('view', 'vessel')
            ->has('vessels', 1)
            ->where('vessels.0.id', $vessel->id)
            ->where('vessels.0.onboard_count', 1)
            ->where('vessels.0.crew.0.employee.id', $employee->id)
            ->has('assignments', 0));
});

test('vessel view excludes non-p4 current phases even when the assignment already has a vessel', function (CrewPhaseCode $phaseCode) {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank, 'vessel' => $vessel] = makeCurrentCrewVesselViewFixtures();

    makeCurrentCrewPhaseAssignment($company, $employee, $rank, $vessel, $phaseCode);

    $this->actingAs($user)
        ->get(route('organization.crew-assignments.index', ['view' => 'vessel']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('view', 'vessel')
            ->has('vessels', 0));
})->with([
    'p2a' => CrewPhaseCode::JoinStandby,
    'p2b' => CrewPhaseCode::Training,
    'p3' => CrewPhaseCode::ReadyToJoin,
    'p5' => CrewPhaseCode::DemobStandby,
    'p6' => CrewPhaseCode::HomeRedeploy,
]);

test('vessel view excludes inactive employees with a leftover active p4 assignment', function () {
    ['user' => $user, 'company' => $company, 'rank' => $rank, 'vessel' => $vessel] = makeCurrentCrewVesselViewFixtures();

    $inactive = Employee::factory()->forCompany($company)->inactive()->create([
        'rank_id' => $rank->id,
    ]);
    makeActiveOnVesselAssignment($company, $inactive, $rank, $vessel);

    $this->actingAs($user)
        ->get(route('organization.crew-assignments.index', ['view' => 'vessel']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('vessels', 0));
});

test('vessel view groups onboard counts by vessel', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank, 'vessel' => $vesselA] = makeCurrentCrewVesselViewFixtures();
    $vesselB = makeCrewMovementVessel('SAFEEN 3000', $company);

    makeActiveOnVesselAssignment($company, $employee, $rank, $vesselA);

    foreach (range(1, 3) as $index) {
        $extra = Employee::factory()->forCompany($company)->create([
            'rank_id' => $rank->id,
            'name' => "Vessel A Crew {$index}",
        ]);
        makeActiveOnVesselAssignment($company, $extra, $rank, $vesselA);
    }

    foreach (range(1, 2) as $index) {
        $extra = Employee::factory()->forCompany($company)->create([
            'rank_id' => $rank->id,
            'name' => "Vessel B Crew {$index}",
        ]);
        makeActiveOnVesselAssignment($company, $extra, $rank, $vesselB);
    }

    $this->actingAs($user)
        ->get(route('organization.crew-assignments.index', ['view' => 'vessel']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('vessels', 2)
            ->where('pagination.total', 2)
            ->where('vessels.0.onboard_count', 4)
            ->has('vessels.0.crew', 4)
            ->where('vessels.1.onboard_count', 2)
            ->has('vessels.1.crew', 2));
});

test('vessel view uses vessel manning for required count and gap', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank, 'vessel' => $vessel] = makeCurrentCrewVesselViewFixtures();

    VesselManning::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
        'required_count' => 6,
    ]);

    makeActiveOnVesselAssignment($company, $employee, $rank, $vessel);

    foreach (range(1, 3) as $index) {
        $extra = Employee::factory()->forCompany($company)->create([
            'rank_id' => $rank->id,
            'name' => "Manning Crew {$index}",
        ]);
        makeActiveOnVesselAssignment($company, $extra, $rank, $vessel);
    }

    $this->actingAs($user)
        ->get(route('organization.crew-assignments.index', ['view' => 'vessel']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('vessels.0.onboard_count', 4)
            ->where('vessels.0.required_count', 6)
            ->where('vessels.0.gap', 2)
            ->where('vessels.0.coverage_label', 'Short 2'));
});

test('vessel view rank filter keeps only matching onboard crew', function () {
    ['user' => $user, 'company' => $company, 'employee' => $chief, 'rank' => $chiefRank, 'vessel' => $vessel] = makeCurrentCrewVesselViewFixtures();
    $abRank = Rank::query()->create([
        'name' => 'Able Seaman '.Str::uuid()->toString(),
        'is_active' => true,
    ]);
    $ab = Employee::factory()->forCompany($company)->create([
        'rank_id' => $abRank->id,
        'name' => 'ABLE SEAMAN CREW',
    ]);

    makeActiveOnVesselAssignment($company, $chief, $chiefRank, $vessel);
    makeActiveOnVesselAssignment($company, $ab, $abRank, $vessel);

    $this->actingAs($user)
        ->get(route('organization.crew-assignments.index', [
            'view' => 'vessel',
            'rank_id' => $chiefRank->id,
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('vessels', 1)
            ->where('vessels.0.onboard_count', 1)
            ->where('vessels.0.crew.0.employee.id', $chief->id)
            ->has('vessels.0.crew', 1));
});

test('vessel view search shows only vessels containing matching onboard crew', function () {
    ['user' => $user, 'company' => $company, 'rank' => $rank] = makeCurrentCrewVesselViewFixtures();
    $vesselA = makeCrewMovementVessel('SEARCH VESSEL A', $company);
    $vesselB = makeCrewMovementVessel('SEARCH VESSEL B', $company);
    $arief = Employee::factory()->forCompany($company)->create([
        'rank_id' => $rank->id,
        'name' => 'ARIEF POERNAMA',
        'employee_no' => 'EMP-ARIEF',
    ]);
    $other = Employee::factory()->forCompany($company)->create([
        'rank_id' => $rank->id,
        'name' => 'OTHER CREW',
    ]);

    makeActiveOnVesselAssignment($company, $arief, $rank, $vesselA);
    makeActiveOnVesselAssignment($company, $other, $rank, $vesselB);

    $this->actingAs($user)
        ->get(route('organization.crew-assignments.index', [
            'view' => 'vessel',
            'search' => 'ARIEF',
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('vessels', 1)
            ->where('vessels.0.id', $vesselA->id)
            ->where('vessels.0.onboard_count', 1)
            ->where('vessels.0.crew.0.employee.id', $arief->id));
});

test('vessel view pagination is vessel-aware and does not split a vessel roster', function () {
    ['user' => $user, 'company' => $company, 'rank' => $rank] = makeCurrentCrewVesselViewFixtures();

    $first = makeCrewMovementVessel('AAA Vessel', $company);
    $second = makeCrewMovementVessel('BBB Vessel', $company);
    $third = makeCrewMovementVessel('CCC Vessel', $company);

    foreach ([$first, $second, $third] as $index => $vessel) {
        $employee = Employee::factory()->forCompany($company)->create([
            'rank_id' => $rank->id,
            'name' => "Pagination Crew {$index}",
        ]);
        makeActiveOnVesselAssignment($company, $employee, $rank, $vessel);
        $secondEmployee = Employee::factory()->forCompany($company)->create([
            'rank_id' => $rank->id,
            'name' => "Pagination Mate {$index}",
        ]);
        makeActiveOnVesselAssignment($company, $secondEmployee, $rank, $vessel);
    }

    $this->actingAs($user)
        ->get(route('organization.crew-assignments.index', [
            'view' => 'vessel',
            'per_page' => 2,
            'page' => 1,
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('vessels', 2)
            ->where('pagination.total', 3)
            ->where('pagination.last_page', 2)
            ->where('pagination.per_page', 2)
            ->where('vessels.0.onboard_count', 2)
            ->has('vessels.0.crew', 2)
            ->where('vessels.1.onboard_count', 2)
            ->has('vessels.1.crew', 2));

    $this->actingAs($user)
        ->get(route('organization.crew-assignments.index', [
            'view' => 'vessel',
            'per_page' => 2,
            'page' => 2,
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('vessels', 1)
            ->where('pagination.total', 3)
            ->where('pagination.current_page', 2)
            ->where('vessels.0.onboard_count', 2)
            ->has('vessels.0.crew', 2));
});

test('vessel view and export are isolated to the active company', function () {
    Excel::fake();

    ['user' => $user, 'company' => $companyA, 'employee' => $employeeA, 'rank' => $rankA] = makeCurrentCrewVesselViewFixtures();
    $vesselA = makeCrewMovementVessel('Company A Vessel', $companyA);
    makeActiveOnVesselAssignment($companyA, $employeeA, $rankA, $vesselA);

    $companyB = makeCrewAssignmentFixtures();
    $vesselB = makeCrewMovementVessel('Company B Vessel', $companyB['company']);
    makeActiveOnVesselAssignment($companyB['company'], $companyB['employee'], $companyB['rank'], $vesselB);

    $this->actingAs($user)
        ->get(route('organization.crew-assignments.index', ['view' => 'vessel']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('vessels', 1)
            ->where('vessels.0.id', $vesselA->id));

    $this->actingAs($user)
        ->get(route('organization.crew-assignments.onboard-vessels.export', ['format' => 'xlsx']))
        ->assertOk();

    Excel::assertDownloaded(
        'current-crew-onboard-vessels-'.now()->toDateString().'.xlsx',
        fn (CurrentCrewOnboardVesselsExport $export): bool => $export->collection()->count() === 1
            && (int) $export->collection()->first()?->company_id === $companyA->id,
    );
});

test('vessel view export includes filtered current p4 crew beyond the current page', function () {
    Excel::fake();

    ['user' => $user, 'company' => $company, 'rank' => $rank] = makeCurrentCrewVesselViewFixtures();

    foreach (range(1, 4) as $index) {
        $vessel = makeCrewMovementVessel("Export Vessel {$index}", $company);
        $employee = Employee::factory()->forCompany($company)->create([
            'rank_id' => $rank->id,
            'name' => "Export Crew {$index}",
            'employee_no' => "EXP-{$index}",
        ]);
        makeActiveOnVesselAssignment($company, $employee, $rank, $vessel);
    }

    $p3Employee = Employee::factory()->forCompany($company)->create([
        'rank_id' => $rank->id,
        'name' => 'P3 NOT ONBOARD',
    ]);
    makeCurrentCrewPhaseAssignment(
        $company,
        $p3Employee,
        $rank,
        makeCrewMovementVessel('P3 Vessel', $company),
        CrewPhaseCode::ReadyToJoin,
    );

    $page = CurrentCrewVesselQuery::paginate($company->id, [
        'search' => 'Export Crew',
        'per_page' => 2,
    ]);

    expect($page->total())->toBe(4)
        ->and($page->count())->toBe(2)
        ->and(CurrentCrewVesselQuery::exportAssignments($company->id, ['search' => 'Export Crew']))->toHaveCount(4);

    $this->actingAs($user)
        ->get(route('organization.crew-assignments.onboard-vessels.export', [
            'format' => 'xlsx',
            'search' => 'Export Crew',
            'page' => 1,
            'per_page' => 2,
        ]))
        ->assertOk();

    Excel::assertDownloaded(
        'current-crew-onboard-vessels-'.now()->toDateString().'.xlsx',
        function (CurrentCrewOnboardVesselsExport $export) use ($p3Employee): bool {
            return $export->collection()->count() === 4
                && $export->collection()->doesntContain('employee_id', $p3Employee->id)
                && in_array('Vessel', $export->headings(), true)
                && in_array('Days Onboard', $export->headings(), true);
        },
    );
});

test('vessel view export selected ids are revalidated and ignore invalid records', function () {
    Excel::fake();

    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank, 'vessel' => $vessel] = makeCurrentCrewVesselViewFixtures();
    $kept = makeActiveOnVesselAssignment($company, $employee, $rank, $vessel);

    $otherEmployee = Employee::factory()->forCompany($company)->create([
        'rank_id' => $rank->id,
        'name' => 'OTHER ONBOARD',
    ]);
    $other = makeActiveOnVesselAssignment($company, $otherEmployee, $rank, $vessel);

    $p3Employee = Employee::factory()->forCompany($company)->create([
        'rank_id' => $rank->id,
        'name' => 'P3 NOT ONBOARD',
    ]);
    $p3 = makeCurrentCrewPhaseAssignment(
        $company,
        $p3Employee,
        $rank,
        $vessel,
        CrewPhaseCode::ReadyToJoin,
    );

    $companyB = makeCrewAssignmentFixtures();
    $foreign = makeActiveOnVesselAssignment(
        $companyB['company'],
        $companyB['employee'],
        $companyB['rank'],
        makeCrewMovementVessel('Foreign Vessel', $companyB['company']),
    );

    $this->actingAs($user)
        ->get(route('organization.crew-assignments.onboard-vessels.export', [
            'format' => 'xlsx',
            'scope' => 'selected',
            'assignment_ids' => [$kept->id, $p3->id, $foreign->id, 0, 'abc', 999999],
        ]))
        ->assertOk();

    Excel::assertDownloaded(
        'current-crew-onboard-vessels-'.now()->toDateString().'.xlsx',
        function (CurrentCrewOnboardVesselsExport $export) use ($kept, $other): bool {
            $ids = $export->collection()->pluck('id')->all();

            return $export->collection()->count() === 1
                && $ids === [$kept->id]
                && ! in_array($other->id, $ids, true);
        },
    );
});

test('vessel view export selected ids still respect active filters', function () {
    Excel::fake();

    ['user' => $user, 'company' => $company, 'rank' => $rank] = makeCurrentCrewVesselViewFixtures();
    $vessel = makeCrewMovementVessel('Filter Vessel', $company);
    $arief = Employee::factory()->forCompany($company)->create([
        'rank_id' => $rank->id,
        'name' => 'ARIEF POERNAMA',
    ]);
    $other = Employee::factory()->forCompany($company)->create([
        'rank_id' => $rank->id,
        'name' => 'OTHER CREW',
    ]);
    $ariefAssignment = makeActiveOnVesselAssignment($company, $arief, $rank, $vessel);
    $otherAssignment = makeActiveOnVesselAssignment($company, $other, $rank, $vessel);

    $this->actingAs($user)
        ->get(route('organization.crew-assignments.onboard-vessels.export', [
            'format' => 'xlsx',
            'search' => 'ARIEF',
            'scope' => 'selected',
            'assignment_ids' => [$ariefAssignment->id, $otherAssignment->id],
        ]))
        ->assertOk();

    Excel::assertDownloaded(
        'current-crew-onboard-vessels-'.now()->toDateString().'.xlsx',
        fn (CurrentCrewOnboardVesselsExport $export): bool => $export->collection()->count() === 1
            && (int) $export->collection()->first()?->id === $ariefAssignment->id,
    );
});

test('vessel view export requires assignment view permission', function () {
    ['user' => $user, 'company' => $company] = makeCrewAssignmentFixtures();
    $user->update(['current_company_id' => $company->id]);

    $this->actingAs($user)
        ->get(route('organization.crew-assignments.onboard-vessels.export'))
        ->assertForbidden();
});

test('p3 crew with a past planned join is not treated as onboard', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank, 'vessel' => $vessel] = makeCurrentCrewVesselViewFixtures();

    makeCurrentCrewPhaseAssignment(
        $company,
        $employee,
        $rank,
        $vessel,
        CrewPhaseCode::ReadyToJoin,
        ['planned_join_at' => now()->subDays(3)],
    );

    $this->actingAs($user)
        ->get(route('organization.crew-assignments.index', ['view' => 'vessel']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('vessels', 0));
});

test('active p4 crew remains onboard after planned sign-off has passed', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank, 'vessel' => $vessel] = makeCurrentCrewVesselViewFixtures();

    makeActiveOnVesselAssignment($company, $employee, $rank, $vessel, [
        'planned_signoff_at' => now()->subDays(5),
    ]);

    $this->actingAs($user)
        ->get(route('organization.crew-assignments.index', ['view' => 'vessel']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('vessels', 1)
            ->where('vessels.0.crew.0.employee.id', $employee->id));
});

test('crew view remains the default and is unchanged when view is omitted', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank, 'vessel' => $vessel] = makeCurrentCrewVesselViewFixtures();
    makeActiveOnVesselAssignment($company, $employee, $rank, $vessel);

    $this->actingAs($user)
        ->get(route('organization.crew-assignments.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('view', 'crew')
            ->has('assignments', 1)
            ->has('vessels', 0)
            ->where('assignments.0.employee.id', $employee->id));
});

test('selected export scope does not fall back to all filtered when ids are empty', function () {
    Excel::fake();

    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank, 'vessel' => $vessel] = makeCurrentCrewVesselViewFixtures();
    makeActiveOnVesselAssignment($company, $employee, $rank, $vessel);

    $this->actingAs($user)
        ->from(route('organization.crew-assignments.index', ['view' => 'vessel']))
        ->get(route('organization.crew-assignments.onboard-vessels.export', [
            'format' => 'xlsx',
            'scope' => 'selected',
        ]))
        ->assertRedirect(route('organization.crew-assignments.index', ['view' => 'vessel']))
        ->assertSessionHasErrors('assignment_ids');
});

test('selected export scope with only invalid ids does not export the filtered set', function () {
    Excel::fake();

    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank, 'vessel' => $vessel] = makeCurrentCrewVesselViewFixtures();
    makeActiveOnVesselAssignment($company, $employee, $rank, $vessel);

    $this->actingAs($user)
        ->from(route('organization.crew-assignments.index', ['view' => 'vessel']))
        ->get(route('organization.crew-assignments.onboard-vessels.export', [
            'format' => 'xlsx',
            'scope' => 'selected',
            'assignment_ids' => [0, 'abc', 999999],
        ]))
        ->assertRedirect(route('organization.crew-assignments.index', ['view' => 'vessel']))
        ->assertSessionHasErrors('assignment_ids');
});

test('all export scope ignores client assignment ids', function () {
    Excel::fake();

    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank, 'vessel' => $vessel] = makeCurrentCrewVesselViewFixtures();
    $kept = makeActiveOnVesselAssignment($company, $employee, $rank, $vessel);
    $otherEmployee = Employee::factory()->forCompany($company)->create([
        'rank_id' => $rank->id,
        'name' => 'SECOND ONBOARD',
    ]);
    $other = makeActiveOnVesselAssignment($company, $otherEmployee, $rank, $vessel);

    $this->actingAs($user)
        ->get(route('organization.crew-assignments.onboard-vessels.export', [
            'format' => 'xlsx',
            'scope' => 'all',
            'assignment_ids' => [$kept->id],
        ]))
        ->assertOk();

    Excel::assertDownloaded(
        'current-crew-onboard-vessels-'.now()->toDateString().'.xlsx',
        function (CurrentCrewOnboardVesselsExport $export) use ($kept, $other): bool {
            $ids = $export->collection()->pluck('id')->all();

            return $export->collection()->count() === 2
                && in_array($kept->id, $ids, true)
                && in_array($other->id, $ids, true);
        },
    );
});

test('selected export scope revalidates mixed valid p3 foreign and garbage ids', function () {
    Excel::fake();

    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank, 'vessel' => $vessel] = makeCurrentCrewVesselViewFixtures();
    $kept = makeActiveOnVesselAssignment($company, $employee, $rank, $vessel);

    $p3Employee = Employee::factory()->forCompany($company)->create([
        'rank_id' => $rank->id,
        'name' => 'P3 NOT ONBOARD',
    ]);
    $p3 = makeCurrentCrewPhaseAssignment(
        $company,
        $p3Employee,
        $rank,
        $vessel,
        CrewPhaseCode::ReadyToJoin,
    );

    $companyB = makeCrewAssignmentFixtures();
    $foreign = makeActiveOnVesselAssignment(
        $companyB['company'],
        $companyB['employee'],
        $companyB['rank'],
        makeCrewMovementVessel('Foreign Vessel', $companyB['company']),
    );

    $this->actingAs($user)
        ->get(route('organization.crew-assignments.onboard-vessels.export', [
            'format' => 'xlsx',
            'scope' => 'selected',
            'assignment_ids' => [$kept->id, $p3->id, $foreign->id, 0, 'abc', 999999],
        ]))
        ->assertOk();

    Excel::assertDownloaded(
        'current-crew-onboard-vessels-'.now()->toDateString().'.xlsx',
        function (CurrentCrewOnboardVesselsExport $export) use ($kept): bool {
            $ids = $export->collection()->pluck('id')->all();

            return $export->collection()->count() === 1
                && $ids === [$kept->id];
        },
    );
});
