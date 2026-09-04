<?php

use App\Enums\DocumentGenerationTemplateFormat;
use App\Enums\DocumentGenerationTemplateStatus;
use App\Enums\DocumentTemplateAutomationMode;
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
use App\Notifications\DocumentGenerationFinishedWebPushNotification;
use App\Services\Documents\CustomTemplatePdfRenderer;
use App\Support\BulkDocuments\CustomDocumentRosterQuery;
use App\Support\BulkDocuments\DocumentGenerationItemErrorPresenter;
use App\Support\Documents\Actions\PublishDocumentGenerationTemplateVersion;
use App\Support\Documents\Actions\ReplaceDocumentGenerationTemplatePdf;
use App\Support\Documents\Actions\SyncGeneratedEmployeeDocument;
use App\Support\Documents\Exceptions\DocumentTemplateLayoutException;
use App\Support\EmployeeDocuments\DocumentDeletionService;
use App\Support\Employees\EmployeeDirectoryFilters;
use Database\Seeders\PermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use setasign\Fpdi\Fpdi;
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
    $mockRenderer = Mockery::mock(CustomTemplatePdfRenderer::class);
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

    $libraryPath = "employee-documents/{$company->id}/{$employee->id}/library.pdf";
    Storage::disk('local')->put($libraryPath, minimalPdfBytes());

    $libraryDoc = EmployeeDocument::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'type' => 'other',
        'document_type' => 'other',
        'title' => $template->name,
        'file_path' => $libraryPath,
        'original_filename' => 'library.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 100,
        'checksum' => 'checksum',
        'current_version' => 1,
        'status' => 'valid',
    ]);

    // Pre-create existing instance with a live library PDF
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
        'employee_document_id' => $libraryDoc->id,
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

    $mockRenderer = Mockery::mock(CustomTemplatePdfRenderer::class);
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

test('generate missing after library delete targets the employee again', function () {
    Queue::fake();

    $user = User::factory()->create();
    $company = createCustomGenTestCompany();
    grantCompanyPermissions($user, $company, ['bulk_documents.generate']);

    $employee = Employee::factory()->forCompany($company)->create(['status' => 'active']);
    $template = DocumentGenerationTemplate::factory()->forCompany($company)->create([
        'status' => DocumentGenerationTemplateStatus::Active,
        'name' => 'Offer letter',
    ]);
    $version = DocumentGenerationTemplateVersion::factory()->forTemplate($template)->published()->create();
    $template->update(['published_version_id' => $version->id]);

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
        'employee_document_id' => null,
        'generated_at' => now(),
    ]);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('organization.documents.custom.generate'), [
            'document_generation_template_id' => $template->id,
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    $run = DocumentGenerationRun::query()->where('company_id', $company->id)->first();
    expect($run)->not->toBeNull()
        ->and($run->total_targeted)->toBe(1);

    expect(DocumentGenerationRunItem::query()->where('document_generation_run_id', $run->id)->pluck('employee_id')->all())
        ->toBe([$employee->id]);
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
    $version = DocumentGenerationTemplateVersion::factory()->forTemplate($template)->published()->create();
    $template->update(['published_version_id' => $version->id]);

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

    $mockRenderer = Mockery::mock(CustomTemplatePdfRenderer::class);
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

    $mockRenderer = Mockery::mock(CustomTemplatePdfRenderer::class);
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

test('file compensation cleans up written private files and leaves no db records if database transaction fails', function () {
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

    $mockRenderer = Mockery::mock(CustomTemplatePdfRenderer::class);
    $mockRenderer->shouldReceive('render')->andReturn(minimalPdfBytes());

    // Inject DB exception during instance creation (after files were stored)
    DocumentInstance::creating(function () {
        throw new RuntimeException('Simulated DB crash');
    });

    $job = new GenerateCustomDocumentsJob($company->id, $user->id, $run->id, false);
    $job->handle($mockRenderer, app(SyncGeneratedEmployeeDocument::class));

    $item->refresh();
    expect($item->status)->toBe('failed')
        ->and($item->error_code)->toBe('GENERATION_FAILED');

    // Verify DB rows: no partial instance, version, or library records
    expect(DocumentInstance::query()->count())->toBe(0)
        ->and(DocumentInstanceVersion::query()->count())->toBe(0)
        ->and(EmployeeDocument::query()->count())->toBe(0);

    // Verify both canonical and library files are cleaned up from storage
    $canonicalFiles = Storage::disk('local')->allFiles("document-instances/{$company->id}");
    expect($canonicalFiles)->toBeEmpty();

    $libraryFiles = Storage::disk('local')->allFiles("employee-documents/{$company->id}");
    expect($libraryFiles)->toBeEmpty();

    // Verify no successful generation audit log was recorded
    $generationAuditCount = Activity::query()
        ->where('log_name', 'document_generation')
        ->where('properties->action', 'document_instance_generated')
        ->count();
    expect($generationAuditCount)->toBe(0);
});

test('failure when creating employee document record cleans up stored library and canonical files', function () {
    $user = User::factory()->create();
    $company = createCustomGenTestCompany();
    $employee = Employee::factory()->forCompany($company)->create(['status' => 'active', 'name' => 'Library Fail Test']);

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
        'correlation_id' => 'lib-fail-corr-id',
        'triggered_by' => $user->id,
    ]);

    $item = DocumentGenerationRunItem::query()->create([
        'company_id' => $company->id,
        'document_generation_run_id' => $run->id,
        'employee_id' => $employee->id,
        'status' => 'pending',
    ]);

    $mockRenderer = Mockery::mock(CustomTemplatePdfRenderer::class);
    $mockRenderer->shouldReceive('render')->andReturn(minimalPdfBytes());

    // Inject DB exception during EmployeeDocument creation
    EmployeeDocument::creating(function () {
        throw new RuntimeException('Simulated EmployeeDocument DB crash');
    });

    $job = new GenerateCustomDocumentsJob($company->id, $user->id, $run->id, false);
    $job->handle($mockRenderer, app(SyncGeneratedEmployeeDocument::class));

    $item->refresh();
    expect($item->status)->toBe('failed')
        ->and($item->error_code)->toBe('GENERATION_FAILED');

    expect(EmployeeDocument::query()->count())->toBe(0)
        ->and(DocumentInstance::query()->count())->toBe(0);

    // Both files cleaned up
    expect(Storage::disk('local')->allFiles("document-instances/{$company->id}"))->toBeEmpty()
        ->and(Storage::disk('local')->allFiles("employee-documents/{$company->id}"))->toBeEmpty();
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
        ->get(route('organization.documents.generate'));

    $response->assertOk();
    $options = $response->viewData('page')['props']['document_type_options'] ?? [];

    $customOption = collect($options)->firstWhere('value', "custom_{$template->id}");
    expect($customOption)->not->toBeNull()
        ->and($customOption['category'])->toBe('Company Templates')
        ->and($customOption['label'])->toBe('Custom Company Letter (v1)')
        ->and($customOption['is_custom'])->toBeTrue();
});

test('pdf overlay template generation is rejected when source pdf is not configured', function () {
    $user = User::factory()->create();
    $company = createCustomGenTestCompany();
    grantCompanyPermissions($user, $company, ['bulk_documents.generate']);

    $template = DocumentGenerationTemplate::factory()->forCompany($company)->create([
        'status' => DocumentGenerationTemplateStatus::Active,
        'template_format' => DocumentGenerationTemplateFormat::PdfOverlay,
    ]);
    // Factory creates version with source_pdf_path = null and source_pdf_page_count = null
    $version = DocumentGenerationTemplateVersion::factory()->forTemplate($template)->published()->create();
    $template->update(['published_version_id' => $version->id]);

    $response = $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('organization.documents.custom.generate'), [
            'document_generation_template_id' => $template->id,
        ]);

    $response->assertSessionHasErrors([
        'document_generation_template_id' => 'This PDF Overlay template has no configured source PDF. Please publish a version with a valid source PDF first.',
    ]);
});

test('pdf overlay template appears in bulk documents dropdown', function () {
    $user = User::factory()->create();
    $company = createCustomGenTestCompany();
    grantCompanyPermissions($user, $company, ['bulk_documents.view']);

    $template = DocumentGenerationTemplate::factory()->forCompany($company)->create([
        'status' => DocumentGenerationTemplateStatus::Active,
        'template_format' => DocumentGenerationTemplateFormat::PdfOverlay,
        'name' => 'Overlay Contract',
    ]);
    $sourcePath = "document-generation-templates/{$company->id}/overlay-source.pdf";
    Storage::disk('local')->put($sourcePath, '%PDF-1.4 overlay-source');
    $version = DocumentGenerationTemplateVersion::factory()->forTemplate($template)->published()->create([
        'version' => 1,
        'source_pdf_path' => $sourcePath,
        'source_pdf_page_count' => 1,
        'placement_config' => ['schema_version' => 1, 'placements' => []],
    ]);
    $template->update(['published_version_id' => $version->id]);

    $response = $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->get(route('organization.documents.generate'));

    $response->assertOk();
    $options = $response->viewData('page')['props']['document_type_options'] ?? [];

    $customOption = collect($options)->firstWhere('value', "custom_{$template->id}");
    expect($customOption)->not->toBeNull()
        ->and($customOption['template_format'])->toBe('pdf_overlay')
        ->and($customOption['is_custom'])->toBeTrue();
});

test('pdf overlay template without a source pdf is excluded from bulk documents dropdown', function () {
    $user = User::factory()->create();
    $company = createCustomGenTestCompany();
    grantCompanyPermissions($user, $company, ['bulk_documents.view']);

    $template = DocumentGenerationTemplate::factory()->forCompany($company)->create([
        'status' => DocumentGenerationTemplateStatus::Active,
        'template_format' => DocumentGenerationTemplateFormat::PdfOverlay,
        'name' => 'Broken Overlay Contract',
    ]);
    $version = DocumentGenerationTemplateVersion::factory()->forTemplate($template)->published()->create([
        'version' => 1,
        'source_pdf_path' => null,
        'source_pdf_page_count' => null,
    ]);
    $template->update(['published_version_id' => $version->id]);

    $response = $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->get(route('organization.documents.generate'));

    $response->assertOk();
    $options = $response->viewData('page')['props']['document_type_options'] ?? [];

    expect(collect($options)->firstWhere('value', "custom_{$template->id}"))->toBeNull();
});

test('pdf overlay template with a valid source pdf can be queued for generation', function () {
    Queue::fake();

    $user = User::factory()->create();
    $company = createCustomGenTestCompany();
    grantCompanyPermissions($user, $company, ['bulk_documents.generate']);
    Employee::factory()->forCompany($company)->create(['status' => 'active']);

    $sourcePath = "document-generation-templates/{$company->id}/overlay-source.pdf";
    Storage::disk('local')->put($sourcePath, '%PDF-1.4 overlay-source');

    $template = DocumentGenerationTemplate::factory()->forCompany($company)->create([
        'status' => DocumentGenerationTemplateStatus::Active,
        'template_format' => DocumentGenerationTemplateFormat::PdfOverlay,
        'name' => 'Overlay Offer Letter',
    ]);
    $version = DocumentGenerationTemplateVersion::factory()->forTemplate($template)->published()->create([
        'version' => 1,
        'source_pdf_path' => $sourcePath,
        'source_pdf_page_count' => 1,
        'placement_config' => ['schema_version' => 1, 'placements' => []],
    ]);
    $template->update(['published_version_id' => $version->id]);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('organization.documents.custom.generate'), [
            'document_generation_template_id' => $template->id,
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    $run = DocumentGenerationRun::query()->where('company_id', $company->id)->first();
    expect($run)->not->toBeNull()
        ->and($run->document_generation_template_version_id)->toBe($version->id);

    Queue::assertPushed(GenerateCustomDocumentsJob::class);
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

    // Create draft version 2 and publish it using the REAL Publish action!
    $version2 = DocumentGenerationTemplateVersion::factory()->forTemplate($template)->draft()->create([
        'version' => 2,
        'content' => 'Content Version 2 for {{employee_name}}',
    ]);

    app(PublishDocumentGenerationTemplateVersion::class)->handle($version2, $user->id);

    $version1->refresh();
    $version2->refresh();
    $template->refresh();

    // Verify real lifecycle state: v1 is Archived, v2 is Published
    expect($version1->status->value)->toBe('archived')
        ->and($version2->status->value)->toBe('published')
        ->and($template->published_version_id)->toBe($version2->id);

    $mockRenderer = Mockery::mock(CustomTemplatePdfRenderer::class);
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

    $mockRenderer = Mockery::mock(CustomTemplatePdfRenderer::class);
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

    $mockRenderer = Mockery::mock(CustomTemplatePdfRenderer::class);
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

    $mockRenderer = Mockery::mock(CustomTemplatePdfRenderer::class);
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

    $mockRenderer = Mockery::mock(CustomTemplatePdfRenderer::class);
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

test('run pointing to a draft template version fails safely and produces no output', function () {
    $user = User::factory()->create();
    $company = createCustomGenTestCompany();
    $employee = Employee::factory()->forCompany($company)->create(['status' => 'active', 'name' => 'Draft Emp']);

    $template = DocumentGenerationTemplate::factory()->forCompany($company)->create([
        'status' => DocumentGenerationTemplateStatus::Active,
        'name' => 'Draft Version Template',
    ]);
    $draftVersion = DocumentGenerationTemplateVersion::factory()->forTemplate($template)->draft()->create(['version' => 1]);

    $run = DocumentGenerationRun::query()->create([
        'company_id' => $company->id,
        'document_generation_template_id' => $template->id,
        'document_generation_template_version_id' => $draftVersion->id,
        'status' => 'queued',
        'total_targeted' => 1,
        'correlation_id' => 'draft-ver-run',
        'triggered_by' => $user->id,
    ]);

    $item = DocumentGenerationRunItem::query()->create([
        'company_id' => $company->id,
        'document_generation_run_id' => $run->id,
        'employee_id' => $employee->id,
        'status' => 'pending',
    ]);

    $mockRenderer = Mockery::mock(CustomTemplatePdfRenderer::class);
    $mockRenderer->shouldNotReceive('render');

    $job = new GenerateCustomDocumentsJob($company->id, $user->id, $run->id, false);
    $job->handle($mockRenderer, app(SyncGeneratedEmployeeDocument::class));

    $run->refresh();
    $item->refresh();

    expect($run->status)->toBe('failed')
        ->and($item->status)->toBe('pending')
        ->and(DocumentInstance::query()->count())->toBe(0)
        ->and(DocumentInstanceVersion::query()->count())->toBe(0)
        ->and(EmployeeDocument::query()->count())->toBe(0);
});

test('document instance immutable identity attributes cannot be mutated', function () {
    $company = createCustomGenTestCompany();
    $employee = Employee::factory()->forCompany($company)->create(['status' => 'active']);
    $template = DocumentGenerationTemplate::factory()->forCompany($company)->create(['status' => DocumentGenerationTemplateStatus::Active]);
    $version = DocumentGenerationTemplateVersion::factory()->forTemplate($template)->published()->create();

    $instance = DocumentInstance::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'employee_name_snapshot' => 'Original Name',
        'employee_no_snapshot' => 'EMP-001',
        'document_generation_template_id' => $template->id,
        'document_generation_template_version_id' => $version->id,
        'template_name_snapshot' => 'Original Template',
        'template_version_number' => 1,
        'title_snapshot' => 'Original Title',
        'status' => 'generated',
        'generated_at' => now(),
    ]);

    expect(fn () => $instance->update(['employee_name_snapshot' => 'Mutated Name']))
        ->toThrow(DomainException::class, "Cannot modify immutable attribute 'employee_name_snapshot'");
    $instance->refresh();

    expect(fn () => $instance->update(['document_generation_template_version_id' => 999]))
        ->toThrow(DomainException::class, "Cannot modify immutable attribute 'document_generation_template_version_id'");
    $instance->refresh();

    expect(fn () => $instance->update(['company_id' => 999]))
        ->toThrow(DomainException::class, "Cannot modify immutable attribute 'company_id'");
    $instance->refresh();

    expect(fn () => $instance->update(['template_version_number' => 2]))
        ->toThrow(DomainException::class, "Cannot modify immutable attribute 'template_version_number'");
    $instance->refresh();

    expect(fn () => $instance->update(['generated_at' => now()->addDay()]))
        ->toThrow(DomainException::class, "Cannot modify immutable attribute 'generated_at'");
    $instance->refresh();

    // Mutable lifecycle fields are permitted
    $instance->update(['status' => 'archived']);
    expect($instance->fresh()->status)->toBe('archived');
});

test('document instance cannot be deleted via eloquent', function () {
    $company = createCustomGenTestCompany();
    $employee = Employee::factory()->forCompany($company)->create(['status' => 'active']);
    $template = DocumentGenerationTemplate::factory()->forCompany($company)->create(['status' => DocumentGenerationTemplateStatus::Active]);
    $version = DocumentGenerationTemplateVersion::factory()->forTemplate($template)->published()->create();

    $instance = DocumentInstance::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'employee_name_snapshot' => 'Protected Name',
        'employee_no_snapshot' => 'EMP-001',
        'document_generation_template_id' => $template->id,
        'document_generation_template_version_id' => $version->id,
        'template_name_snapshot' => 'Protected Template',
        'template_version_number' => 1,
        'title_snapshot' => 'Protected Title',
        'status' => 'generated',
        'generated_at' => now(),
    ]);

    expect(fn () => $instance->delete())
        ->toThrow(DomainException::class, 'Cannot delete an immutable document instance.');
});

test('cross-company explicit employee id in generation request is rejected with validation error', function () {
    $user = User::factory()->create();
    $companyA = createCustomGenTestCompany('Company A');
    $companyB = createCustomGenTestCompany('Company B');

    grantCompanyPermissions($user, $companyA, ['bulk_documents.generate']);

    $employeeA = Employee::factory()->forCompany($companyA)->create(['status' => 'active']);
    $employeeB = Employee::factory()->forCompany($companyB)->create(['status' => 'active']);

    $templateA = DocumentGenerationTemplate::factory()->forCompany($companyA)->create([
        'status' => DocumentGenerationTemplateStatus::Active,
    ]);
    $versionA = DocumentGenerationTemplateVersion::factory()->forTemplate($templateA)->published()->create();
    $templateA->update(['published_version_id' => $versionA->id]);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $companyA->id])
        ->post(route('organization.documents.custom.generate'), [
            'document_generation_template_id' => $templateA->id,
            'employee_ids' => [$employeeA->id, $employeeB->id],
        ])
        ->assertSessionHasErrors(['employee_ids.1']);

    expect(DocumentGenerationRun::query()->count())->toBe(0)
        ->and(DocumentGenerationRunItem::query()->count())->toBe(0);
});

test('unknown explicit employee id in generation request is rejected with validation error', function () {
    $user = User::factory()->create();
    $company = createCustomGenTestCompany();

    grantCompanyPermissions($user, $company, ['bulk_documents.generate']);

    $template = DocumentGenerationTemplate::factory()->forCompany($company)->create([
        'status' => DocumentGenerationTemplateStatus::Active,
    ]);
    $version = DocumentGenerationTemplateVersion::factory()->forTemplate($template)->published()->create();
    $template->update(['published_version_id' => $version->id]);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('organization.documents.custom.generate'), [
            'document_generation_template_id' => $template->id,
            'employee_ids' => [999999],
        ])
        ->assertSessionHasErrors(['employee_ids.0']);

    expect(DocumentGenerationRun::query()->count())->toBe(0);
});

test('queue dispatch failure marks generation run as failed safely without crashing', function () {
    $user = User::factory()->create();
    $company = createCustomGenTestCompany();
    grantCompanyPermissions($user, $company, ['bulk_documents.generate']);

    $employee = Employee::factory()->forCompany($company)->create(['status' => 'active']);
    $template = DocumentGenerationTemplate::factory()->forCompany($company)->create([
        'status' => DocumentGenerationTemplateStatus::Active,
    ]);
    $version = DocumentGenerationTemplateVersion::factory()->forTemplate($template)->published()->create();
    $template->update(['published_version_id' => $version->id]);

    Queue::shouldReceive('connection')->andThrow(new RuntimeException('Queue connection down'));

    $response = $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('organization.documents.custom.generate'), [
            'document_generation_template_id' => $template->id,
            'employee_ids' => [$employee->id],
        ]);

    $response->assertSessionHasErrors(['document_generation_template_id']);

    $run = DocumentGenerationRun::query()->first();
    expect($run)->not->toBeNull()
        ->and($run->status)->toBe('failed')
        ->and($run->finished_at)->not->toBeNull();
});

test('concurrent non-repeat generation runs for same employee deduplicate inside transaction and clean up files', function () {
    Storage::fake('local');
    $user = User::factory()->create();
    $company = createCustomGenTestCompany();
    $employee = Employee::factory()->forCompany($company)->create(['status' => 'active']);
    $template = DocumentGenerationTemplate::factory()->forCompany($company)->create([
        'status' => DocumentGenerationTemplateStatus::Active,
        'template_format' => DocumentGenerationTemplateFormat::Content,
    ]);
    $version = DocumentGenerationTemplateVersion::factory()->forTemplate($template)->published()->create();
    $template->update(['published_version_id' => $version->id]);

    // Create Run B targeting the employee
    $runB = DocumentGenerationRun::query()->create([
        'company_id' => $company->id,
        'document_generation_template_id' => $template->id,
        'document_generation_template_version_id' => $version->id,
        'status' => 'queued',
        'total_targeted' => 1,
        'correlation_id' => (string) Str::uuid(),
        'triggered_by' => $user->id,
    ]);
    $itemB = DocumentGenerationRunItem::query()->create([
        'company_id' => $company->id,
        'document_generation_run_id' => $runB->id,
        'employee_id' => $employee->id,
        'status' => 'pending',
    ]);

    // Assert that before Worker B runs, NO DocumentInstance exists.
    // This proves Worker B will pass the initial early exists() check.
    expect(DocumentInstance::query()->count())->toBe(0);

    $winningCanonicalPath = null;
    $winningLibraryDoc = null;

    $mockRenderer = Mockery::mock(CustomTemplatePdfRenderer::class);
    $mockRenderer->shouldReceive('render')
        ->once()
        ->andReturnUsing(function ($tmpl, $vers, $emp, $comp) use (
            $company,
            $user,
            $employee,
            $template,
            $version,
            &$winningCanonicalPath,
            &$winningLibraryDoc,
        ) {
            // At this exact point in execution:
            // Worker B has already passed the early DocumentInstance::exists() check!
            // Simulate competing Worker A completing generation right before Worker B reaches the locked DB transaction:

            // 1. Worker A stores winning canonical file
            $winningCanonicalPath = "document-instances/{$company->id}/winning_".Str::uuid().'.pdf';
            Storage::disk('local')->put($winningCanonicalPath, '%PDF-1.4 Worker A Canonical File');

            // 2. Worker A stores winning library file
            $winningLibraryPath = "employee-documents/{$company->id}/{$employee->id}/winning_".Str::uuid().'.pdf';
            Storage::disk('local')->put($winningLibraryPath, '%PDF-1.4 Worker A Library File');

            // 3. Worker A creates winning EmployeeDocument
            $winningLibraryDoc = EmployeeDocument::query()->create([
                'company_id' => $company->id,
                'employee_id' => $employee->id,
                'type' => 'other',
                'document_type' => 'other',
                'title' => $template->name,
                'file_path' => $winningLibraryPath,
                'original_filename' => Str::slug($template->name).'.pdf',
                'mime_type' => 'application/pdf',
                'size_bytes' => strlen('%PDF-1.4 Worker A Library File'),
                'checksum' => hash('sha256', '%PDF-1.4 Worker A Library File'),
                'current_version' => 1,
                'status' => 'valid',
                'uploaded_by' => $user->id,
            ]);

            // 4. Worker A creates winning DocumentInstance
            $winningInstance = DocumentInstance::query()->create([
                'company_id' => $company->id,
                'employee_id' => $employee->id,
                'employee_name_snapshot' => (string) $employee->name,
                'employee_no_snapshot' => $employee->employee_no,
                'document_generation_template_id' => $template->id,
                'document_generation_template_version_id' => $version->id,
                'document_type_id' => $template->document_type_id,
                'employee_document_id' => $winningLibraryDoc->id,
                'template_name_snapshot' => $template->name,
                'template_version_number' => $version->version,
                'title_snapshot' => $template->name,
                'status' => 'generated',
                'generated_by' => $user->id,
                'generated_at' => now(),
            ]);

            // 5. Worker A creates winning DocumentInstanceVersion
            $winningVersion = DocumentInstanceVersion::query()->create([
                'company_id' => $company->id,
                'document_instance_id' => $winningInstance->id,
                'version' => 1,
                'stage' => 'generated',
                'file_path' => $winningCanonicalPath,
                'original_filename' => Str::slug($template->name).'.pdf',
                'mime_type' => 'application/pdf',
                'size_bytes' => strlen('%PDF-1.4 Worker A Canonical File'),
                'checksum' => hash('sha256', '%PDF-1.4 Worker A Canonical File'),
                'created_by' => $user->id,
            ]);

            $winningInstance->current_version_id = $winningVersion->id;
            $winningInstance->save();

            // Return Worker B's rendered PDF content so Worker B continues
            // to store its canonical/library files and reaches the locked DB transaction
            return '%PDF-1.4 Worker B Rendered Content';
        });
    app()->instance(CustomTemplatePdfRenderer::class, $mockRenderer);

    // Execute Worker B
    $jobB = new GenerateCustomDocumentsJob(
        companyId: $company->id,
        userId: $user->id,
        runId: $runB->id,
        allowRepeatGeneration: false,
    );
    $jobB->handle(app(CustomTemplatePdfRenderer::class), app(SyncGeneratedEmployeeDocument::class));

    // Assert: Database has only the winning instance, winning version, and winning EmployeeDocument
    expect(DocumentInstance::query()->count())->toBe(1)
        ->and(DocumentInstanceVersion::query()->count())->toBe(1)
        ->and(EmployeeDocument::query()->count())->toBe(1)
        ->and($itemB->fresh()->status)->toBe('skipped');

    // Worker B Run counters
    expect($runB->fresh()->generated_count)->toBe(0)
        ->and($runB->fresh()->skipped_count)->toBe(1)
        ->and($runB->fresh()->status)->toBe('completed');

    // Winning files are untouched in storage
    Storage::disk('local')->assertExists($winningCanonicalPath);
    Storage::disk('local')->assertExists($winningLibraryDoc->file_path);

    // Worker B's rendered canonical and library files were purged upon discovering the race in DB::transaction
    $allInstanceFiles = Storage::disk('local')->allFiles("document-instances/{$company->id}");
    $allEmployeeFiles = Storage::disk('local')->allFiles("employee-documents/{$company->id}");
    expect($allInstanceFiles)->toHaveCount(1)
        ->and($allInstanceFiles[0])->toBe($winningCanonicalPath)
        ->and($allEmployeeFiles)->toHaveCount(1)
        ->and($allEmployeeFiles[0])->toBe($winningLibraryDoc->file_path);
});

test('explicit repeat generation allows creating an intentional second instance across runs', function () {
    Storage::fake('local');
    $user = User::factory()->create();
    $company = createCustomGenTestCompany();
    $employee = Employee::factory()->forCompany($company)->create(['status' => 'active']);
    $template = DocumentGenerationTemplate::factory()->forCompany($company)->create([
        'status' => DocumentGenerationTemplateStatus::Active,
        'template_format' => DocumentGenerationTemplateFormat::Content,
    ]);
    $version = DocumentGenerationTemplateVersion::factory()->forTemplate($template)->published()->create();
    $template->update(['published_version_id' => $version->id]);

    $mockRenderer = Mockery::mock(CustomTemplatePdfRenderer::class);
    $mockRenderer->shouldReceive('render')->andReturn('%PDF-1.4 Fake PDF Content');
    app()->instance(CustomTemplatePdfRenderer::class, $mockRenderer);

    // Run 1: initial run
    $run1 = DocumentGenerationRun::query()->create([
        'company_id' => $company->id,
        'document_generation_template_id' => $template->id,
        'document_generation_template_version_id' => $version->id,
        'status' => 'queued',
        'total_targeted' => 1,
        'correlation_id' => (string) Str::uuid(),
        'triggered_by' => $user->id,
    ]);
    DocumentGenerationRunItem::query()->create([
        'company_id' => $company->id,
        'document_generation_run_id' => $run1->id,
        'employee_id' => $employee->id,
        'status' => 'pending',
    ]);
    $job1 = new GenerateCustomDocumentsJob(
        companyId: $company->id,
        userId: $user->id,
        runId: $run1->id,
        allowRepeatGeneration: false,
    );
    $job1->handle(app(CustomTemplatePdfRenderer::class), app(SyncGeneratedEmployeeDocument::class));
    expect(DocumentInstance::query()->count())->toBe(1);

    // Run 2: explicit repeat generation
    $run2 = DocumentGenerationRun::query()->create([
        'company_id' => $company->id,
        'document_generation_template_id' => $template->id,
        'document_generation_template_version_id' => $version->id,
        'status' => 'queued',
        'total_targeted' => 1,
        'correlation_id' => (string) Str::uuid(),
        'triggered_by' => $user->id,
    ]);
    $item2 = DocumentGenerationRunItem::query()->create([
        'company_id' => $company->id,
        'document_generation_run_id' => $run2->id,
        'employee_id' => $employee->id,
        'status' => 'pending',
    ]);
    $job2 = new GenerateCustomDocumentsJob(
        companyId: $company->id,
        userId: $user->id,
        runId: $run2->id,
        allowRepeatGeneration: true,
    );
    $job2->handle(app(CustomTemplatePdfRenderer::class), app(SyncGeneratedEmployeeDocument::class));

    expect(DocumentInstance::query()->count())->toBe(2)
        ->and($item2->fresh()->status)->toBe('completed')
        ->and($run2->fresh()->generated_count)->toBe(1);
});

test('template with generation run history but no instances cannot be deleted and returns validation error', function () {
    $user = User::factory()->create();
    $company = createCustomGenTestCompany();
    grantCompanyPermissions($user, $company, ['documents.templates.delete']);

    $template = DocumentGenerationTemplate::factory()->forCompany($company)->create([
        'status' => DocumentGenerationTemplateStatus::Active,
    ]);
    $version = DocumentGenerationTemplateVersion::factory()->forTemplate($template)->published()->create();
    $template->update(['published_version_id' => $version->id]);

    // Create a failed or queued Run with NO DocumentInstances
    $run = DocumentGenerationRun::query()->create([
        'company_id' => $company->id,
        'document_generation_template_id' => $template->id,
        'document_generation_template_version_id' => $version->id,
        'status' => 'failed',
        'total_targeted' => 1,
        'failed_count' => 1,
        'correlation_id' => (string) Str::uuid(),
        'triggered_by' => $user->id,
    ]);

    expect($template->instances()->count())->toBe(0)
        ->and($template->generationRuns()->count())->toBe(1);

    $response = $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->delete(route('organization.documents.templates.destroy', $template));

    $response->assertSessionHasErrors([
        'template' => 'This template cannot be deleted because document generation history exists. Deactivate the template instead.',
    ]);
    $this->assertDatabaseHas('document_generation_templates', ['id' => $template->id]);
    $this->assertDatabaseHas('document_generation_runs', ['id' => $run->id]);
});

test('job fails safely when template and version do not match in same company', function () {
    $company = createCustomGenTestCompany();
    $user = User::factory()->create();
    $employee = Employee::factory()->forCompany($company)->create(['status' => 'active']);

    $templateA = DocumentGenerationTemplate::factory()->forCompany($company)->create([
        'status' => DocumentGenerationTemplateStatus::Active,
        'template_format' => DocumentGenerationTemplateFormat::Content,
    ]);
    $templateB = DocumentGenerationTemplate::factory()->forCompany($company)->create([
        'status' => DocumentGenerationTemplateStatus::Active,
        'template_format' => DocumentGenerationTemplateFormat::Content,
    ]);
    $versionB = DocumentGenerationTemplateVersion::factory()->forTemplate($templateB)->published()->create();

    // Mismatched run: template A with version from template B
    $run = DocumentGenerationRun::query()->create([
        'company_id' => $company->id,
        'document_generation_template_id' => $templateA->id,
        'document_generation_template_version_id' => $versionB->id,
        'status' => 'queued',
        'total_targeted' => 1,
        'correlation_id' => (string) Str::uuid(),
        'triggered_by' => $user->id,
    ]);
    DocumentGenerationRunItem::query()->create([
        'company_id' => $company->id,
        'document_generation_run_id' => $run->id,
        'employee_id' => $employee->id,
        'status' => 'pending',
    ]);

    $mockRenderer = Mockery::mock(CustomTemplatePdfRenderer::class);
    $mockRenderer->shouldNotReceive('render');
    app()->instance(CustomTemplatePdfRenderer::class, $mockRenderer);

    $job = new GenerateCustomDocumentsJob(
        companyId: $company->id,
        userId: $user->id,
        runId: $run->id,
    );
    $job->handle(app(CustomTemplatePdfRenderer::class), app(SyncGeneratedEmployeeDocument::class));

    expect($run->fresh()->status)->toBe('failed')
        ->and($run->fresh()->finished_at)->not->toBeNull()
        ->and(DocumentInstance::query()->count())->toBe(0);
});

test('document instance version created_by attribute cannot be mutated', function () {
    $company = createCustomGenTestCompany();
    $employee = Employee::factory()->forCompany($company)->create(['status' => 'active']);
    $template = DocumentGenerationTemplate::factory()->forCompany($company)->create(['status' => DocumentGenerationTemplateStatus::Active]);
    $version = DocumentGenerationTemplateVersion::factory()->forTemplate($template)->published()->create();
    $user1 = User::factory()->create();
    $user2 = User::factory()->create();

    $instance = DocumentInstance::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'employee_name_snapshot' => 'Test Employee',
        'document_generation_template_id' => $template->id,
        'document_generation_template_version_id' => $version->id,
        'template_name_snapshot' => 'Template',
        'template_version_number' => 1,
        'title_snapshot' => 'Title',
        'status' => 'generated',
        'generated_at' => now(),
    ]);

    $instanceVersion = DocumentInstanceVersion::query()->create([
        'company_id' => $company->id,
        'document_instance_id' => $instance->id,
        'version' => 1,
        'stage' => 'generated',
        'file_path' => 'document-instances/test.pdf',
        'original_filename' => 'test.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 1024,
        'checksum' => 'checksum',
        'created_by' => $user1->id,
    ]);

    expect(fn () => $instanceVersion->update(['created_by' => $user2->id]))
        ->toThrow(DomainException::class, "Cannot modify immutable attribute 'created_by' on document instance version.");
});

test('document deletion service unlinks document instance only within same company', function () {
    Storage::fake('local');
    $companyA = createCustomGenTestCompany();
    $companyB = createCustomGenTestCompany();

    $employeeA = Employee::factory()->forCompany($companyA)->create(['status' => 'active']);
    $docA = EmployeeDocument::query()->create([
        'company_id' => $companyA->id,
        'employee_id' => $employeeA->id,
        'type' => 'other',
        'document_type' => 'other',
        'title' => 'Doc A',
        'file_path' => 'employee-documents/docA.pdf',
        'original_filename' => 'docA.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 100,
        'checksum' => 'checksumA',
        'current_version' => 1,
        'status' => 'valid',
    ]);
    Storage::disk('local')->put('employee-documents/docA.pdf', 'content');

    $templateA = DocumentGenerationTemplate::factory()->forCompany($companyA)->create(['status' => DocumentGenerationTemplateStatus::Active]);
    $versionA = DocumentGenerationTemplateVersion::factory()->forTemplate($templateA)->published()->create();

    $instanceA = DocumentInstance::query()->create([
        'company_id' => $companyA->id,
        'employee_id' => $employeeA->id,
        'employee_name_snapshot' => 'Employee A',
        'document_generation_template_id' => $templateA->id,
        'document_generation_template_version_id' => $versionA->id,
        'employee_document_id' => $docA->id,
        'template_name_snapshot' => 'Template A',
        'template_version_number' => 1,
        'title_snapshot' => 'Title A',
        'status' => 'generated',
        'generated_at' => now(),
    ]);

    // Same document ID mock or instance in company B
    $instanceB = DocumentInstance::query()->create([
        'company_id' => $companyB->id,
        'employee_name_snapshot' => 'Employee B',
        'document_generation_template_id' => $templateA->id,
        'document_generation_template_version_id' => $versionA->id,
        'employee_document_id' => $docA->id, // points to docA id
        'template_name_snapshot' => 'Template B',
        'template_version_number' => 1,
        'title_snapshot' => 'Title B',
        'status' => 'generated',
        'generated_at' => now(),
    ]);

    $service = app(DocumentDeletionService::class);
    $service->delete($docA);

    expect($instanceA->fresh()->employee_document_id)->toBeNull()
        ->and($instanceB->fresh()->employee_document_id)->toBe($docA->id);
});

test('custom document roster pagination does not expose private file_path in document prop', function () {
    $company = createCustomGenTestCompany();
    $employee = Employee::factory()->forCompany($company)->create(['status' => 'active']);
    $template = DocumentGenerationTemplate::factory()->forCompany($company)->create([
        'status' => DocumentGenerationTemplateStatus::Active,
        'template_format' => DocumentGenerationTemplateFormat::Content,
    ]);
    $version = DocumentGenerationTemplateVersion::factory()->forTemplate($template)->published()->create();
    $template->update(['published_version_id' => $version->id]);

    $doc = EmployeeDocument::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'type' => 'other',
        'document_type' => 'other',
        'title' => 'Secret Doc',
        'file_path' => 'private/secret-path/test.pdf',
        'original_filename' => 'test.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 100,
        'checksum' => 'checksum',
        'current_version' => 1,
        'status' => 'valid',
    ]);

    DocumentInstance::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'employee_name_snapshot' => (string) $employee->name,
        'document_generation_template_id' => $template->id,
        'document_generation_template_version_id' => $version->id,
        'employee_document_id' => $doc->id,
        'template_name_snapshot' => $template->name,
        'template_version_number' => 1,
        'title_snapshot' => $template->name,
        'status' => 'generated',
        'generated_at' => now(),
    ]);

    $paginator = CustomDocumentRosterQuery::paginate(
        companyId: $company->id,
        template: $template,
        version: $version,
        filters: new EmployeeDirectoryFilters,
        perPage: 15,
    );

    $items = $paginator->items();
    expect($items)->toHaveCount(1);
    $row = $items[0];
    expect($row['document'])->not->toBeNull()
        ->and($row['document']['id'])->toBe($doc->id)
        ->and(array_key_exists('file_path', $row['document']))->toBeFalse();
});

test('employee document deletion rollback preserves instance pointer when delete fails', function () {
    Storage::fake('local');
    $company = createCustomGenTestCompany();
    $employee = Employee::factory()->forCompany($company)->create(['status' => 'active']);
    $template = DocumentGenerationTemplate::factory()->forCompany($company)->create([
        'status' => DocumentGenerationTemplateStatus::Active,
        'template_format' => DocumentGenerationTemplateFormat::Content,
    ]);
    $version = DocumentGenerationTemplateVersion::factory()->forTemplate($template)->published()->create();
    $template->update(['published_version_id' => $version->id]);

    $libraryPath = "employee-documents/{$company->id}/{$employee->id}/test_doc.pdf";
    Storage::disk('local')->put($libraryPath, '%PDF-1.4 Library File');

    $doc = EmployeeDocument::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'type' => 'other',
        'document_type' => 'other',
        'title' => 'Test Deletion Doc',
        'file_path' => $libraryPath,
        'original_filename' => 'test_doc.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 100,
        'checksum' => 'checksum123',
        'current_version' => 1,
        'status' => 'valid',
    ]);

    $instance = DocumentInstance::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'employee_name_snapshot' => (string) $employee->name,
        'document_generation_template_id' => $template->id,
        'document_generation_template_version_id' => $version->id,
        'employee_document_id' => $doc->id,
        'template_name_snapshot' => $template->name,
        'template_version_number' => 1,
        'title_snapshot' => $template->name,
        'status' => 'generated',
        'generated_at' => now(),
    ]);

    // Force EmployeeDocument soft-delete DB operation to fail inside the transaction
    $shouldFail = true;
    EmployeeDocument::deleting(function () use (&$shouldFail) {
        if ($shouldFail) {
            throw new RuntimeException('Simulated database failure during soft delete.');
        }
    });

    $service = app(DocumentDeletionService::class);

    try {
        expect(fn () => $service->delete($doc))
            ->toThrow(RuntimeException::class, 'Simulated database failure during soft delete.');

        // Assert: EmployeeDocument was NOT deleted / still active
        expect($doc->fresh())->not->toBeNull()
            ->and($doc->fresh()->deleted_at)->toBeNull()
            // Assert: DocumentInstance pointer was rolled back and still points to $doc->id
            ->and($instance->fresh()->employee_document_id)->toBe($doc->id);

        // Assert: Library file was NOT deleted
        Storage::disk('local')->assertExists($libraryPath);
    } finally {
        $shouldFail = false;
    }
});

function overlaySourcePdfBytes(): string
{
    $pdf = new Fpdi;
    $pdf->AddPage('P', [210, 297]);
    $pdf->SetFont('Helvetica', 'B', 16);
    $pdf->Cell(0, 10, 'OVERLAY SOURCE');

    return $pdf->Output('S');
}

test('pdf overlay job stores checksum of the composed pdf not the source pdf', function () {
    $user = User::factory()->create();
    $company = createCustomGenTestCompany();
    $employee = Employee::factory()->forCompany($company)->create(['status' => 'active', 'name' => 'Checksum Emp']);

    $sourceBytes = overlaySourcePdfBytes();
    $sourcePath = "document-generation-templates/{$company->id}/overlay-source.pdf";
    Storage::disk('local')->put($sourcePath, $sourceBytes);

    $template = DocumentGenerationTemplate::factory()->forCompany($company)->create([
        'status' => DocumentGenerationTemplateStatus::Active,
        'template_format' => DocumentGenerationTemplateFormat::PdfOverlay,
        'name' => 'Overlay Checksum',
    ]);
    $version = DocumentGenerationTemplateVersion::factory()->forTemplate($template)->published()->create([
        'version' => 1,
        'source_pdf_path' => $sourcePath,
        'source_pdf_page_count' => 1,
        'placement_config' => ['schema_version' => 1, 'placements' => []],
    ]);
    $template->update(['published_version_id' => $version->id]);

    $run = DocumentGenerationRun::query()->create([
        'company_id' => $company->id,
        'document_generation_template_id' => $template->id,
        'document_generation_template_version_id' => $version->id,
        'status' => 'queued',
        'total_targeted' => 1,
        'correlation_id' => 'overlay-checksum',
        'triggered_by' => $user->id,
    ]);
    $item = DocumentGenerationRunItem::query()->create([
        'company_id' => $company->id,
        'document_generation_run_id' => $run->id,
        'employee_id' => $employee->id,
        'status' => 'pending',
    ]);

    $job = new GenerateCustomDocumentsJob($company->id, $user->id, $run->id, false);
    $job->handle(app(CustomTemplatePdfRenderer::class), app(SyncGeneratedEmployeeDocument::class));

    $item->refresh();
    expect($item->status)->toBe('completed')
        ->and($item->document_instance_id)->not->toBeNull();

    $instance = DocumentInstance::query()->find($item->document_instance_id);
    $instanceVersion = $instance->currentVersion;
    $storedBytes = Storage::disk('local')->get($instanceVersion->file_path);

    expect($instanceVersion->checksum)->toBe(hash('sha256', $storedBytes))
        ->and($instanceVersion->checksum)->not->toBe(hash('sha256', $sourceBytes))
        ->and($storedBytes)->toStartWith('%PDF-');
});

test('pdf overlay missing source file fails the run item without creating documents', function () {
    $user = User::factory()->create();
    $company = createCustomGenTestCompany();
    $employee = Employee::factory()->forCompany($company)->create(['status' => 'active', 'name' => 'Missing Source Emp']);

    $template = DocumentGenerationTemplate::factory()->forCompany($company)->create([
        'status' => DocumentGenerationTemplateStatus::Active,
        'template_format' => DocumentGenerationTemplateFormat::PdfOverlay,
        'name' => 'Missing Source Overlay',
    ]);
    $version = DocumentGenerationTemplateVersion::factory()->forTemplate($template)->published()->create([
        'version' => 1,
        'source_pdf_path' => "document-generation-templates/{$company->id}/missing.pdf",
        'source_pdf_page_count' => 1,
        'placement_config' => ['schema_version' => 1, 'placements' => []],
    ]);
    $template->update(['published_version_id' => $version->id]);

    $run = DocumentGenerationRun::query()->create([
        'company_id' => $company->id,
        'document_generation_template_id' => $template->id,
        'document_generation_template_version_id' => $version->id,
        'status' => 'queued',
        'total_targeted' => 1,
        'correlation_id' => 'overlay-missing-source',
        'triggered_by' => $user->id,
    ]);
    $item = DocumentGenerationRunItem::query()->create([
        'company_id' => $company->id,
        'document_generation_run_id' => $run->id,
        'employee_id' => $employee->id,
        'status' => 'pending',
    ]);

    Log::spy();

    $job = new GenerateCustomDocumentsJob($company->id, $user->id, $run->id, false);
    $job->handle(app(CustomTemplatePdfRenderer::class), app(SyncGeneratedEmployeeDocument::class));

    $item->refresh();
    expect($item->status)->toBe('failed')
        ->and($item->error_code)->toBe('TEMPLATE_SOURCE_UNAVAILABLE')
        ->and($item->document_instance_id)->toBeNull()
        ->and(DocumentInstance::query()->count())->toBe(0)
        ->and(EmployeeDocument::query()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles("document-instances/{$company->id}"))->toBeEmpty()
        ->and(Storage::disk('local')->allFiles("employee-documents/{$company->id}"))->toBeEmpty();

    Log::shouldHaveReceived('warning')->withArgs(function (string $message, array $context): bool {
        $encoded = json_encode($context);

        return $message === 'PDF overlay source unavailable during generation'
            && ! str_contains($encoded, 'document-generation-templates/');
    });
});

test('pdf overlay rejects a source path that points at another company directory', function () {
    $user = User::factory()->create();
    $companyA = createCustomGenTestCompany('Overlay Co A');
    $companyB = createCustomGenTestCompany('Overlay Co B');
    $employee = Employee::factory()->forCompany($companyA)->create(['status' => 'active', 'name' => 'Tenant Emp']);

    $foreignPath = "document-generation-templates/{$companyB->id}/secret.pdf";
    Storage::disk('local')->put($foreignPath, overlaySourcePdfBytes());

    $template = DocumentGenerationTemplate::factory()->forCompany($companyA)->create([
        'status' => DocumentGenerationTemplateStatus::Active,
        'template_format' => DocumentGenerationTemplateFormat::PdfOverlay,
        'name' => 'Cross Company Overlay',
    ]);
    $version = DocumentGenerationTemplateVersion::factory()->forTemplate($template)->published()->create([
        'version' => 1,
        'source_pdf_path' => $foreignPath,
        'source_pdf_page_count' => 1,
        'placement_config' => ['schema_version' => 1, 'placements' => []],
    ]);
    $template->update(['published_version_id' => $version->id]);

    $run = DocumentGenerationRun::query()->create([
        'company_id' => $companyA->id,
        'document_generation_template_id' => $template->id,
        'document_generation_template_version_id' => $version->id,
        'status' => 'queued',
        'total_targeted' => 1,
        'correlation_id' => 'overlay-cross-company',
        'triggered_by' => $user->id,
    ]);
    $item = DocumentGenerationRunItem::query()->create([
        'company_id' => $companyA->id,
        'document_generation_run_id' => $run->id,
        'employee_id' => $employee->id,
        'status' => 'pending',
    ]);

    $job = new GenerateCustomDocumentsJob($companyA->id, $user->id, $run->id, false);
    $job->handle(app(CustomTemplatePdfRenderer::class), app(SyncGeneratedEmployeeDocument::class));

    $item->refresh();
    expect($item->status)->toBe('failed')
        ->and($item->error_code)->toBe('TEMPLATE_SOURCE_UNAVAILABLE')
        ->and($item->document_instance_id)->toBeNull()
        ->and(DocumentInstance::query()->count())->toBe(0)
        ->and(Storage::disk('local')->exists($foreignPath))->toBeTrue();
});

test('pdf overlay layout overflow fails the item without creating official documents', function () {
    $user = User::factory()->create();
    $company = createCustomGenTestCompany();
    $employee = Employee::factory()->forCompany($company)->create(['status' => 'active', 'name' => 'Overflow Emp']);

    $template = DocumentGenerationTemplate::factory()->forCompany($company)->create([
        'status' => DocumentGenerationTemplateStatus::Active,
        'template_format' => DocumentGenerationTemplateFormat::PdfOverlay,
        'name' => 'Overflow Overlay',
    ]);
    $version = DocumentGenerationTemplateVersion::factory()->forTemplate($template)->published()->create(['version' => 1]);
    $template->update(['published_version_id' => $version->id]);

    $run = DocumentGenerationRun::query()->create([
        'company_id' => $company->id,
        'document_generation_template_id' => $template->id,
        'document_generation_template_version_id' => $version->id,
        'status' => 'queued',
        'total_targeted' => 1,
        'correlation_id' => 'overlay-overflow',
        'triggered_by' => $user->id,
    ]);
    $item = DocumentGenerationRunItem::query()->create([
        'company_id' => $company->id,
        'document_generation_run_id' => $run->id,
        'employee_id' => $employee->id,
        'status' => 'pending',
    ]);

    $mockRenderer = Mockery::mock(CustomTemplatePdfRenderer::class);
    $mockRenderer->shouldReceive('render')
        ->once()
        ->andThrow(new DocumentTemplateLayoutException(
            fieldKey: '{{employee_name}}',
            pageNumber: 1,
            message: 'overflow',
            placementId: 'placement-001',
        ));

    Log::spy();

    $job = new GenerateCustomDocumentsJob($company->id, $user->id, $run->id, false);
    $job->handle($mockRenderer, app(SyncGeneratedEmployeeDocument::class));

    $item->refresh();
    expect($item->status)->toBe('failed')
        ->and($item->error_code)->toBe('TEMPLATE_LAYOUT_OVERFLOW')
        ->and($item->error_message)->toContain('Employee Full Name')
        ->and($item->error_message)->toContain('page 1')
        ->and($item->document_instance_id)->toBeNull()
        ->and(DocumentInstance::query()->count())->toBe(0)
        ->and(EmployeeDocument::query()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles("document-instances/{$company->id}"))->toBeEmpty();

    Log::shouldHaveReceived('warning')->withArgs(function (string $message, array $context): bool {
        $encoded = json_encode($context);

        return $message === 'PDF overlay layout overflow during generation'
            && ($context['placement_id'] ?? null) === 'placement-001'
            && ($context['field_key'] ?? null) === '{{employee_name}}'
            && ! str_contains($encoded, 'Overflow Emp');
    });
});

test('pdf overlay archived version run uses the snapshotted source rather than the current published version', function () {
    $user = User::factory()->create();
    $company = createCustomGenTestCompany();
    $employee = Employee::factory()->forCompany($company)->create(['status' => 'active', 'name' => 'Archive Overlay Emp']);

    $v1Path = "document-generation-templates/{$company->id}/v1.pdf";
    $v2Path = "document-generation-templates/{$company->id}/v2.pdf";
    Storage::disk('local')->put($v1Path, overlaySourcePdfBytes());
    Storage::disk('local')->put($v2Path, overlaySourcePdfBytes());

    $template = DocumentGenerationTemplate::factory()->forCompany($company)->create([
        'status' => DocumentGenerationTemplateStatus::Active,
        'template_format' => DocumentGenerationTemplateFormat::PdfOverlay,
        'name' => 'Archived Overlay',
    ]);
    $version1 = DocumentGenerationTemplateVersion::factory()->forTemplate($template)->published()->create([
        'version' => 1,
        'source_pdf_path' => $v1Path,
        'source_pdf_page_count' => 1,
        'placement_config' => ['schema_version' => 1, 'placements' => []],
    ]);
    $template->update(['published_version_id' => $version1->id]);

    $run = DocumentGenerationRun::query()->create([
        'company_id' => $company->id,
        'document_generation_template_id' => $template->id,
        'document_generation_template_version_id' => $version1->id,
        'status' => 'queued',
        'total_targeted' => 1,
        'correlation_id' => 'overlay-archived-run',
        'triggered_by' => $user->id,
    ]);
    $item = DocumentGenerationRunItem::query()->create([
        'company_id' => $company->id,
        'document_generation_run_id' => $run->id,
        'employee_id' => $employee->id,
        'status' => 'pending',
    ]);

    $version2 = DocumentGenerationTemplateVersion::factory()->forTemplate($template)->draft()->automationNone()->create([
        'version' => 2,
        'source_pdf_path' => $v2Path,
        'source_pdf_page_count' => 1,
        'placement_config' => ['schema_version' => 1, 'placements' => []],
    ]);
    app(PublishDocumentGenerationTemplateVersion::class)->handle($version2, $user->id);

    $version1->refresh();
    expect($version1->status->value)->toBe('archived');

    $job = new GenerateCustomDocumentsJob($company->id, $user->id, $run->id, false);
    $job->handle(app(CustomTemplatePdfRenderer::class), app(SyncGeneratedEmployeeDocument::class));

    $item->refresh();
    $instance = DocumentInstance::query()->find($item->document_instance_id);

    expect($item->status)->toBe('completed')
        ->and($instance)->not->toBeNull()
        ->and($instance->document_generation_template_version_id)->toBe($version1->id)
        ->and($instance->template_version_number)->toBe(1);
});

test('pdf overlay v2 publication marks a v1 employee as missing for the current version', function () {
    $company = createCustomGenTestCompany();
    $employee = Employee::factory()->forCompany($company)->create(['status' => 'active', 'name' => 'Overlay Roster Emp']);

    $template = DocumentGenerationTemplate::factory()->forCompany($company)->create([
        'status' => DocumentGenerationTemplateStatus::Active,
        'template_format' => DocumentGenerationTemplateFormat::PdfOverlay,
        'name' => 'Overlay Roster',
    ]);
    $version1 = DocumentGenerationTemplateVersion::factory()->forTemplate($template)->published()->create(['version' => 1]);
    $version2 = DocumentGenerationTemplateVersion::factory()->forTemplate($template)->published()->create(['version' => 2]);
    $template->update(['published_version_id' => $version2->id]);

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
    $counts = CustomDocumentRosterQuery::counts($company->id, $template, $version2, $filters);

    expect($counts['targeted'])->toBe(1)
        ->and($counts['generated'])->toBe(0)
        ->and($counts['not_generated'])->toBe(1);
});

test('pdf overlay explicit selection generates a new copy without deleting the previous instance', function () {
    $user = User::factory()->create();
    $company = createCustomGenTestCompany();
    $employee = Employee::factory()->forCompany($company)->create(['status' => 'active', 'name' => 'Repeat Overlay Emp']);

    $sourcePath = "document-generation-templates/{$company->id}/overlay-source.pdf";
    Storage::disk('local')->put($sourcePath, overlaySourcePdfBytes());

    $template = DocumentGenerationTemplate::factory()->forCompany($company)->create([
        'status' => DocumentGenerationTemplateStatus::Active,
        'template_format' => DocumentGenerationTemplateFormat::PdfOverlay,
        'name' => 'Repeat Overlay',
    ]);
    $version = DocumentGenerationTemplateVersion::factory()->forTemplate($template)->published()->create([
        'version' => 1,
        'source_pdf_path' => $sourcePath,
        'source_pdf_page_count' => 1,
        'placement_config' => ['schema_version' => 1, 'placements' => []],
    ]);
    $template->update(['published_version_id' => $version->id]);

    $firstRun = DocumentGenerationRun::query()->create([
        'company_id' => $company->id,
        'document_generation_template_id' => $template->id,
        'document_generation_template_version_id' => $version->id,
        'status' => 'queued',
        'total_targeted' => 1,
        'correlation_id' => 'overlay-repeat-1',
        'triggered_by' => $user->id,
    ]);
    DocumentGenerationRunItem::query()->create([
        'company_id' => $company->id,
        'document_generation_run_id' => $firstRun->id,
        'employee_id' => $employee->id,
        'status' => 'pending',
    ]);

    $job1 = new GenerateCustomDocumentsJob($company->id, $user->id, $firstRun->id, false);
    $job1->handle(app(CustomTemplatePdfRenderer::class), app(SyncGeneratedEmployeeDocument::class));

    expect(DocumentInstance::query()->count())->toBe(1);

    $secondRun = DocumentGenerationRun::query()->create([
        'company_id' => $company->id,
        'document_generation_template_id' => $template->id,
        'document_generation_template_version_id' => $version->id,
        'status' => 'queued',
        'total_targeted' => 1,
        'correlation_id' => 'overlay-repeat-2',
        'triggered_by' => $user->id,
    ]);
    DocumentGenerationRunItem::query()->create([
        'company_id' => $company->id,
        'document_generation_run_id' => $secondRun->id,
        'employee_id' => $employee->id,
        'status' => 'pending',
    ]);

    $job2 = new GenerateCustomDocumentsJob($company->id, $user->id, $secondRun->id, true);
    $job2->handle(app(CustomTemplatePdfRenderer::class), app(SyncGeneratedEmployeeDocument::class));

    expect(DocumentInstance::query()->where('employee_id', $employee->id)->count())->toBe(2)
        ->and(EmployeeDocument::query()->where('employee_id', $employee->id)->count())->toBe(2);
});

test('pdf overlay create publish and generate with zero placements succeeds through official pipeline', function () {
    $user = User::factory()->create();
    $company = createCustomGenTestCompany();
    grantCompanyPermissions($user, $company, [
        'documents.templates.create',
        'documents.templates.update',
        'bulk_documents.generate',
    ]);

    $pdfContent = overlaySourcePdfBytes();
    $uploadedFile = UploadedFile::fake()->createWithContent('letterhead.pdf', $pdfContent);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('organization.documents.templates.store'), [
            'template_format' => DocumentGenerationTemplateFormat::PdfOverlay->value,
            'name' => 'Zero Placement Letterhead',
            'file' => $uploadedFile,
        ])
        ->assertRedirect();

    $template = DocumentGenerationTemplate::query()
        ->where('company_id', $company->id)
        ->where('name', 'Zero Placement Letterhead')
        ->first();

    expect($template)->not->toBeNull();

    $draft = $template->draftVersion;
    expect($draft)->not->toBeNull()
        ->and($draft->placement_config)->toMatchArray([
            'schema_version' => 2,
            'placements' => [],
        ]);

    $draft->update([
        'document_workflow_mode' => DocumentTemplateAutomationMode::None,
        'document_signing_mode' => DocumentTemplateAutomationMode::None,
    ]);

    app(PublishDocumentGenerationTemplateVersion::class)->handle($draft, $user->id);

    $published = $template->fresh()->publishedVersion;
    expect($published)->not->toBeNull()
        ->and($published->placement_config)->toMatchArray([
            'schema_version' => 2,
            'placements' => [],
        ]);

    $employee = Employee::factory()->forCompany($company)->create(['status' => 'active', 'name' => 'Zero Placement Emp']);

    $run = DocumentGenerationRun::query()->create([
        'company_id' => $company->id,
        'document_generation_template_id' => $template->id,
        'document_generation_template_version_id' => $published->id,
        'status' => 'queued',
        'total_targeted' => 1,
        'correlation_id' => 'zero-placement-lifecycle',
        'triggered_by' => $user->id,
    ]);
    $item = DocumentGenerationRunItem::query()->create([
        'company_id' => $company->id,
        'document_generation_run_id' => $run->id,
        'employee_id' => $employee->id,
        'status' => 'pending',
    ]);

    $job = new GenerateCustomDocumentsJob($company->id, $user->id, $run->id, false);
    $job->handle(app(CustomTemplatePdfRenderer::class), app(SyncGeneratedEmployeeDocument::class));

    $item->refresh();
    expect($item->status)->toBe('completed')
        ->and($item->document_instance_id)->not->toBeNull();

    $instance = DocumentInstance::query()->find($item->document_instance_id);
    expect($instance)->not->toBeNull();

    $employeeDocument = EmployeeDocument::query()->find($instance->employee_document_id);
    expect($employeeDocument)->not->toBeNull()
        ->and($employeeDocument->employee_id)->toBe($employee->id);

    $instanceVersion = $instance->currentVersion;
    expect($instanceVersion)->not->toBeNull();
    Storage::disk('local')->assertExists($instanceVersion->file_path);

    $generatedPdf = new Fpdi;
    $pageCount = $generatedPdf->setSourceFile(Storage::disk('local')->path($instanceVersion->file_path));
    expect($pageCount)->toBe(1);
});

test('pdf overlay replace pdf publish and generate with zero placements succeeds', function () {
    $user = User::factory()->create();
    $company = createCustomGenTestCompany();
    grantCompanyPermissions($user, $company, [
        'documents.templates.create',
        'documents.templates.update',
        'bulk_documents.generate',
    ]);

    $initialPdf = overlaySourcePdfBytes();
    $uploadedFile = UploadedFile::fake()->createWithContent('initial.pdf', $initialPdf);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('organization.documents.templates.store'), [
            'template_format' => DocumentGenerationTemplateFormat::PdfOverlay->value,
            'name' => 'Replace Zero Placement',
            'file' => $uploadedFile,
        ])
        ->assertRedirect();

    $template = DocumentGenerationTemplate::query()
        ->where('company_id', $company->id)
        ->where('name', 'Replace Zero Placement')
        ->firstOrFail();

    $draft = $template->draftVersion;

    $replacementPdf = new Fpdi;
    $replacementPdf->AddPage('P', [210, 297]);
    $replacementPdf->SetFont('Helvetica', '', 12);
    $replacementPdf->Cell(0, 10, 'Replacement Page 1');
    $replacementPdf->AddPage('P', [210, 297]);
    $replacementPdf->Cell(0, 10, 'Replacement Page 2');
    $replacementBytes = $replacementPdf->Output('S');

    app(ReplaceDocumentGenerationTemplatePdf::class)->handle(
        $draft,
        UploadedFile::fake()->createWithContent('replacement.pdf', $replacementBytes),
    );

    $draft->refresh();
    expect($draft->placement_config)->toMatchArray([
        'schema_version' => 2,
        'placements' => [],
    ])
        ->and($draft->source_pdf_page_count)->toBe(2);

    $draft->update([
        'document_workflow_mode' => DocumentTemplateAutomationMode::None,
        'document_signing_mode' => DocumentTemplateAutomationMode::None,
    ]);

    app(PublishDocumentGenerationTemplateVersion::class)->handle($draft, $user->id);

    $published = $template->fresh()->publishedVersion;
    $employee = Employee::factory()->forCompany($company)->create(['status' => 'active']);

    $run = DocumentGenerationRun::query()->create([
        'company_id' => $company->id,
        'document_generation_template_id' => $template->id,
        'document_generation_template_version_id' => $published->id,
        'status' => 'queued',
        'total_targeted' => 1,
        'correlation_id' => 'replace-zero-placement',
        'triggered_by' => $user->id,
    ]);
    DocumentGenerationRunItem::query()->create([
        'company_id' => $company->id,
        'document_generation_run_id' => $run->id,
        'employee_id' => $employee->id,
        'status' => 'pending',
    ]);

    $job = new GenerateCustomDocumentsJob($company->id, $user->id, $run->id, false);
    $job->handle(app(CustomTemplatePdfRenderer::class), app(SyncGeneratedEmployeeDocument::class));

    $instance = DocumentInstance::query()->where('employee_id', $employee->id)->first();
    expect($instance)->not->toBeNull();

    $generatedPdf = new Fpdi;
    $pageCount = $generatedPdf->setSourceFile(Storage::disk('local')->path($instance->currentVersion->file_path));
    expect($pageCount)->toBe(2);
});

test('legacy published null placement config generates as zero placements', function () {
    $user = User::factory()->create();
    $company = createCustomGenTestCompany();
    $employee = Employee::factory()->forCompany($company)->create(['status' => 'active']);

    $sourceBytes = overlaySourcePdfBytes();
    $sourcePath = "document-generation-templates/{$company->id}/legacy-null.pdf";
    Storage::disk('local')->put($sourcePath, $sourceBytes);

    $template = DocumentGenerationTemplate::factory()->forCompany($company)->create([
        'status' => DocumentGenerationTemplateStatus::Active,
        'template_format' => DocumentGenerationTemplateFormat::PdfOverlay,
    ]);
    $version = DocumentGenerationTemplateVersion::factory()->forTemplate($template)->published()->create([
        'source_pdf_path' => $sourcePath,
        'source_pdf_page_count' => 1,
        'placement_config' => null,
    ]);
    $template->update(['published_version_id' => $version->id]);

    $run = DocumentGenerationRun::query()->create([
        'company_id' => $company->id,
        'document_generation_template_id' => $template->id,
        'document_generation_template_version_id' => $version->id,
        'status' => 'queued',
        'total_targeted' => 1,
        'correlation_id' => 'legacy-null-placement',
        'triggered_by' => $user->id,
    ]);
    DocumentGenerationRunItem::query()->create([
        'company_id' => $company->id,
        'document_generation_run_id' => $run->id,
        'employee_id' => $employee->id,
        'status' => 'pending',
    ]);

    $job = new GenerateCustomDocumentsJob($company->id, $user->id, $run->id, false);
    $job->handle(app(CustomTemplatePdfRenderer::class), app(SyncGeneratedEmployeeDocument::class));

    expect(DocumentGenerationRunItem::query()->first()->status)->toBe('completed')
        ->and(DocumentInstance::query()->count())->toBe(1)
        ->and(EmployeeDocument::query()->count())->toBe(1);
});

test('corrupt duplicate placement ids in published config cannot generate official documents', function () {
    $user = User::factory()->create();
    $company = createCustomGenTestCompany();
    $employee = Employee::factory()->forCompany($company)->create(['status' => 'active']);

    $sourceBytes = overlaySourcePdfBytes();
    $sourcePath = "document-generation-templates/{$company->id}/duplicate-id.pdf";
    Storage::disk('local')->put($sourcePath, $sourceBytes);

    $template = DocumentGenerationTemplate::factory()->forCompany($company)->create([
        'status' => DocumentGenerationTemplateStatus::Active,
        'template_format' => DocumentGenerationTemplateFormat::PdfOverlay,
    ]);
    $version = DocumentGenerationTemplateVersion::factory()->forTemplate($template)->published()->create([
        'source_pdf_path' => $sourcePath,
        'source_pdf_page_count' => 1,
        'placement_config' => [
            'schema_version' => 1,
            'placements' => [
                [
                    'id' => 'dup',
                    'field' => '{{employee_name}}',
                    'page' => 1,
                    'x' => 0.1,
                    'y' => 0.1,
                    'width' => 0.3,
                    'height' => 0.05,
                    'font_size' => 12,
                    'font_weight' => 'normal',
                    'text_align' => 'left',
                ],
                [
                    'id' => 'dup',
                    'field' => '{{employee_no}}',
                    'page' => 1,
                    'x' => 0.5,
                    'y' => 0.1,
                    'width' => 0.3,
                    'height' => 0.05,
                    'font_size' => 12,
                    'font_weight' => 'normal',
                    'text_align' => 'left',
                ],
            ],
        ],
    ]);
    $template->update(['published_version_id' => $version->id]);

    $run = DocumentGenerationRun::query()->create([
        'company_id' => $company->id,
        'document_generation_template_id' => $template->id,
        'document_generation_template_version_id' => $version->id,
        'status' => 'queued',
        'total_targeted' => 1,
        'correlation_id' => 'duplicate-placement-id',
        'triggered_by' => $user->id,
    ]);
    DocumentGenerationRunItem::query()->create([
        'company_id' => $company->id,
        'document_generation_run_id' => $run->id,
        'employee_id' => $employee->id,
        'status' => 'pending',
    ]);

    $job = new GenerateCustomDocumentsJob($company->id, $user->id, $run->id, false);
    $job->handle(app(CustomTemplatePdfRenderer::class), app(SyncGeneratedEmployeeDocument::class));

    $item = DocumentGenerationRunItem::query()->first();
    expect($item->status)->toBe('failed')
        ->and($item->error_code)->toBe('GENERATION_FAILED')
        ->and(DocumentInstance::query()->count())->toBe(0)
        ->and(EmployeeDocument::query()->count())->toBe(0);
});

test('generate page can delete custom template library documents', function () {
    $user = User::factory()->create();
    $company = createCustomGenTestCompany();
    grantCompanyPermissions($user, $company, ['bulk_documents.view', 'bulk_documents.delete']);

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
        'title' => $template->name,
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
        'employee_name_snapshot' => (string) $employee->name,
        'document_generation_template_id' => $template->id,
        'document_generation_template_version_id' => $version->id,
        'template_name_snapshot' => $template->name,
        'template_version_number' => $version->version,
        'title_snapshot' => $template->name,
        'status' => 'generated',
        'employee_document_id' => $libraryDoc->id,
        'generated_at' => now(),
    ]);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->from(route('organization.documents.generate', ['document_type_key' => "custom_{$template->id}"]))
        ->delete(route('organization.documents.bulk.documents.destroy'), [
            'document_type_key' => "custom_{$template->id}",
            'document_ids' => [$libraryDoc->id],
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    expect(EmployeeDocument::query()->find($libraryDoc->id))->toBeNull()
        ->and($instance->fresh()->employee_document_id)->toBeNull()
        ->and(Storage::disk('local')->exists($canonicalPath))->toBeTrue()
        ->and(Storage::disk('local')->exists($libraryPath))->toBeFalse();

    $filters = EmployeeDirectoryFilters::fromArray(['status' => 'active']);
    $counts = CustomDocumentRosterQuery::counts($company->id, $template, $version, $filters);
    $generatedPage = CustomDocumentRosterQuery::paginate(
        $company->id,
        $template,
        $version,
        $filters,
        20,
        'generated',
    );
    $missingPage = CustomDocumentRosterQuery::paginate(
        $company->id,
        $template,
        $version,
        $filters,
        20,
        'missing',
    );

    expect($counts['generated'])->toBe(0)
        ->and($counts['not_generated'])->toBe(1)
        ->and($generatedPage->total())->toBe(0)
        ->and($missingPage->total())->toBe(1)
        ->and($missingPage->items()[0]['id'])->toBe($employee->id)
        ->and($missingPage->items()[0]['document'])->toBeNull();
});

test('generate page delete rejects another companys custom template key', function () {
    $user = User::factory()->create();
    $companyA = createCustomGenTestCompany('Company A');
    $companyB = createCustomGenTestCompany('Company B');
    grantCompanyPermissions($user, $companyA, ['bulk_documents.view', 'bulk_documents.delete']);

    $templateB = DocumentGenerationTemplate::factory()->forCompany($companyB)->create([
        'status' => DocumentGenerationTemplateStatus::Active,
    ]);
    $versionB = DocumentGenerationTemplateVersion::factory()->forTemplate($templateB)->published()->create();
    $templateB->update(['published_version_id' => $versionB->id]);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $companyA->id])
        ->delete(route('organization.documents.bulk.documents.destroy'), [
            'document_type_key' => "custom_{$templateB->id}",
            'document_ids' => [1],
        ])
        ->assertSessionHasErrors(['document_type_key']);
});

test('generate page delete does not remove another templates library document', function () {
    $user = User::factory()->create();
    $company = createCustomGenTestCompany();
    grantCompanyPermissions($user, $company, ['bulk_documents.view', 'bulk_documents.delete']);

    $employee = Employee::factory()->forCompany($company)->create(['status' => 'active']);
    $templateA = DocumentGenerationTemplate::factory()->forCompany($company)->create([
        'status' => DocumentGenerationTemplateStatus::Active,
        'name' => 'Template A',
    ]);
    $versionA = DocumentGenerationTemplateVersion::factory()->forTemplate($templateA)->published()->create();
    $templateA->update(['published_version_id' => $versionA->id]);

    $templateB = DocumentGenerationTemplate::factory()->forCompany($company)->create([
        'status' => DocumentGenerationTemplateStatus::Active,
        'name' => 'Template B',
    ]);
    $versionB = DocumentGenerationTemplateVersion::factory()->forTemplate($templateB)->published()->create();
    $templateB->update(['published_version_id' => $versionB->id]);

    $libraryPath = "employee-documents/{$company->id}/{$employee->id}/library-b.pdf";
    Storage::disk('local')->put($libraryPath, minimalPdfBytes());

    $libraryDoc = EmployeeDocument::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'type' => 'other',
        'document_type' => 'other',
        'title' => $templateB->name,
        'file_path' => $libraryPath,
        'original_filename' => 'library-b.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 100,
        'checksum' => 'checksum',
        'current_version' => 1,
        'status' => 'valid',
    ]);

    DocumentInstance::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'employee_name_snapshot' => (string) $employee->name,
        'document_generation_template_id' => $templateB->id,
        'document_generation_template_version_id' => $versionB->id,
        'template_name_snapshot' => $templateB->name,
        'template_version_number' => $versionB->version,
        'title_snapshot' => $templateB->name,
        'status' => 'generated',
        'employee_document_id' => $libraryDoc->id,
        'generated_at' => now(),
    ]);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->from(route('organization.documents.generate', ['document_type_key' => "custom_{$templateA->id}"]))
        ->delete(route('organization.documents.bulk.documents.destroy'), [
            'document_type_key' => "custom_{$templateA->id}",
            'document_ids' => [$libraryDoc->id],
        ])
        ->assertRedirect()
        ->assertSessionHas('success', 'No documents were removed.');

    expect(EmployeeDocument::query()->find($libraryDoc->id))->not->toBeNull()
        ->and(Storage::disk('local')->exists($libraryPath))->toBeTrue();
});

test('users without bulk_documents.delete cannot delete custom generated documents', function () {
    $user = User::factory()->create();
    $company = createCustomGenTestCompany();
    grantCompanyPermissions($user, $company, ['bulk_documents.view']);

    $template = DocumentGenerationTemplate::factory()->forCompany($company)->create([
        'status' => DocumentGenerationTemplateStatus::Active,
    ]);
    $version = DocumentGenerationTemplateVersion::factory()->forTemplate($template)->published()->create();
    $template->update(['published_version_id' => $version->id]);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->delete(route('organization.documents.bulk.documents.destroy'), [
            'document_type_key' => "custom_{$template->id}",
            'document_ids' => [1],
        ])
        ->assertForbidden();
});

test('custom template selection returns matching generated library document ids', function () {
    $user = User::factory()->create();
    $company = createCustomGenTestCompany();
    grantCompanyPermissions($user, $company, ['bulk_documents.view']);

    $generated = Employee::factory()->forCompany($company)->create(['status' => 'active', 'name' => 'Generated Emp']);
    Employee::factory()->forCompany($company)->create(['status' => 'active', 'name' => 'Missing Emp']);

    $template = DocumentGenerationTemplate::factory()->forCompany($company)->create([
        'status' => DocumentGenerationTemplateStatus::Active,
    ]);
    $version = DocumentGenerationTemplateVersion::factory()->forTemplate($template)->published()->create();
    $template->update(['published_version_id' => $version->id]);

    $libraryPath = "employee-documents/{$company->id}/{$generated->id}/library.pdf";
    Storage::disk('local')->put($libraryPath, minimalPdfBytes());

    $libraryDoc = EmployeeDocument::query()->create([
        'company_id' => $company->id,
        'employee_id' => $generated->id,
        'type' => 'other',
        'document_type' => 'other',
        'title' => $template->name,
        'file_path' => $libraryPath,
        'original_filename' => 'library.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 100,
        'checksum' => 'checksum',
        'current_version' => 1,
        'status' => 'valid',
    ]);

    DocumentInstance::query()->create([
        'company_id' => $company->id,
        'employee_id' => $generated->id,
        'employee_name_snapshot' => (string) $generated->name,
        'document_generation_template_id' => $template->id,
        'document_generation_template_version_id' => $version->id,
        'template_name_snapshot' => $template->name,
        'template_version_number' => $version->version,
        'title_snapshot' => $template->name,
        'status' => 'generated',
        'employee_document_id' => $libraryDoc->id,
        'generated_at' => now(),
    ]);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->get(route('organization.documents.bulk.selection', [
            'document_type_key' => "custom_{$template->id}",
            'generation_filter' => 'generated',
        ]))
        ->assertOk()
        ->assertJsonPath('total', 1)
        ->assertJsonPath('employee_ids.0', $generated->id)
        ->assertJsonPath('document_ids.0', $libraryDoc->id);
});

function customGenOverlayPuppeteerAvailable(): bool
{
    return file_exists(base_path('node_modules/puppeteer'));
}

test('company template roster uses the same employee image as built-in rosters', function () {
    $user = User::factory()->create();
    $company = createCustomGenTestCompany();
    grantCompanyPermissions($user, $company, ['bulk_documents.view']);

    $employee = Employee::factory()->forCompany($company)->create([
        'status' => 'active',
        'name' => 'Photo Emp',
        'image' => 'employee-photos/photo.jpg',
        'work_email' => 'work@example.com',
        'personal_email' => 'personal@example.com',
    ]);

    $template = DocumentGenerationTemplate::factory()->forCompany($company)->create([
        'status' => DocumentGenerationTemplateStatus::Active,
        'name' => 'Photo Letter',
    ]);
    $version = DocumentGenerationTemplateVersion::factory()->forTemplate($template)->published()->create();
    $template->update(['published_version_id' => $version->id]);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->get(route('organization.documents.generate', [
            'document_type_key' => "custom_{$template->id}",
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('employees.0.id', $employee->id)
            ->where('employees.0.image', 'employee-photos/photo.jpg')
            ->where('employees.0.email', 'work@example.com')
            ->where('employees.0.generation_run_status', null));
});

test('active custom run maps item status onto the initiating users roster', function () {
    $user = User::factory()->create();
    $company = createCustomGenTestCompany();
    grantCompanyPermissions($user, $company, ['bulk_documents.view']);

    $employee = Employee::factory()->forCompany($company)->create([
        'status' => 'active',
        'name' => 'Queued Emp',
        'image' => 'employee-photos/queued.jpg',
    ]);

    $template = DocumentGenerationTemplate::factory()->forCompany($company)->create([
        'status' => DocumentGenerationTemplateStatus::Active,
    ]);
    $version = DocumentGenerationTemplateVersion::factory()->forTemplate($template)->published()->create();
    $template->update(['published_version_id' => $version->id]);

    $run = DocumentGenerationRun::query()->create([
        'company_id' => $company->id,
        'document_generation_template_id' => $template->id,
        'document_generation_template_version_id' => $version->id,
        'status' => 'running',
        'total_targeted' => 1,
        'correlation_id' => 'roster-status',
        'triggered_by' => $user->id,
        'started_at' => now(),
    ]);
    DocumentGenerationRunItem::query()->create([
        'company_id' => $company->id,
        'document_generation_run_id' => $run->id,
        'employee_id' => $employee->id,
        'status' => 'processing',
    ]);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->get(route('organization.documents.generate', [
            'document_type_key' => "custom_{$template->id}",
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('employees.0.image', 'employee-photos/queued.jpg')
            ->where('employees.0.generation_run_status', 'processing')
            ->where('latest_run.failure_summary', null));
});

test('pdf overlay generate for one employee creates library instance version and generated roster row', function () {
    if (! customGenOverlayPuppeteerAvailable()) {
        $this->markTestSkipped('Puppeteer node module is not installed in this environment.');
    }

    $user = User::factory()->create();
    $company = createCustomGenTestCompany();
    grantCompanyPermissions($user, $company, ['bulk_documents.view', 'bulk_documents.generate']);

    $employee = Employee::factory()->forCompany($company)->create([
        'status' => 'active',
        'name' => 'Mohammed Rabil',
        'image' => 'employee-photos/rabil.jpg',
    ]);

    $sourcePath = "document-generation-templates/{$company->id}/letterhead.pdf";
    Storage::disk('local')->put($sourcePath, overlaySourcePdfBytes());

    $template = DocumentGenerationTemplate::factory()->forCompany($company)->create([
        'status' => DocumentGenerationTemplateStatus::Active,
        'template_format' => DocumentGenerationTemplateFormat::PdfOverlay,
        'name' => 'Company Letterhead',
    ]);
    $version = DocumentGenerationTemplateVersion::factory()->forTemplate($template)->published()->create([
        'version' => 1,
        'source_pdf_path' => $sourcePath,
        'source_pdf_page_count' => 1,
        'placement_config' => [
            'schema_version' => 2,
            'placements' => [
                [
                    'id' => 'name-field',
                    'type' => 'field',
                    'field' => '{{employee_name}}',
                    'page' => 1,
                    'x' => 0.1,
                    'y' => 0.1,
                    'width' => 0.8,
                    'height' => 0.08,
                    'font_size' => 14,
                    'font_weight' => 'normal',
                    'text_align' => 'left',
                    'vertical_align' => 'top',
                    'font_family' => 'sans',
                ],
                [
                    'id' => 'today-field',
                    'type' => 'field',
                    'field' => '{{today}}',
                    'page' => 1,
                    'x' => 0.1,
                    'y' => 0.2,
                    'width' => 0.4,
                    'height' => 0.05,
                    'font_size' => 12,
                    'font_weight' => 'normal',
                    'text_align' => 'left',
                    'vertical_align' => 'top',
                    'font_family' => 'sans',
                ],
            ],
        ],
    ]);
    $template->update(['published_version_id' => $version->id]);

    Queue::fake();

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('organization.documents.custom.generate'), [
            'document_generation_template_id' => $template->id,
            'employee_ids' => [$employee->id],
        ])
        ->assertRedirect();

    $run = DocumentGenerationRun::query()->first();
    expect($run)->not->toBeNull();

    $job = new GenerateCustomDocumentsJob($company->id, $user->id, $run->id, false);
    $job->handle(app(CustomTemplatePdfRenderer::class), app(SyncGeneratedEmployeeDocument::class));

    $run->refresh();
    $item = DocumentGenerationRunItem::query()->where('document_generation_run_id', $run->id)->first();

    expect($run->status)->toBe('completed')
        ->and($run->generated_count)->toBe(1)
        ->and($run->failed_count)->toBe(0)
        ->and($item?->status)->toBe('completed')
        ->and($item?->document_instance_id)->not->toBeNull();

    $instance = DocumentInstance::query()->find($item->document_instance_id);
    expect($instance)->not->toBeNull()
        ->and($instance->employee_id)->toBe($employee->id)
        ->and($instance->employee_document_id)->not->toBeNull()
        ->and($instance->current_version_id)->not->toBeNull();

    $employeeDocument = EmployeeDocument::query()->find($instance->employee_document_id);
    expect($employeeDocument)->not->toBeNull()
        ->and($employeeDocument->employee_id)->toBe($employee->id)
        ->and(Storage::disk('local')->exists($employeeDocument->file_path))->toBeTrue();

    $instanceVersion = DocumentInstanceVersion::query()->find($instance->current_version_id);
    $pdfBytes = Storage::disk('local')->get($instanceVersion->file_path);
    expect($instanceVersion->stage)->toBe('generated')
        ->and($pdfBytes)->toStartWith('%PDF')
        ->and($instanceVersion->checksum)->toBe(hash('sha256', $pdfBytes));

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->get(route('organization.documents.generate', [
            'document_type_key' => "custom_{$template->id}",
            'generation_filter' => 'generated',
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('employees.0.id', $employee->id)
            ->where('employees.0.image', 'employee-photos/rabil.jpg')
            ->where('employees.0.document.id', $employeeDocument->id));
});

test('invalid renderer bytes fail the item without storing documents', function () {
    $user = User::factory()->create();
    $company = createCustomGenTestCompany();
    $employee = Employee::factory()->forCompany($company)->create(['status' => 'active']);

    $template = DocumentGenerationTemplate::factory()->forCompany($company)->create([
        'status' => DocumentGenerationTemplateStatus::Active,
        'name' => 'Invalid Bytes',
    ]);
    $version = DocumentGenerationTemplateVersion::factory()->forTemplate($template)->published()->create();
    $template->update(['published_version_id' => $version->id]);

    $run = DocumentGenerationRun::query()->create([
        'company_id' => $company->id,
        'document_generation_template_id' => $template->id,
        'document_generation_template_version_id' => $version->id,
        'status' => 'queued',
        'total_targeted' => 1,
        'correlation_id' => 'invalid-bytes',
        'triggered_by' => $user->id,
    ]);
    $item = DocumentGenerationRunItem::query()->create([
        'company_id' => $company->id,
        'document_generation_run_id' => $run->id,
        'employee_id' => $employee->id,
        'status' => 'pending',
    ]);

    $mockRenderer = Mockery::mock(CustomTemplatePdfRenderer::class);
    $mockRenderer->shouldReceive('render')->once()->andReturn('not-a-pdf');

    $job = new GenerateCustomDocumentsJob($company->id, $user->id, $run->id, false);
    $job->handle($mockRenderer, app(SyncGeneratedEmployeeDocument::class));

    $item->refresh();
    expect($item->status)->toBe('failed')
        ->and($item->error_code)->toBe('GENERATION_FAILED')
        ->and($item->error_message)->toBe(DocumentGenerationItemErrorPresenter::userMessage('GENERATION_FAILED'))
        ->and(DocumentInstance::query()->count())->toBe(0)
        ->and(EmployeeDocument::query()->count())->toBe(0)
        ->and($run->fresh()->status)->toBe('failed');
});

test('hard job failure terminalizes processing items without leaving them stuck', function () {
    Notification::fake();
    $user = User::factory()->create();
    $company = createCustomGenTestCompany();
    $employee = Employee::factory()->forCompany($company)->create(['status' => 'active']);

    $template = DocumentGenerationTemplate::factory()->forCompany($company)->create([
        'status' => DocumentGenerationTemplateStatus::Active,
    ]);
    $version = DocumentGenerationTemplateVersion::factory()->forTemplate($template)->published()->create();
    $template->update(['published_version_id' => $version->id]);

    $run = DocumentGenerationRun::query()->create([
        'company_id' => $company->id,
        'document_generation_template_id' => $template->id,
        'document_generation_template_version_id' => $version->id,
        'status' => 'running',
        'total_targeted' => 1,
        'correlation_id' => 'job-failed',
        'triggered_by' => $user->id,
        'started_at' => now(),
    ]);
    $item = DocumentGenerationRunItem::query()->create([
        'company_id' => $company->id,
        'document_generation_run_id' => $run->id,
        'employee_id' => $employee->id,
        'status' => 'processing',
    ]);

    $job = new GenerateCustomDocumentsJob($company->id, $user->id, $run->id, false);
    $job->failed(new RuntimeException('worker killed'));

    $item->refresh();
    $run->refresh();

    expect($item->status)->toBe('failed')
        ->and($item->error_code)->toBe('JOB_FAILED')
        ->and($item->error_message)->toBe(DocumentGenerationItemErrorPresenter::JOB_FAILED_MESSAGE)
        ->and($run->status)->toBe('failed')
        ->and($run->failed_count)->toBe(1)
        ->and($run->finished_at)->not->toBeNull();

    Notification::assertSentTo($user, DocumentGenerationFinishedWebPushNotification::class, function (DocumentGenerationFinishedWebPushNotification $notification): bool {
        return $notification->title === 'Document generation failed';
    });
    Notification::assertSentTimes(DocumentGenerationFinishedWebPushNotification::class, 1);
});

test('mixed custom batch completes with issues and surfaces failure summary', function () {
    Notification::fake();
    $user = User::factory()->create();
    $company = createCustomGenTestCompany();
    grantCompanyPermissions($user, $company, ['bulk_documents.view']);

    $okOne = Employee::factory()->forCompany($company)->create(['status' => 'active', 'name' => 'Ok One']);
    $okTwo = Employee::factory()->forCompany($company)->create(['status' => 'active', 'name' => 'Ok Two']);
    $failEmp = Employee::factory()->forCompany($company)->create(['status' => 'active', 'name' => 'Fail Emp']);

    $template = DocumentGenerationTemplate::factory()->forCompany($company)->create([
        'status' => DocumentGenerationTemplateStatus::Active,
        'name' => 'Mixed Batch',
    ]);
    $version = DocumentGenerationTemplateVersion::factory()->forTemplate($template)->published()->create();
    $template->update(['published_version_id' => $version->id]);

    $run = DocumentGenerationRun::query()->create([
        'company_id' => $company->id,
        'document_generation_template_id' => $template->id,
        'document_generation_template_version_id' => $version->id,
        'status' => 'queued',
        'total_targeted' => 3,
        'correlation_id' => 'mixed-batch',
        'triggered_by' => $user->id,
    ]);

    foreach ([$okOne, $okTwo, $failEmp] as $employee) {
        DocumentGenerationRunItem::query()->create([
            'company_id' => $company->id,
            'document_generation_run_id' => $run->id,
            'employee_id' => $employee->id,
            'status' => 'pending',
        ]);
    }

    $mockRenderer = Mockery::mock(CustomTemplatePdfRenderer::class);
    $mockRenderer->shouldReceive('render')->times(3)->andReturnUsing(function ($templateArg, $versionArg, Employee $employee) {
        if ($employee->name === 'Fail Emp') {
            throw new DocumentTemplateLayoutException(
                fieldKey: '{{employee_name}}',
                pageNumber: 1,
                placementId: 'name-field',
            );
        }

        return minimalPdfBytes();
    });

    $job = new GenerateCustomDocumentsJob($company->id, $user->id, $run->id, false);
    $job->handle($mockRenderer, app(SyncGeneratedEmployeeDocument::class));

    $run->refresh();
    expect($run->status)->toBe('completed')
        ->and($run->generated_count)->toBe(2)
        ->and($run->failed_count)->toBe(1);

    $failedItem = DocumentGenerationRunItem::query()
        ->where('document_generation_run_id', $run->id)
        ->where('employee_id', $failEmp->id)
        ->first();

    expect($failedItem?->status)->toBe('failed')
        ->and($failedItem?->error_code)->toBe('TEMPLATE_LAYOUT_OVERFLOW')
        ->and($failedItem?->error_message)->toContain('Employee Full Name');

    Notification::assertSentTo($user, DocumentGenerationFinishedWebPushNotification::class, function (DocumentGenerationFinishedWebPushNotification $notification): bool {
        return $notification->title === 'Document generation completed with issues';
    });
    Notification::assertSentTimes(DocumentGenerationFinishedWebPushNotification::class, 1);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->get(route('organization.documents.generate', [
            'document_type_key' => "custom_{$template->id}",
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('latest_run.status', 'completed')
            ->where('latest_run.progress_percent', 100)
            ->where('latest_run.generated_count', 2)
            ->where('latest_run.failed_count', 1)
            ->where('latest_run.failure_summary.count', 1)
            ->where('latest_run.failure_summary.items.0.employee_id', $failEmp->id)
            ->where('latest_run.failure_summary.show_edit_template', true));
});

test('overlay generation continues in smaller chunks until pending items are done', function () {
    Queue::fake();
    $user = User::factory()->create();
    $company = createCustomGenTestCompany();

    $template = DocumentGenerationTemplate::factory()->forCompany($company)->create([
        'status' => DocumentGenerationTemplateStatus::Active,
        'template_format' => DocumentGenerationTemplateFormat::PdfOverlay,
        'name' => 'Chunk Overlay',
    ]);
    $sourcePath = "document-generation-templates/{$company->id}/chunk.pdf";
    Storage::disk('local')->put($sourcePath, overlaySourcePdfBytes());
    $version = DocumentGenerationTemplateVersion::factory()->forTemplate($template)->published()->create([
        'source_pdf_path' => $sourcePath,
        'source_pdf_page_count' => 1,
        'placement_config' => ['schema_version' => 1, 'placements' => []],
    ]);
    $template->update(['published_version_id' => $version->id]);

    $employees = Employee::factory()->count(5)->forCompany($company)->create(['status' => 'active']);

    $run = DocumentGenerationRun::query()->create([
        'company_id' => $company->id,
        'document_generation_template_id' => $template->id,
        'document_generation_template_version_id' => $version->id,
        'status' => 'queued',
        'total_targeted' => 5,
        'correlation_id' => 'overlay-chunks',
        'triggered_by' => $user->id,
    ]);

    foreach ($employees as $employee) {
        DocumentGenerationRunItem::query()->create([
            'company_id' => $company->id,
            'document_generation_run_id' => $run->id,
            'employee_id' => $employee->id,
            'status' => 'pending',
        ]);
    }

    expect(GenerateCustomDocumentsJob::PDF_OVERLAY_CHUNK_SIZE)->toBe(4);

    $job = new GenerateCustomDocumentsJob($company->id, $user->id, $run->id, false);
    $job->handle(app(CustomTemplatePdfRenderer::class), app(SyncGeneratedEmployeeDocument::class));

    expect($run->fresh()->status)->toBe('running')
        ->and(DocumentGenerationRunItem::query()->where('document_generation_run_id', $run->id)->where('status', 'completed')->count())->toBe(4)
        ->and(DocumentGenerationRunItem::query()->where('document_generation_run_id', $run->id)->where('status', 'pending')->count())->toBe(1);

    $continuation = null;
    Queue::assertPushed(GenerateCustomDocumentsJob::class, function (GenerateCustomDocumentsJob $queued) use (&$continuation): bool {
        $continuation = $queued;

        return true;
    });

    expect($continuation)->not->toBeNull();
    $continuation->handle(app(CustomTemplatePdfRenderer::class), app(SyncGeneratedEmployeeDocument::class));

    expect($run->fresh()->status)->toBe('completed')
        ->and($run->fresh()->generated_count)->toBe(5)
        ->and(DocumentGenerationRunItem::query()->where('status', 'processing')->count())->toBe(0);
});
