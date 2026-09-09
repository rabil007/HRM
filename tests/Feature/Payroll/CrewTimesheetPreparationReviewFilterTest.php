<?php

use App\Enums\CrewPhaseCode;
use App\Enums\CrewTimelineWarningCode;
use App\Enums\CrewTimesheetPayCategory;
use App\Models\CrewAssignment;
use App\Models\CrewTimesheetPreparation;
use App\Models\CrewTimesheetPreparationLine;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use Inertia\Testing\AssertableInertia as Assert;

function grantCrewTimelineReviewFilterPermissions(array $fixtures): void
{
    grantCompanyPermissions($fixtures['user'], $fixtures['company'], [
        'payroll.crew_timesheets.view',
        'payroll.crew_timesheets.prepare',
    ]);
}

/**
 * @return array{
 *     preparation: CrewTimesheetPreparation,
 *     operationsEmployee: Employee,
 *     hrEmployee: Employee,
 *     operationsDepartment: Department,
 *     hrDepartment: Department,
 *     parentDepartment: Department,
 *     childDepartment: Department,
 *     operationsAssignment: CrewAssignment,
 *     hrAssignment: CrewAssignment
 * }
 */
function makeFilteredCrewTimelineReview(array $fixtures): array
{
    $parentDepartment = Department::query()->create([
        'company_id' => $fixtures['company']->id,
        'name' => 'Marine',
        'parent_id' => null,
    ]);

    $operationsDepartment = Department::query()->create([
        'company_id' => $fixtures['company']->id,
        'name' => 'Deck',
        'parent_id' => $parentDepartment->id,
    ]);

    $hrDepartment = Department::query()->create([
        'company_id' => $fixtures['company']->id,
        'name' => 'Human Resources',
        'parent_id' => null,
    ]);

    $operationsPosition = Position::query()->create([
        'company_id' => $fixtures['company']->id,
        'department_id' => $operationsDepartment->id,
        'title' => 'Able Seaman',
        'status' => 'active',
    ]);

    $hrPosition = Position::query()->create([
        'company_id' => $fixtures['company']->id,
        'department_id' => $hrDepartment->id,
        'title' => 'HR Officer',
        'status' => 'active',
    ]);

    $operationsEmployee = $fixtures['employee'];
    $operationsEmployee->update([
        'name' => 'Operations Timeline Crew',
        'employee_no' => 'OPS-TL-001',
        'department_id' => $operationsDepartment->id,
        'position_id' => $operationsPosition->id,
    ]);

    $hrEmployee = Employee::factory()->forCompany($fixtures['company'])->create([
        'name' => 'HR Timeline Crew',
        'employee_no' => 'HR-TL-001',
        'status' => 'active',
        'rank_id' => $fixtures['rank']->id,
        'department_id' => $hrDepartment->id,
        'position_id' => $hrPosition->id,
    ]);

    $operationsAssignment = $fixtures['assignment'];
    $hrVessel = makeCrewMovementVessel('HR Timeline Vessel', $fixtures['company']);
    $hrAssignment = CrewAssignment::query()->create([
        'company_id' => $fixtures['company']->id,
        'assignment_no' => 'CA-TL-HR-'.fake()->unique()->numerify('######'),
        'employee_id' => $hrEmployee->id,
        'rank_id' => $fixtures['rank']->id,
        'vessel_id' => $hrVessel->id,
        'status' => $operationsAssignment->status,
        'source' => 'manual',
    ]);

    $operationsPhase = addTimelinePhase(
        $operationsAssignment,
        CrewPhaseCode::OnVessel,
        1,
        '2026-07-04 08:00:00',
        '2026-07-10 18:00:00',
    );
    $hrPhase = addTimelinePhase(
        $hrAssignment,
        CrewPhaseCode::OnVessel,
        1,
        '2026-07-04 08:00:00',
        '2026-07-12 18:00:00',
    );

    $preparation = CrewTimesheetPreparation::factory()
        ->forPeriod($fixtures['period'])
        ->create();

    CrewTimesheetPreparationLine::factory()
        ->forPreparation($preparation)
        ->forAssignment($operationsAssignment, $operationsPhase)
        ->create([
            'pay_category' => CrewTimesheetPayCategory::Onsite,
            'from_date' => '2026-07-04',
            'to_date' => '2026-07-10',
            'days' => 7,
        ]);

    CrewTimesheetPreparationLine::factory()
        ->forPreparation($preparation)
        ->forAssignment($hrAssignment, $hrPhase)
        ->create([
            'pay_category' => CrewTimesheetPayCategory::Onsite,
            'from_date' => '2026-07-04',
            'to_date' => '2026-07-12',
            'days' => 9,
        ]);

    return [
        'preparation' => $preparation,
        'operationsEmployee' => $operationsEmployee->fresh(),
        'hrEmployee' => $hrEmployee,
        'operationsDepartment' => $operationsDepartment,
        'hrDepartment' => $hrDepartment,
        'parentDepartment' => $parentDepartment,
        'childDepartment' => $operationsDepartment,
        'operationsAssignment' => $operationsAssignment,
        'hrAssignment' => $hrAssignment,
    ];
}

test('crew timeline review exposes search and department filter props', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    grantCrewTimelineReviewFilterPermissions($fixtures);
    $review = makeFilteredCrewTimelineReview($fixtures);

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->get(route('payroll.crew-timeline.show', [
            'payrollPeriod' => $fixtures['period'],
            'preparation' => $review['preparation'],
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('payroll/crew-timeline/show')
            ->has('employees', 2)
            ->where('search', '')
            ->where('filters.department_id', '')
            ->where('filters.position_id', '')
            ->where('filters.summary', '')
            ->where('department_tree_selected_id', null)
            ->has('department_tree')
            ->where('summary.total_employees', 2));
});

test('crew timeline review can search employees by name and number', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    grantCrewTimelineReviewFilterPermissions($fixtures);
    $review = makeFilteredCrewTimelineReview($fixtures);

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->get(route('payroll.crew-timeline.show', [
            'payrollPeriod' => $fixtures['period'],
            'preparation' => $review['preparation'],
            'search' => 'HR Timeline',
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('employees', 1)
            ->where('employees.0.employee_id', $review['hrEmployee']->id)
            ->where('search', 'HR Timeline')
            ->where('summary.total_employees', 2));

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->get(route('payroll.crew-timeline.show', [
            'payrollPeriod' => $fixtures['period'],
            'preparation' => $review['preparation'],
            'search' => 'OPS-TL-001',
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('employees', 1)
            ->where('employees.0.employee_id', $review['operationsEmployee']->id)
            ->where('summary.total_employees', 2));
});

test('crew timeline review can search by assignment number', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    grantCrewTimelineReviewFilterPermissions($fixtures);
    $review = makeFilteredCrewTimelineReview($fixtures);

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->get(route('payroll.crew-timeline.show', [
            'payrollPeriod' => $fixtures['period'],
            'preparation' => $review['preparation'],
            'search' => $review['hrAssignment']->assignment_no,
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('employees', 1)
            ->where('employees.0.employee_id', $review['hrEmployee']->id));
});

test('crew timeline review department filter returns the intersection and keeps summary totals', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    grantCrewTimelineReviewFilterPermissions($fixtures);
    $review = makeFilteredCrewTimelineReview($fixtures);

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->get(route('payroll.crew-timeline.show', [
            'payrollPeriod' => $fixtures['period'],
            'preparation' => $review['preparation'],
            'department_id' => $review['operationsDepartment']->id,
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('employees', 1)
            ->where('employees.0.employee_id', $review['operationsEmployee']->id)
            ->where('filters.department_id', (string) $review['operationsDepartment']->id)
            ->where('department_tree_selected_id', $review['operationsDepartment']->id)
            ->where('summary.total_employees', 2));
});

test('crew timeline review parent department filter includes child department employees', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    grantCrewTimelineReviewFilterPermissions($fixtures);
    $review = makeFilteredCrewTimelineReview($fixtures);

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->get(route('payroll.crew-timeline.show', [
            'payrollPeriod' => $fixtures['period'],
            'preparation' => $review['preparation'],
            'department_id' => $review['parentDepartment']->id,
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('employees', 1)
            ->where('employees.0.employee_id', $review['operationsEmployee']->id)
            ->where('summary.total_employees', 2));
});

test('crew timeline review can combine search with department filter', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    grantCrewTimelineReviewFilterPermissions($fixtures);
    $review = makeFilteredCrewTimelineReview($fixtures);

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->get(route('payroll.crew-timeline.show', [
            'payrollPeriod' => $fixtures['period'],
            'preparation' => $review['preparation'],
            'search' => 'Timeline Crew',
            'department_id' => $review['hrDepartment']->id,
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('employees', 1)
            ->where('employees.0.employee_id', $review['hrEmployee']->id)
            ->where('summary.total_employees', 2));
});

test('crew timeline review department filter stays isolated to the current company', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    grantCrewTimelineReviewFilterPermissions($fixtures);
    $review = makeFilteredCrewTimelineReview($fixtures);

    $foreign = makeDailyCrewTimelineFixtures();
    $foreignDepartment = Department::query()->create([
        'company_id' => $foreign['company']->id,
        'name' => 'Foreign Marine',
        'parent_id' => null,
    ]);

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->get(route('payroll.crew-timeline.show', [
            'payrollPeriod' => $fixtures['period'],
            'preparation' => $review['preparation'],
            'department_id' => $foreignDepartment->id,
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('employees', 0)
            ->where('summary.total_employees', 2));
});

test('crew timeline review summary cards filter employees and keep preparation totals', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    grantCrewTimelineReviewFilterPermissions($fixtures);
    $review = makeFilteredCrewTimelineReview($fixtures);

    $signOnPhase = addTimelinePhase(
        $review['operationsAssignment'],
        CrewPhaseCode::JoinStandby,
        2,
        '2026-07-01 08:00:00',
        '2026-07-03 18:00:00',
    );
    $signOffPhase = addTimelinePhase(
        $review['hrAssignment'],
        CrewPhaseCode::DemobStandby,
        2,
        '2026-07-13 08:00:00',
        '2026-07-14 18:00:00',
    );

    CrewTimesheetPreparationLine::factory()
        ->forPreparation($review['preparation'])
        ->forAssignment($review['operationsAssignment'], $signOnPhase)
        ->create([
            'pay_category' => CrewTimesheetPayCategory::SignOnStandby,
            'from_date' => '2026-07-01',
            'to_date' => '2026-07-03',
            'days' => 3,
        ]);

    CrewTimesheetPreparationLine::factory()
        ->forPreparation($review['preparation'])
        ->forAssignment($review['hrAssignment'], $signOffPhase)
        ->create([
            'pay_category' => CrewTimesheetPayCategory::SignOffStandby,
            'from_date' => '2026-07-13',
            'to_date' => '2026-07-14',
            'days' => 2,
        ]);

    CrewTimesheetPreparationLine::factory()
        ->forPreparation($review['preparation'])
        ->forAssignment($review['operationsAssignment'], $signOnPhase)
        ->create([
            'pay_category' => CrewTimesheetPayCategory::Excluded,
            'days' => 0,
            'warning_code' => CrewTimelineWarningCode::MissingActualStart->value,
            'remarks' => 'Missing actual start',
        ]);

    CrewTimesheetPreparationLine::factory()
        ->forPreparation($review['preparation'])
        ->forAssignment($review['hrAssignment'], $signOffPhase)
        ->create([
            'pay_category' => CrewTimesheetPayCategory::Excluded,
            'days' => 0,
            'warning_code' => CrewTimelineWarningCode::TimelineGap->value,
            'remarks' => 'Timeline gap',
        ]);

    $show = fn (array $query = []) => $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->get(route('payroll.crew-timeline.show', [
            'payrollPeriod' => $fixtures['period'],
            'preparation' => $review['preparation'],
            ...$query,
        ]));

    $show(['summary' => 'sign_on_standby'])
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('employees', 1)
            ->where('employees.0.employee_id', $review['operationsEmployee']->id)
            ->where('filters.summary', 'sign_on_standby')
            ->where('summary.total_employees', 2));

    $show(['summary' => 'sign_off_standby'])
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('employees', 1)
            ->where('employees.0.employee_id', $review['hrEmployee']->id)
            ->where('filters.summary', 'sign_off_standby')
            ->where('summary.total_employees', 2));

    $show(['summary' => 'onsite'])
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('employees', 2)
            ->where('filters.summary', 'onsite')
            ->where('summary.total_employees', 2));

    $show(['summary' => 'blocking'])
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('employees', 1)
            ->where('employees.0.employee_id', $review['operationsEmployee']->id)
            ->where('filters.summary', 'blocking'));

    $show(['summary' => 'informational'])
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('employees', 1)
            ->where('employees.0.employee_id', $review['hrEmployee']->id)
            ->where('filters.summary', 'informational'));

    $show(['summary' => 'not-a-card'])
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('employees', 2)
            ->where('filters.summary', ''));
});
