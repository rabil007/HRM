<?php

use App\Enums\BulkDocumentSignatureRequestStatus;
use App\Jobs\GenerateBulkDocumentsJob;
use App\Mail\BulkDocumentMail;
use App\Models\BulkDocumentEmailBatch;
use App\Models\BulkDocumentEmailSend;
use App\Models\BulkDocumentGenerationRun;
use App\Models\BulkDocumentSignatureRequest;
use App\Models\Company;
use App\Models\CompanyVisaType;
use App\Models\Department;
use App\Models\DocumentType;
use App\Models\EmailTemplate;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\User;
use App\Services\BulkDocuments\RendersEmployeeDocumentPdf;
use App\Services\SalaryDeclaration\SalaryDeclarationPdfRenderer;
use App\Support\BulkDocuments\BulkDocumentRosterQuery;
use App\Support\BulkDocuments\BulkDocumentTypeRegistry;
use App\Support\BulkDocuments\CreateBulkDocumentSignatureRequest;
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

test('bulk documents permissions are registered and legacy permission removed', function () {
    expect(Permission::query()->where('name', 'settings.application.bulk-documents')->exists())->toBeFalse();

    foreach ([
        'bulk_documents.view',
        'bulk_documents.generate',
        'bulk_documents.delete',
        'bulk_documents.email',
        'bulk_documents.signatures.review',
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
    EmailTemplatesSeeder::seedBulkSalaryDeclarationSignReminderTemplate();
    EmailTemplatesSeeder::seedBulkSalaryCertificateTemplate();

    expect(EmailTemplate::query()->where('slug', 'bulk_salary_declaration')->exists())->toBeTrue()
        ->and(EmailTemplate::query()->where('slug', 'bulk_salary_declaration_sign_reminder')->exists())->toBeTrue()
        ->and(EmailTemplate::query()->where('slug', 'bulk_salary_certificate')->exists())->toBeTrue();
});

test('bulk document email templates use structured html paragraphs', function () {
    EmailTemplatesSeeder::seedBulkSalaryDeclarationTemplate();
    EmailTemplatesSeeder::seedBulkSalaryDeclarationSignReminderTemplate();
    EmailTemplatesSeeder::seedBulkSalaryCertificateTemplate();

    $declarationBody = EmailTemplate::query()
        ->where('slug', 'bulk_salary_declaration')
        ->value('body_html');

    $reminderBody = EmailTemplate::query()
        ->where('slug', 'bulk_salary_declaration_sign_reminder')
        ->value('body_html');

    $certificateBody = EmailTemplate::query()
        ->where('slug', 'bulk_salary_certificate')
        ->value('body_html');

    expect($declarationBody)
        ->toContain('<p style="margin:0 0 16px;">Dear {{employee_name}},</p>')
        ->and($reminderBody)
        ->toContain('still awaiting your signature')
        ->toContain('{{signature_url}}')
        ->toContain('Sign declaration')
        ->and($certificateBody)
        ->toContain('<p style="margin:0 0 16px;">Dear {{employee_name}},</p>');
});

test('resolve email template returns wired template per document type', function () {
    EmailTemplatesSeeder::seedBulkSalaryDeclarationTemplate();
    EmailTemplatesSeeder::seedBulkSalaryDeclarationSignReminderTemplate();
    EmailTemplatesSeeder::seedBulkSalaryCertificateTemplate();

    $declaration = BulkDocumentTypeRegistry::resolveEmailTemplate('salary_declaration');
    $reminder = BulkDocumentTypeRegistry::resolveEmailTemplate('salary_declaration', 'reminder');
    $certificate = BulkDocumentTypeRegistry::resolveEmailTemplate('salary_certificate');
    $certificateReminder = BulkDocumentTypeRegistry::resolveEmailTemplate('salary_certificate', 'reminder');

    expect($declaration?->slug)->toBe('bulk_salary_declaration')
        ->and($reminder?->slug)->toBe('bulk_salary_declaration_sign_reminder')
        ->and($certificate?->slug)->toBe('bulk_salary_certificate')
        ->and($certificateReminder?->slug)->toBe('bulk_salary_certificate');
});

test('bulk documents page exposes wired email template for active document type', function () {
    EmailTemplatesSeeder::seedBulkSalaryDeclarationTemplate();
    EmailTemplatesSeeder::seedBulkSalaryDeclarationSignReminderTemplate();
    EmailTemplatesSeeder::seedBulkSalaryCertificateTemplate();

    $user = User::factory()->create();
    $this->actingAs($user);

    setupBulkDocumentsCompany($user, ['bulk_documents.view']);

    $this->get(route('organization.documents.bulk', ['document_type_key' => 'salary_certificate']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('email_template.slug', 'bulk_salary_certificate')
            ->where('reminder_email_template', null)
            ->where('company_name', 'Bulk Docs Co'));

    $this->get(route('organization.documents.bulk', ['document_type_key' => 'salary_declaration']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('email_template.slug', 'bulk_salary_declaration')
            ->where('reminder_email_template.slug', 'bulk_salary_declaration_sign_reminder'));
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

test('generated generation filter paginates only employees with documents', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $company = setupBulkDocumentsCompany($user, ['bulk_documents.view']);

    $withDoc = Employee::factory()->forCompany($company)->create(['status' => 'active']);
    Employee::factory()->count(5)->forCompany($company)->create(['status' => 'active']);

    $documentType = DocumentType::query()->firstOrCreate(['title' => 'Salary Declaration'], ['is_active' => true]);

    createEmployeePdfDocument(
        $company->id,
        $withDoc->id,
        $documentType->id,
        "employee-documents/{$company->id}/{$withDoc->id}/existing.pdf",
        'existing.pdf',
    );

    $this->get(route('organization.documents.bulk', [
        'generation_filter' => 'generated',
        'per_page' => 10,
    ]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('employees', 1)
            ->where('pagination.total', 1)
            ->where('counts.generated', 1)
            ->where('counts.not_generated', 5)
            ->where('generation_filter', 'generated'));
});

test('emailed filter paginates only employees with sent bulk document emails', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $company = setupBulkDocumentsCompany($user, ['bulk_documents.view']);

    $emailedEmployee = Employee::factory()->forCompany($company)->create([
        'status' => 'active',
        'name' => 'Emailed Employee',
    ]);

    $notEmailedEmployee = Employee::factory()->forCompany($company)->create([
        'status' => 'active',
        'name' => 'Not Emailed Employee',
    ]);

    $batch = BulkDocumentEmailBatch::query()->create([
        'company_id' => $company->id,
        'document_type_key' => 'salary_declaration',
        'subject' => 'Salary declaration',
        'total_selected' => 1,
        'sent_count' => 1,
        'failed_count' => 0,
        'skipped_no_email_count' => 0,
        'triggered_by' => $user->id,
    ]);

    BulkDocumentEmailSend::query()->create([
        'batch_id' => $batch->id,
        'employee_id' => $emailedEmployee->id,
        'recipient_email' => 'emailed@example.com',
        'status' => 'sent',
        'sent_at' => now(),
    ]);

    $this->get(route('organization.documents.bulk', [
        'email_filter' => 'emailed',
    ]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('employees', 1)
            ->where('employees.0.name', 'Emailed Employee')
            ->where('email_filter', 'emailed'));

    $this->get(route('organization.documents.bulk', [
        'email_filter' => 'not_emailed',
    ]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('employees', 1)
            ->where('employees.0.name', 'Not Emailed Employee')
            ->where('email_filter', 'not_emailed'));
});

test('sponsor filter paginates only employees with matching company visa type', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $company = setupBulkDocumentsCompany($user, ['bulk_documents.view']);

    $experts = CompanyVisaType::query()->create([
        'name' => 'EXPERTS',
        'is_active' => true,
    ]);

    $highLand = CompanyVisaType::query()->create([
        'name' => 'High Land',
        'is_active' => true,
    ]);

    Employee::factory()->forCompany($company)->create([
        'status' => 'active',
        'name' => 'Experts Employee',
        'company_visa_type_id' => $experts->id,
    ]);

    Employee::factory()->forCompany($company)->create([
        'status' => 'active',
        'name' => 'High Land Employee',
        'company_visa_type_id' => $highLand->id,
    ]);

    $this->get(route('organization.documents.bulk', [
        'company_visa_type_id' => $experts->id,
    ]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('employees', 1)
            ->where('employees.0.name', 'Experts Employee')
            ->where('filters.company_visa_type_id', (string) $experts->id)
            ->where('counts.targeted', 1)
            ->has('company_visa_types'));
});

test('bulk document counts respect department and emailed filters', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $company = setupBulkDocumentsCompany($user, ['bulk_documents.view']);

    $operationsDepartment = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Operations',
        'code' => 'OPS',
        'status' => 'active',
    ]);

    $hrDepartment = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Human Resources',
        'code' => 'HR',
        'status' => 'active',
    ]);

    $emailedInOps = Employee::factory()->forCompany($company)->create([
        'status' => 'active',
        'department_id' => $operationsDepartment->id,
    ]);

    Employee::factory()->forCompany($company)->create([
        'status' => 'active',
        'department_id' => $hrDepartment->id,
    ]);

    $batch = BulkDocumentEmailBatch::query()->create([
        'company_id' => $company->id,
        'document_type_key' => 'salary_declaration',
        'subject' => 'Salary declaration',
        'total_selected' => 1,
        'sent_count' => 1,
        'failed_count' => 0,
        'skipped_no_email_count' => 0,
        'triggered_by' => $user->id,
    ]);

    BulkDocumentEmailSend::query()->create([
        'batch_id' => $batch->id,
        'employee_id' => $emailedInOps->id,
        'recipient_email' => 'ops@example.com',
        'status' => 'sent',
        'sent_at' => now(),
    ]);

    $this->get(route('organization.documents.bulk', [
        'department_id' => $operationsDepartment->id,
        'email_filter' => 'emailed',
    ]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('counts.targeted', 1)
            ->where('counts.generated', 0)
            ->where('counts.not_generated', 1));

    $this->get(route('organization.documents.bulk', [
        'department_id' => $hrDepartment->id,
    ]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('counts.targeted', 1)
            ->where('counts.not_generated', 1));
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

test('bulk documents signatures view respects employee filters', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $company = setupBulkDocumentsCompany($user, ['bulk_documents.view']);

    $operationsDepartment = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Operations',
        'code' => 'OPS',
        'status' => 'active',
    ]);

    $hrDepartment = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Human Resources',
        'code' => 'HR',
        'status' => 'active',
    ]);

    $operationsEmployee = Employee::factory()->forCompany($company)->create([
        'status' => 'active',
        'name' => 'Alice Operations',
        'department_id' => $operationsDepartment->id,
    ]);

    $hrEmployee = Employee::factory()->forCompany($company)->create([
        'status' => 'active',
        'name' => 'Bob Human Resources',
        'department_id' => $hrDepartment->id,
    ]);

    $documentType = DocumentType::query()->firstOrCreate(['title' => 'Salary Declaration'], ['is_active' => true]);

    foreach ([$operationsEmployee, $hrEmployee] as $employee) {
        $document = createEmployeePdfDocument(
            $company->id,
            $employee->id,
            $documentType->id,
            "employee-documents/{$company->id}/{$employee->id}/declaration.pdf",
            'declaration.pdf',
        );

        BulkDocumentSignatureRequest::query()->create([
            'company_id' => $company->id,
            'employee_id' => $employee->id,
            'employee_document_id' => $document->id,
            'document_type_key' => 'salary_declaration',
            'token' => str_repeat((string) $employee->id, 48),
            'status' => 'awaiting_signature',
            'expires_at' => now()->addDays(14),
        ]);
    }

    $this->get(route('organization.documents.bulk', [
        'view' => 'signatures',
        'department_id' => $operationsDepartment->id,
    ]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('view', 'signatures')
            ->has('signature_requests', 1)
            ->where('signature_requests.0.employee.name', 'Alice Operations'));

    $this->get(route('organization.documents.bulk', [
        'view' => 'signatures',
        'search' => 'Bob',
    ]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('signature_requests', 1)
            ->where('signature_requests.0.employee.name', 'Bob Human Resources'));
});

test('bulk documents signatures view orders submitted requests by signed date descending', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $company = setupBulkDocumentsCompany($user, ['bulk_documents.view']);

    $olderEmployee = Employee::factory()->forCompany($company)->create([
        'status' => 'active',
        'name' => 'Older Submission',
    ]);

    $newerEmployee = Employee::factory()->forCompany($company)->create([
        'status' => 'active',
        'name' => 'Newer Submission',
    ]);

    $documentType = DocumentType::query()->firstOrCreate(['title' => 'Salary Declaration'], ['is_active' => true]);

    foreach ([
        [$olderEmployee, now()->subDays(2)],
        [$newerEmployee, now()->subDay()],
    ] as [$employee, $signedAt]) {
        $document = createEmployeePdfDocument(
            $company->id,
            $employee->id,
            $documentType->id,
            "employee-documents/{$company->id}/{$employee->id}/declaration-{$employee->id}.pdf",
            'declaration.pdf',
        );

        BulkDocumentSignatureRequest::query()->create([
            'company_id' => $company->id,
            'employee_id' => $employee->id,
            'employee_document_id' => $document->id,
            'document_type_key' => 'salary_declaration',
            'token' => str_repeat((string) $employee->id, 48),
            'status' => BulkDocumentSignatureRequestStatus::Submitted,
            'signed_at' => $signedAt,
            'expires_at' => now()->addDays(14),
        ]);
    }

    $this->get(route('organization.documents.bulk', [
        'view' => 'signatures',
        'signature_filter' => 'submitted',
    ]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('view', 'signatures')
            ->has('signature_requests', 2)
            ->where('signature_requests.0.employee.name', 'Newer Submission')
            ->where('signature_requests.1.employee.name', 'Older Submission'));
});

test('bulk documents history view respects employee filters for email batches', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $company = setupBulkDocumentsCompany($user, ['bulk_documents.view']);

    $operationsDepartment = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Operations',
        'code' => 'OPS',
        'status' => 'active',
    ]);

    $hrDepartment = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Human Resources',
        'code' => 'HR',
        'status' => 'active',
    ]);

    $operationsEmployee = Employee::factory()->forCompany($company)->create([
        'status' => 'active',
        'name' => 'Alice Operations',
        'department_id' => $operationsDepartment->id,
    ]);

    $hrEmployee = Employee::factory()->forCompany($company)->create([
        'status' => 'active',
        'name' => 'Bob Human Resources',
        'department_id' => $hrDepartment->id,
    ]);

    $batch = BulkDocumentEmailBatch::query()->create([
        'company_id' => $company->id,
        'document_type_key' => 'salary_declaration',
        'subject' => 'Salary declaration',
        'total_selected' => 2,
        'sent_count' => 2,
        'failed_count' => 0,
        'skipped_no_email_count' => 0,
        'triggered_by' => $user->id,
    ]);

    foreach ([$operationsEmployee, $hrEmployee] as $employee) {
        BulkDocumentEmailSend::query()->create([
            'batch_id' => $batch->id,
            'employee_id' => $employee->id,
            'recipient_email' => 'employee@example.com',
            'status' => 'sent',
            'sent_at' => now(),
        ]);
    }

    BulkDocumentGenerationRun::query()->create([
        'company_id' => $company->id,
        'document_type_key' => 'salary_declaration',
        'filters' => ['department_id' => (string) $hrDepartment->id],
        'status' => 'completed',
        'total_targeted' => 1,
        'generated_count' => 1,
        'replaced_count' => 0,
        'skipped_count' => 0,
        'failed_count' => 0,
        'triggered_by' => $user->id,
    ]);

    $this->get(route('organization.documents.bulk', [
        'view' => 'history',
        'department_id' => $operationsDepartment->id,
    ]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('view', 'history')
            ->has('activity', 1)
            ->where('activity.0.kind', 'email')
            ->where('activity.0.id', $batch->id));

    $this->get(route('organization.documents.bulk', [
        'view' => 'history',
        'department_id' => $hrDepartment->id,
    ]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('activity', 2)
            ->where('activity', function ($activity) use ($batch): bool {
                $items = collect($activity);

                return $items->count() === 2
                    && $items->pluck('kind')->contains('email')
                    && $items->pluck('kind')->contains('generation')
                    && $items->contains(
                        fn (array $item): bool => $item['kind'] === 'email' && $item['id'] === $batch->id,
                    );
            }));
});

test('bulk email batch sends endpoint returns recipient rows for history drill-down', function () {
    Mail::fake();

    $user = User::factory()->create();
    $this->actingAs($user);

    $company = setupBulkDocumentsCompany($user, ['bulk_documents.view']);
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

    $template = EmailTemplate::factory()->create([
        'subject' => 'Hello {{employee_name}}',
        'body_html' => '<p>{{document_type}}</p>',
        'enabled' => true,
        'category' => 'document',
    ]);

    $batchId = app(SendBulkDocumentEmails::class)->handle(
        $company->id,
        $user->id,
        'salary_declaration',
        collect([$employee]),
        $template,
    )['batch_id'];

    $this->getJson(route('organization.documents.bulk.email-batches.sends', ['batch' => $batchId]))
        ->assertOk()
        ->assertJsonPath('batch.id', $batchId)
        ->assertJsonPath('sends.0.employee.id', $employee->id)
        ->assertJsonPath('sends.0.recipient_email', 'work@example.com')
        ->assertJsonPath('sends.0.status', 'sent');
});

test('bulk email batch sends endpoint returns not found for other company batches', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $company = setupBulkDocumentsCompany($user, ['bulk_documents.view']);

    $otherCompany = Company::query()->create([
        'name' => 'Other Bulk Docs Co',
        'slug' => 'other-bulk-docs-co-'.fake()->unique()->numerify('###'),
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $company->country_id,
        'currency_id' => $company->currency_id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $batch = BulkDocumentEmailBatch::query()->create([
        'company_id' => $otherCompany->id,
        'document_type_key' => 'salary_declaration',
        'subject' => 'Test',
        'total_selected' => 1,
        'sent_count' => 0,
        'failed_count' => 0,
        'skipped_no_email_count' => 0,
        'triggered_by' => $user->id,
    ]);

    $this->getJson(route('organization.documents.bulk.email-batches.sends', ['batch' => $batch->id]))
        ->assertNotFound();
});

test('bulk documents page excludes inactive employees even when status filter is requested', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $company = setupBulkDocumentsCompany($user, ['bulk_documents.view']);

    Employee::factory()->forCompany($company)->create([
        'name' => 'Active Employee',
        'status' => 'active',
    ]);

    Employee::factory()->forCompany($company)->create([
        'name' => 'Inactive Employee',
        'status' => 'inactive',
    ]);

    $this->get(route('organization.documents.bulk', ['status' => 'inactive']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('employees', 1)
            ->where('employees.0.name', 'Active Employee')
            ->where('filters.status', 'active')
            ->where('counts.targeted', 1));
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

test('bulk document counts include pending review awaiting and approved totals', function () {
    $user = User::factory()->create();
    $company = setupBulkDocumentsCompany($user, ['bulk_documents.view']);

    $submittedEmployee = Employee::factory()->forCompany($company)->create(['status' => 'active']);
    $awaitingEmployee = Employee::factory()->forCompany($company)->create(['status' => 'active']);
    $approvedEmployee = Employee::factory()->forCompany($company)->create(['status' => 'active']);

    $documentType = DocumentType::query()->firstOrCreate(['title' => 'Salary Declaration'], ['is_active' => true]);

    foreach ([
        [$submittedEmployee, BulkDocumentSignatureRequestStatus::Submitted],
        [$awaitingEmployee, BulkDocumentSignatureRequestStatus::AwaitingSignature],
        [$approvedEmployee, BulkDocumentSignatureRequestStatus::Approved],
    ] as [$employee, $status]) {
        $document = createEmployeePdfDocument(
            $company->id,
            $employee->id,
            $documentType->id,
            "employee-documents/{$company->id}/{$employee->id}/declaration-{$employee->id}.pdf",
            'declaration.pdf',
        );

        BulkDocumentSignatureRequest::query()->create([
            'company_id' => $company->id,
            'employee_id' => $employee->id,
            'employee_document_id' => $document->id,
            'document_type_key' => 'salary_declaration',
            'token' => str_repeat((string) $employee->id, 48),
            'status' => $status,
            'expires_at' => now()->addDays(14),
        ]);
    }

    $counts = BulkDocumentRosterQuery::counts(
        $company->id,
        'salary_declaration',
        EmployeeDirectoryFilters::fromArray(['status' => 'active']),
    );

    expect($counts)->toMatchArray([
        'pending_review' => 1,
        'awaiting_signature' => 1,
        'approved' => 1,
    ]);

    $this->actingAs($user)
        ->get(route('organization.documents.bulk', [
            'view' => 'signatures',
            'signature_filter' => 'awaiting_signature',
        ]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('counts.awaiting_signature', 1)
            ->where('counts.pending_review', 1)
            ->where('counts.approved', 1)
            ->has('signature_requests', 1)
            ->where('signature_requests.0.status', 'awaiting_signature'));

    $this->actingAs($user)
        ->get(route('organization.documents.bulk', [
            'view' => 'signatures',
            'signature_filter' => 'approved',
        ]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('signature_filter', 'approved')
            ->has('signature_requests', 1)
            ->where('signature_requests.0.status', 'approved')
            ->where('signature_requests.0.employee.id', $approvedEmployee->id));
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
        public function render(Employee $employee, int $companyId, ?array $signature = null): string
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
        public function render(Employee $employee, int $companyId, ?array $signature = null): string
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

test('deleting bulk documents cancels pending signature requests', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $company = setupBulkDocumentsCompany($user, ['bulk_documents.view', 'bulk_documents.delete']);

    $employee = Employee::factory()->forCompany($company)->create(['status' => 'active']);
    $documentType = DocumentType::query()->firstOrCreate(['title' => 'Salary Declaration'], ['is_active' => true]);

    $document = createEmployeePdfDocument(
        $company->id,
        $employee->id,
        $documentType->id,
        "employee-documents/{$company->id}/{$employee->id}/awaiting.pdf",
        'awaiting.pdf',
    );

    $awaitingRequest = app(CreateBulkDocumentSignatureRequest::class)->handle(
        $company->id,
        $employee->id,
        $document,
        'salary_declaration',
    );

    $submittedDocument = createEmployeePdfDocument(
        $company->id,
        $employee->id,
        $documentType->id,
        "employee-documents/{$company->id}/{$employee->id}/submitted.pdf",
        'submitted.pdf',
    );

    $submittedRequest = BulkDocumentSignatureRequest::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'employee_document_id' => $submittedDocument->id,
        'document_type_key' => 'salary_declaration',
        'token' => str_repeat('c', 48),
        'status' => BulkDocumentSignatureRequestStatus::Submitted,
        'expires_at' => now()->addDays(14),
    ]);

    $this->delete(route('organization.documents.bulk.documents.destroy'), [
        'document_type_key' => 'salary_declaration',
        'document_ids' => [$document->id, $submittedDocument->id],
    ])->assertRedirect()
        ->assertSessionHas('success');

    expect($awaitingRequest->fresh()->status)->toBe(BulkDocumentSignatureRequestStatus::Cancelled)
        ->and($submittedRequest->fresh()->status)->toBe(BulkDocumentSignatureRequestStatus::Cancelled);
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

    $batch = BulkDocumentEmailBatch::query()->latest('id')->first();

    expect($batch?->email_template_id)->toBe(
        EmailTemplate::query()->where('slug', 'bulk_salary_declaration')->value('id'),
    );

    Mail::assertQueued(BulkDocumentMail::class, function (BulkDocumentMail $mail) {
        return $mail->ccRecipients === ['hr@example.com']
            && str_contains($mail->subjectLine, 'Your Salary Declaration');
    });
});

test('bulk email reminder intent stores and sends reminder template', function () {
    Mail::fake();

    $user = User::factory()->create();
    $this->actingAs($user);

    $company = setupBulkDocumentsCompany($user, ['bulk_documents.email']);

    EmailTemplatesSeeder::seedBulkSalaryDeclarationTemplate();
    $reminder = EmailTemplatesSeeder::seedBulkSalaryDeclarationSignReminderTemplate();

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
        'email_intent' => 'reminder',
    ])->assertRedirect()
        ->assertSessionHas('success');

    $batch = BulkDocumentEmailBatch::query()->latest('id')->first();

    expect($batch?->email_template_id)->toBe($reminder->id)
        ->and($batch?->subject)->toContain('Reminder: please sign');

    Mail::assertQueued(BulkDocumentMail::class, function (BulkDocumentMail $mail) {
        return str_contains($mail->subjectLine, 'Reminder: please sign')
            && str_contains($mail->bodyMessage, 'still awaiting your signature');
    });
});

test('bulk document email template slugs include reminder template', function () {
    expect(BulkDocumentTypeRegistry::emailTemplateSlugs())
        ->toContain('bulk_salary_declaration')
        ->toContain('bulk_salary_declaration_sign_reminder')
        ->toContain('bulk_salary_certificate');
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

test('bulk document selection returns all matching employee ids across pages', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $company = setupBulkDocumentsCompany($user, ['bulk_documents.view']);

    Employee::factory()->count(25)->forCompany($company)->create([
        'status' => 'active',
    ]);

    $this->get(route('organization.documents.bulk.selection'))
        ->assertOk()
        ->assertJsonPath('total', 25)
        ->assertJson(fn ($json) => $json
            ->where('total', 25)
            ->has('employee_ids', 25)
            ->has('document_ids')
            ->etc());
});

test('bulk document selection respects generation filter', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $company = setupBulkDocumentsCompany($user, ['bulk_documents.view']);

    $withDoc = Employee::factory()->forCompany($company)->create(['status' => 'active']);
    Employee::factory()->count(5)->forCompany($company)->create(['status' => 'active']);

    $documentType = DocumentType::query()->firstOrCreate(['title' => 'Salary Declaration'], ['is_active' => true]);

    createEmployeePdfDocument(
        $company->id,
        $withDoc->id,
        $documentType->id,
        "employee-documents/{$company->id}/{$withDoc->id}/existing.pdf",
        'existing.pdf',
    );

    $missingSelection = $this->get(route('organization.documents.bulk.selection', [
        'generation_filter' => 'missing',
    ]))
        ->assertOk()
        ->assertJsonPath('total', 5);

    expect($missingSelection->json('employee_ids'))->not->toContain($withDoc->id);

    $this->get(route('organization.documents.bulk.selection', [
        'generation_filter' => 'generated',
    ]))
        ->assertOk()
        ->assertJsonPath('total', 1)
        ->assertJsonPath('employee_ids.0', $withDoc->id)
        ->assertJsonCount(1, 'document_ids');
});

test('bulk document selection requires view permission', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    setupBulkDocumentsCompany($user, []);

    $this->get(route('organization.documents.bulk.selection'))
        ->assertForbidden();
});
