<?php

use App\Models\EmployeeDocument;
use App\Support\EmployeeDocuments\LatestEmployeeDocumentQuery;

test('latest query prefers newer created_at over a higher id', function () {
    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();

    $newer = EmployeeDocument::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'document_type_id' => $passportType->id,
        'type' => 'other',
        'document_type' => (string) $passportType->id,
        'file_path' => 'employee-documents/test/latest-newer.pdf',
        'status' => 'valid',
        'created_at' => '2026-06-10 12:00:00',
        'updated_at' => '2026-06-10 12:00:00',
    ]);

    $olderHigherId = EmployeeDocument::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'document_type_id' => $passportType->id,
        'type' => 'other',
        'document_type' => (string) $passportType->id,
        'file_path' => 'employee-documents/test/latest-older.pdf',
        'status' => 'expired',
        'created_at' => '2026-01-10 12:00:00',
        'updated_at' => '2026-01-10 12:00:00',
    ]);

    expect($olderHigherId->id)->toBeGreaterThan($newer->id);

    $row = (new LatestEmployeeDocumentQuery)->forCompany($company->id)->first();

    expect((int) $row->id)->toBe($newer->id);
});

test('latest query breaks created_at ties with the highest id', function () {
    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();

    $tiedAt = '2026-06-10 12:00:00';

    EmployeeDocument::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'document_type_id' => $passportType->id,
        'type' => 'other',
        'document_type' => (string) $passportType->id,
        'file_path' => 'employee-documents/test/latest-tie-low.pdf',
        'status' => 'valid',
        'created_at' => $tiedAt,
        'updated_at' => $tiedAt,
    ]);

    $higherId = EmployeeDocument::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'document_type_id' => $passportType->id,
        'type' => 'other',
        'document_type' => (string) $passportType->id,
        'file_path' => 'employee-documents/test/latest-tie-high.pdf',
        'status' => 'expired',
        'created_at' => $tiedAt,
        'updated_at' => $tiedAt,
    ]);

    $row = (new LatestEmployeeDocumentQuery)->forCompany($company->id)->first();

    expect((int) $row->id)->toBe($higherId->id);
});
