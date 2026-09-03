<?php

use App\Enums\BulkDocumentSignatureRequestStatus;
use App\Models\BulkDocumentSignatureRequest;
use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\User;
use Illuminate\Support\Str;

function setupBulkDocumentsCompany(User $user, array $permissions = []): Company
{
    $country = Country::query()->create([
        'code' => 'BD'.fake()->unique()->numerify('###'),
        'name' => 'Bulk Docs Land',
        'dial_code' => '+971',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'BD'.fake()->unique()->numerify('###'),
        'name' => 'Bulk Docs Currency',
        'symbol' => 'AED',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Bulk Docs Co',
        'slug' => 'bulk-docs-co-'.fake()->unique()->numerify('###'),
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    grantCompanyPermissions($user, $company, $permissions);

    session(['current_company_id' => $company->id]);

    return $company;
}

function createLegacyBulkDocumentSignatureRequest(
    Company $company,
    Employee $employee,
    EmployeeDocument $document,
    BulkDocumentSignatureRequestStatus $status = BulkDocumentSignatureRequestStatus::AwaitingSignature,
    array $overrides = [],
): BulkDocumentSignatureRequest {
    return BulkDocumentSignatureRequest::query()->create(array_merge([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'employee_document_id' => $document->id,
        'document_type_key' => 'salary_declaration',
        'token' => Str::random(48),
        'status' => $status,
        'expires_at' => now()->addDays(14),
    ], $overrides));
}

function minimalSignatureDataUrl(): string
{
    $png = base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
        true,
    );

    return 'data:image/png;base64,'.base64_encode($png ?: '');
}
