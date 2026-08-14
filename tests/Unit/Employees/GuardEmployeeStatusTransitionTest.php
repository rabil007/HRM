<?php

use App\Models\Company;
use App\Models\Country;
use App\Models\CrewPlanningAssignment;
use App\Models\Currency;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Rank;
use App\Support\Employees\Actions\GuardEmployeeStatusTransition;
use Illuminate\Validation\ValidationException;

function makeStatusTransitionCompany(string $suffix): Company
{
    $country = Country::query()->create([
        'code' => $suffix,
        'name' => "GST Country {$suffix}",
        'dial_code' => '+971',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => $suffix,
        'name' => "GST Currency {$suffix}",
        'symbol' => '$',
        'is_active' => true,
    ]);

    return Company::query()->create([
        'name' => "GST {$suffix}",
        'slug' => 'gst-'.strtolower($suffix),
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);
}

test('status transition allows leaving active when there are no operational blockers', function () {
    $company = makeStatusTransitionCompany('OK');
    $employee = Employee::factory()->forCompany($company)->create(['status' => 'active']);

    GuardEmployeeStatusTransition::assertCanLeaveActive($employee, 'inactive');
    GuardEmployeeStatusTransition::assertCanLeaveActive($employee, 'terminated');

    expect($employee->fresh()->status)->toBe('active');
});

test('status transition ignores already inactive employees and on_leave changes', function () {
    $company = makeStatusTransitionCompany('IG');
    $inactive = Employee::factory()->forCompany($company)->inactive()->create();
    $active = Employee::factory()->forCompany($company)->create(['status' => 'active']);

    GuardEmployeeStatusTransition::assertCanLeaveActive($inactive, 'terminated');
    GuardEmployeeStatusTransition::assertCanLeaveActive($active, 'on_leave');

    expect(true)->toBeTrue();
});

test('status transition rejects leaving active while a crew assignment is open', function () {
    $company = makeStatusTransitionCompany('CA');
    $rank = Rank::query()->create(['name' => 'GST Rank', 'is_active' => true]);
    $employee = Employee::factory()->forCompany($company)->create([
        'rank_id' => $rank->id,
        'status' => 'active',
    ]);

    makeActiveOnVesselAssignment($company, $employee, $rank, makeCrewMovementVessel('GST Vessel', $company));

    expect(fn () => GuardEmployeeStatusTransition::assertCanLeaveActive($employee, 'inactive'))
        ->toThrow(ValidationException::class, 'active crew assignment');
});

test('status transition rejects leaving active while current planning exists', function () {
    $company = makeStatusTransitionCompany('PL');
    $rank = Rank::query()->create(['name' => 'GST Plan Rank', 'is_active' => true]);
    $employee = Employee::factory()->forCompany($company)->create([
        'rank_id' => $rank->id,
        'status' => 'active',
    ]);
    $vessel = makeCrewMovementVessel('GST Plan Vessel', $company);

    CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
        'employee_id' => $employee->id,
        'planned_join_date' => now()->toDateString(),
        'planned_leave_date' => now()->addMonth()->toDateString(),
    ]);

    expect(fn () => GuardEmployeeStatusTransition::assertCanLeaveActive($employee, 'terminated'))
        ->toThrow(ValidationException::class, 'crew planning assignment');
});

test('status transition rejects leaving active while leave is pending', function () {
    $company = makeStatusTransitionCompany('LV');
    $employee = Employee::factory()->forCompany($company)->create(['status' => 'active']);
    $leaveType = LeaveType::factory()->for($company)->create(['status' => 'active']);

    $leaveRequest = new LeaveRequest;
    $leaveRequest->forceFill([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'start_date' => now()->addDay()->toDateString(),
        'end_date' => now()->addDays(3)->toDateString(),
        'total_days' => 3,
        'status' => 'pending',
        'reason' => 'Guard test',
    ])->save();

    expect(fn () => GuardEmployeeStatusTransition::assertCanLeaveActive($employee, 'inactive'))
        ->toThrow(ValidationException::class, 'pending leave request');
});

test('status transition rejects leaving active while the employee manages a department', function () {
    $company = makeStatusTransitionCompany('MG');
    $employee = Employee::factory()->forCompany($company)->create(['status' => 'active']);

    Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Managed Dept',
        'status' => 'active',
        'manager_id' => $employee->id,
    ]);

    expect(fn () => GuardEmployeeStatusTransition::assertCanLeaveActive($employee, 'terminated'))
        ->toThrow(ValidationException::class, 'department manager');
});

test('status transition does not treat another company department manager as a blocker', function () {
    $company = makeStatusTransitionCompany('TN');
    $other = makeStatusTransitionCompany('TX');
    $employee = Employee::factory()->forCompany($company)->create(['status' => 'active']);
    $foreignManager = Employee::factory()->forCompany($other)->create(['status' => 'active']);

    Department::query()->create([
        'company_id' => $other->id,
        'name' => 'Foreign Dept',
        'status' => 'active',
        'manager_id' => $foreignManager->id,
    ]);

    GuardEmployeeStatusTransition::assertCanLeaveActive($employee, 'inactive');

    expect($employee->fresh()->status)->toBe('active');
});
