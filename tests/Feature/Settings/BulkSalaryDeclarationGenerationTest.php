<?php

use App\Jobs\GenerateSalaryDeclarationsJob;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\DocumentType;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\User;
use App\Services\SalaryDeclaration\RendersSalaryDeclarationPdf;
use App\Support\BulkDocuments\SalaryDeclarationGenerationProgress;
use App\Support\EmployeeDocuments\StoresEmployeeDocument;
use Database\Seeders\PermissionsSeeder;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed(PermissionsSeeder::class);
    Storage::fake('public');
});

test('authenticated users can dispatch salary declaration bulk generation job', function () {
    Queue::fake();

    $user = User::factory()->create();
    $this->actingAs($user);

    setupCompanyWithSettingsPermissions($user, ['settings.application.view']);

    $this->from(route('application.edit'))
        ->post(route('application.bulk-documents.salary-declarations'))
        ->assertRedirect(route('application.edit'))
        ->assertSessionHas('success');

    Queue::assertPushed(GenerateSalaryDeclarationsJob::class, function (GenerateSalaryDeclarationsJob $job) use ($user) {
        return $job->userId === $user->id;
    });
});

test('application settings expose salary declaration generation progress', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $country = Country::query()->create([
        'code' => 'PRG',
        'name' => 'Progress Land',
        'dial_code' => '+971',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'PRG',
        'name' => 'Progress Currency',
        'symbol' => 'AED',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Progress Co',
        'slug' => 'progress-co',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    grantCompanyPermissions($user, $company, ['settings.application.view']);

    SalaryDeclarationGenerationProgress::markQueued($company->id);

    $this->get(route('application.edit', ['tab' => 'bulk-documents']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('settings/application')
            ->where('salary_declaration_generation.status', 'running')
            ->where('salary_declaration_generation.message', 'Salary declaration generation queued...'));
});

test('authenticated users can clear all salary declaration documents', function () {
    $country = Country::query()->create([
        'code' => 'CL1',
        'name' => 'Clear Decl Land',
        'dial_code' => '+971',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'CL1',
        'name' => 'Clear Decl Currency',
        'symbol' => 'AED',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Clear Decl Co',
        'slug' => 'clear-decl-co',
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

    $employee = Employee::factory()->forCompany($company)->create([
        'branch_id' => $branch->id,
        'employee_no' => 'EMP-CL-01',
        'status' => 'active',
    ]);

    $otherCompany = Company::query()->create([
        'name' => 'Other Decl Co',
        'slug' => 'other-decl-co',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $otherEmployee = Employee::factory()->forCompany($otherCompany)->create([
        'branch_id' => Branch::query()->create([
            'company_id' => $otherCompany->id,
            'name' => 'Other HQ',
            'code' => 'OHQ',
            'status' => 'active',
            'is_headquarters' => true,
        ])->id,
        'employee_no' => 'EMP-CL-02',
        'status' => 'active',
    ]);

    $documentType = DocumentType::query()->firstOrCreate(
        ['title' => 'Salary Declaration'],
        ['is_active' => true],
    );

    createEmployeePdfDocument(
        $company->id,
        $employee->id,
        $documentType->id,
        "employee-documents/{$company->id}/{$employee->id}/clear-me.pdf",
        'clear-me.pdf',
    );

    createEmployeePdfDocument(
        $otherCompany->id,
        $otherEmployee->id,
        $documentType->id,
        "employee-documents/{$otherCompany->id}/{$otherEmployee->id}/keep-me.pdf",
        'keep-me.pdf',
    );

    $user = User::factory()->create();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['settings.application.view']);

    $this->from(route('application.edit'))
        ->delete(route('application.bulk-documents.salary-declarations.clear'))
        ->assertRedirect(route('application.edit'))
        ->assertSessionHas('success');

    expect(EmployeeDocument::query()
        ->where('company_id', $company->id)
        ->where('document_type_id', $documentType->id)
        ->count())->toBe(0);

    expect(EmployeeDocument::query()
        ->where('company_id', $otherCompany->id)
        ->where('document_type_id', $documentType->id)
        ->count())->toBe(1);

    Storage::disk('public')->assertMissing("employee-documents/{$company->id}/{$employee->id}/clear-me.pdf");
    Storage::disk('public')->assertExists("employee-documents/{$otherCompany->id}/{$otherEmployee->id}/keep-me.pdf");
});

test('authenticated users can download all salary declaration documents as zip', function () {
    $country = Country::query()->create([
        'code' => 'DL1',
        'name' => 'Download Decl Land',
        'dial_code' => '+971',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'DL1',
        'name' => 'Download Decl Currency',
        'symbol' => 'AED',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Download Decl Co',
        'slug' => 'download-decl-co',
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

    $employee = Employee::factory()->forCompany($company)->create([
        'branch_id' => $branch->id,
        'employee_no' => 'EMP-DL-01',
        'name' => 'Download Employee',
        'status' => 'active',
    ]);

    $documentType = DocumentType::query()->firstOrCreate(
        ['title' => 'Salary Declaration'],
        ['is_active' => true],
    );

    createEmployeePdfDocument(
        $company->id,
        $employee->id,
        $documentType->id,
        "employee-documents/{$company->id}/{$employee->id}/salary-declaration.pdf",
        'salary-declaration.pdf',
    );

    $user = User::factory()->create();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['settings.application.view']);

    $response = $this->post(route('application.bulk-documents.salary-declarations.download'));

    $response->assertOk();
    $response->assertDownload('salary_declarations.zip');

    $tmpPath = tempnam(sys_get_temp_dir(), 'salary_decl_zip_');
    file_put_contents($tmpPath, $response->streamedContent());

    $zip = new ZipArchive;
    expect($zip->open($tmpPath))->toBeTrue();
    expect($zip->numFiles)->toBeGreaterThan(0);
    $zip->close();

    @unlink($tmpPath);
});

test('download salary declarations returns not found when none exist', function () {
    $country = Country::query()->create([
        'code' => 'DL2',
        'name' => 'Download Decl Land 2',
        'dial_code' => '+971',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'DL2',
        'name' => 'Download Decl Currency 2',
        'symbol' => 'AED',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Download Decl Co 2',
        'slug' => 'download-decl-co-2',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $user = User::factory()->create();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['settings.application.view']);

    $this->post(route('application.bulk-documents.salary-declarations.download'))
        ->assertNotFound();
});

test('bulk generation job creates documents for active employees and skips existing', function () {
    $country = Country::query()->create([
        'code' => 'BD1',
        'name' => 'Bulk Decl Land',
        'dial_code' => '+971',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'BD1',
        'name' => 'Bulk Decl Currency',
        'symbol' => 'AED',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Bulk Decl Co',
        'slug' => 'bulk-decl-co',
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

    $activeEmployee = Employee::factory()->forCompany($company)->create([
        'branch_id' => $branch->id,
        'employee_no' => 'EMP-BD-01',
        'name' => 'Active Employee',
        'status' => 'active',
    ]);

    $inactiveEmployee = Employee::factory()->forCompany($company)->create([
        'branch_id' => $branch->id,
        'employee_no' => 'EMP-BD-02',
        'name' => 'Inactive Employee',
        'status' => 'inactive',
    ]);

    $existingEmployee = Employee::factory()->forCompany($company)->create([
        'branch_id' => $branch->id,
        'employee_no' => 'EMP-BD-03',
        'name' => 'Existing Employee',
        'status' => 'active',
    ]);

    $documentType = DocumentType::query()->firstOrCreate(
        ['title' => 'Salary Declaration'],
        ['is_active' => true],
    );

    createEmployeePdfDocument(
        $company->id,
        $existingEmployee->id,
        $documentType->id,
        "employee-documents/{$company->id}/{$existingEmployee->id}/existing.pdf",
        'existing.pdf',
    );

    $user = User::factory()->create();

    $this->mock(RendersSalaryDeclarationPdf::class, function ($mock): void {
        $mock->shouldReceive('render')
            ->andReturn(minimalPdfBytes());
    });

    (new GenerateSalaryDeclarationsJob($company->id, $user->id))->handle(
        app(RendersSalaryDeclarationPdf::class),
        app(StoresEmployeeDocument::class),
    );

    expect(EmployeeDocument::query()
        ->where('company_id', $company->id)
        ->where('document_type_id', $documentType->id)
        ->count())->toBe(2);

    expect(EmployeeDocument::query()
        ->where('employee_id', $activeEmployee->id)
        ->where('document_type_id', $documentType->id)
        ->exists())->toBeTrue();

    expect(EmployeeDocument::query()
        ->where('employee_id', $inactiveEmployee->id)
        ->where('document_type_id', $documentType->id)
        ->exists())->toBeFalse();

    expect(EmployeeDocument::query()
        ->where('employee_id', $existingEmployee->id)
        ->where('document_type_id', $documentType->id)
        ->count())->toBe(1);

    $createdDocument = EmployeeDocument::query()
        ->where('employee_id', $activeEmployee->id)
        ->where('document_type_id', $documentType->id)
        ->first();

    expect($createdDocument)->not->toBeNull();
    expect($createdDocument?->title)->toBe('Salary Declaration');
    expect($createdDocument?->mime_type)->toBe('application/pdf');
    Storage::disk('public')->assertExists((string) $createdDocument?->file_path);
});

test('bulk generation job chains chunks for large employee sets', function () {
    Queue::fake();

    $country = Country::query()->create([
        'code' => 'BD2',
        'name' => 'Bulk Decl Land 2',
        'dial_code' => '+971',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'BD2',
        'name' => 'Bulk Decl Currency 2',
        'symbol' => 'AED',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Bulk Decl Co 2',
        'slug' => 'bulk-decl-co-2',
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
        'code' => 'HQ2',
        'status' => 'active',
        'is_headquarters' => true,
    ]);

    Employee::factory()
        ->count(13)
        ->forCompany($company)
        ->create([
            'branch_id' => $branch->id,
            'status' => 'active',
        ]);

    $user = User::factory()->create();

    $this->mock(RendersSalaryDeclarationPdf::class, function ($mock): void {
        $mock->shouldReceive('render')
            ->andReturn(minimalPdfBytes());
    });

    (new GenerateSalaryDeclarationsJob($company->id, $user->id))->handle(
        app(RendersSalaryDeclarationPdf::class),
        app(StoresEmployeeDocument::class),
    );

    Queue::assertPushed(GenerateSalaryDeclarationsJob::class, function (GenerateSalaryDeclarationsJob $job) use ($company, $user) {
        return $job->companyId === $company->id
            && $job->userId === $user->id
            && $job->afterEmployeeId !== null
            && $job->batchCorrelationId !== null;
    });
});
