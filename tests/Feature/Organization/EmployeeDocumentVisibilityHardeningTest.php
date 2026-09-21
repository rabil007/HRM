<?php

use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\EmployeeDocumentVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

function putTestDocumentFile(int $companyId, int $employeeId, string $filename = 'test.pdf'): string
{
    $path = "employee-documents/{$companyId}/{$employeeId}/passport/{$filename}";
    Storage::disk('public')->put($path, '%PDF-1.4 sample');

    return $path;
}

test('visible employee document is accessible', function () {
    fakeEmployeeFileDisks();
    ['user' => $user, 'company' => $company, 'marineDept' => $marineDept, 'marineEmployee' => $marine] = makeEmployeeVisibilityFixtures();
    restrictUserToDepartments($user, $company, [$marineDept->id]);
    grantCompanyPermissions($user, $company, ['documents.view', 'documents.download']);

    $path = putTestDocumentFile($company->id, $marine->id, 'test.pdf');

    $doc = EmployeeDocument::query()->create([
        'company_id' => $company->id,
        'employee_id' => $marine->id,
        'title' => 'Marine Passport',
        'type' => 'passport',
        'document_type' => 'passport',
        'file_path' => $path,
        'original_filename' => 'test.pdf',
        'mime_type' => 'application/pdf',
    ]);

    $this->actingAs($user)
        ->get(route('organization.documents.files.download', $doc))
        ->assertOk();
});

test('hidden employee document returns 404', function () {
    fakeEmployeeFileDisks();
    ['user' => $user, 'company' => $company, 'marineDept' => $marineDept, 'officeEmployee' => $office] = makeEmployeeVisibilityFixtures();
    grantCompanyPermissions($user, $company, ['documents.view', 'documents.download']);
    restrictUserToDepartments($user, $company, [$marineDept->id]);

    $path = putTestDocumentFile($company->id, $office->id, 'test.pdf');

    $doc = EmployeeDocument::query()->create([
        'company_id' => $company->id,
        'employee_id' => $office->id,
        'title' => 'Office Passport',
        'type' => 'passport',
        'document_type' => 'passport',
        'file_path' => $path,
        'original_filename' => 'test.pdf',
        'mime_type' => 'application/pdf',
    ]);

    $this->actingAs($user)
        ->get(route('organization.documents.files.download', $doc))
        ->assertNotFound();
});

test('soft-deleted hidden employee document returns 404', function () {
    fakeEmployeeFileDisks();
    ['user' => $user, 'company' => $company, 'marineDept' => $marineDept, 'officeEmployee' => $office] = makeEmployeeVisibilityFixtures();
    grantCompanyPermissions($user, $company, ['documents.view', 'documents.download']);
    restrictUserToDepartments($user, $company, [$marineDept->id]);

    $path = putTestDocumentFile($company->id, $office->id, 'test.pdf');

    $doc = EmployeeDocument::query()->create([
        'company_id' => $company->id,
        'employee_id' => $office->id,
        'title' => 'Terminated Office Passport',
        'type' => 'passport',
        'document_type' => 'passport',
        'file_path' => $path,
        'original_filename' => 'test.pdf',
        'mime_type' => 'application/pdf',
    ]);

    // Soft delete the Office employee
    $office->delete();

    $this->actingAs($user)
        ->get(route('organization.documents.files.download', $doc))
        ->assertNotFound();
});

test('soft-deleted authorized employee document remains accessible', function () {
    fakeEmployeeFileDisks();
    ['user' => $user, 'company' => $company, 'marineDept' => $marineDept, 'marineEmployee' => $marine] = makeEmployeeVisibilityFixtures();
    grantCompanyPermissions($user, $company, ['documents.view', 'documents.download']);
    restrictUserToDepartments($user, $company, [$marineDept->id]);

    $path = putTestDocumentFile($company->id, $marine->id, 'test.pdf');

    $doc = EmployeeDocument::query()->create([
        'company_id' => $company->id,
        'employee_id' => $marine->id,
        'title' => 'Former Marine Passport',
        'type' => 'passport',
        'document_type' => 'passport',
        'file_path' => $path,
        'original_filename' => 'test.pdf',
        'mime_type' => 'application/pdf',
    ]);

    // Soft delete the Marine employee
    $marine->delete();

    $this->actingAs($user)
        ->get(route('organization.documents.files.download', $doc))
        ->assertOk();
});

test('document with invalid or missing employee ownership fails closed with 404', function () {
    fakeEmployeeFileDisks();
    ['user' => $user, 'company' => $company, 'marineDept' => $marineDept] = makeEmployeeVisibilityFixtures();
    grantCompanyPermissions($user, $company, ['documents.view', 'documents.download']);
    restrictUserToDepartments($user, $company, [$marineDept->id]);

    $tempEmployee = Employee::factory()->create(['company_id' => $company->id]);
    $path = putTestDocumentFile($company->id, $tempEmployee->id, 'test.pdf');

    $doc = EmployeeDocument::query()->create([
        'company_id' => $company->id,
        'employee_id' => $tempEmployee->id,
        'title' => 'Orphan Document',
        'type' => 'passport',
        'document_type' => 'passport',
        'file_path' => $path,
        'original_filename' => 'test.pdf',
        'mime_type' => 'application/pdf',
    ]);

    // Forcefully remove the employee row to simulate missing ownership
    DB::statement('PRAGMA foreign_keys = OFF;');
    DB::table('employees')->where('id', $tempEmployee->id)->delete();
    DB::statement('PRAGMA foreign_keys = ON;');

    $this->actingAs($user)
        ->get(route('organization.documents.files.download', $doc))
        ->assertNotFound();
});

test('cross-company document access returns 404', function () {
    fakeEmployeeFileDisks();
    ['user' => $user, 'company' => $company] = makeEmployeeVisibilityFixtures();
    grantCompanyPermissions($user, $company, ['documents.view', 'documents.download']);

    $country = Country::query()->firstOrCreate(
        ['code' => 'USA'],
        ['name' => 'USA', 'dial_code' => '+1', 'is_active' => true],
    );
    $currency = Currency::query()->firstOrCreate(
        ['code' => 'USD'],
        ['name' => 'US Dollar', 'symbol' => '$', 'is_active' => true],
    );

    $otherCompany = Company::query()->create([
        'name' => 'Other Co',
        'slug' => 'other-co',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);
    $otherEmployee = Employee::factory()->create(['company_id' => $otherCompany->id]);

    $path = putTestDocumentFile($otherCompany->id, $otherEmployee->id, 'test.pdf');

    $doc = EmployeeDocument::query()->create([
        'company_id' => $otherCompany->id,
        'employee_id' => $otherEmployee->id,
        'title' => 'Other Company Document',
        'type' => 'passport',
        'document_type' => 'passport',
        'file_path' => $path,
        'original_filename' => 'test.pdf',
        'mime_type' => 'application/pdf',
    ]);

    $this->actingAs($user)
        ->get(route('organization.documents.files.download', $doc))
        ->assertNotFound();
});

test('hidden employee document preview returns 404', function () {
    fakeEmployeeFileDisks();
    ['user' => $user, 'company' => $company, 'marineDept' => $marineDept, 'officeEmployee' => $office] = makeEmployeeVisibilityFixtures();
    grantCompanyPermissions($user, $company, ['documents.view', 'documents.download']);
    restrictUserToDepartments($user, $company, [$marineDept->id]);

    $path = putTestDocumentFile($company->id, $office->id, 'test.pdf');

    $doc = EmployeeDocument::query()->create([
        'company_id' => $company->id,
        'employee_id' => $office->id,
        'title' => 'Office Passport',
        'type' => 'passport',
        'document_type' => 'passport',
        'file_path' => $path,
        'original_filename' => 'test.pdf',
        'mime_type' => 'application/pdf',
    ]);

    $this->actingAs($user)
        ->get(route('organization.documents.files.preview', $doc))
        ->assertNotFound();
});

test('hidden employee document version download returns 404', function () {
    fakeEmployeeFileDisks();
    ['user' => $user, 'company' => $company, 'marineDept' => $marineDept, 'officeEmployee' => $office] = makeEmployeeVisibilityFixtures();
    grantCompanyPermissions($user, $company, ['documents.view', 'documents.download']);
    restrictUserToDepartments($user, $company, [$marineDept->id]);

    $path = putTestDocumentFile($company->id, $office->id, 'test.pdf');
    $versionPath = putTestDocumentFile($company->id, $office->id, 'version1.pdf');

    $doc = EmployeeDocument::query()->create([
        'company_id' => $company->id,
        'employee_id' => $office->id,
        'title' => 'Office Passport',
        'type' => 'passport',
        'document_type' => 'passport',
        'file_path' => $path,
        'original_filename' => 'test.pdf',
        'mime_type' => 'application/pdf',
    ]);

    $version = EmployeeDocumentVersion::query()->create([
        'company_id' => $company->id,
        'employee_id' => $office->id,
        'employee_document_id' => $doc->id,
        'version' => 1,
        'file_path' => $versionPath,
        'original_filename' => 'version1.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 1234,
        'replaced_by' => $user->id,
    ]);

    $this->actingAs($user)
        ->get(route('organization.documents.files.versions.download', [$doc, $version]))
        ->assertNotFound();
});
