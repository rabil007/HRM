<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\DocumentType;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use Illuminate\Support\Facades\Storage;
use setasign\Fpdi\Fpdi;

function makeDocumentFixtures(): array
{
    $country = Country::query()->firstOrCreate(
        ['code' => 'DT1'],
        ['name' => 'Doc Test Land', 'dial_code' => '+900', 'is_active' => true],
    );

    $currency = Currency::query()->firstOrCreate(
        ['code' => 'DT1'],
        ['name' => 'Doc Test Currency', 'symbol' => 'D$', 'is_active' => true],
    );

    $company = Company::query()->create([
        'name' => 'DocCo',
        'slug' => 'docco-'.uniqid(),
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $branch = Branch::query()->create([
        'company_id' => $company->id,
        'name' => 'HQ',
        'code' => 'HQ',
        'status' => 'active',
        'is_headquarters' => true,
    ]);

    $employee = Employee::query()->create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'employee_no' => 'DOC001',
        'name' => 'Test Employee',
        'status' => 'active',
    ]);

    $passportType = DocumentType::query()->firstOrCreate(
        ['title' => 'Passport Copy'],
        ['is_active' => true],
    );

    $visaType = DocumentType::query()->firstOrCreate(
        ['title' => 'Visa'],
        ['is_active' => true],
    );

    return compact('company', 'branch', 'employee', 'passportType', 'visaType');
}

function minimalPdfBytes(): string
{
    $pdf = new Fpdi;
    $pdf->AddPage();
    $pdf->SetFont('Helvetica', '', 12);
    $pdf->Cell(0, 10, 'Test document');

    return $pdf->Output('S');
}

function createEmployeePdfDocument(
    int $companyId,
    int $employeeId,
    int $documentTypeId,
    string $relativePath,
    string $filename,
): EmployeeDocument {
    Storage::disk('public')->put($relativePath, minimalPdfBytes());

    return EmployeeDocument::query()->create([
        'company_id' => $companyId,
        'employee_id' => $employeeId,
        'document_type_id' => $documentTypeId,
        'type' => 'other',
        'document_type' => (string) $documentTypeId,
        'file_path' => $relativePath,
        'original_filename' => $filename,
        'mime_type' => 'application/pdf',
        'status' => 'valid',
    ]);
}
