<?php

use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('documents folder index filters employees by department using shared tree', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employeeA, 'passportType' => $passportType] = makeDocumentFixtures();

    $departmentA = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Deck',
        'status' => 'active',
    ]);
    $departmentB = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Engine',
        'status' => 'active',
    ]);

    $employeeA->update(['department_id' => $departmentA->id]);

    $employeeB = Employee::query()->create([
        'company_id' => $company->id,
        'branch_id' => $employeeA->branch_id,
        'department_id' => $departmentB->id,
        'employee_no' => 'DOC-DEPT-B',
        'name' => 'Engine Employee',
        'status' => 'active',
    ]);

    grantCompanyPermissions($user, $company, ['documents.view']);

    foreach ([$employeeA, $employeeB] as $employee) {
        EmployeeDocument::query()->create([
            'company_id' => $company->id,
            'employee_id' => $employee->id,
            'document_type_id' => $passportType->id,
            'type' => 'other',
            'document_type' => (string) $passportType->id,
            'file_path' => "employee-documents/test/{$employee->id}.pdf",
            'status' => 'valid',
        ]);
    }

    $this->get(route('organization.documents'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/documents/index')
            ->has('department_tree')
            ->has('employees', 2)
            ->where('summary.total_documents', 2)
            ->where('department_tree_selected_id', null));

    $this->get(route('organization.documents', [
        'department_id' => $departmentA->id,
    ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/documents/index')
            ->where('department_id', (string) $departmentA->id)
            ->where('department_tree_selected_id', $departmentA->id)
            ->where('summary.total_documents', 1)
            ->has('employees', 1)
            ->where('employees.0.employee_id', $employeeA->id));
});

test('documents compliance view respects department filter', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employeeA, 'passportType' => $passportType] = makeDocumentFixtures();

    $departmentA = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Compliance Deck',
        'status' => 'active',
    ]);
    $departmentB = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Compliance Engine',
        'status' => 'active',
    ]);

    $employeeA->update(['department_id' => $departmentA->id]);

    $employeeB = Employee::query()->create([
        'company_id' => $company->id,
        'branch_id' => $employeeA->branch_id,
        'department_id' => $departmentB->id,
        'employee_no' => 'DOC-COMP-B',
        'name' => 'Compliance Engine Employee',
        'status' => 'active',
    ]);

    grantCompanyPermissions($user, $company, ['documents.view']);

    foreach ([$employeeA, $employeeB] as $index => $employee) {
        EmployeeDocument::query()->create([
            'company_id' => $company->id,
            'employee_id' => $employee->id,
            'document_type_id' => $passportType->id,
            'type' => 'other',
            'document_type' => (string) $passportType->id,
            'file_path' => "employee-documents/test/comp-{$employee->id}.pdf",
            'expiry_date' => now()->subDays($index + 1)->toDateString(),
            'status' => 'expired',
        ]);
    }

    $this->get(route('organization.documents', [
        'expiry' => 'expired',
        'department_id' => $departmentA->id,
    ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/documents/index')
            ->where('expiry', 'expired')
            ->where('department_tree_selected_id', $departmentA->id)
            ->where('summary.total_documents', 1)
            ->where('summary.expired', 1)
            ->has('complianceDocuments.data', 1)
            ->where('complianceDocuments.data.0.employee_id', $employeeA->id));
});
