<?php

use App\Enums\CrewAssignmentStatus;
use App\Exports\RolesExport;
use App\Models\AppSetting;
use App\Models\CrewAssignment;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\PayrollPeriod;
use App\Models\Rank;
use App\Services\Settings\SettingService;
use App\Support\CrewMovements\CrewAssignmentStatusResolver;
use App\Support\Departments\DepartmentManagerExportContext;
use App\Support\Employees\EmployeeCrewStatusFilter;
use App\Support\Employees\EmployeeExportFieldResolver;
use App\Support\Payroll\OfficeLeavePeriodSummary;
use App\Support\Payroll\PayrollPeriodBoardQuery;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

test('employee directory crew status batch query count stays bounded', function () {
    ['company' => $company] = makePayrollFixtures();
    $rank = Rank::query()->create(['name' => 'Perf Rank', 'is_active' => true]);
    $firstEmployee = Employee::factory()->forCompany($company)->create([
        'status' => 'active',
        'rank_id' => $rank->id,
    ]);
    $firstVessel = makeCrewMovementVessel('Perf First Vessel');
    CrewAssignment::query()->create([
        'company_id' => $company->id,
        'assignment_no' => 'CA-PERF-FIRST',
        'employee_id' => $firstEmployee->id,
        'rank_id' => $rank->id,
        'vessel_id' => $firstVessel->id,
        'status' => CrewAssignmentStatus::Completed,
        'started_at' => CarbonImmutable::today()->subDays(20),
        'closed_at' => CarbonImmutable::today()->subDays(5),
        'source' => 'manual',
    ]);

    DB::flushQueryLog();
    DB::enableQueryLog();
    $smallResults = app(CrewAssignmentStatusResolver::class)->forEmployeeIds($company->id, [$firstEmployee->id]);
    $smallPageQueryCount = count(DB::getQueryLog());

    expect($smallResults[$firstEmployee->id]['vessel_name'])->toBe($firstVessel->name);

    $employees = Employee::factory()->count(10)->forCompany($company)->create([
        'status' => 'active',
        'rank_id' => $rank->id,
    ]);

    foreach ($employees as $index => $employee) {
        $vessel = makeCrewMovementVessel("Perf Completed Vessel {$index}");
        CrewAssignment::query()->create([
            'company_id' => $company->id,
            'assignment_no' => "CA-PERF-DONE-{$index}",
            'employee_id' => $employee->id,
            'rank_id' => $rank->id,
            'vessel_id' => $vessel->id,
            'status' => CrewAssignmentStatus::Completed,
            'started_at' => CarbonImmutable::today()->subDays(30),
            'closed_at' => CarbonImmutable::today()->subDays(2 + $index),
            'source' => 'manual',
        ]);
    }

    $employeeIds = $employees->pluck('id')->map(intval(...))->all();
    $employeeIds[] = $firstEmployee->id;

    DB::flushQueryLog();
    DB::enableQueryLog();
    $results = app(CrewAssignmentStatusResolver::class)->forEmployeeIds($company->id, $employeeIds);
    $queries = collect(DB::getQueryLog());
    $vesselQueries = $queries->filter(fn (array $query): bool => str_contains(strtolower($query['query']), 'vessels'));

    expect(count(DB::getQueryLog()))->toBeLessThanOrEqual($smallPageQueryCount + 1)
        ->and($vesselQueries)->toHaveCount(1)
        ->and($results[$firstEmployee->id]['vessel_name'])->toBe($firstVessel->name)
        ->and($results[$employees->first()->id]['status'])->toBe('in_home')
        ->and($results[$employees->first()->id]['vessel_name'])->not->toBeNull();
});

test('crew status filtering query count stays bounded', function () {
    ['company' => $company] = makePayrollFixtures();
    Employee::factory()->count(10)->forCompany($company)->create(['status' => 'active']);

    DB::flushQueryLog();
    DB::enableQueryLog();
    EmployeeCrewStatusFilter::matchingEmployeeIds($company->id, 'in_home');
    $inHomeQueryCount = count(DB::getQueryLog());

    Employee::factory()->count(20)->forCompany($company)->create(['status' => 'active']);

    DB::flushQueryLog();
    DB::enableQueryLog();
    EmployeeCrewStatusFilter::matchingEmployeeIds($company->id, 'in_home');

    expect($inHomeQueryCount)->toBeLessThanOrEqual(2)
        ->and(count(DB::getQueryLog()))->toBe($inHomeQueryCount);
});

test('role index mappings use eager loaded permissions', function () {
    ['company' => $company] = makePayrollFixtures();
    $permission = Permission::query()->create(['name' => 'performance.role-index', 'guard_name' => 'web']);
    collect(range(1, 5))->each(fn (int $number) => Role::query()->create([
        'company_id' => $company->id,
        'name' => "performance-index-role-{$number}",
        'guard_name' => 'web',
    ])->syncPermissions([$permission]));
    $roles = Role::query()->where('company_id', $company->id)->with('permissions:id,name')->get();

    DB::flushQueryLog();
    DB::enableQueryLog();
    $roles->each(fn (Role $role) => $role->permissions->pluck('name')->all());

    expect(DB::getQueryLog())->toBeEmpty();
});

test('role mappings use eager loaded permissions', function () {
    ['company' => $company] = makePayrollFixtures();
    $permission = Permission::query()->create(['name' => 'performance.roles', 'guard_name' => 'web']);
    $roles = collect(range(1, 5))->map(fn (int $number): Role => Role::query()->create([
        'company_id' => $company->id,
        'name' => "performance-role-{$number}",
        'guard_name' => 'web',
    ]));
    $roles->each(fn (Role $role) => $role->syncPermissions([$permission]));

    $export = new RolesExport(
        Role::query()->where('company_id', $company->id)->with('permissions:id,name'),
    );
    $loadedRoles = $export->query()->get();

    DB::flushQueryLog();
    DB::enableQueryLog();
    $loadedRoles->each(fn (Role $role) => $export->map($role));

    expect(DB::getQueryLog())->toBeEmpty();
});

test('employee export manager resolution does not query per employee', function () {
    ['company' => $company] = makePayrollFixtures();
    $manager = Employee::factory()->forCompany($company)->create(['name' => 'Manager']);
    $department = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Operations',
        'manager_id' => $manager->id,
    ]);
    $employees = Employee::factory()
        ->count(5)
        ->forCompany($company)
        ->create(['department_id' => $department->id]);

    $resolver = new EmployeeExportFieldResolver(DepartmentManagerExportContext::forCompany($company->id));

    DB::flushQueryLog();
    DB::enableQueryLog();
    $employees->each(fn (Employee $employee) => $resolver->resolve($employee, ['manager']));

    expect(DB::getQueryLog())->toBeEmpty();
});

test('office leave empty summary loads leave types once', function () {
    ['company' => $company] = makePayrollFixtures();

    DB::flushQueryLog();
    DB::enableQueryLog();
    app(OfficeLeavePeriodSummary::class)->empty($company->id);

    $leaveTypeQueries = collect(DB::getQueryLog())
        ->filter(fn (array $query): bool => str_contains($query['query'], 'leave_types'));

    expect($leaveTypeQueries)->toHaveCount(1);
});

test('payroll board loads office leave only for paginated employees', function () {
    ['company' => $company] = makePayrollFixtures();
    $period = PayrollPeriod::factory()->for($company)->office()->create([
        'start_date' => '2026-06-01',
        'end_date' => '2026-06-30',
    ]);
    $firstEmployee = createOfficeEmployeeWithContract($company, 'PERF-001', 1000, 0, 0, 0);
    $secondEmployee = createOfficeEmployeeWithContract($company, 'PERF-002', 1000, 0, 0, 0);
    $firstEmployee->update(['name' => 'AAA Page One']);
    $secondEmployee->update(['name' => 'ZZZ Page Two']);

    $leaveType = LeaveType::factory()->for($company)->create([
        'code' => 'AL',
        'status' => 'active',
    ]);

    foreach ([$firstEmployee, $secondEmployee] as $employee) {
        LeaveRequest::query()->create([
            'company_id' => $company->id,
            'employee_id' => $employee->id,
            'leave_type_id' => $leaveType->id,
            'start_date' => '2026-06-02',
            'end_date' => '2026-06-03',
            'total_days' => 2,
            'status' => 'approved',
        ]);
    }

    DB::flushQueryLog();
    DB::enableQueryLog();
    $page = app(PayrollPeriodBoardQuery::class)->paginate($company->id, $period, perPage: 1);

    $leaveRequestQueries = collect(DB::getQueryLog())
        ->filter(fn (array $query): bool => str_contains($query['query'], 'leave_requests'));
    $leaveRequestQuery = $leaveRequestQueries->first();
    $pageEmployeeId = (int) $page->getCollection()->first()['employee']['id'];

    expect($page->total())->toBe(2)
        ->and($pageEmployeeId)->toBe($firstEmployee->id)
        ->and($leaveRequestQueries)->toHaveCount(1)
        ->and($leaveRequestQuery['bindings'])->toContain($firstEmployee->id)
        ->and($leaveRequestQuery['bindings'])->not->toContain($secondEmployee->id);
});

test('setting service memoizes repeated reads per instance', function () {
    Cache::forget('app.settings.all');
    AppSetting::query()->create(['key' => 'app_name', 'value' => 'Performance', 'type' => 'string']);
    $settings = new SettingService;
    $settings->all();

    DB::flushQueryLog();
    DB::enableQueryLog();
    $settings->get('app_name');
    $settings->forInertia();

    expect(DB::getQueryLog())->toBeEmpty();
});
