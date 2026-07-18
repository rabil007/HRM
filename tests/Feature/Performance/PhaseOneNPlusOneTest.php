<?php

use App\Exports\RolesExport;
use App\Models\AppSetting;
use App\Models\Department;
use App\Models\Employee;
use App\Models\PayrollPeriod;
use App\Services\Settings\SettingService;
use App\Support\CrewMovements\CrewAssignmentStatusResolver;
use App\Support\Departments\DepartmentManagerExportContext;
use App\Support\Employees\EmployeeCrewStatusFilter;
use App\Support\Employees\EmployeeExportFieldResolver;
use App\Support\Payroll\OfficeLeavePeriodSummary;
use App\Support\Payroll\PayrollPeriodBoardQuery;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

test('employee directory crew status batch query count stays bounded', function () {
    ['company' => $company] = makePayrollFixtures();
    $firstEmployee = Employee::factory()->forCompany($company)->create(['status' => 'active']);

    DB::flushQueryLog();
    DB::enableQueryLog();
    app(CrewAssignmentStatusResolver::class)->forEmployeeIds($company->id, [$firstEmployee->id]);
    $smallPageQueryCount = count(DB::getQueryLog());

    $employeeIds = Employee::factory()->count(10)->forCompany($company)->create(['status' => 'active'])->pluck('id')->all();
    $employeeIds[] = $firstEmployee->id;

    DB::flushQueryLog();
    DB::enableQueryLog();
    app(CrewAssignmentStatusResolver::class)->forEmployeeIds($company->id, $employeeIds);

    expect(count(DB::getQueryLog()))->toBeLessThanOrEqual($smallPageQueryCount + 1);
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
    $period = PayrollPeriod::factory()->for($company)->office()->create();
    [$firstEmployee, $secondEmployee] = [
        createOfficeEmployeeWithContract($company, 'PERF-001', 1000, 0, 0, 0),
        createOfficeEmployeeWithContract($company, 'PERF-002', 1000, 0, 0, 0),
    ];

    DB::flushQueryLog();
    DB::enableQueryLog();
    app(PayrollPeriodBoardQuery::class)->paginate($company->id, $period, perPage: 1);

    $leaveRequestQuery = collect(DB::getQueryLog())
        ->first(fn (array $query): bool => str_contains($query['query'], 'leave_requests'));

    expect($leaveRequestQuery)->not->toBeNull()
        ->and(collect(DB::getQueryLog())
            ->filter(fn (array $query): bool => str_contains($query['query'], 'leave_requests')))
        ->toHaveCount(1);
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
