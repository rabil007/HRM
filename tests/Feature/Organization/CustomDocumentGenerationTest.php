<?php

use App\Enums\DocumentGenerationTemplateFormat;
use App\Enums\DocumentGenerationTemplateStatus;
use App\Jobs\GenerateCustomDocumentsJob;
use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\DocumentGenerationRun;
use App\Models\DocumentGenerationRunItem;
use App\Models\DocumentGenerationTemplate;
use App\Models\DocumentGenerationTemplateVersion;
use App\Models\DocumentInstance;
use App\Models\DocumentInstanceVersion;
use App\Models\DocumentType;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\User;
use App\Services\Documents\ContentTemplatePdfRenderer;
use App\Support\BulkDocuments\CustomDocumentRosterQuery;
use App\Support\Documents\Actions\SyncGeneratedEmployeeDocument;
use App\Support\EmployeeDocuments\DocumentDeletionService;
use App\Support\Employees\EmployeeDirectoryFilters;
use Database\Seeders\PermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;

require_once __DIR__.'/../../Support/document-fixtures.php';

function createCustomGenTestCompany(string $name = 'Custom Gen Co'): Company
{
    $code = strtoupper((string) fake()->unique()->lexify('??'));
    $country = Country::query()->firstOrCreate(
        ['code' => $code],
        ['name' => "Test {$code}", 'dial_code' => '+999', 'is_active' => true],
    );
    $currency = Currency::query()->firstOrCreate(
        ['code' => $code],
        ['name' => "Test {$code}", 'symbol' => '$', 'is_active' => true],
    );

    return Company::query()->create([
        'name' => $name,
        'slug' => strtolower($code).'-'.fake()->unique()->numberBetween(1000, 9999),
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);
}

beforeEach(function () {
    $this->seed(PermissionsSeeder::class);
    Storage::fake('local');
});

test('users without bulk_documents.generate cannot generate custom documents', function () {
    $user = User::factory()->create();
    $company = createCustomGenTestCompany();
    grantCompanyPermissions($user, $company, ['documents.templates.view']);

    $template = DocumentGenerationTemplate::factory()->forCompany($company)->create([
        'status' => DocumentGenerationTemplateStatus::Active,
    ]);
    $version = DocumentGenerationTemplateVersion::factory()->forTemplate($template)->published()->create();
    $template->update(['published_version_id' => $version->id]);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('organization.documents.custom.generate'), [
            'document_generation_template_id' => $template->id,
        ])
        ->assertForbidden();
});

test('cannot generate custom document for another companys template', function () {
    $user = User::factory()->create();
    $companyA = createCustomGenTestCompany('Company A');
    $companyB = createCustomGenTestCompany('Company B');

    grantCompanyPermissions($user, $companyA, ['bulk_documents.generate']);

    $templateB = DocumentGenerationTemplate::factory()->forCompany($companyB)->create([
        'status' => DocumentGenerationTemplateStatus::Active,
    ]);
    $versionB = DocumentGenerationTemplateVersion::factory()->forTemplate($templateB)->published()->create();
    $templateB->update(['published_version_id' => $versionB->id]);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $companyA->id])
        ->post(route('organization.documents.custom.generate'), [
            'document_generation_template_id' => $templateB->id,
        ])
        ->assertSessionHasErrors(['document_generation_template_id']);
});

test('cannot generate custom document for inactive template', function () {
    $user = User::factory()->create();
    $company = createCustomGenTestCompany();
    grantCompanyPermissions($user, $company, ['bulk_documents.generate']);

    $template = DocumentGenerationTemplate::factory()->forCompany($company)->create([
        'status' => DocumentGenerationTemplateStatus::Inactive,
    ]);
    $version = DocumentGenerationTemplateVersion::factory()->forTemplate($template)->published()->create();
    $template->update(['published_version_id' => $version->id]);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('organization.documents.custom.generate'), [
            'document_generation_template_id' => $template->id,
        ])
        ->assertSessionHasErrors(['document_generation_template_id']);
});

test('generate custom documents creates run and dispatches job for targeted employees', function () {
    Queue::fake();

    $user = User::factory()->create();
    $company = createCustomGenTestCompany();
    grantCompanyPermissions($user, $company, ['bulk_documents.generate']);

    $emp1 = Employee::factory()->forCompany($company)->create(['status' => 'active']);
    $emp2 = Employee::factory()->forCompany($company)->create(['status' => 'active']);

    $template = DocumentGenerationTemplate::factory()->forCompany($company)->create([
        'status' => DocumentGenerationTemplateStatus::Active,
        'name' => 'Employment Verification',
    ]);
    $version = DocumentGenerationTemplateVersion::factory()->forTemplate($template)->published()->create([
        'version' => 1,
        'content' => 'Dear {{employee_name}}, you are employed with {{company_name}}.',
    ]);
    $template->update(['published_version_id' => $version->id]);

    $response = $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('organization.documents.custom.generate'), [
            'document_generation_template_id' => $template->id,
        ]);

    $response->assertRedirect();
    $response->assertSessionHas('success');

    $run = DocumentGenerationRun::query()->where('company_id', $company->id)->first();
    expect($run)->not->toBeNull()
        ->and($run->document_generation_template_id)->toBe($template->id)
        ->and($run->document_generation_template_version_id)->toBe($version->id)
        ->and($run->total_targeted)->toBe(2)
        ->and($run->status)->toBe('queued');

    expect(DocumentGenerationRunItem::query()->where('document_generation_run_id', $run->id)->count())->toBe(2);

    Queue::assertPushed(GenerateCustomDocumentsJob::class, function (GenerateCustomDocumentsJob $job) use ($company, $user, $run) {
        return $job->companyId === $company->id
            && $job->userId === $user->id
            && $job->runId === $run->id;
    });
});

test('job renders PDF, stores canonical artifact, creates document instance and version 1, and syncs employee document', function () {
    $user = User::factory()->create();
    $company = createCustomGenTestCompany();

    $employee = Employee::factory()->forCompany($company)->create([
        'status' => 'active',
        'name' => 'John Doe',
        'employee_no' => 'EMP-1001',
    ]);

    $template = DocumentGenerationTemplate::factory()->forCompany($company)->create([
        'status' => DocumentGenerationTemplateStatus::Active,
        'name' => 'Experience Certificate',
    ]);
    $version = DocumentGenerationTemplateVersion::factory()->forTemplate($template)->published()->create([
        'version' => 1,
        'content' => 'This certifies that {{employee_name}} works for {{company_name}}.',
    ]);
    $template->update(['published_version_id' => $version->id]);

    $run = DocumentGenerationRun::query()->create([
        'company_id' => $company->id,
        'document_generation_template_id' => $template->id,
        'document_generation_template_version_id' => $version->id,
        'status' => 'queued',
        'total_targeted' => 1,
        'correlation_id' => 'test-corr-id',
        'triggered_by' => $user->id,
    ]);

    $item = DocumentGenerationRunItem::query()->create([
        'company_id' => $company->id,
        'document_generation_run_id' => $run->id,
        'employee_id' => $employee->id,
        'status' => 'pending',
    ]);

    // Mock renderer with deterministic PDF bytes
    $fakePdfBytes = minimalPdfBytes();
    $mockRenderer = Mockery::mock(ContentTemplatePdfRenderer::class);
    $mockRenderer->shouldReceive('render')
        ->once()
        ->with(
            Mockery::type(DocumentGenerationTemplate::class),
            Mockery::type(DocumentGenerationTemplateVersion::class),
            Mockery::type(Employee::class),
            $company->id,
        )
        ->andReturn($fakePdfBytes);

    $job = new GenerateCustomDocumentsJob($company->id, $user->id, $run->id, false);
    $job->handle($mockRenderer, app(SyncGeneratedEmployeeDocument::class));

    $run->refresh();
    $item->refresh();

    expect($run->status)->toBe('completed')
        ->and($run->generated_count)->toBe(1)
        ->and($run->skipped_count)->toBe(0)
        ->and($run->failed_count)->toBe(0)
        ->and($item->status)->toBe('completed')
        ->and($item->document_instance_id)->not->toBeNull();

    // Verify DocumentInstance
    $instance = DocumentInstance::query()->find($item->document_instance_id);
    expect($instance)->not->toBeNull()
        ->and($instance->company_id)->toBe($company->id)
        ->and($instance->employee_id)->toBe($employee->id)
        ->and($instance->employee_name_snapshot)->toBe($employee->name)
        ->and($instance->employee_no_snapshot)->toBe('EMP-1001')
        ->and($instance->template_name_snapshot)->toBe('Experience Certificate')
        ->and($instance->template_version_number)->toBe(1)
        ->and($instance->status)->toBe('generated')
        ->and($instance->employee_document_id)->not->toBeNull();

    // Verify DocumentInstanceVersion
    $instanceVersion = $instance->currentVersion;
    expect($instanceVersion)->not->toBeNull()
        ->and($instanceVersion->version)->toBe(1)
        ->and($instanceVersion->stage)->toBe('generated')
        ->and($instanceVersion->size_bytes)->toBe(strlen($fakePdfBytes))
        ->and($instanceVersion->checksum)->toBe(hash('sha256', $fakePdfBytes))
        ->and(str_starts_with($instanceVersion->file_path, "document-instances/{$company->id}/"))->toBeTrue();

    // Verify file exists on disk
    expect(Storage::disk('local')->exists($instanceVersion->file_path))->toBeTrue();

    // Verify EmployeeDocument library representation
    $libraryDoc = EmployeeDocument::query()->find($instance->employee_document_id);
    expect($libraryDoc)->not->toBeNull()
        ->and($libraryDoc->company_id)->toBe($company->id)
        ->and($libraryDoc->employee_id)->toBe($employee->id)
        ->and($libraryDoc->title)->toBe('Experience Certificate')
        ->and($libraryDoc->current_version)->toBe(1)
        ->and(str_starts_with($libraryDoc->file_path, "employee-documents/{$company->id}/{$employee->id}/"))->toBeTrue()
        ->and(Storage::disk('local')->exists($libraryDoc->file_path))->toBeTrue();

    // Verify DocumentInstance immutability guards
    expect(fn () => $instanceVersion->update(['checksum' => 'tampered']))
        ->toThrow(DomainException::class);
});

test('deleting library employee document does NOT delete canonical artifact from document-instances/ and sets employee_document_id to null', function () {
    $user = User::factory()->create();
    $company = createCustomGenTestCompany();
    $employee = Employee::factory()->forCompany($company)->create(['status' => 'active']);

    $template = DocumentGenerationTemplate::factory()->forCompany($company)->create([
        'status' => DocumentGenerationTemplateStatus::Active,
    ]);
    $version = DocumentGenerationTemplateVersion::factory()->forTemplate($template)->published()->create();
    $template->update(['published_version_id' => $version->id]);

    $canonicalPath = "document-instances/{$company->id}/canonical.pdf";
    $libraryPath = "employee-documents/{$company->id}/{$employee->id}/library.pdf";

    Storage::disk('local')->put($canonicalPath, minimalPdfBytes());
    Storage::disk('local')->put($libraryPath, minimalPdfBytes());

    $libraryDoc = EmployeeDocument::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'type' => 'other',
        'document_type' => 'other',
        'title' => 'Test Letter',
        'file_path' => $libraryPath,
        'original_filename' => 'library.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 100,
        'checksum' => 'checksum',
        'current_version' => 1,
        'status' => 'valid',
    ]);

    $instance = DocumentInstance::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'employee_name_snapshot' => $employee->name,
        'document_generation_template_id' => $template->id,
        'document_generation_template_version_id' => $version->id,
        'template_name_snapshot' => $template->name,
        'template_version_number' => 1,
        'title_snapshot' => $template->name,
        'status' => 'generated',
        'employee_document_id' => $libraryDoc->id,
        'generated_at' => now(),
    ]);

    $instanceVersion = DocumentInstanceVersion::query()->create([
        'company_id' => $company->id,
        'document_instance_id' => $instance->id,
        'version' => 1,
        'file_path' => $canonicalPath,
        'original_filename' => 'canonical.pdf',
        'size_bytes' => 100,
        'checksum' => 'checksum',
    ]);
    $instance->update(['current_version_id' => $instanceVersion->id]);

    // Delete library document via service
    app(DocumentDeletionService::class)->delete($libraryDoc);

    $instance->refresh();

    // Canonical artifact still exists on disk!
    expect(Storage::disk('local')->exists($canonicalPath))->toBeTrue();
    // Library copy is removed from disk!
    expect(Storage::disk('local')->exists($libraryPath))->toBeFalse();
    // Instance retains provenance, but employee_document_id is now null
    expect($instance->employee_document_id)->toBeNull()
        ->and($instance->employee_name_snapshot)->toBe($employee->name)
        ->and($instance->current_version_id)->toBe($instanceVersion->id);
});

test('re-running generation without explicit selection skips employees who already have an instance for that version', function () {
    $user = User::factory()->create();
    $company = createCustomGenTestCompany();

    $employee = Employee::factory()->forCompany($company)->create(['status' => 'active']);

    $template = DocumentGenerationTemplate::factory()->forCompany($company)->create([
        'status' => DocumentGenerationTemplateStatus::Active,
    ]);
    $version = DocumentGenerationTemplateVersion::factory()->forTemplate($template)->published()->create();
    $template->update(['published_version_id' => $version->id]);

    // Pre-create existing instance
    DocumentInstance::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'employee_name_snapshot' => $employee->name,
        'document_generation_template_id' => $template->id,
        'document_generation_template_version_id' => $version->id,
        'template_name_snapshot' => $template->name,
        'template_version_number' => $version->version,
        'title_snapshot' => $template->name,
        'status' => 'generated',
        'generated_at' => now(),
    ]);

    $run = DocumentGenerationRun::query()->create([
        'company_id' => $company->id,
        'document_generation_template_id' => $template->id,
        'document_generation_template_version_id' => $version->id,
        'status' => 'queued',
        'total_targeted' => 1,
        'correlation_id' => 'test-corr-id',
        'triggered_by' => $user->id,
    ]);

    $item = DocumentGenerationRunItem::query()->create([
        'company_id' => $company->id,
        'document_generation_run_id' => $run->id,
        'employee_id' => $employee->id,
        'status' => 'pending',
    ]);

    $mockRenderer = Mockery::mock(ContentTemplatePdfRenderer::class);
    $mockRenderer->shouldNotReceive('render');

    $job = new GenerateCustomDocumentsJob($company->id, $user->id, $run->id, false);
    $job->handle($mockRenderer, app(SyncGeneratedEmployeeDocument::class));

    $run->refresh();
    $item->refresh();

    expect($run->status)->toBe('completed')
        ->and($run->generated_count)->toBe(0)
        ->and($run->skipped_count)->toBe(1)
        ->and($item->status)->toBe('skipped');
});

test('template with generated instances cannot be deleted and returns validation exception', function () {
    $user = User::factory()->create();
    $company = createCustomGenTestCompany();
    grantCompanyPermissions($user, $company, ['documents.templates.delete']);

    $template = DocumentGenerationTemplate::factory()->forCompany($company)->create([
        'status' => DocumentGenerationTemplateStatus::Active,
    ]);
    $version = DocumentGenerationTemplateVersion::factory()->forTemplate($template)->published()->create();
    $template->update(['published_version_id' => $version->id]);

    DocumentInstance::query()->create([
        'company_id' => $company->id,
        'employee_name_snapshot' => 'Jane Smith',
        'document_generation_template_id' => $template->id,
        'document_generation_template_version_id' => $version->id,
        'template_name_snapshot' => $template->name,
        'template_version_number' => 1,
        'title_snapshot' => $template->name,
        'status' => 'generated',
        'generated_at' => now(),
    ]);

    $response = $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->delete(route('organization.documents.templates.destroy', $template));

    $response->assertSessionHasErrors(['template']);
    $this->assertDatabaseHas('document_generation_templates', ['id' => $template->id]);
});

test('template without generated instances can be deleted normally', function () {
    $user = User::factory()->create();
    $company = createCustomGenTestCompany();
    grantCompanyPermissions($user, $company, ['documents.templates.delete']);

    $template = DocumentGenerationTemplate::factory()->forCompany($company)->create([
        'status' => DocumentGenerationTemplateStatus::Active,
    ]);

    $response = $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->delete(route('organization.documents.templates.destroy', $template));

    $response->assertRedirect();
    $response->assertSessionHas('success', 'Template deleted.');
    $this->assertDatabaseMissing('document_generation_templates', ['id' => $template->id]);
});

test('activity is logged with privacy-safe properties and no PII leak', function () {
    $user = User::factory()->create();
    $company = createCustomGenTestCompany();

    $employee = Employee::factory()->forCompany($company)->create([
        'status' => 'active',
        'name' => 'Alice Wonderland',
    ]);

    $template = DocumentGenerationTemplate::factory()->forCompany($company)->create([
        'status' => DocumentGenerationTemplateStatus::Active,
        'name' => 'NDA Agreement',
    ]);
    $version = DocumentGenerationTemplateVersion::factory()->forTemplate($template)->published()->create([
        'version' => 1,
        'content' => 'Content for {{employee_name}}',
    ]);
    $template->update(['published_version_id' => $version->id]);

    $run = DocumentGenerationRun::query()->create([
        'company_id' => $company->id,
        'document_generation_template_id' => $template->id,
        'document_generation_template_version_id' => $version->id,
        'status' => 'queued',
        'total_targeted' => 1,
        'correlation_id' => 'corr-log-test',
        'triggered_by' => $user->id,
    ]);

    DocumentGenerationRunItem::query()->create([
        'company_id' => $company->id,
        'document_generation_run_id' => $run->id,
        'employee_id' => $employee->id,
        'status' => 'pending',
    ]);

    $mockRenderer = Mockery::mock(ContentTemplatePdfRenderer::class);
    $mockRenderer->shouldReceive('render')->andReturn(minimalPdfBytes());

    $job = new GenerateCustomDocumentsJob($company->id, $user->id, $run->id, false);
    $job->handle($mockRenderer, app(SyncGeneratedEmployeeDocument::class));

    $activity = Activity::where('log_name', 'document_generation')->latest('id')->first();
    expect($activity)->not->toBeNull()
        ->and($activity->company_id)->toBe($company->id)
        ->and($activity->causer_id)->toBe($user->id);

    $props = $activity->properties->toArray();
    expect($props)->toHaveKey('checksum')
        ->and($props)->toHaveKey('template_id')
        ->and($props)->toHaveKey('document_instance_id')
        ->and($props)->not->toHaveKey('salary')
        ->and($props)->not->toHaveKey('raw_pdf')
        ->and($props)->not->toHaveKey('placement_config');
});

test('employee document toShowArray includes provenance when generated from custom template', function () {
    $company = createCustomGenTestCompany();
    $employee = Employee::factory()->forCompany($company)->create(['status' => 'active']);
    $user = User::factory()->create(['name' => 'HR Admin']);

    $template = DocumentGenerationTemplate::factory()->forCompany($company)->create([
        'name' => 'Salary Confirmation',
    ]);
    $version = DocumentGenerationTemplateVersion::factory()->forTemplate($template)->published()->create([
        'version' => 2,
    ]);

    $libraryDoc = EmployeeDocument::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'type' => 'other',
        'document_type' => 'other',
        'title' => 'Salary Confirmation',
        'file_path' => "employee-documents/{$company->id}/{$employee->id}/doc.pdf",
        'original_filename' => 'doc.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 2048,
        'checksum' => 'checksum',
        'current_version' => 1,
        'status' => 'valid',
    ]);

    DocumentInstance::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'employee_name_snapshot' => $employee->name,
        'document_generation_template_id' => $template->id,
        'document_generation_template_version_id' => $version->id,
        'template_name_snapshot' => 'Salary Confirmation',
        'template_version_number' => 2,
        'title_snapshot' => 'Salary Confirmation',
        'status' => 'generated',
        'employee_document_id' => $libraryDoc->id,
        'generated_by' => $user->id,
        'generated_at' => now(),
    ]);

    $libraryDoc->load(['versions', 'documentInstance.generatedBy']);
    $showData = $libraryDoc->toShowArray();

    expect($showData)->toHaveKey('provenance')
        ->and($showData['provenance'])->not->toBeNull()
        ->and($showData['provenance']['source'])->toBe('Generated from company template')
        ->and($showData['provenance']['template_name'])->toBe('Salary Confirmation')
        ->and($showData['provenance']['template_version'])->toBe('v2')
        ->and($showData['provenance']['generated_by'])->toBe('HR Admin');
});

test('repeat generation with explicit selection creates a second instance and version without destroying the first', function () {
    $user = User::factory()->create();
    $company = createCustomGenTestCompany();
    $employee = Employee::factory()->forCompany($company)->create(['status' => 'active', 'name' => 'Bob Builder']);

    $template = DocumentGenerationTemplate::factory()->forCompany($company)->create([
        'status' => DocumentGenerationTemplateStatus::Active,
        'name' => 'Service Letter',
    ]);
    $version = DocumentGenerationTemplateVersion::factory()->forTemplate($template)->published()->create(['version' => 1]);
    $template->update(['published_version_id' => $version->id]);

    // Create 1st instance
    $instance1 = DocumentInstance::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'employee_name_snapshot' => $employee->name,
        'document_generation_template_id' => $template->id,
        'document_generation_template_version_id' => $version->id,
        'template_name_snapshot' => $template->name,
        'template_version_number' => 1,
        'title_snapshot' => $template->name,
        'status' => 'generated',
        'generated_at' => now()->subDay(),
    ]);

    $run = DocumentGenerationRun::query()->create([
        'company_id' => $company->id,
        'document_generation_template_id' => $template->id,
        'document_generation_template_version_id' => $version->id,
        'status' => 'queued',
        'total_targeted' => 1,
        'correlation_id' => 'repeat-corr-id',
        'triggered_by' => $user->id,
    ]);

    $item = DocumentGenerationRunItem::query()->create([
        'company_id' => $company->id,
        'document_generation_run_id' => $run->id,
        'employee_id' => $employee->id,
        'status' => 'pending',
    ]);

    $mockRenderer = Mockery::mock(ContentTemplatePdfRenderer::class);
    $mockRenderer->shouldReceive('render')->once()->andReturn(minimalPdfBytes());

    // replaceExisting = true simulates explicit selection
    $job = new GenerateCustomDocumentsJob($company->id, $user->id, $run->id, true);
    $job->handle($mockRenderer, app(SyncGeneratedEmployeeDocument::class));

    $item->refresh();
    expect($item->status)->toBe('completed')
        ->and($item->document_instance_id)->not->toBe($instance1->id);

    // Both instances exist
    expect(DocumentInstance::query()->where('employee_id', $employee->id)->count())->toBe(2);
    expect(DocumentInstance::query()->find($instance1->id))->not->toBeNull();
});

test('publishing v2 causes the roster missing filter to target employees even if they hold a v1 document', function () {
    $company = createCustomGenTestCompany();
    $employee = Employee::factory()->forCompany($company)->create(['status' => 'active', 'name' => 'Sam Smith']);

    $template = DocumentGenerationTemplate::factory()->forCompany($company)->create([
        'status' => DocumentGenerationTemplateStatus::Active,
        'name' => 'Policy Doc',
    ]);
    $version1 = DocumentGenerationTemplateVersion::factory()->forTemplate($template)->published()->create(['version' => 1]);
    $version2 = DocumentGenerationTemplateVersion::factory()->forTemplate($template)->published()->create(['version' => 2]);
    $template->update(['published_version_id' => $version2->id]);

    // Employee has v1 instance only
    DocumentInstance::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'employee_name_snapshot' => $employee->name,
        'document_generation_template_id' => $template->id,
        'document_generation_template_version_id' => $version1->id,
        'template_name_snapshot' => $template->name,
        'template_version_number' => 1,
        'title_snapshot' => $template->name,
        'status' => 'generated',
        'generated_at' => now(),
    ]);

    $filters = EmployeeDirectoryFilters::fromArray(['status' => 'active']);
    $counts = CustomDocumentRosterQuery::counts(
        $company->id,
        $template,
        $version2,
        $filters,
    );

    expect($counts['targeted'])->toBe(1)
        ->and($counts['generated'])->toBe(0)
        ->and($counts['not_generated'])->toBe(1);
});

test('file compensation cleans up written private files if database transaction fails', function () {
    $user = User::factory()->create();
    $company = createCustomGenTestCompany();
    $employee = Employee::factory()->forCompany($company)->create(['status' => 'active', 'name' => 'Crash Test']);

    $template = DocumentGenerationTemplate::factory()->forCompany($company)->create([
        'status' => DocumentGenerationTemplateStatus::Active,
    ]);
    $version = DocumentGenerationTemplateVersion::factory()->forTemplate($template)->published()->create();
    $template->update(['published_version_id' => $version->id]);

    $run = DocumentGenerationRun::query()->create([
        'company_id' => $company->id,
        'document_generation_template_id' => $template->id,
        'document_generation_template_version_id' => $version->id,
        'status' => 'queued',
        'total_targeted' => 1,
        'correlation_id' => 'crash-corr-id',
        'triggered_by' => $user->id,
    ]);

    $item = DocumentGenerationRunItem::query()->create([
        'company_id' => $company->id,
        'document_generation_run_id' => $run->id,
        'employee_id' => $employee->id,
        'status' => 'pending',
    ]);

    $mockRenderer = Mockery::mock(ContentTemplatePdfRenderer::class);
    $mockRenderer->shouldReceive('render')->andReturn(minimalPdfBytes());

    // Inject DB exception during instance creation
    DocumentInstance::creating(function () {
        throw new RuntimeException('Simulated DB crash');
    });

    $job = new GenerateCustomDocumentsJob($company->id, $user->id, $run->id, false);
    $job->handle($mockRenderer, app(SyncGeneratedEmployeeDocument::class));

    $item->refresh();
    expect($item->status)->toBe('failed')
        ->and($item->error_code)->toBe('GENERATION_FAILED');

    // Verify no orphaned files left in document-instances/
    $files = Storage::disk('local')->allFiles("document-instances/{$company->id}");
    expect($files)->toBeEmpty();
});

test('bulk documents index presents custom active templates with published versions in dropdown', function () {
    $user = User::factory()->create();
    $company = createCustomGenTestCompany();
    grantCompanyPermissions($user, $company, ['bulk_documents.view']);

    $template = DocumentGenerationTemplate::factory()->forCompany($company)->create([
        'status' => DocumentGenerationTemplateStatus::Active,
        'name' => 'Custom Company Letter',
    ]);
    $version = DocumentGenerationTemplateVersion::factory()->forTemplate($template)->published()->create(['version' => 1]);
    $template->update(['published_version_id' => $version->id]);

    $response = $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->get(route('organization.documents.bulk'));

    $response->assertOk();
    $options = $response->viewData('page')['props']['document_type_options'] ?? [];

    $customOption = collect($options)->firstWhere('value', "custom_{$template->id}");
    expect($customOption)->not->toBeNull()
        ->and($customOption['category'])->toBe('Company Templates')
        ->and($customOption['label'])->toBe('Custom Company Letter (v1)')
        ->and($customOption['is_custom'])->toBeTrue();
});

test('pdf overlay template generation is rejected with clear validation error', function () {
    $user = User::factory()->create();
    $company = createCustomGenTestCompany();
    grantCompanyPermissions($user, $company, ['bulk_documents.generate']);

    $template = DocumentGenerationTemplate::factory()->forCompany($company)->create([
        'status' => DocumentGenerationTemplateStatus::Active,
        'template_format' => DocumentGenerationTemplateFormat::PdfOverlay,
    ]);
    $version = DocumentGenerationTemplateVersion::factory()->forTemplate($template)->published()->create();
    $template->update(['published_version_id' => $version->id]);

    $response = $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('organization.documents.custom.generate'), [
            'document_generation_template_id' => $template->id,
        ]);

    $response->assertSessionHasErrors([
        'document_generation_template_id' => 'PDF Overlay production generation is not available yet.',
    ]);
});

test('pdf overlay template is excluded from bulk documents dropdown', function () {
    $user = User::factory()->create();
    $company = createCustomGenTestCompany();
    grantCompanyPermissions($user, $company, ['bulk_documents.view']);

    $template = DocumentGenerationTemplate::factory()->forCompany($company)->create([
        'status' => DocumentGenerationTemplateStatus::Active,
        'template_format' => DocumentGenerationTemplateFormat::PdfOverlay,
        'name' => 'Overlay Contract',
    ]);
    $version = DocumentGenerationTemplateVersion::factory()->forTemplate($template)->published()->create(['version' => 1]);
    $template->update(['published_version_id' => $version->id]);

    $response = $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->get(route('organization.documents.bulk'));

    $response->assertOk();
    $options = $response->viewData('page')['props']['document_type_options'] ?? [];

    $customOption = collect($options)->firstWhere('value', "custom_{$template->id}");
    expect($customOption)->toBeNull();
});

test('run snapshots template version and does not switch if template v2 is published while job is running', function () {
    $user = User::factory()->create();
    $company = createCustomGenTestCompany();
    $employee = Employee::factory()->forCompany($company)->create(['status' => 'active', 'name' => 'Version Test Emp']);

    $template = DocumentGenerationTemplate::factory()->forCompany($company)->create([
        'status' => DocumentGenerationTemplateStatus::Active,
        'name' => 'Version Freeze Test',
    ]);
    $version1 = DocumentGenerationTemplateVersion::factory()->forTemplate($template)->published()->create([
        'version' => 1,
        'content' => 'Content Version 1 for {{employee_name}}',
    ]);
    $template->update(['published_version_id' => $version1->id]);

    // Create run frozen to version 1
    $run = DocumentGenerationRun::query()->create([
        'company_id' => $company->id,
        'document_generation_template_id' => $template->id,
        'document_generation_template_version_id' => $version1->id,
        'status' => 'queued',
        'total_targeted' => 1,
        'correlation_id' => 'v1-freeze-corr',
        'triggered_by' => $user->id,
    ]);

    $item = DocumentGenerationRunItem::query()->create([
        'company_id' => $company->id,
        'document_generation_run_id' => $run->id,
        'employee_id' => $employee->id,
        'status' => 'pending',
    ]);

    // Now publish version 2 before job executes!
    $version2 = DocumentGenerationTemplateVersion::factory()->forTemplate($template)->published()->create([
        'version' => 2,
        'content' => 'Content Version 2 for {{employee_name}}',
    ]);
    $template->update(['published_version_id' => $version2->id]);

    $mockRenderer = Mockery::mock(ContentTemplatePdfRenderer::class);
    $mockRenderer->shouldReceive('render')
        ->once()
        ->with(
            Mockery::type(DocumentGenerationTemplate::class),
            Mockery::on(fn ($v) => $v->id === $version1->id && $v->version === 1),
            Mockery::type(Employee::class),
            $company->id,
        )
        ->andReturn(minimalPdfBytes());

    $job = new GenerateCustomDocumentsJob($company->id, $user->id, $run->id, false);
    $job->handle($mockRenderer, app(SyncGeneratedEmployeeDocument::class));

    $item->refresh();
    $instance = DocumentInstance::query()->find($item->document_instance_id);

    expect($instance)->not->toBeNull()
        ->and($instance->document_generation_template_version_id)->toBe($version1->id)
        ->and($instance->template_version_number)->toBe(1);
});

test('duplicate job invocation does not double generate or corrupt run counts', function () {
    $user = User::factory()->create();
    $company = createCustomGenTestCompany();
    $employee = Employee::factory()->forCompany($company)->create(['status' => 'active', 'name' => 'Idempotent Emp']);

    $template = DocumentGenerationTemplate::factory()->forCompany($company)->create([
        'status' => DocumentGenerationTemplateStatus::Active,
        'name' => 'Idempotent Letter',
    ]);
    $version = DocumentGenerationTemplateVersion::factory()->forTemplate($template)->published()->create(['version' => 1]);
    $template->update(['published_version_id' => $version->id]);

    $run = DocumentGenerationRun::query()->create([
        'company_id' => $company->id,
        'document_generation_template_id' => $template->id,
        'document_generation_template_version_id' => $version->id,
        'status' => 'queued',
        'total_targeted' => 1,
        'correlation_id' => 'idempotent-corr',
        'triggered_by' => $user->id,
    ]);

    DocumentGenerationRunItem::query()->create([
        'company_id' => $company->id,
        'document_generation_run_id' => $run->id,
        'employee_id' => $employee->id,
        'status' => 'pending',
    ]);

    $mockRenderer = Mockery::mock(ContentTemplatePdfRenderer::class);
    // Even if job is executed twice, renderer should only be called ONCE because second run sees item already claimed/completed
    $mockRenderer->shouldReceive('render')->once()->andReturn(minimalPdfBytes());

    // Worker 1 runs
    $job1 = new GenerateCustomDocumentsJob($company->id, $user->id, $run->id, false);
    $job1->handle($mockRenderer, app(SyncGeneratedEmployeeDocument::class));

    $run->refresh();
    expect($run->generated_count)->toBe(1)
        ->and(DocumentInstance::query()->where('employee_id', $employee->id)->count())->toBe(1);

    // Worker 2 runs same job
    $job2 = new GenerateCustomDocumentsJob($company->id, $user->id, $run->id, false);
    $job2->handle($mockRenderer, app(SyncGeneratedEmployeeDocument::class));

    $run->refresh();
    expect($run->generated_count)->toBe(1)
        ->and(DocumentInstance::query()->where('employee_id', $employee->id)->count())->toBe(1);
});

test('document instance version cannot be deleted through model operations', function () {
    $company = createCustomGenTestCompany();
    $instance = DocumentInstance::factory()->forCompany($company)->create();
    $version = DocumentInstanceVersion::factory()->forCompany($company)->forInstance($instance)->create([
        'version' => 1,
    ]);

    expect(fn () => $version->delete())
        ->toThrow(DomainException::class, 'Cannot delete an immutable document instance version.');
});

test('custom template without document_type_id succeeds without creating new DocumentType', function () {
    $user = User::factory()->create();
    $company = createCustomGenTestCompany();
    $employee = Employee::factory()->forCompany($company)->create(['status' => 'active']);

    $template = DocumentGenerationTemplate::factory()->forCompany($company)->create([
        'status' => DocumentGenerationTemplateStatus::Active,
        'document_type_id' => null,
        'name' => 'Custom One-Off Memo',
    ]);
    $version = DocumentGenerationTemplateVersion::factory()->forTemplate($template)->published()->create(['version' => 1]);
    $template->update(['published_version_id' => $version->id]);

    $docTypeCountBefore = DocumentType::query()->count();

    $run = DocumentGenerationRun::query()->create([
        'company_id' => $company->id,
        'document_generation_template_id' => $template->id,
        'document_generation_template_version_id' => $version->id,
        'status' => 'queued',
        'total_targeted' => 1,
        'correlation_id' => 'no-doctype-corr',
        'triggered_by' => $user->id,
    ]);

    $item = DocumentGenerationRunItem::query()->create([
        'company_id' => $company->id,
        'document_generation_run_id' => $run->id,
        'employee_id' => $employee->id,
        'status' => 'pending',
    ]);

    $mockRenderer = Mockery::mock(ContentTemplatePdfRenderer::class);
    $mockRenderer->shouldReceive('render')->once()->andReturn(minimalPdfBytes());

    $job = new GenerateCustomDocumentsJob($company->id, $user->id, $run->id, false);
    $job->handle($mockRenderer, app(SyncGeneratedEmployeeDocument::class));

    $item->refresh();
    expect($item->status)->toBe('completed');

    // Confirm NO new DocumentType was created in master data!
    expect(DocumentType::query()->count())->toBe($docTypeCountBefore);

    $libraryDoc = EmployeeDocument::query()->find(DocumentInstance::query()->find($item->document_instance_id)->employee_document_id);
    expect($libraryDoc->document_type_id)->toBeNull()
        ->and($libraryDoc->type)->toBe('other');
});

test('job rejects cross-company template or employee', function () {
    $user = User::factory()->create();
    $companyA = createCustomGenTestCompany('Company A');
    $companyB = createCustomGenTestCompany('Company B');

    $employeeB = Employee::factory()->forCompany($companyB)->create(['status' => 'active']);

    $templateA = DocumentGenerationTemplate::factory()->forCompany($companyA)->create([
        'status' => DocumentGenerationTemplateStatus::Active,
    ]);
    $versionA = DocumentGenerationTemplateVersion::factory()->forTemplate($templateA)->published()->create();
    $templateA->update(['published_version_id' => $versionA->id]);

    // Run created for Company A, but item references Employee B
    $run = DocumentGenerationRun::query()->create([
        'company_id' => $companyA->id,
        'document_generation_template_id' => $templateA->id,
        'document_generation_template_version_id' => $versionA->id,
        'status' => 'queued',
        'total_targeted' => 1,
        'correlation_id' => 'cross-comp-corr',
        'triggered_by' => $user->id,
    ]);

    $item = DocumentGenerationRunItem::query()->create([
        'company_id' => $companyA->id,
        'document_generation_run_id' => $run->id,
        'employee_id' => $employeeB->id,
        'status' => 'pending',
    ]);

    $mockRenderer = Mockery::mock(ContentTemplatePdfRenderer::class);
    $mockRenderer->shouldNotReceive('render');

    $job = new GenerateCustomDocumentsJob($companyA->id, $user->id, $run->id, false);
    $job->handle($mockRenderer, app(SyncGeneratedEmployeeDocument::class));

    $item->refresh();
    expect($item->status)->toBe('failed')
        ->and($item->error_code)->toBe('EMPLOYEE_NOT_FOUND');

    // No document instance created
    expect(DocumentInstance::query()->count())->toBe(0);
});

test('subsequent employee or template renames do not modify existing instance snapshots', function () {
    $user = User::factory()->create();
    $company = createCustomGenTestCompany();
    $employee = Employee::factory()->forCompany($company)->create([
        'status' => 'active',
        'name' => 'Original Employee Name',
        'employee_no' => 'EMP-ORIG',
    ]);

    $template = DocumentGenerationTemplate::factory()->forCompany($company)->create([
        'status' => DocumentGenerationTemplateStatus::Active,
        'name' => 'Original Template Name',
    ]);
    $version = DocumentGenerationTemplateVersion::factory()->forTemplate($template)->published()->create(['version' => 1]);
    $template->update(['published_version_id' => $version->id]);

    $run = DocumentGenerationRun::query()->create([
        'company_id' => $company->id,
        'document_generation_template_id' => $template->id,
        'document_generation_template_version_id' => $version->id,
        'status' => 'queued',
        'total_targeted' => 1,
        'correlation_id' => 'snapshot-corr',
        'triggered_by' => $user->id,
    ]);

    $item = DocumentGenerationRunItem::query()->create([
        'company_id' => $company->id,
        'document_generation_run_id' => $run->id,
        'employee_id' => $employee->id,
        'status' => 'pending',
    ]);

    $mockRenderer = Mockery::mock(ContentTemplatePdfRenderer::class);
    $mockRenderer->shouldReceive('render')->once()->andReturn(minimalPdfBytes());

    $job = new GenerateCustomDocumentsJob($company->id, $user->id, $run->id, false);
    $job->handle($mockRenderer, app(SyncGeneratedEmployeeDocument::class));

    $item->refresh();
    $instance = DocumentInstance::query()->find($item->document_instance_id);

    // Verify initial snapshot
    expect($instance->employee_name_snapshot)->toBe('Original Employee Name')
        ->and($instance->employee_no_snapshot)->toBe('EMP-ORIG')
        ->and($instance->template_name_snapshot)->toBe('Original Template Name');

    // Mutate source employee and template
    $employee->update(['name' => 'Renamed Employee', 'employee_no' => 'EMP-NEW']);
    $template->update(['name' => 'Renamed Template']);

    // Refresh instance from DB: snapshot remains completely intact!
    $instance->refresh();
    expect($instance->employee_name_snapshot)->toBe('Original Employee Name')
        ->and($instance->employee_no_snapshot)->toBe('EMP-ORIG')
        ->and($instance->template_name_snapshot)->toBe('Original Template Name');
});
