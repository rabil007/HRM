<?php

use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\User;

test('guests cannot access documents export', function () {
    $this->get(route('organization.documents.export'))->assertRedirect(route('login'));
});

test('users without documents view or download permission cannot export documents', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company] = makeDocumentFixtures();

    grantCompanyPermissions($user, $company, ['employees.view']);

    $this->get(route('organization.documents.export'))->assertForbidden();
});

test('authenticated users with permission can export documents as xlsx and csv', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();

    grantCompanyPermissions($user, $company, ['documents.view', 'documents.download']);

    EmployeeDocument::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'document_type_id' => $passportType->id,
        'type' => 'passport',
        'document_type' => (string) $passportType->id,
        'original_filename' => 'passport.pdf',
        'file_path' => 'employee-documents/test/passport.pdf',
        'document_number' => 'PASS12345',
        'status' => 'valid',
        'expiry_date' => now()->addDays(200)->toDateString(),
    ]);

    $responseXlsx = $this->get(route('organization.documents.export', ['format' => 'xlsx']));
    $responseXlsx->assertOk();
    expect($responseXlsx->headers->get('content-disposition'))->toContain('.xlsx');

    $responseCsv = $this->get(route('organization.documents.export', ['format' => 'csv']));
    $responseCsv->assertOk();
    expect($responseCsv->headers->get('content-disposition'))->toContain('.csv');
});

test('documents export respects search query filter', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();

    grantCompanyPermissions($user, $company, ['documents.view', 'documents.download']);

    EmployeeDocument::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'document_type_id' => $passportType->id,
        'type' => 'insurance',
        'document_type' => (string) $passportType->id,
        'original_filename' => 'INSURANCE-CARD.pdf',
        'file_path' => 'employee-documents/test/insurance.pdf',
        'document_number' => 'INS-999',
        'status' => 'valid',
    ]);

    $response = $this->get(route('organization.documents.export', [
        'format' => 'csv',
        'search' => 'INSURANCE',
    ]));

    $response->assertOk();
    $content = $response->streamedContent();
    expect($content)->toContain('INSURANCE-CARD.pdf');
    expect($content)->toContain('INS-999');
});

test('documents export respects expiry filter', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();

    grantCompanyPermissions($user, $company, ['documents.view', 'documents.download']);

    EmployeeDocument::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'document_type_id' => $passportType->id,
        'type' => 'other',
        'document_type' => (string) $passportType->id,
        'original_filename' => 'expired_cert.pdf',
        'file_path' => 'employee-documents/test/expired.pdf',
        'status' => 'expired',
        'expiry_date' => now()->subDays(10)->toDateString(),
    ]);

    $response = $this->get(route('organization.documents.export', [
        'format' => 'csv',
        'expiry' => 'expired',
    ]));

    $response->assertOk();
    $content = $response->streamedContent();
    expect($content)->toContain('expired_cert.pdf');
});

test('documents export respects requirement compliance filter', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();

    grantCompanyPermissions($user, $company, ['documents.view', 'documents.download']);

    $response = $this->get(route('organization.documents.export', [
        'format' => 'csv',
        'requirement_status' => 'missing',
    ]));

    $response->assertOk();
    expect($response->headers->get('content-disposition'))->toContain('documents_compliance');
});

test('documents export can be limited to selected document ids', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();

    grantCompanyPermissions($user, $company, ['documents.view', 'documents.download']);

    $doc1 = EmployeeDocument::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'document_type_id' => $passportType->id,
        'type' => 'passport',
        'document_type' => (string) $passportType->id,
        'original_filename' => 'doc1.pdf',
        'file_path' => 'employee-documents/test/doc1.pdf',
        'status' => 'valid',
    ]);

    $doc2 = EmployeeDocument::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'document_type_id' => $passportType->id,
        'type' => 'passport',
        'document_type' => (string) $passportType->id,
        'original_filename' => 'doc2.pdf',
        'file_path' => 'employee-documents/test/doc2.pdf',
        'status' => 'valid',
    ]);

    $response = $this->get(route('organization.documents.export', [
        'format' => 'csv',
        'ids' => (string) $doc1->id,
    ]));

    $response->assertOk();
    $content = $response->streamedContent();
    expect($content)->toContain('doc1.pdf');
    expect($content)->not->toContain('doc2.pdf');
});

test('documents export for single employee exports that employees documents', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employeeA, 'passportType' => $passportType] = makeDocumentFixtures();

    $employeeB = Employee::query()->create([
        'company_id' => $company->id,
        'branch_id' => $employeeA->branch_id,
        'employee_no' => 'EMP002',
        'name' => 'Other Employee',
        'status' => 'active',
    ]);

    grantCompanyPermissions($user, $company, ['documents.view', 'documents.download']);

    EmployeeDocument::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employeeA->id,
        'document_type_id' => $passportType->id,
        'type' => 'passport',
        'document_type' => (string) $passportType->id,
        'original_filename' => 'employee_a_doc.pdf',
        'file_path' => 'employee-documents/test/a.pdf',
        'status' => 'valid',
    ]);

    EmployeeDocument::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employeeB->id,
        'document_type_id' => $passportType->id,
        'type' => 'passport',
        'document_type' => (string) $passportType->id,
        'original_filename' => 'employee_b_doc.pdf',
        'file_path' => 'employee-documents/test/b.pdf',
        'status' => 'valid',
    ]);

    $response = $this->get(route('organization.documents.export', [
        'format' => 'csv',
        'employee_id' => $employeeA->id,
    ]));

    $response->assertOk();
    $content = $response->streamedContent();
    expect($content)->toContain('employee_a_doc.pdf');
    expect($content)->not->toContain('employee_b_doc.pdf');
    expect($response->headers->get('content-disposition'))->toContain($employeeA->employee_no);
});
