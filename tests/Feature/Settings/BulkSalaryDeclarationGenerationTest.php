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
use App\Support\EmployeeDocuments\StoresEmployeeDocument;
use Database\Seeders\PermissionsSeeder;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed(PermissionsSeeder::class);
    Storage::fake('public');
});

test('users without permission cannot bulk generate salary declarations', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    setupCompanyWithSettingsPermissions($user, ['settings.application.view']);

    $this->post(route('application.bulk-documents.salary-declarations'))
        ->assertForbidden();
});

test('authorized users can dispatch salary declaration bulk generation job', function () {
    Queue::fake();

    $user = User::factory()->create();
    $this->actingAs($user);

    setupCompanyWithSettingsPermissions($user, ['settings.application.bulk-documents']);

    $this->from(route('application.edit'))
        ->post(route('application.bulk-documents.salary-declarations'))
        ->assertRedirect(route('application.edit'))
        ->assertSessionHas('success');

    Queue::assertPushed(GenerateSalaryDeclarationsJob::class, function (GenerateSalaryDeclarationsJob $job) use ($user) {
        return $job->userId === $user->id;
    });
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
