<?php

use App\Jobs\GenerateBulkDocumentsJob;
use App\Mail\BulkDocumentMail;
use App\Models\BulkDocumentEmailBatch;
use App\Models\BulkDocumentGenerationRun;
use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\DocumentType;
use App\Models\EmailTemplate;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\User;
use App\Services\BulkDocuments\RendersEmployeeDocumentPdf;
use App\Services\SalaryDeclaration\SalaryDeclarationPdfRenderer;
use App\Support\BulkDocuments\BulkDocumentRosterQuery;
use App\Support\BulkDocuments\BulkDocumentTypeRegistry;
use App\Support\BulkDocuments\SendBulkDocumentEmails;
use App\Support\EmployeeDocuments\DocumentDeletionService;
use App\Support\EmployeeDocuments\StoresEmployeeDocument;
use App\Support\Employees\EmployeeDirectoryFilters;
use Database\Seeders\EmailTemplatesSeeder;
use Database\Seeders\PermissionsSeeder;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;

beforeEach(function () {
    $this->seed(PermissionsSeeder::class);
    Storage::fake('public');
});

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

test('bulk documents permissions are registered and legacy permission removed', function () {
    expect(Permission::query()->where('name', 'settings.application.bulk-documents')->exists())->toBeFalse();

    foreach ([
        'bulk_documents.view',
        'bulk_documents.generate',
        'bulk_documents.delete',
        'bulk_documents.email',
    ] as $permission) {
        expect(Permission::query()->where('name', $permission)->exists())->toBeTrue();
    }
});

test('users with view permission can open bulk documents page', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $company = setupBulkDocumentsCompany($user, ['bulk_documents.view']);

    Employee::factory()->forCompany($company)->create([
        'status' => 'active',
    ]);

    $this->get(route('organization.documents.bulk'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('organization/documents/bulk/index')
            ->has('document_type_options', 2)
            ->has('employees', 1)
            ->has('pagination')
            ->where('pagination.total', 1)
            ->where('counts.targeted', 1)
            ->where('counts.not_generated', 1)
            ->where('company_name', 'Bulk Docs Co')
            ->has('email_template'));
});

test('bulk document email templates are seeded', function () {
    EmailTemplatesSeeder::seedBulkSalaryDeclarationTemplate();
    EmailTemplatesSeeder::seedBulkSalaryCertificateTemplate();

    expect(EmailTemplate::query()->where('slug', 'bulk_salary_declaration')->exists())->toBeTrue()
        ->and(EmailTemplate::query()->where('slug', 'bulk_salary_certificate')->exists())->toBeTrue();
});

test('resolve email template returns wired template per document type', function () {
    EmailTemplatesSeeder::seedBulkSalaryDeclarationTemplate();
    EmailTemplatesSeeder::seedBulkSalaryCertificateTemplate();

    $declaration = BulkDocumentTypeRegistry::resolveEmailTemplate('salary_declaration');
    $certificate = BulkDocumentTypeRegistry::resolveEmailTemplate('salary_certificate');

    expect($declaration?->slug)->toBe('bulk_salary_declaration')
        ->and($certificate?->slug)->toBe('bulk_salary_certificate');
});

test('bulk documents page exposes wired email template for active document type', function () {
    EmailTemplatesSeeder::seedBulkSalaryDeclarationTemplate();
    EmailTemplatesSeeder::seedBulkSalaryCertificateTemplate();

    $user = User::factory()->create();
    $this->actingAs($user);

    setupBulkDocumentsCompany($user, ['bulk_documents.view']);

    $this->get(route('organization.documents.bulk', ['document_type_key' => 'salary_certificate']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('email_template.slug', 'bulk_salary_certificate')
            ->where('company_name', 'Bulk Docs Co'));
});

test('bulk documents page paginates employee roster', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $company = setupBulkDocumentsCompany($user, ['bulk_documents.view']);

    Employee::factory()
        ->count(25)
        ->forCompany($company)
        ->create(['status' => 'active']);

    $this->get(route('organization.documents.bulk', ['per_page' => 10]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('employees', 10)
            ->where('pagination.total', 25)
            ->where('pagination.per_page', 10)
            ->where('pagination.last_page', 3)
            ->where('counts.targeted', 25));

    $this->get(route('organization.documents.bulk', ['per_page' => 10, 'page' => 2]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('employees', 10)
            ->where('pagination.current_page', 2));
});

test('missing generation filter paginates only employees without documents', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $company = setupBulkDocumentsCompany($user, ['bulk_documents.view']);

    $withDoc = Employee::factory()->forCompany($company)->create(['status' => 'active']);
    Employee::factory()->count(14)->forCompany($company)->create(['status' => 'active']);

    $documentType = DocumentType::query()->firstOrCreate(['title' => 'Salary Declaration'], ['is_active' => true]);

    createEmployeePdfDocument(
        $company->id,
        $withDoc->id,
        $documentType->id,
        "employee-documents/{$company->id}/{$withDoc->id}/existing.pdf",
        'existing.pdf',
    );

    $this->get(route('organization.documents.bulk', [
        'generation_filter' => 'missing',
        'per_page' => 10,
    ]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('employees', 10)
            ->where('pagination.total', 14)
            ->where('counts.generated', 1)
            ->where('counts.not_generated', 14)
            ->where('generation_filter', 'missing'));
});

test('bulk documents history view returns paginated activity', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $company = setupBulkDocumentsCompany($user, ['bulk_documents.view']);

    BulkDocumentGenerationRun::query()->create([
        'company_id' => $company->id,
        'document_type_key' => 'salary_declaration',
        'filters' => ['status' => 'active'],
        'status' => 'completed',
        'total_targeted' => 5,
        'generated_count' => 2,
        'replaced_count' => 0,
        'skipped_count' => 3,
        'failed_count' => 0,
        'triggered_by' => $user->id,
    ]);

    $this->get(route('organization.documents.bulk', ['view' => 'history']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('organization/documents/bulk/index')
            ->where('view', 'history')
            ->has('activity', 1)
            ->where('activity.0.kind', 'generation')
            ->where('activity.0.generated_count', 2)
            ->has('pagination'));
});

test('bulk documents routes enforce permissions', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $company = setupBulkDocumentsCompany($user, ['bulk_documents.view']);
    $employee = Employee::factory()->forCompany($company)->create(['status' => 'active']);

    $this->post(route('organization.documents.bulk.generate'), [
        'document_type_key' => 'salary_declaration',
    ])->assertForbidden();

    $this->delete(route('organization.documents.bulk.documents.destroy'), [
        'document_type_key' => 'salary_declaration',
        'document_ids' => [1],
    ])->assertForbidden();

    $this->post(route('organization.documents.bulk.email'), [
        'document_type_key' => 'salary_declaration',
        'employee_ids' => [$employee->id],
    ])->assertForbidden();
});

test('generate dispatches bulk documents job and creates run row', function () {
    Queue::fake();

    $user = User::factory()->create();
    $this->actingAs($user);

    $company = setupBulkDocumentsCompany($user, ['bulk_documents.view', 'bulk_documents.generate']);

    Employee::factory()->forCompany($company)->create(['status' => 'active']);

    $this->post(route('organization.documents.bulk.generate'), [
        'document_type_key' => 'salary_declaration',
        'status' => 'active',
    ])->assertRedirect()
        ->assertSessionHas('success');

    expect(BulkDocumentGenerationRun::query()->count())->toBe(1);

    Queue::assertPushed(GenerateBulkDocumentsJob::class, function (GenerateBulkDocumentsJob $job) use ($company, $user) {
        return $job->companyId === $company->id
            && $job->userId === $user->id
            && $job->documentTypeKey === 'salary_declaration'
            && $job->replaceExisting === false;
    });
});

test('selected generate uses replace existing mode', function () {
    Queue::fake();

    $user = User::factory()->create();
    $this->actingAs($user);

    $company = setupBulkDocumentsCompany($user, ['bulk_documents.view', 'bulk_documents.generate']);
    $employee = Employee::factory()->forCompany($company)->create(['status' => 'active']);

    $this->post(route('organization.documents.bulk.generate'), [
        'document_type_key' => 'salary_declaration',
        'employee_ids' => [$employee->id],
    ])->assertRedirect();

    Queue::assertPushed(GenerateBulkDocumentsJob::class, fn (GenerateBulkDocumentsJob $job) => $job->replaceExisting === true
        && $job->employeeIds === [$employee->id]);
});

test('roster counts reflect generated and missing documents', function () {
    $user = User::factory()->create();
    $company = setupBulkDocumentsCompany($user, ['bulk_documents.view']);

    $employeeWithDoc = Employee::factory()->forCompany($company)->create(['status' => 'active']);
    $employeeMissing = Employee::factory()->forCompany($company)->create(['status' => 'active']);

    $documentType = DocumentType::query()->firstOrCreate(['title' => 'Salary Declaration'], ['is_active' => true]);

    createEmployeePdfDocument(
        $company->id,
        $employeeWithDoc->id,
        $documentType->id,
        "employee-documents/{$company->id}/{$employeeWithDoc->id}/existing.pdf",
        'existing.pdf',
    );

    $counts = BulkDocumentRosterQuery::counts(
        $company->id,
        'salary_declaration',
        EmployeeDirectoryFilters::fromArray(['status' => 'active']),
    );

    expect($counts)->toMatchArray([
        'targeted' => 2,
        'generated' => 1,
        'not_generated' => 1,
    ]);
});

test('bulk generation job skips existing documents in fill gaps mode', function () {
    $user = User::factory()->create();
    $company = setupBulkDocumentsCompany($user, ['bulk_documents.generate']);

    $existingEmployee = Employee::factory()->forCompany($company)->create(['status' => 'active']);
    $missingEmployee = Employee::factory()->forCompany($company)->create(['status' => 'active']);

    $documentType = DocumentType::query()->firstOrCreate(['title' => 'Salary Declaration'], ['is_active' => true]);

    createEmployeePdfDocument(
        $company->id,
        $existingEmployee->id,
        $documentType->id,
        "employee-documents/{$company->id}/{$existingEmployee->id}/existing.pdf",
        'existing.pdf',
    );

    $run = BulkDocumentGenerationRun::query()->create([
        'company_id' => $company->id,
        'document_type_key' => 'salary_declaration',
        'filters' => ['status' => 'active'],
        'status' => 'queued',
        'total_targeted' => 2,
        'triggered_by' => $user->id,
    ]);

    $renderer = new class implements RendersEmployeeDocumentPdf
    {
        public function render(Employee $employee, int $companyId): string
        {
            return minimalPdfBytes();
        }
    };

    app()->instance(SalaryDeclarationPdfRenderer::class, $renderer);

    (new GenerateBulkDocumentsJob(
        $company->id,
        $user->id,
        'salary_declaration',
        ['status' => 'active'],
        $run->id,
        false,
    ))->handle(app(StoresEmployeeDocument::class), app(DocumentDeletionService::class));

    $run->refresh();

    expect($run->generated_count)->toBe(1)
        ->and($run->skipped_count)->toBe(1)
        ->and(EmployeeDocument::query()->where('document_type_id', $documentType->id)->count())->toBe(2);
});

test('bulk generation job replaces existing documents for selected employees', function () {
    $user = User::factory()->create();
    $company = setupBulkDocumentsCompany($user, ['bulk_documents.generate']);

    $employee = Employee::factory()->forCompany($company)->create(['status' => 'active']);

    $documentType = DocumentType::query()->firstOrCreate(['title' => 'Salary Declaration'], ['is_active' => true]);

    $existing = createEmployeePdfDocument(
        $company->id,
        $employee->id,
        $documentType->id,
        "employee-documents/{$company->id}/{$employee->id}/existing.pdf",
        'existing.pdf',
    );

    $run = BulkDocumentGenerationRun::query()->create([
        'company_id' => $company->id,
        'document_type_key' => 'salary_declaration',
        'filters' => ['status' => 'active'],
        'status' => 'queued',
        'total_targeted' => 1,
        'triggered_by' => $user->id,
    ]);

    $renderer = new class implements RendersEmployeeDocumentPdf
    {
        public function render(Employee $employee, int $companyId): string
        {
            return minimalPdfBytes();
        }
    };

    app()->instance(SalaryDeclarationPdfRenderer::class, $renderer);

    (new GenerateBulkDocumentsJob(
        $company->id,
        $user->id,
        'salary_declaration',
        ['status' => 'active'],
        $run->id,
        true,
        [$employee->id],
    ))->handle(app(StoresEmployeeDocument::class), app(DocumentDeletionService::class));

    $run->refresh();

    expect($run->replaced_count)->toBe(1)
        ->and(EmployeeDocument::query()->find($existing->id))->toBeNull()
        ->and(EmployeeDocument::query()->where('employee_id', $employee->id)->where('document_type_id', $documentType->id)->count())->toBe(1);
});

test('users can delete selected bulk documents', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $company = setupBulkDocumentsCompany($user, ['bulk_documents.view', 'bulk_documents.delete']);

    $employee = Employee::factory()->forCompany($company)->create(['status' => 'active']);
    $documentType = DocumentType::query()->firstOrCreate(['title' => 'Salary Declaration'], ['is_active' => true]);

    $document = createEmployeePdfDocument(
        $company->id,
        $employee->id,
        $documentType->id,
        "employee-documents/{$company->id}/{$employee->id}/existing.pdf",
        'existing.pdf',
    );

    $this->delete(route('organization.documents.bulk.documents.destroy'), [
        'document_type_key' => 'salary_declaration',
        'document_ids' => [$document->id],
    ])->assertRedirect()
        ->assertSessionHas('success');

    expect(EmployeeDocument::query()->find($document->id))->toBeNull();
});

test('bulk email uses work email with personal fallback and records history', function () {
    Mail::fake();

    $user = User::factory()->create();
    $company = setupBulkDocumentsCompany($user, ['bulk_documents.email']);

    $withWorkEmail = Employee::factory()->forCompany($company)->create([
        'status' => 'active',
        'work_email' => 'work@example.com',
        'personal_email' => 'personal@example.com',
    ]);

    $withPersonalOnly = Employee::factory()->forCompany($company)->create([
        'status' => 'active',
        'work_email' => null,
        'personal_email' => 'personal-only@example.com',
    ]);

    $withoutEmail = Employee::factory()->forCompany($company)->create([
        'status' => 'active',
        'work_email' => null,
        'personal_email' => null,
    ]);

    $documentType = DocumentType::query()->firstOrCreate(['title' => 'Salary Declaration'], ['is_active' => true]);

    foreach ([$withWorkEmail, $withPersonalOnly] as $employee) {
        createEmployeePdfDocument(
            $company->id,
            $employee->id,
            $documentType->id,
            "employee-documents/{$company->id}/{$employee->id}/doc-{$employee->id}.pdf",
            'doc.pdf',
        );
    }

    $template = EmailTemplate::factory()->create([
        'subject' => 'Hello {{employee_name}}',
        'body_html' => '<p>{{document_type}} for {{company_name}}</p>',
        'enabled' => true,
        'category' => 'document',
    ]);

    $sender = app(SendBulkDocumentEmails::class);

    $result = $sender->handle(
        $company->id,
        $user->id,
        'salary_declaration',
        collect([$withWorkEmail, $withPersonalOnly, $withoutEmail]),
        $template,
    );

    expect($result['sent'])->toBe(2)
        ->and($result['skipped_no_email'])->toBe(1)
        ->and(BulkDocumentEmailBatch::query()->count())->toBe(1);

    Mail::assertQueued(BulkDocumentMail::class, 2);
});

test('bulk email applies cc recipients and excludes recipient duplicates', function () {
    Mail::fake();

    $user = User::factory()->create();
    $company = setupBulkDocumentsCompany($user, ['bulk_documents.email']);

    $employee = Employee::factory()->forCompany($company)->create([
        'status' => 'active',
        'work_email' => 'work@example.com',
    ]);

    $documentType = DocumentType::query()->firstOrCreate(['title' => 'Salary Declaration'], ['is_active' => true]);

    createEmployeePdfDocument(
        $company->id,
        $employee->id,
        $documentType->id,
        "employee-documents/{$company->id}/{$employee->id}/doc.pdf",
        'doc.pdf',
    );

    $template = EmailTemplate::query()->where('slug', 'bulk_salary_declaration')->first()
        ?? EmailTemplate::factory()->create([
            'slug' => 'bulk_salary_declaration_test',
            'subject' => 'Hello {{employee_name}}',
            'body_html' => '<p>{{document_type}}</p>',
            'enabled' => true,
            'category' => 'document',
        ]);

    $sender = app(SendBulkDocumentEmails::class);

    $sender->handle(
        $company->id,
        $user->id,
        'salary_declaration',
        collect([$employee]),
        $template,
        ['hr@example.com', 'work@example.com', 'hr@example.com'],
    );

    Mail::assertQueued(BulkDocumentMail::class, function (BulkDocumentMail $mail) {
        return $mail->ccRecipients === ['hr@example.com'];
    });
});

test('bulk email send resolves template from registry and accepts cc', function () {
    Mail::fake();

    $user = User::factory()->create();
    $this->actingAs($user);

    $company = setupBulkDocumentsCompany($user, ['bulk_documents.email']);

    EmailTemplatesSeeder::seedBulkSalaryDeclarationTemplate();

    $employee = Employee::factory()->forCompany($company)->create([
        'status' => 'active',
        'work_email' => 'employee@example.com',
    ]);

    $documentType = DocumentType::query()->firstOrCreate(['title' => 'Salary Declaration'], ['is_active' => true]);

    createEmployeePdfDocument(
        $company->id,
        $employee->id,
        $documentType->id,
        "employee-documents/{$company->id}/{$employee->id}/doc.pdf",
        'doc.pdf',
    );

    $this->post(route('organization.documents.bulk.email'), [
        'document_type_key' => 'salary_declaration',
        'employee_ids' => [$employee->id],
        'cc' => ['hr@example.com'],
    ])->assertRedirect()
        ->assertSessionHas('success');

    Mail::assertQueued(BulkDocumentMail::class, function (BulkDocumentMail $mail) {
        return $mail->ccRecipients === ['hr@example.com'];
    });
});

test('bulk document recipients search returns employees with resolved email', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $company = setupBulkDocumentsCompany($user, ['bulk_documents.email']);

    $match = Employee::factory()->forCompany($company)->create([
        'name' => 'Searchable Person',
        'employee_no' => 'EMP-100',
        'work_email' => 'searchable@example.com',
        'status' => 'active',
    ]);

    Employee::factory()->forCompany($company)->create([
        'name' => 'No Email Person',
        'work_email' => null,
        'personal_email' => null,
        'status' => 'active',
    ]);

    $this->get(route('organization.documents.bulk.recipients-search', ['q' => 'Searchable']))
        ->assertOk()
        ->assertJsonPath('employees.0.id', $match->id)
        ->assertJsonPath('employees.0.name', 'Searchable Person')
        ->assertJsonPath('employees.0.email', 'searchable@example.com');
});

test('bulk document recipients search requires email permission', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    setupBulkDocumentsCompany($user, ['bulk_documents.view']);

    $this->get(route('organization.documents.bulk.recipients-search', ['q' => 'test']))
        ->assertForbidden();
});
