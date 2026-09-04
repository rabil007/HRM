<?php

use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\User;
use App\Support\BulkDocuments\BulkDocumentRosterEmployeePresenter;
use Database\Seeders\PermissionsSeeder;

beforeEach(function () {
    $this->seed(PermissionsSeeder::class);
});

test('identity uses image not avatar_url and prefers work email', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $company = setupBulkDocumentsCompany($user);

    $department = Department::query()->create(['company_id' => $company->id, 'name' => 'Deck']);
    $position = Position::query()->create(['company_id' => $company->id, 'title' => 'Able Seaman']);

    $employee = Employee::factory()->forCompany($company)->create([
        'name' => 'Mohammed Rabil',
        'employee_no' => 'EMP-100',
        'image' => 'employee-photos/rabil.jpg',
        'work_email' => 'work@example.com',
        'personal_email' => 'personal@example.com',
        'department_id' => $department->id,
        'position_id' => $position->id,
        'status' => 'active',
    ]);

    $employee->load(['department', 'position']);

    expect(BulkDocumentRosterEmployeePresenter::identity($employee))->toMatchArray([
        'id' => $employee->id,
        'name' => 'Mohammed Rabil',
        'employee_no' => 'EMP-100',
        'image' => 'employee-photos/rabil.jpg',
        'department' => 'Deck',
        'position' => 'Able Seaman',
        'email' => 'work@example.com',
        'status' => 'active',
    ])->and($employee->avatar_url ?? null)->toBeNull();
});
