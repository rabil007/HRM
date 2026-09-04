<?php

use App\Enums\DocumentGenerationTemplateStatus;
use App\Jobs\GenerateCustomDocumentsJob;
use App\Models\BulkDocumentGenerationRun;
use App\Models\Company;
use App\Models\DocumentGenerationRun;
use App\Models\DocumentGenerationRunItem;
use App\Models\DocumentGenerationTemplate;
use App\Models\DocumentGenerationTemplateVersion;
use App\Models\Employee;
use App\Models\User;
use App\Support\BulkDocuments\DocumentGenerationProgressQuery;
use Database\Seeders\PermissionsSeeder;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed(PermissionsSeeder::class);
});

/**
 * @return array{user: User, other: User, company: Company, otherCompany: Company, template: DocumentGenerationTemplate, version: DocumentGenerationTemplateVersion}
 */
function makeGenerationProgressFixtures(): array
{
    $user = User::factory()->create();
    $other = User::factory()->create();
    test()->actingAs($user);
    $company = setupBulkDocumentsCompany($user, ['bulk_documents.view', 'bulk_documents.generate']);
    grantCompanyPermissions($other, $company, ['bulk_documents.view', 'bulk_documents.generate']);

    $otherCompany = setupBulkDocumentsCompany($user, ['bulk_documents.view']);

    $template = DocumentGenerationTemplate::factory()->forCompany($company)->create([
        'status' => DocumentGenerationTemplateStatus::Active,
        'name' => 'Salary Declaration',
    ]);
    $version = DocumentGenerationTemplateVersion::factory()->forTemplate($template)->published()->create(['version' => 1]);
    $template->update(['published_version_id' => $version->id]);

    return compact('user', 'other', 'company', 'otherCompany', 'template', 'version');
}

test('posting custom generation creates a queued run for the current user', function () {
    Queue::fake();
    ['user' => $user, 'company' => $company, 'template' => $template] = makeGenerationProgressFixtures();
    Employee::factory()->forCompany($company)->create(['status' => 'active']);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('organization.documents.custom.generate'), [
            'document_generation_template_id' => $template->id,
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    $run = DocumentGenerationRun::query()->first();
    expect($run)->not->toBeNull()
        ->and($run->status)->toBe('queued')
        ->and($run->triggered_by)->toBe($user->id)
        ->and($run->company_id)->toBe($company->id)
        ->and($run->total_targeted)->toBe(1);

    Queue::assertPushed(GenerateCustomDocumentsJob::class);
});

test('custom generate page includes the current users queued run as latest_run', function () {
    ['user' => $user, 'company' => $company, 'template' => $template, 'version' => $version] = makeGenerationProgressFixtures();

    $run = DocumentGenerationRun::query()->create([
        'company_id' => $company->id,
        'document_generation_template_id' => $template->id,
        'document_generation_template_version_id' => $version->id,
        'status' => 'queued',
        'total_targeted' => 20,
        'correlation_id' => (string) Str::uuid(),
        'triggered_by' => $user->id,
    ]);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->get(route('organization.documents.generate', [
            'document_type_key' => "custom_{$template->id}",
            'per_page' => 20,
            'search' => 'rabil',
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/documents/bulk/index')
            ->where('document_type_key', "custom_{$template->id}")
            ->where('search', 'rabil')
            ->where('latest_run.id', $run->id)
            ->where('latest_run.source', 'company_template')
            ->where('latest_run.status', 'queued')
            ->where('latest_run.total_targeted', 20)
            ->where('latest_run.processed_count', 0)
            ->where('latest_run.progress_percent', 0)
            ->where('latest_run.triggered_by.id', $user->id));
});

test('progress query is company and template scoped and hides another users run', function () {
    ['user' => $user, 'other' => $other, 'company' => $company, 'otherCompany' => $otherCompany, 'template' => $template, 'version' => $version] = makeGenerationProgressFixtures();

    $otherTemplate = DocumentGenerationTemplate::factory()->forCompany($company)->create([
        'status' => DocumentGenerationTemplateStatus::Active,
        'name' => 'Other Letter',
    ]);
    $otherVersion = DocumentGenerationTemplateVersion::factory()->forTemplate($otherTemplate)->published()->create();
    $otherTemplate->update(['published_version_id' => $otherVersion->id]);

    $ownRun = DocumentGenerationRun::query()->create([
        'company_id' => $company->id,
        'document_generation_template_id' => $template->id,
        'document_generation_template_version_id' => $version->id,
        'status' => 'running',
        'total_targeted' => 3,
        'correlation_id' => (string) Str::uuid(),
        'triggered_by' => $user->id,
    ]);

    DocumentGenerationRun::query()->create([
        'company_id' => $company->id,
        'document_generation_template_id' => $template->id,
        'document_generation_template_version_id' => $version->id,
        'status' => 'running',
        'total_targeted' => 99,
        'correlation_id' => (string) Str::uuid(),
        'triggered_by' => $other->id,
    ]);

    DocumentGenerationRun::query()->create([
        'company_id' => $company->id,
        'document_generation_template_id' => $otherTemplate->id,
        'document_generation_template_version_id' => $otherVersion->id,
        'status' => 'running',
        'total_targeted' => 7,
        'correlation_id' => (string) Str::uuid(),
        'triggered_by' => $user->id,
    ]);

    $otherCompanyTemplate = DocumentGenerationTemplate::factory()->forCompany($otherCompany)->create([
        'status' => DocumentGenerationTemplateStatus::Active,
        'name' => 'Foreign Letter',
    ]);
    $otherCompanyVersion = DocumentGenerationTemplateVersion::factory()->forTemplate($otherCompanyTemplate)->published()->create();
    $otherCompanyTemplate->update(['published_version_id' => $otherCompanyVersion->id]);

    DocumentGenerationRun::query()->create([
        'company_id' => $otherCompany->id,
        'document_generation_template_id' => $otherCompanyTemplate->id,
        'document_generation_template_version_id' => $otherCompanyVersion->id,
        'status' => 'running',
        'total_targeted' => 4,
        'correlation_id' => (string) Str::uuid(),
        'triggered_by' => $user->id,
    ]);

    $query = new DocumentGenerationProgressQuery;
    $payload = $query->forCurrentUserCustomTemplate($company->id, $user->id, $template, $version);

    expect($payload['id'])->toBe($ownRun->id)
        ->and($payload['total_targeted'])->toBe(3);

    $this->actingAs($other)
        ->withSession(['current_company_id' => $company->id])
        ->get(route('organization.documents.generate', [
            'document_type_key' => "custom_{$template->id}",
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('latest_run.triggered_by.id', $other->id)
            ->where('latest_run.total_targeted', 99));
});

test('active custom run updates item-derived counts on the generate page', function () {
    ['user' => $user, 'company' => $company, 'template' => $template, 'version' => $version] = makeGenerationProgressFixtures();

    $run = DocumentGenerationRun::query()->create([
        'company_id' => $company->id,
        'document_generation_template_id' => $template->id,
        'document_generation_template_version_id' => $version->id,
        'status' => 'running',
        'total_targeted' => 4,
        'generated_count' => 0,
        'skipped_count' => 0,
        'failed_count' => 0,
        'correlation_id' => (string) Str::uuid(),
        'triggered_by' => $user->id,
    ]);

    foreach (['completed', 'pending', 'processing', 'failed'] as $status) {
        DocumentGenerationRunItem::query()->create([
            'company_id' => $company->id,
            'document_generation_run_id' => $run->id,
            'employee_id' => Employee::factory()->forCompany($company)->create(['status' => 'active'])->id,
            'status' => $status,
        ]);
    }

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->get(route('organization.documents.generate', [
            'document_type_key' => "custom_{$template->id}",
            'search' => 'rabil',
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('latest_run.generated_count', 1)
            ->where('latest_run.failed_count', 1)
            ->where('latest_run.pending_count', 1)
            ->where('latest_run.processing_count', 1)
            ->where('latest_run.processed_count', 2)
            ->where('latest_run.progress_percent', 50)
            ->where('search', 'rabil'));
});

test('custom generate page exposes failure_summary for the current users run', function () {
    ['user' => $user, 'company' => $company, 'template' => $template, 'version' => $version] = makeGenerationProgressFixtures();
    $employee = Employee::factory()->forCompany($company)->create([
        'status' => 'active',
        'name' => 'Overflow Emp',
        'image' => 'employee-photos/overflow.jpg',
    ]);

    $run = DocumentGenerationRun::query()->create([
        'company_id' => $company->id,
        'document_generation_template_id' => $template->id,
        'document_generation_template_version_id' => $version->id,
        'status' => 'completed',
        'total_targeted' => 1,
        'generated_count' => 0,
        'failed_count' => 1,
        'correlation_id' => (string) Str::uuid(),
        'triggered_by' => $user->id,
        'finished_at' => now(),
    ]);
    DocumentGenerationRunItem::query()->create([
        'company_id' => $company->id,
        'document_generation_run_id' => $run->id,
        'employee_id' => $employee->id,
        'status' => 'failed',
        'error_code' => 'TEMPLATE_LAYOUT_OVERFLOW',
        'error_message' => 'Employee Full Name does not fit the configured field on page 1.',
    ]);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->get(route('organization.documents.generate', [
            'document_type_key' => "custom_{$template->id}",
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('employees.0.image', 'employee-photos/overflow.jpg')
            ->where('employees.0.generation_run_status', 'failed')
            ->where('employees.0.generation_error.code', 'TEMPLATE_LAYOUT_OVERFLOW')
            ->where('latest_run.failure_summary.count', 1)
            ->where('latest_run.failure_summary.items.0.employee_id', $employee->id)
            ->where('latest_run.failure_summary.show_edit_template', true));
});

test('built-in generate page still includes latest_run', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $company = setupBulkDocumentsCompany($user, ['bulk_documents.view']);

    $run = BulkDocumentGenerationRun::query()->create([
        'company_id' => $company->id,
        'document_type_key' => 'salary_certificate',
        'filters' => ['status' => 'active'],
        'status' => 'running',
        'total_targeted' => 5,
        'generated_count' => 2,
        'replaced_count' => 0,
        'skipped_count' => 1,
        'failed_count' => 0,
        'triggered_by' => $user->id,
    ]);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->get(route('organization.documents.generate', [
            'document_type_key' => 'salary_certificate',
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('latest_run.id', $run->id)
            ->where('latest_run.source', 'built_in')
            ->where('latest_run.status', 'running')
            ->where('latest_run.processed_count', 3)
            ->where('latest_run.progress_percent', 60)
            ->where('latest_run.template_name', 'Salary Certificate'));
});
