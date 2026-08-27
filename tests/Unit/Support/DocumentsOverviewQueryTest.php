<?php

use App\Models\DocumentType;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Support\Documents\DocumentsOverviewQuery;

test('overview query stays scoped to the given company', function () {
    ['companyA' => $companyA, 'companyB' => $companyB] = makeCompanyAuthorizationPair();

    $employeeA = Employee::factory()->forCompany($companyA)->create(['status' => 'active']);
    $employeeB = Employee::factory()->forCompany($companyB)->create(['status' => 'active']);

    $documentType = DocumentType::query()->firstOrCreate(
        ['title' => 'Passport Copy'],
        ['is_active' => true],
    );

    EmployeeDocument::query()->create([
        'company_id' => $companyA->id,
        'employee_id' => $employeeA->id,
        'document_type_id' => $documentType->id,
        'type' => 'other',
        'document_type' => (string) $documentType->id,
        'file_path' => 'employee-documents/a.pdf',
        'expiry_date' => now()->subDay()->toDateString(),
        'status' => 'expired',
    ]);

    EmployeeDocument::query()->create([
        'company_id' => $companyB->id,
        'employee_id' => $employeeB->id,
        'document_type_id' => $documentType->id,
        'type' => 'other',
        'document_type' => (string) $documentType->id,
        'file_path' => 'employee-documents/b.pdf',
        'expiry_date' => now()->subDay()->toDateString(),
        'status' => 'expired',
    ]);

    $payload = app(DocumentsOverviewQuery::class)->forCompany($companyA->id);

    expect($payload['summary']['total_documents'])->toBe(1)
        ->and($payload['summary']['expired'])->toBe(1)
        ->and($payload['attention'])->toContain([
            'key' => 'expired',
            'label' => 'Expired documents',
            'count' => 1,
            'query' => ['expiry' => 'expired'],
        ]);
});
