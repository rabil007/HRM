<?php

use App\Enums\CrewAssignmentStatus;
use App\Enums\CrewMobilisationReadinessStatus;
use App\Enums\CrewPhaseCode;
use App\Enums\CrewPhaseStatus;
use App\Models\Company;
use App\Models\Country;
use App\Models\CrewAssignment;
use App\Models\CrewAssignmentPhase;
use App\Models\CrewPlanningAssignment;
use App\Models\Currency;
use App\Models\Department;
use App\Models\DocumentType;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\Position;
use App\Models\Vessel;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

function makeReadinessEmployeeDocument(
    int $companyId,
    int $employeeId,
    int $documentTypeId,
    string $status,
    ?string $expiryDate = null
): EmployeeDocument {
    return EmployeeDocument::query()->create([
        'company_id' => $companyId,
        'employee_id' => $employeeId,
        'document_type_id' => $documentTypeId,
        'type' => 'other',
        'document_type' => (string) $documentTypeId,
        'file_path' => 'employee-documents/test.pdf',
        'status' => $status,
        'expiry_date' => $expiryDate,
    ]);
}

function makeReadinessCompany(string $name = 'Other Company'): Company
{
    $country = Country::query()->firstOrCreate(
        ['code' => 'OC'],
        ['name' => 'Other Country', 'dial_code' => '+999', 'is_active' => true]
    );
    $currency = Currency::query()->firstOrCreate(
        ['code' => 'OCD'],
        ['name' => 'Other Currency', 'symbol' => '$', 'is_active' => true]
    );

    return Company::query()->create([
        'name' => $name,
        'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);
}

function makeCrewReadinessTestFixtures(array $permissions = [
    'crew_operations.planning.view',
    'crew_operations.assignments.view',
]): array
{
    $fixtures = makeCrewAssignmentFixtures();
    grantCompanyPermissions($fixtures['user'], $fixtures['company'], $permissions);
    $fixtures['user']->update(['current_company_id' => $fixtures['company']->id]);
    $fixtures['vessel'] = makeCrewMovementVessel('Readiness Vessel', $fixtures['company']);

    $timezone = $fixtures['company']->timezone ?? 'Asia/Dubai';
    $today = CarbonImmutable::parse('2026-10-04 08:00:00', $timezone);
    Carbon::setTestNow($today);
    CarbonImmutable::setTestNow($today);
    $fixtures['today'] = $today;

    return $fixtures;
}

it('redirects guests to the login page', function () {
    $this->get(route('organization.crew-readiness.index'))
        ->assertRedirect(route('login'));
});

it('forbids users without planning or assignments view permission', function () {
    $fixtures = makeCrewReadinessTestFixtures([]);

    $this->actingAs($fixtures['user'])
        ->get(route('organization.crew-readiness.index'))
        ->assertForbidden();
});

it('allows planning-only viewers to see eligible planning rows and excludes assignments', function () {
    $fixtures = makeCrewReadinessTestFixtures(['crew_operations.planning.view']);
    $company = $fixtures['company'];
    $rank = $fixtures['rank'];
    $vessel = $fixtures['vessel'];
    $today = $fixtures['today'];

    $planEmployee = Employee::factory()->forCompany($company)->create([
        'name' => 'Planning Sailor',
        'position_id' => $rank->id,
    ]);

    $assignmentEmployee = Employee::factory()->forCompany($company)->create([
        'name' => 'Assignment Sailor',
        'position_id' => $rank->id,
    ]);

    // Eligible future named plan
    $plan = CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'employee_id' => $planEmployee->id,
        'position_id' => $rank->id,
        'vessel_id' => $vessel->id,
        'planned_join_date' => $today->addDays(10)->toDateString(),
        'planned_leave_date' => $today->addDays(40)->toDateString(),
    ]);

    // Pre-join assignment
    CrewAssignment::factory()->forEmployee($assignmentEmployee)->create([
        'vessel_id' => $vessel->id,
        'status' => CrewAssignmentStatus::Draft,
        'planned_join_at' => $today->addDays(5)->toDateTimeString(),
    ]);

    $this->actingAs($fixtures['user'])
        ->get(route('organization.crew-readiness.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/crew-readiness/index')
            ->has('readiness.rows', 1)
            ->where('readiness.rows.0.source_type', 'planning')
            ->where('readiness.rows.0.planning_assignment_id', $plan->id)
            ->where('readiness.rows.0.employee.name', 'Planning Sailor')
            ->where('readiness.summary.upcoming_crew', 1)
        );
});

it('excludes vacant planning rows and historical expired planning rows', function () {
    $fixtures = makeCrewReadinessTestFixtures(['crew_operations.planning.view']);
    $company = $fixtures['company'];
    $rank = $fixtures['rank'];
    $vessel = $fixtures['vessel'];
    $today = $fixtures['today'];

    // Vacant plan
    CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'employee_id' => null,
        'position_id' => $rank->id,
        'vessel_id' => $vessel->id,
        'planned_join_date' => $today->addDays(5)->toDateString(),
        'planned_leave_date' => $today->addDays(35)->toDateString(),
    ]);

    // Historical expired plan (planned_leave_date in past)
    $expiredEmployee = Employee::factory()->forCompany($company)->create(['position_id' => $rank->id]);
    CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'employee_id' => $expiredEmployee->id,
        'position_id' => $rank->id,
        'vessel_id' => $vessel->id,
        'planned_join_date' => $today->subDays(60)->toDateString(),
        'planned_leave_date' => $today->subDays(10)->toDateString(),
    ]);

    $this->actingAs($fixtures['user'])
        ->get(route('organization.crew-readiness.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/crew-readiness/index')
            ->has('readiness.rows', 0)
            ->where('readiness.summary.upcoming_crew', 0)
        );
});

it('allows assignment-only viewers to see operational pre-join assignments and excludes planning rows', function () {
    $fixtures = makeCrewReadinessTestFixtures(['crew_operations.assignments.view']);
    $company = $fixtures['company'];
    $rank = $fixtures['rank'];
    $vessel = $fixtures['vessel'];
    $today = $fixtures['today'];

    $planEmployee = Employee::factory()->forCompany($company)->create(['position_id' => $rank->id]);
    CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'employee_id' => $planEmployee->id,
        'position_id' => $rank->id,
        'vessel_id' => $vessel->id,
        'planned_join_date' => $today->addDays(5)->toDateString(),
        'planned_leave_date' => $today->addDays(35)->toDateString(),
    ]);

    $assignmentEmployee = Employee::factory()->forCompany($company)->create([
        'name' => 'Pre-Join Crew',
        'position_id' => $rank->id,
    ]);
    $assignment = CrewAssignment::factory()->forEmployee($assignmentEmployee)->create([
        'vessel_id' => $vessel->id,
        'status' => CrewAssignmentStatus::Draft,
        'planned_join_at' => $today->addDays(5)->toDateTimeString(),
    ]);

    $this->actingAs($fixtures['user'])
        ->get(route('organization.crew-readiness.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/crew-readiness/index')
            ->has('readiness.rows', 1)
            ->where('readiness.rows.0.source_type', 'assignment')
            ->where('readiness.rows.0.crew_assignment_id', $assignment->id)
            ->where('readiness.rows.0.employee.name', 'Pre-Join Crew')
            ->where('readiness.summary.upcoming_crew', 1)
        );
});

it('includes Draft, P0, P2A, and P2B assignments, and excludes On-Vessel, Completed, and Cancelled', function () {
    $fixtures = makeCrewReadinessTestFixtures(['crew_operations.assignments.view']);
    $company = $fixtures['company'];
    $rank = $fixtures['rank'];
    $vessel = $fixtures['vessel'];
    $today = $fixtures['today'];

    // 1. Draft assignment
    $eDraft = Employee::factory()->forCompany($company)->create(['name' => 'Draft Crew', 'position_id' => $rank->id]);
    CrewAssignment::factory()->forEmployee($eDraft)->create([
        'vessel_id' => $vessel->id,
        'status' => CrewAssignmentStatus::Draft,
        'planned_join_at' => $today->addDays(1)->toDateTimeString(),
    ]);

    // 2. Active P0 Pre-Mobilisation
    $eP0 = Employee::factory()->forCompany($company)->create(['name' => 'P0 Crew', 'position_id' => $rank->id]);
    $caP0 = CrewAssignment::factory()->forEmployee($eP0)->active()->create([
        'vessel_id' => $vessel->id,
        'planned_join_at' => $today->addDays(2)->toDateTimeString(),
    ]);
    $phaseP0 = CrewAssignmentPhase::factory()->forAssignment($caP0)->create([
        'phase_code' => CrewPhaseCode::PreMobilisation,
        'status' => CrewPhaseStatus::Active,
        'sequence' => 1,
    ]);
    $caP0->update(['current_phase_id' => $phaseP0->id]);

    // 3. Active P2A Join Standby
    $eP2A = Employee::factory()->forCompany($company)->create(['name' => 'P2A Crew', 'position_id' => $rank->id]);
    CrewAssignment::factory()->forEmployee($eP2A)->joinStandby()->create([
        'vessel_id' => $vessel->id,
        'planned_join_at' => $today->addDays(3)->toDateTimeString(),
    ]);

    // 4. Active P2B Training
    $eP2B = Employee::factory()->forCompany($company)->create(['name' => 'P2B Crew', 'position_id' => $rank->id]);
    CrewAssignment::factory()->forEmployee($eP2B)->training()->create([
        'vessel_id' => $vessel->id,
        'planned_join_at' => $today->addDays(4)->toDateTimeString(),
    ]);

    // 5. Active P4 On Vessel (should NOT appear)
    $eP4 = Employee::factory()->forCompany($company)->create(['name' => 'P4 Onboard Crew', 'position_id' => $rank->id]);
    CrewAssignment::factory()->forEmployee($eP4)->onVessel()->create([
        'vessel_id' => $vessel->id,
        'planned_join_at' => $today->subDays(10)->toDateTimeString(),
    ]);

    // 6. Completed assignment (should NOT appear)
    $eCompleted = Employee::factory()->forCompany($company)->create(['name' => 'Completed Crew', 'position_id' => $rank->id]);
    CrewAssignment::factory()->forEmployee($eCompleted)->completed()->create([
        'vessel_id' => $vessel->id,
    ]);

    // 7. Cancelled assignment (should NOT appear)
    $eCancelled = Employee::factory()->forCompany($company)->create(['name' => 'Cancelled Crew', 'position_id' => $rank->id]);
    CrewAssignment::factory()->forEmployee($eCancelled)->cancelled()->create([
        'vessel_id' => $vessel->id,
    ]);

    $response = $this->actingAs($fixtures['user'])
        ->get(route('organization.crew-readiness.index'))
        ->assertOk();

    $response->assertInertia(fn (Assert $page) => $page
        ->component('organization/crew-readiness/index')
        ->has('readiness.rows', 4)
        ->where('readiness.summary.upcoming_crew', 4)
    );
});

it('deduplicates linked planning rows into their operational crew assignment', function () {
    $fixtures = makeCrewReadinessTestFixtures([
        'crew_operations.planning.view',
        'crew_operations.assignments.view',
    ]);
    $company = $fixtures['company'];
    $rank = $fixtures['rank'];
    $vessel = $fixtures['vessel'];
    $today = $fixtures['today'];

    $employee = Employee::factory()->forCompany($company)->create([
        'name' => 'Mobilised Crew',
        'position_id' => $rank->id,
    ]);

    $assignment = CrewAssignment::factory()->forEmployee($employee)->create([
        'vessel_id' => $vessel->id,
        'status' => CrewAssignmentStatus::Draft,
        'planned_join_at' => $today->addDays(5)->toDateTimeString(),
    ]);

    // Planning row linked to the assignment (crew_assignment_id is set)
    CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'crew_assignment_id' => $assignment->id,
        'position_id' => $rank->id,
        'vessel_id' => $vessel->id,
        'planned_join_date' => $today->addDays(5)->toDateString(),
        'planned_leave_date' => $today->addDays(35)->toDateString(),
    ]);

    $this->actingAs($fixtures['user'])
        ->get(route('organization.crew-readiness.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/crew-readiness/index')
            ->has('readiness.rows', 1)
            ->where('readiness.rows.0.source_type', 'assignment')
            ->where('readiness.rows.0.crew_assignment_id', $assignment->id)
            ->where('readiness.rows.0.planning_assignment_id', null)
        );
});

it('resolves ready, needs attention, and not ready readiness statuses based on compliance', function () {
    $fixtures = makeCrewReadinessTestFixtures([
        'crew_operations.assignments.view',
    ]);
    $company = $fixtures['company'];
    $rank = $fixtures['rank'];
    $vessel = $fixtures['vessel'];
    $today = $fixtures['today'];

    // Requirement: Passport (required)
    $passportType = DocumentType::query()->create([
        'title' => 'Passport '.uniqid(),
        'is_active' => true,
    ]);
    makeDocumentRequirement($company->id, $passportType->id, requiredForAll: true);

    // Requirement: Medical (required)
    $medicalType = DocumentType::query()->create([
        'title' => 'Medical '.uniqid(),
        'is_active' => true,
    ]);
    makeDocumentRequirement($company->id, $medicalType->id, requiredForAll: true);

    // 1. Employee 1: Missing Passport -> Not Ready
    $empNotReady = Employee::factory()->forCompany($company)->create(['name' => 'Not Ready Crew', 'position_id' => $rank->id]);
    CrewAssignment::factory()->forEmployee($empNotReady)->create([
        'vessel_id' => $vessel->id,
        'status' => CrewAssignmentStatus::Draft,
        'planned_join_at' => $today->addDays(2)->toDateTimeString(),
    ]);

    // 2. Employee 2: Passport valid, Medical expiring in 10 days -> Attention
    $empAttention = Employee::factory()->forCompany($company)->create(['name' => 'Attention Crew', 'position_id' => $rank->id]);
    makeReadinessEmployeeDocument(
        $company->id,
        $empAttention->id,
        $passportType->id,
        'valid',
        $today->addMonths(12)->toDateString()
    );
    makeReadinessEmployeeDocument(
        $company->id,
        $empAttention->id,
        $medicalType->id,
        'valid',
        $today->addDays(10)->toDateString()
    );
    CrewAssignment::factory()->forEmployee($empAttention)->create([
        'vessel_id' => $vessel->id,
        'status' => CrewAssignmentStatus::Draft,
        'planned_join_at' => $today->addDays(3)->toDateTimeString(),
    ]);

    // 3. Employee 3: Passport valid, Medical valid (> 60 days) -> Ready
    $empReady = Employee::factory()->forCompany($company)->create(['name' => 'Ready Crew', 'position_id' => $rank->id]);
    makeReadinessEmployeeDocument(
        $company->id,
        $empReady->id,
        $passportType->id,
        'valid',
        $today->addMonths(12)->toDateString()
    );
    makeReadinessEmployeeDocument(
        $company->id,
        $empReady->id,
        $medicalType->id,
        'valid',
        $today->addMonths(6)->toDateString()
    );
    CrewAssignment::factory()->forEmployee($empReady)->create([
        'vessel_id' => $vessel->id,
        'status' => CrewAssignmentStatus::Draft,
        'planned_join_at' => $today->addDays(4)->toDateTimeString(),
    ]);

    $this->actingAs($fixtures['user'])
        ->get(route('organization.crew-readiness.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/crew-readiness/index')
            ->where('readiness.summary.upcoming_crew', 3)
            ->where('readiness.summary.not_ready', 1)
            ->where('readiness.summary.attention', 1)
            ->where('readiness.summary.ready', 1)
            ->where('readiness.rows.0.employee.name', 'Not Ready Crew')
            ->where('readiness.rows.0.readiness.status', CrewMobilisationReadinessStatus::NotReady->value)
            ->where('readiness.rows.1.employee.name', 'Attention Crew')
            ->where('readiness.rows.1.readiness.status', CrewMobilisationReadinessStatus::Attention->value)
            ->where('readiness.rows.2.employee.name', 'Ready Crew')
            ->where('readiness.rows.2.readiness.status', CrewMobilisationReadinessStatus::Ready->value)
        );
});

it('handles zero configured checks gracefully with No Checks Configured presentation label', function () {
    $fixtures = makeCrewReadinessTestFixtures([
        'crew_operations.assignments.view',
    ]);
    $company = $fixtures['company'];
    $rank = $fixtures['rank'];
    $vessel = $fixtures['vessel'];
    $today = $fixtures['today'];

    $employee = Employee::factory()->forCompany($company)->create([
        'name' => 'Unchecked Crew',
        'position_id' => $rank->id,
    ]);

    CrewAssignment::factory()->forEmployee($employee)->create([
        'vessel_id' => $vessel->id,
        'status' => CrewAssignmentStatus::Draft,
        'planned_join_at' => $today->addDays(5)->toDateTimeString(),
    ]);

    $this->actingAs($fixtures['user'])
        ->get(route('organization.crew-readiness.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/crew-readiness/index')
            ->where('readiness.summary.upcoming_crew', 1)
            ->where('readiness.summary.no_checks', 1)
            ->where('readiness.summary.ready', 0)
            ->where('readiness.rows.0.readiness.status_label', 'No Checks Configured')
            ->where('readiness.rows.0.readiness.has_configured_checks', false)
        );
});

it('omits document links without documents.view and provides them with documents.view', function () {
    $fixtures = makeCrewReadinessTestFixtures([
        'crew_operations.assignments.view',
    ]);
    $company = $fixtures['company'];
    $rank = $fixtures['rank'];
    $vessel = $fixtures['vessel'];
    $today = $fixtures['today'];

    $employee = Employee::factory()->forCompany($company)->create(['position_id' => $rank->id]);
    CrewAssignment::factory()->forEmployee($employee)->create([
        'vessel_id' => $vessel->id,
        'status' => CrewAssignmentStatus::Draft,
        'planned_join_at' => $today->addDays(5)->toDateTimeString(),
    ]);

    // 1. Without documents.view
    $this->actingAs($fixtures['user'])
        ->get(route('organization.crew-readiness.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/crew-readiness/index')
            ->where('readiness.rows.0.readiness.documents_href', null)
        );

    // 2. With documents.view
    grantCompanyPermissions($fixtures['user'], $company, [
        'crew_operations.assignments.view',
        'documents.view',
    ]);

    $this->actingAs($fixtures['user'])
        ->get(route('organization.crew-readiness.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/crew-readiness/index')
            ->where('readiness.rows.0.readiness.documents_href', route('organization.documents.employee', ['employee' => $employee->id]))
        );
});

it('enforces company isolation', function () {
    $fixtures = makeCrewReadinessTestFixtures([
        'crew_operations.planning.view',
        'crew_operations.assignments.view',
    ]);
    $companyA = $fixtures['company'];
    $today = $fixtures['today'];

    // Other company
    $otherCompany = makeReadinessCompany('Foreign Company');
    $rankB = Position::query()->create([
        'company_id' => $otherCompany->id,
        'title' => 'AB Sailor',
        'status' => 'active',
        'is_crew_position' => true,
    ]);
    $vesselB = makeCrewMovementVessel('Foreign Vessel', $otherCompany);
    $employeeB = Employee::factory()->forCompany($otherCompany)->create(['position_id' => $rankB->id]);

    CrewPlanningAssignment::query()->create([
        'company_id' => $otherCompany->id,
        'employee_id' => $employeeB->id,
        'position_id' => $rankB->id,
        'vessel_id' => $vesselB->id,
        'planned_join_date' => $today->addDays(5)->toDateString(),
        'planned_leave_date' => $today->addDays(35)->toDateString(),
    ]);

    CrewAssignment::factory()->forEmployee($employeeB)->create([
        'vessel_id' => $vesselB->id,
        'status' => CrewAssignmentStatus::Draft,
        'planned_join_at' => $today->addDays(5)->toDateTimeString(),
    ]);

    $this->actingAs($fixtures['user'])
        ->get(route('organization.crew-readiness.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/crew-readiness/index')
            ->has('readiness.rows', 0)
            ->where('readiness.summary.upcoming_crew', 0)
        );
});

it('enforces employee visibility scope and does not leak hidden employees in rows or summary counts', function () {
    $fixtures = makeCrewReadinessTestFixtures([
        'crew_operations.planning.view',
        'crew_operations.assignments.view',
    ]);
    $company = $fixtures['company'];
    $rank = $fixtures['rank'];
    $vessel = $fixtures['vessel'];
    $today = $fixtures['today'];

    $deptAllowed = Department::query()->create(['company_id' => $company->id, 'name' => 'Deck Allowed', 'status' => 'active']);
    $deptHidden = Department::query()->create(['company_id' => $company->id, 'name' => 'Deck Hidden', 'status' => 'active']);

    restrictUserToDepartments($fixtures['user'], $company, [$deptAllowed->id]);

    $visibleEmployee = Employee::factory()->forCompany($company)->create([
        'name' => 'Visible Officer',
        'department_id' => $deptAllowed->id,
        'position_id' => $rank->id,
    ]);

    $hiddenEmployee = Employee::factory()->forCompany($company)->create([
        'name' => 'Hidden Officer',
        'department_id' => $deptHidden->id,
        'position_id' => $rank->id,
    ]);

    // Visible planning row
    CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'employee_id' => $visibleEmployee->id,
        'position_id' => $rank->id,
        'vessel_id' => $vessel->id,
        'planned_join_date' => $today->addDays(5)->toDateString(),
        'planned_leave_date' => $today->addDays(35)->toDateString(),
    ]);

    // Hidden planning row
    CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'employee_id' => $hiddenEmployee->id,
        'position_id' => $rank->id,
        'vessel_id' => $vessel->id,
        'planned_join_date' => $today->addDays(5)->toDateString(),
        'planned_leave_date' => $today->addDays(35)->toDateString(),
    ]);

    // Hidden assignment
    CrewAssignment::factory()->forEmployee($hiddenEmployee)->create([
        'vessel_id' => $vessel->id,
        'status' => CrewAssignmentStatus::Draft,
        'planned_join_at' => $today->addDays(5)->toDateTimeString(),
    ]);

    $this->actingAs($fixtures['user'])
        ->get(route('organization.crew-readiness.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/crew-readiness/index')
            ->has('readiness.rows', 1)
            ->where('readiness.rows.0.employee.name', 'Visible Officer')
            ->where('readiness.summary.upcoming_crew', 1)
        );

    // Searching for the hidden employee returns 0 results
    $this->actingAs($fixtures['user'])
        ->get(route('organization.crew-readiness.index', ['search' => 'Hidden']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/crew-readiness/index')
            ->has('readiness.rows', 0)
            ->where('readiness.summary.upcoming_crew', 0)
        );
});

it('supports practical filters (search, vessel, rank, source, window)', function () {
    $fixtures = makeCrewReadinessTestFixtures([
        'crew_operations.planning.view',
        'crew_operations.assignments.view',
    ]);
    $company = $fixtures['company'];
    $rank1 = $fixtures['rank'];
    $rank2 = Position::query()->create([
        'company_id' => $company->id,
        'title' => 'Chief Engineer '.Str::uuid()->toString(),
        'status' => 'active',
        'is_crew_position' => true,
    ]);
    $vessel1 = $fixtures['vessel'];
    $vessel2 = makeCrewMovementVessel('Falcon Vessel', $company);
    $today = $fixtures['today'];

    $e1 = Employee::factory()->forCompany($company)->create(['name' => 'Ahmed Khan', 'position_id' => $rank1->id]);
    $e2 = Employee::factory()->forCompany($company)->create(['name' => 'Sameer Ali', 'position_id' => $rank2->id]);

    // Plan row for e1 on vessel1
    CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'employee_id' => $e1->id,
        'position_id' => $rank1->id,
        'vessel_id' => $vessel1->id,
        'planned_join_date' => $today->addDays(5)->toDateString(),
        'planned_leave_date' => $today->addDays(35)->toDateString(),
    ]);

    // Assignment row for e2 on vessel2
    CrewAssignment::factory()->forEmployee($e2)->create([
        'vessel_id' => $vessel2->id,
        'status' => CrewAssignmentStatus::Draft,
        'planned_join_at' => $today->addDays(20)->toDateTimeString(),
    ]);

    // 1. Filter by search
    $this->actingAs($fixtures['user'])
        ->get(route('organization.crew-readiness.index', ['search' => 'Sameer']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/crew-readiness/index')
            ->has('readiness.rows', 1)
            ->where('readiness.rows.0.employee.name', 'Sameer Ali')
        );

    // 2. Filter by vessel
    $this->actingAs($fixtures['user'])
        ->get(route('organization.crew-readiness.index', ['vessel_id' => $vessel1->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/crew-readiness/index')
            ->has('readiness.rows', 1)
            ->where('readiness.rows.0.employee.name', 'Ahmed Khan')
        );

    // 3. Filter by position
    $this->actingAs($fixtures['user'])
        ->get(route('organization.crew-readiness.index', ['position_id' => $rank2->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/crew-readiness/index')
            ->has('readiness.rows', 1)
            ->where('readiness.rows.0.employee.name', 'Sameer Ali')
        );

    // 4. Filter by source: planning only
    $this->actingAs($fixtures['user'])
        ->get(route('organization.crew-readiness.index', ['source' => 'planning']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/crew-readiness/index')
            ->has('readiness.rows', 1)
            ->where('readiness.rows.0.source_type', 'planning')
        );

    // 5. Filter by join window: Next 7 days (e1 is in 5 days, e2 is in 20 days)
    $this->actingAs($fixtures['user'])
        ->get(route('organization.crew-readiness.index', ['window' => '7']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/crew-readiness/index')
            ->has('readiness.rows', 1)
            ->where('readiness.rows.0.employee.name', 'Ahmed Khan')
        );
});

it('prioritizes overdue and not ready rows first', function () {
    $fixtures = makeCrewReadinessTestFixtures([
        'crew_operations.assignments.view',
    ]);
    $company = $fixtures['company'];
    $rank = $fixtures['rank'];
    $vessel = $fixtures['vessel'];
    $today = $fixtures['today'];

    $type = DocumentType::query()->create(['title' => 'Passport '.uniqid(), 'is_active' => true]);
    makeDocumentRequirement($company->id, $type->id, requiredForAll: true);

    // Emp A: Ready, joining in 2 days
    $empA = Employee::factory()->forCompany($company)->create(['name' => 'Alpha', 'position_id' => $rank->id]);
    makeReadinessEmployeeDocument(
        $company->id,
        $empA->id,
        $type->id,
        'valid',
        $today->addMonths(12)->toDateString()
    );
    CrewAssignment::factory()->forEmployee($empA)->create([
        'vessel_id' => $vessel->id,
        'status' => CrewAssignmentStatus::Draft,
        'planned_join_at' => $today->addDays(2)->toDateTimeString(),
    ]);

    // Emp B: Not Ready, joining in 15 days
    $empB = Employee::factory()->forCompany($company)->create(['name' => 'Bravo', 'position_id' => $rank->id]);
    CrewAssignment::factory()->forEmployee($empB)->create([
        'vessel_id' => $vessel->id,
        'status' => CrewAssignmentStatus::Draft,
        'planned_join_at' => $today->addDays(15)->toDateTimeString(),
    ]);

    // Emp C: Not Ready, overdue by 2 days (planned_join_at in the past)
    $empC = Employee::factory()->forCompany($company)->create(['name' => 'Charlie', 'position_id' => $rank->id]);
    CrewAssignment::factory()->forEmployee($empC)->create([
        'vessel_id' => $vessel->id,
        'status' => CrewAssignmentStatus::Draft,
        'planned_join_at' => $today->subDays(2)->toDateTimeString(),
    ]);

    $this->actingAs($fixtures['user'])
        ->get(route('organization.crew-readiness.index', ['window' => 'all']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/crew-readiness/index')
            ->where('readiness.rows.0.employee.name', 'Charlie')
            ->where('readiness.rows.0.is_overdue', true)
            ->where('readiness.rows.1.employee.name', 'Bravo')
            ->where('readiness.rows.2.employee.name', 'Alpha')
        );
});
