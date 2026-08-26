<?php

use App\Models\DocumentType;
use App\Models\EmployeeDocument;

test('audit command reports unmapped rows without printing file contents', function () {
    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();

    $document = makeUnmappedEmployeeDocument($company->id, $employee->id, $passportType->title);
    $document->update([
        'original_filename' => 'CONFIDENTIAL-PASSPORT.pdf',
        'notes' => 'Passport number hidden-secret-99',
        'file_path' => 'employee-documents/secret/path.pdf',
    ]);

    $this->artisan('employee-documents:audit-unmapped-types')
        ->expectsOutputToContain('Found 1 unmapped employee document row(s).')
        ->expectsOutputToContain('Deterministic matches: 1.')
        ->doesntExpectOutputToContain('CONFIDENTIAL-PASSPORT.pdf')
        ->doesntExpectOutputToContain('hidden-secret-99')
        ->doesntExpectOutputToContain('employee-documents/secret/path.pdf')
        ->assertSuccessful();
});

test('backfill maps a deterministic exact title match', function () {
    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();
    $document = makeUnmappedEmployeeDocument($company->id, $employee->id, $passportType->title);

    $this->artisan('employee-documents:backfill-document-types')
        ->expectsOutputToContain('Mapped 1 row(s).')
        ->assertSuccessful();

    expect($document->fresh()->document_type_id)->toBe($passportType->id);
});

test('backfill maps a trim and case-normalized exact title match', function () {
    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();
    $document = makeUnmappedEmployeeDocument($company->id, $employee->id, '  '.$passportType->title.'  ');

    $this->artisan('employee-documents:backfill-document-types')->assertSuccessful();

    expect($document->fresh()->document_type_id)->toBe($passportType->id);
});

test('backfill leaves unmatched legacy values unchanged', function () {
    ['company' => $company, 'employee' => $employee] = makeDocumentFixtures();
    $document = makeUnmappedEmployeeDocument($company->id, $employee->id, 'passport-copy');

    $this->artisan('employee-documents:backfill-document-types')
        ->expectsOutputToContain('Unmatched left unchanged: 1.')
        ->assertSuccessful();

    expect($document->fresh()->document_type_id)->toBeNull();
});

test('backfill leaves ambiguous normalized titles unchanged', function () {
    ['company' => $company, 'employee' => $employee] = makeDocumentFixtures();

    DocumentType::query()->create(['title' => 'Seafarer Medical', 'is_active' => true]);
    DocumentType::query()->create(['title' => 'seafarer medical', 'is_active' => true]);

    $document = makeUnmappedEmployeeDocument($company->id, $employee->id, 'Seafarer Medical');

    $this->artisan('employee-documents:backfill-document-types')
        ->expectsOutputToContain('Ambiguous left unchanged: 1.')
        ->assertSuccessful();

    expect($document->fresh()->document_type_id)->toBeNull();
});

test('backfill does not change rows that already have a document type', function () {
    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType, 'visaType' => $visaType] = makeDocumentFixtures();

    $document = EmployeeDocument::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'document_type_id' => $visaType->id,
        'type' => 'other',
        'document_type' => $passportType->title,
        'file_path' => 'employee-documents/test/already-mapped.pdf',
        'status' => 'valid',
    ]);

    $this->artisan('employee-documents:backfill-document-types')
        ->expectsOutputToContain('Mapped 0 row(s).')
        ->assertSuccessful();

    expect($document->fresh()->document_type_id)->toBe($visaType->id);
});

test('dry-run backfill makes no changes', function () {
    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();
    $document = makeUnmappedEmployeeDocument($company->id, $employee->id, $passportType->title);

    $this->artisan('employee-documents:backfill-document-types', ['--dry-run' => true])
        ->expectsOutputToContain('Dry run complete. Would map 1 row(s).')
        ->expectsOutputToContain('No database changes were written.')
        ->assertSuccessful();

    expect($document->fresh()->document_type_id)->toBeNull();
});

test('backfill is idempotent', function () {
    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();
    $document = makeUnmappedEmployeeDocument($company->id, $employee->id, $passportType->title);

    $this->artisan('employee-documents:backfill-document-types')->assertSuccessful();
    $this->artisan('employee-documents:backfill-document-types')
        ->expectsOutputToContain('Mapped 0 row(s).')
        ->assertSuccessful();

    expect($document->fresh()->document_type_id)->toBe($passportType->id);
});

test('backfill company filter does not map another company', function () {
    ['company' => $companyA, 'employee' => $employeeA, 'passportType' => $passportType] = makeDocumentFixtures();
    $other = makeDocumentFixtures();

    $documentA = makeUnmappedEmployeeDocument($companyA->id, $employeeA->id, $passportType->title, 'employee-documents/test/unmapped-a.pdf');
    $documentB = makeUnmappedEmployeeDocument($other['company']->id, $other['employee']->id, $passportType->title, 'employee-documents/test/unmapped-b.pdf');

    $this->artisan('employee-documents:backfill-document-types', ['--company' => $companyA->id])
        ->expectsOutputToContain('Mapped 1 row(s).')
        ->assertSuccessful();

    expect($documentA->fresh()->document_type_id)->toBe($passportType->id)
        ->and($documentB->fresh()->document_type_id)->toBeNull();
});

test('audit company filter does not report another company', function () {
    ['company' => $companyA, 'employee' => $employeeA, 'passportType' => $passportType] = makeDocumentFixtures();
    $other = makeDocumentFixtures();

    makeUnmappedEmployeeDocument($companyA->id, $employeeA->id, $passportType->title, 'employee-documents/test/audit-a.pdf');
    makeUnmappedEmployeeDocument($other['company']->id, $other['employee']->id, $passportType->title, 'employee-documents/test/audit-b.pdf');

    $this->artisan('employee-documents:audit-unmapped-types', ['--company' => $companyA->id])
        ->expectsOutputToContain('Found 1 unmapped employee document row(s).')
        ->assertSuccessful();
});

test('unknown company option fails without writing', function () {
    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();
    $document = makeUnmappedEmployeeDocument($company->id, $employee->id, $passportType->title);

    $this->artisan('employee-documents:backfill-document-types', ['--company' => 999999])
        ->expectsOutputToContain('Company [999999] was not found.')
        ->assertFailed();

    expect($document->fresh()->document_type_id)->toBeNull();
});

test('non-numeric company option fails without writing', function () {
    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();
    $document = makeUnmappedEmployeeDocument($company->id, $employee->id, $passportType->title);

    $this->artisan('employee-documents:backfill-document-types', ['--company' => 'abc'])
        ->expectsOutputToContain('The --company option must be a positive integer company ID.')
        ->assertFailed();

    expect($document->fresh()->document_type_id)->toBeNull();
});
