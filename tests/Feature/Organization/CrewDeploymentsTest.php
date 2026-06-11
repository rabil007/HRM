<?php

use App\Models\Client;
use App\Models\Company;
use App\Models\CompanyVisaType;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Models\EmployeeDeployment;
use App\Models\Rank;
use App\Models\User;
use App\Support\CrewDeployments\DeploymentStatus;
use App\Support\CrewDeployments\ImportEmployeeDeploymentsFromSpreadsheet;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia as Assert;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Spatie\Activitylog\Models\Activity;

function makeCrewDeploymentFixtures(): array
{
    $user = User::factory()->create();

    $country = Country::query()->create([
        'code' => 'CRW',
        'name' => 'Crewland',
        'dial_code' => '+971',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'CRW',
        'name' => 'Crew Currency',
        'symbol' => 'C$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Crew Co',
        'slug' => 'crew-co',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $rank = Rank::query()->create([
        'name' => 'SM',
        'is_active' => true,
    ]);

    $employee = Employee::factory()
        ->forCompany($company)
        ->create([
            'employee_no' => '2018',
            'name' => 'Boby Jahja',
            'rank_id' => $rank->id,
            'status' => 'active',
        ]);

    EmployeeContract::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'contract_type' => 'unlimited',
        'start_date' => '2026-01-01',
        'end_date' => null,
        'labor_contract_id' => null,
        'status' => 'active',
    ]);

    grantCompanyPermissions($user, $company, [
        'crew_operations.deployments.view',
        'crew_operations.deployments.manage',
    ]);

    return compact('user', 'company', 'employee', 'rank');
}

test('guests cannot access crew deployments', function () {
    $this->get(route('organization.crew-deployments.index'))
        ->assertRedirect(route('login'));
});

test('authorized users can view crew deployment board', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewDeploymentFixtures();

    $client = Client::query()->create(['name' => 'JDL', 'is_active' => true]);
    $companyVisaType = CompanyVisaType::query()->create(['name' => 'EXPERTS', 'is_active' => true]);

    EmployeeDeployment::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'rank_id' => $rank->id,
        'client_id' => $client->id,
        'company_visa_type_id' => $companyVisaType->id,
        'vessel_name' => 'JDL',
        'joined_date' => CarbonImmutable::today()->subDays(5),
        'disembarked_date' => CarbonImmutable::today()->addDays(25),
    ]);

    $this->actingAs($user)
        ->get(route('organization.crew-deployments.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/crew-deployments/index')
            ->has('deployments.data', 1)
            ->where('deployments.data.0.employee_no', '2018')
            ->where('deployments.data.0.status', DeploymentStatus::ON_VESSEL)
            ->where('summary.on_vessel', 1)
            ->where('summary.total', 1));
});

test('crew deployment board shows hire date from employee record', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewDeploymentFixtures();

    $employee->update(['hire_date' => '2024-03-15']);

    EmployeeDeployment::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'rank_id' => $rank->id,
        'vessel_name' => 'Hire Date Vessel',
        'joined_date' => CarbonImmutable::today()->subDay(),
    ]);

    $this->actingAs($user)
        ->get(route('organization.crew-deployments.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('deployments.data.0.hire_date', '2024-03-15'));
});

test('authorized users can store update and destroy crew deployments', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewDeploymentFixtures();

    $client = Client::query()->create(['name' => 'Berltiz', 'is_active' => true]);
    $companyVisaType = CompanyVisaType::query()->create(['name' => 'High Land', 'is_active' => true]);

    $this->actingAs($user)
        ->post(route('organization.crew-deployments.store'), [
            'employee_id' => $employee->id,
            'rank_id' => $rank->id,
            'client_id' => $client->id,
            'company_visa_type_id' => $companyVisaType->id,
            'vessel_name' => 'Safeen OSV Pearl',
            'joined_date' => '2024-11-26',
            'disembarked_date' => '2025-01-26',
            'remarks' => 'Test remark',
        ])
        ->assertRedirect();

    $deployment = EmployeeDeployment::query()->where('employee_id', $employee->id)->first();

    expect($deployment)->not->toBeNull()
        ->and($deployment->vessel_name)->toBe('Safeen OSV Pearl');

    $this->actingAs($user)
        ->put(route('organization.crew-deployments.update', $deployment), [
            'employee_id' => $employee->id,
            'rank_id' => $rank->id,
            'client_id' => $client->id,
            'company_visa_type_id' => $companyVisaType->id,
            'vessel_name' => 'Cecilie K',
            'joined_date' => '2024-11-26',
            'disembarked_date' => '2025-02-26',
        ])
        ->assertRedirect();

    $deployment->refresh();
    expect($deployment->vessel_name)->toBe('Cecilie K');

    $this->actingAs($user)
        ->delete(route('organization.crew-deployments.destroy', $deployment))
        ->assertRedirect();

    expect(EmployeeDeployment::query()->find($deployment->id))->toBeNull();
});

test('deployment board shows all assignment records per employee', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewDeploymentFixtures();

    EmployeeDeployment::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'rank_id' => $rank->id,
        'vessel_name' => 'Old Vessel',
        'joined_date' => CarbonImmutable::today()->subMonths(6),
        'disembarked_date' => CarbonImmutable::today()->subMonths(4),
    ]);

    EmployeeDeployment::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'rank_id' => $rank->id,
        'vessel_name' => 'Current Vessel',
        'joined_date' => CarbonImmutable::today()->subDays(3),
        'disembarked_date' => CarbonImmutable::today()->addDays(30),
    ]);

    $this->actingAs($user)
        ->get(route('organization.crew-deployments.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('deployments.data', 2)
            ->where('deployments.data.0.vessel_name', 'Current Vessel')
            ->where('deployments.data.1.vessel_name', 'Old Vessel'));
});

test('authorized users can download crew deployment export', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewDeploymentFixtures();

    EmployeeDeployment::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'rank_id' => $rank->id,
        'vessel_name' => 'Export Vessel',
        'joined_date' => '2024-01-01',
        'disembarked_date' => '2024-03-01',
    ]);

    $response = $this->actingAs($user)
        ->get(route('organization.crew-deployments.export'));

    $response->assertOk();
    expect($response->headers->get('content-type'))
        ->toContain('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
});

test('authorized users can import crew deployments from spreadsheet', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewDeploymentFixtures();

    CompanyVisaType::query()->create(['name' => 'EXPERTS', 'is_active' => true]);

    $spreadsheet = new Spreadsheet;
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->fromArray([
        ['EMP. NO', 'NAME', 'RANK', 'VESSEL', 'SPONSER', 'CLIENT', 'DATE JOINED', 'DATE DISEMBARKED', 'REMARKS'],
        [$employee->employee_no, $employee->name, $rank->name, 'JDL', 'EXPERTS', 'JDL', '2024-11-26', '2025-01-26', 'Imported row'],
    ]);

    $path = tempnam(sys_get_temp_dir(), 'crew-import-').'.xlsx';
    (new Xlsx($spreadsheet))->save($path);

    $uploaded = new UploadedFile($path, 'crew-deployments.xlsx', null, null, true);

    $this->actingAs($user)
        ->post(route('organization.crew-deployments.import'), ['file' => $uploaded])
        ->assertRedirect()
        ->assertSessionHas('success');

    $deployment = EmployeeDeployment::query()->where('employee_id', $employee->id)->first();

    expect($deployment)->not->toBeNull()
        ->and($deployment->vessel_name)->toBe('JDL')
        ->and($deployment->remarks)->toBe('Imported row');

    expect($deployment->company_visa_type_id)->toBe(
        CompanyVisaType::query()->where('name', 'EXPERTS')->value('id'),
    )->and(Client::query()->where('name', 'JDL')->exists())->toBeTrue();

    @unlink($path);
});

test('import service skips rows with unknown employee numbers', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewDeploymentFixtures();

    $spreadsheet = new Spreadsheet;
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->fromArray([
        ['EMP. NO', 'NAME', 'RANK', 'VESSEL'],
        ['9999', 'Unknown', $rank->name, 'Nowhere'],
        [$employee->employee_no, $employee->name, $rank->name, 'Known Vessel'],
    ]);

    $path = tempnam(sys_get_temp_dir(), 'crew-import-').'.xlsx';
    (new Xlsx($spreadsheet))->save($path);

    $result = app(ImportEmployeeDeploymentsFromSpreadsheet::class)->import($path, $company->id);

    expect($result['imported'])->toBe(1)
        ->and($result['skipped'])->toBe(1)
        ->and(EmployeeDeployment::query()->where('employee_id', $employee->id)->count())->toBe(1);

    @unlink($path);
});

test('crew deployment board can sort assignments by employee name', function () {
    ['user' => $user, 'company' => $company, 'rank' => $rank] = makeCrewDeploymentFixtures();

    $employeeAlpha = Employee::factory()
        ->forCompany($company)
        ->create([
            'employee_no' => 'CD100',
            'name' => 'Alpha Crew',
            'rank_id' => $rank->id,
            'status' => 'active',
        ]);

    $employeeZulu = Employee::factory()
        ->forCompany($company)
        ->create([
            'employee_no' => 'CD200',
            'name' => 'Zulu Crew',
            'rank_id' => $rank->id,
            'status' => 'active',
        ]);

    EmployeeDeployment::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employeeZulu->id,
        'rank_id' => $rank->id,
        'vessel_name' => 'Zulu Vessel',
        'joined_date' => CarbonImmutable::today()->subDay(),
    ]);

    EmployeeDeployment::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employeeAlpha->id,
        'rank_id' => $rank->id,
        'vessel_name' => 'Alpha Vessel',
        'joined_date' => CarbonImmutable::today()->subDays(2),
    ]);

    $this->actingAs($user)
        ->get(route('organization.crew-deployments.index', [
            'sort' => 'employee_name',
            'direction' => 'asc',
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('deployments.data', 2)
            ->where('deployments.data.0.employee_name', 'Alpha Crew')
            ->where('deployments.data.1.employee_name', 'Zulu Crew')
            ->where('filters.sort', 'employee_name')
            ->where('filters.direction', 'asc'));
});

test('crew deployment board can sort assignments by vessel days', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewDeploymentFixtures();

    EmployeeDeployment::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'rank_id' => $rank->id,
        'vessel_name' => 'Short Tour',
        'joined_date' => '2024-01-01',
        'disembarked_date' => '2024-01-10',
    ]);

    EmployeeDeployment::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'rank_id' => $rank->id,
        'vessel_name' => 'Long Tour',
        'joined_date' => '2024-02-01',
        'disembarked_date' => '2024-03-01',
    ]);

    $this->actingAs($user)
        ->get(route('organization.crew-deployments.index', [
            'sort' => 'vessel_days',
            'direction' => 'desc',
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('deployments.data', 2)
            ->where('deployments.data.0.vessel_name', 'Long Tour')
            ->where('deployments.data.0.vessel_days', 30)
            ->where('deployments.data.1.vessel_name', 'Short Tour')
            ->where('deployments.data.1.vessel_days', 10));
});

test('crew deployment board marks overdue arrivals without join date as needs update', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewDeploymentFixtures();

    EmployeeDeployment::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'rank_id' => $rank->id,
        'vessel_name' => 'Vessel A',
        'arrived_date' => CarbonImmutable::today()->subDay(),
    ]);

    $this->actingAs($user)
        ->get(route('organization.crew-deployments.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('deployments.data.0.status', DeploymentStatus::UNKNOWN)
            ->where('deployments.data.0.status_label', 'Needs update')
            ->where('deployments.data.0.status_hint', 'Arrived 1d ago — add join date')
            ->where('summary.unknown', 1)
            ->where('summary.awaiting_join', 0));
});

test('crew deployment board treats open ended join standby as join standby status', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewDeploymentFixtures();

    EmployeeDeployment::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'rank_id' => $rank->id,
        'vessel_name' => 'Vessel A',
        'arrived_date' => CarbonImmutable::today()->subDays(10),
        'join_standby_from' => CarbonImmutable::today()->subDays(9),
        'join_standby_to' => null,
    ]);

    $this->actingAs($user)
        ->get(route('organization.crew-deployments.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('deployments.data.0.status', DeploymentStatus::JOIN_STANDBY)
            ->where('deployments.data.0.status_label', 'Join standby')
            ->where('summary.join_standby', 1)
            ->where('summary.unknown', 0));
});

test('crew deployment board marks past disembark without follow up dates as needs update', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewDeploymentFixtures();

    EmployeeDeployment::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'rank_id' => $rank->id,
        'vessel_name' => 'Vessel A',
        'joined_date' => CarbonImmutable::today()->subDays(4),
        'disembarked_date' => CarbonImmutable::today()->subDays(3),
    ]);

    $this->actingAs($user)
        ->get(route('organization.crew-deployments.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('deployments.data.0.status', DeploymentStatus::UNKNOWN)
            ->where('deployments.data.0.status_label', 'Needs update')
            ->where('deployments.data.0.status_hint', 'Disembarked 3d ago — add travel or standby')
            ->where('summary.unknown', 1)
            ->where('summary.disembarked', 0));
});

test('crew deployment board marks overdue leave standby without travel as needs update', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewDeploymentFixtures();

    EmployeeDeployment::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'rank_id' => $rank->id,
        'vessel_name' => 'Vessel A',
        'joined_date' => CarbonImmutable::today()->subDays(4),
        'disembarked_date' => CarbonImmutable::today()->subDays(3),
        'leave_standby_from' => CarbonImmutable::today()->subDays(2),
        'leave_standby_to' => CarbonImmutable::today()->subDay(),
    ]);

    $this->actingAs($user)
        ->get(route('organization.crew-deployments.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('deployments.data.0.status', DeploymentStatus::UNKNOWN)
            ->where('deployments.data.0.status_label', 'Needs update')
            ->where('deployments.data.0.status_hint', 'Leave standby ended 1d ago — add travel date')
            ->where('summary.unknown', 1)
            ->where('summary.disembarked', 0));
});

test('guests cannot access crew deployment show page', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewDeploymentFixtures();

    $deployment = EmployeeDeployment::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'rank_id' => $rank->id,
        'vessel_name' => 'Guest Vessel',
    ]);

    $this->get(route('organization.crew-deployments.show', $deployment))
        ->assertRedirect(route('login'));
});

test('authorized users can view crew deployment show page', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewDeploymentFixtures();

    $deployment = EmployeeDeployment::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'rank_id' => $rank->id,
        'vessel_name' => 'Detail Vessel',
        'joined_date' => '2024-11-26',
        'disembarked_date' => '2025-01-26',
        'remarks' => 'Detail remark',
    ]);

    $this->actingAs($user)
        ->get(route('organization.crew-deployments.show', $deployment))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/crew-deployments/show')
            ->where('deployment.id', $deployment->id)
            ->where('deployment.vessel_name', 'Detail Vessel')
            ->where('deployment.employee_no', '2018')
            ->where('deployment.remarks', 'Detail remark')
            ->has('back_query'));
});

test('users cannot view crew deployment from another company', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewDeploymentFixtures();

    $otherCompany = Company::query()->create([
        'name' => 'Other Co',
        'slug' => 'other-co',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $company->country_id,
        'currency_id' => $company->currency_id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $otherEmployee = Employee::factory()
        ->forCompany($otherCompany)
        ->create([
            'employee_no' => '9999',
            'name' => 'Other Crew',
            'rank_id' => $rank->id,
            'status' => 'active',
        ]);

    $deployment = EmployeeDeployment::query()->create([
        'company_id' => $otherCompany->id,
        'employee_id' => $otherEmployee->id,
        'rank_id' => $rank->id,
        'vessel_name' => 'Foreign Vessel',
    ]);

    $this->actingAs($user)
        ->get(route('organization.crew-deployments.show', $deployment))
        ->assertNotFound();
});

test('deployment show page hides recent activity without audit permission', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewDeploymentFixtures();

    $deployment = EmployeeDeployment::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'rank_id' => $rank->id,
        'vessel_name' => 'Audit Hidden Vessel',
    ]);

    $this->actingAs($user)
        ->get(route('organization.crew-deployments.show', $deployment))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('can_view_audit', false)
            ->where('recent_activity', []));
});

test('deployment show page exposes recent activity with audit permission', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewDeploymentFixtures();

    grantCompanyPermissions($user, $company, [
        'crew_operations.deployments.view',
        'crew_operations.deployments.manage',
        'audit.view',
    ]);

    $deployment = EmployeeDeployment::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'rank_id' => $rank->id,
        'vessel_name' => 'Audit Visible Vessel',
    ]);

    $this->actingAs($user)
        ->get(route('organization.crew-deployments.show', $deployment))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('can_view_audit', true)
            ->has('recent_activity', 1)
            ->where('recent_activity.0.event', 'created'));
});

test('updating a deployment records activity and can redirect to show page', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewDeploymentFixtures();

    $deployment = EmployeeDeployment::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'rank_id' => $rank->id,
        'vessel_name' => 'Before Update',
        'joined_date' => '2024-01-01',
    ]);

    $this->actingAs($user)
        ->put(route('organization.crew-deployments.update', $deployment), [
            'employee_id' => $employee->id,
            'rank_id' => $rank->id,
            'vessel_name' => 'After Update',
            'joined_date' => '2024-02-01',
            'redirect_to' => 'show',
        ])
        ->assertRedirect(route('organization.crew-deployments.show', $deployment));

    expect($deployment->fresh()->vessel_name)->toBe('After Update');

    $activity = Activity::query()
        ->where('subject_type', EmployeeDeployment::class)
        ->where('subject_id', $deployment->id)
        ->where('event', 'updated')
        ->latest('id')
        ->first();

    expect($activity)->not->toBeNull()
        ->and($activity->attribute_changes?->get('attributes'))->toMatchArray([
            'vessel_name' => 'After Update',
        ])
        ->and($activity->attribute_changes?->get('attributes'))->toHaveKey('joined_date');
});

test('crew deployment board can filter by join standby status', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewDeploymentFixtures();

    EmployeeDeployment::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'rank_id' => $rank->id,
        'vessel_name' => 'Join Standby Pool',
        'join_standby_from' => CarbonImmutable::today()->subDay(),
        'join_standby_to' => CarbonImmutable::today()->addDays(5),
    ]);

    $this->actingAs($user)
        ->get(route('organization.crew-deployments.index', [
            'status' => DeploymentStatus::JOIN_STANDBY,
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('deployments.data', 1)
            ->where('deployments.data.0.status', DeploymentStatus::JOIN_STANDBY));
});
