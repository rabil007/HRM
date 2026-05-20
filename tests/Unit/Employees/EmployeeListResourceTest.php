<?php

use App\Models\Employee;
use App\Support\Employees\Resources\EmployeeListResource;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('employee list resource exposes directory row keys', function () {
    $employee = Employee::factory()->create([
        'employee_no' => 'EMP-100',
        'name' => 'Directory Employee',
        'work_email' => 'directory@example.com',
    ]);

    $employee->load([
        'branch:id,name',
        'department:id,name',
        'position:id,title',
        'manager:id,name,employee_no',
        'religionRef:id,name',
        'genderRef:id,name',
        'nationalityRef:id,name,code',
        'primaryBankAccount.bank:id,name',
        'currentContract',
    ]);

    $payload = EmployeeListResource::toArray($employee);

    expect($payload)->toMatchArray([
        'id' => $employee->id,
        'employee_no' => 'EMP-100',
        'name' => 'Directory Employee',
        'work_email' => 'directory@example.com',
    ])->and($payload)->toHaveKeys([
        'branch',
        'department',
        'position',
        'phone',
        'status',
        'created_at',
    ]);
});
