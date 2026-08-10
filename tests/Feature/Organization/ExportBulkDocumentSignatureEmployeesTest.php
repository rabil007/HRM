<?php

use App\Enums\BulkDocumentSignatureRequestStatus;
use App\Models\BulkDocumentSignatureRequest;
use App\Models\Department;
use App\Models\DocumentType;
use App\Models\Employee;
use App\Models\Position;
use App\Models\User;
use Database\Seeders\PermissionsSeeder;
use PhpOffice\PhpSpreadsheet\IOFactory;

beforeEach(function () {
    $this->seed(PermissionsSeeder::class);
});

test('users can export selected signature employees with org details', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $company = setupBulkDocumentsCompany($user, ['bulk_documents.view']);

    $department = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Operations',
    ]);

    $position = Position::query()->create([
        'company_id' => $company->id,
        'department_id' => $department->id,
        'title' => 'Technician',
        'status' => 'active',
    ]);

    $employee = Employee::factory()->forCompany($company)->create([
        'status' => 'active',
        'name' => 'Export Person',
        'employee_no' => 'EXP-100',
        'department_id' => $department->id,
        'position_id' => $position->id,
        'work_email' => 'export.person@example.com',
        'personal_email' => 'personal@example.com',
    ]);

    $otherEmployee = Employee::factory()->forCompany($company)->create([
        'status' => 'active',
        'name' => 'Other Person',
        'employee_no' => 'EXP-200',
        'work_email' => 'other@example.com',
    ]);

    $documentType = DocumentType::query()->firstOrCreate(
        ['title' => 'Salary Declaration'],
        ['is_active' => true],
    );

    $document = createEmployeePdfDocument(
        $company->id,
        $employee->id,
        $documentType->id,
        "employee-documents/{$company->id}/{$employee->id}/declaration.pdf",
        'declaration.pdf',
    );

    $otherDocument = createEmployeePdfDocument(
        $company->id,
        $otherEmployee->id,
        $documentType->id,
        "employee-documents/{$company->id}/{$otherEmployee->id}/declaration.pdf",
        'declaration.pdf',
    );

    $selected = BulkDocumentSignatureRequest::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'employee_document_id' => $document->id,
        'document_type_key' => 'salary_declaration',
        'token' => 'export-selected-token',
        'status' => BulkDocumentSignatureRequestStatus::AwaitingSignature,
        'expires_at' => now()->addDays(14),
    ]);

    BulkDocumentSignatureRequest::query()->create([
        'company_id' => $company->id,
        'employee_id' => $otherEmployee->id,
        'employee_document_id' => $otherDocument->id,
        'document_type_key' => 'salary_declaration',
        'token' => 'export-other-token',
        'status' => BulkDocumentSignatureRequestStatus::AwaitingSignature,
        'expires_at' => now()->addDays(14),
    ]);

    $response = $this->post(route('organization.documents.bulk.signatures.export-employees'), [
        'signature_request_ids' => [$selected->id],
        'document_type_key' => 'salary_declaration',
        'format' => 'xlsx',
    ]);

    $response->assertOk()->assertDownload();

    $tempPath = tempnam(sys_get_temp_dir(), 'bulk-sig-export-').'.xlsx';
    file_put_contents($tempPath, $response->streamedContent());

    $sheet = IOFactory::load($tempPath)->getActiveSheet()->toArray(null, true, true, true);
    unlink($tempPath);

    expect($sheet[1])->toBe([
        'A' => 'Employee No',
        'B' => 'Name',
        'C' => 'Department',
        'D' => 'Position',
        'E' => 'Email',
    ])
        ->and($sheet[2])->toBe([
            'A' => 'EXP-100',
            'B' => 'Export Person',
            'C' => 'Operations',
            'D' => 'Technician',
            'E' => 'export.person@example.com',
        ])
        ->and(count($sheet))->toBe(2);
});

test('users can export selected roster employees by employee ids', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $company = setupBulkDocumentsCompany($user, ['bulk_documents.view']);

    $department = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Deck',
    ]);

    $position = Position::query()->create([
        'company_id' => $company->id,
        'department_id' => $department->id,
        'title' => 'Able Seaman',
        'status' => 'active',
    ]);

    $employee = Employee::factory()->forCompany($company)->create([
        'status' => 'active',
        'name' => 'Roster Export Person',
        'employee_no' => 'RST-100',
        'department_id' => $department->id,
        'position_id' => $position->id,
        'work_email' => 'roster.export@example.com',
    ]);

    Employee::factory()->forCompany($company)->create([
        'status' => 'active',
        'name' => 'Other Roster Person',
        'employee_no' => 'RST-200',
        'work_email' => 'other.roster@example.com',
    ]);

    $response = $this->post(route('organization.documents.bulk.signatures.export-employees'), [
        'employee_ids' => [$employee->id],
        'document_type_key' => 'salary_declaration',
        'format' => 'xlsx',
    ]);

    $response->assertOk()->assertDownload();

    $tempPath = tempnam(sys_get_temp_dir(), 'bulk-roster-export-').'.xlsx';
    file_put_contents($tempPath, $response->streamedContent());

    $sheet = IOFactory::load($tempPath)->getActiveSheet()->toArray(null, true, true, true);
    unlink($tempPath);

    expect($sheet[1])->toBe([
        'A' => 'Employee No',
        'B' => 'Name',
        'C' => 'Department',
        'D' => 'Position',
        'E' => 'Email',
    ])
        ->and($sheet[2])->toBe([
            'A' => 'RST-100',
            'B' => 'Roster Export Person',
            'C' => 'Deck',
            'D' => 'Able Seaman',
            'E' => 'roster.export@example.com',
        ])
        ->and(count($sheet))->toBe(2);
});

test('export requires either signature request ids or employee ids', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    setupBulkDocumentsCompany($user, ['bulk_documents.view']);

    $this->post(route('organization.documents.bulk.signatures.export-employees'), [
        'document_type_key' => 'salary_declaration',
        'format' => 'xlsx',
    ])->assertSessionHasErrors('signature_request_ids');
});

test('users without permission cannot export signature employees', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $company = setupBulkDocumentsCompany($user, ['employees.view']);

    $this->withSession(['current_company_id' => $company->id])
        ->post(route('organization.documents.bulk.signatures.export-employees'), [
            'signature_request_ids' => [1],
            'document_type_key' => 'salary_declaration',
        ])
        ->assertForbidden();
});
