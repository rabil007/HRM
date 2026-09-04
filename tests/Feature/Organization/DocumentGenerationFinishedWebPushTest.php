<?php

use App\Enums\DocumentGenerationTemplateStatus;
use App\Jobs\GenerateCustomDocumentsJob;
use App\Models\Company;
use App\Models\DocumentGenerationRun;
use App\Models\DocumentGenerationRunItem;
use App\Models\DocumentGenerationTemplate;
use App\Models\DocumentGenerationTemplateVersion;
use App\Models\Employee;
use App\Models\User;
use App\Notifications\DocumentGenerationFinishedWebPushNotification;
use App\Services\Documents\CustomTemplatePdfRenderer;
use App\Support\Documents\Actions\SyncGeneratedEmployeeDocument;
use App\Support\Documents\NotifyDocumentGenerationFinished;
use Database\Seeders\PermissionsSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->seed(PermissionsSeeder::class);
    Storage::fake('local');
});

/**
 * @return array{user: User, other: User, company: Company, template: DocumentGenerationTemplate, version: DocumentGenerationTemplateVersion, employee: Employee}
 */
function makeFinishedPushFixtures(): array
{
    $user = User::factory()->create();
    $other = User::factory()->create();
    test()->actingAs($user);
    $company = setupBulkDocumentsCompany($user, ['bulk_documents.generate']);
    grantCompanyPermissions($other, $company, ['bulk_documents.generate']);

    $template = DocumentGenerationTemplate::factory()->forCompany($company)->create([
        'status' => DocumentGenerationTemplateStatus::Active,
        'name' => 'Salary Declaration',
    ]);
    $version = DocumentGenerationTemplateVersion::factory()->forTemplate($template)->published()->create(['version' => 1]);
    $template->update(['published_version_id' => $version->id]);
    $employee = Employee::factory()->forCompany($company)->create(['status' => 'active']);

    return compact('user', 'other', 'company', 'template', 'version', 'employee');
}

test('completed run notifies only triggered_by once even if the job continues', function () {
    Notification::fake();
    ['user' => $user, 'other' => $other, 'company' => $company, 'template' => $template, 'version' => $version, 'employee' => $employee] = makeFinishedPushFixtures();

    $run = DocumentGenerationRun::query()->create([
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
        'document_generation_run_id' => $run->id,
        'employee_id' => $employee->id,
        'status' => 'pending',
    ]);

    $renderer = Mockery::mock(CustomTemplatePdfRenderer::class);
    $renderer->shouldReceive('render')->once()->andReturn(minimalPdfBytes());

    $job = new GenerateCustomDocumentsJob($company->id, $user->id, $run->id, false);
    $job->handle($renderer, app(SyncGeneratedEmployeeDocument::class));

    $continuation = new GenerateCustomDocumentsJob($company->id, $user->id, $run->id, false, $run->items()->max('id'));
    $continuation->handle($renderer, app(SyncGeneratedEmployeeDocument::class));

    expect($run->fresh()->status)->toBe('completed');

    Notification::assertSentTo($user, DocumentGenerationFinishedWebPushNotification::class, function (DocumentGenerationFinishedWebPushNotification $notification) use ($run): bool {
        return $notification->runId === $run->id
            && $notification->title === 'Document generation completed'
            && str_contains($notification->body, 'Salary Declaration')
            && str_contains($notification->body, '1 documents generated');
    });
    Notification::assertNotSentTo($other, DocumentGenerationFinishedWebPushNotification::class);
    Notification::assertSentTimes(DocumentGenerationFinishedWebPushNotification::class, 1);
});

test('partial completion uses the issues message', function () {
    $run = DocumentGenerationRun::factory()->create([
        'status' => 'completed',
        'generated_count' => 18,
        'skipped_count' => 1,
        'failed_count' => 1,
    ]);
    $run->template()->update(['name' => 'Salary Declaration']);

    $notification = DocumentGenerationFinishedWebPushNotification::fromRun($run->load('template'));

    expect($notification->title)->toBe('Document generation completed with issues')
        ->and($notification->body)->toBe('Salary Declaration: 18 generated, 1 skipped, 1 failed.');
});

test('failed run uses the failure message', function () {
    $run = DocumentGenerationRun::factory()->create([
        'status' => 'failed',
        'generated_count' => 0,
        'failed_count' => 1,
    ]);
    $run->template()->update(['name' => 'Salary Declaration']);

    $notification = DocumentGenerationFinishedWebPushNotification::fromRun($run->load('template'));

    expect($notification->title)->toBe('Document generation failed')
        ->and($notification->body)->toBe('Salary Declaration could not be completed.');
});

test('structural job failure notifies triggered_by without leaving the run running', function () {
    Notification::fake();
    ['user' => $user, 'company' => $company, 'template' => $template, 'employee' => $employee] = makeFinishedPushFixtures();

    $draftVersion = DocumentGenerationTemplateVersion::factory()->forTemplate($template)->draft()->create(['version' => 2]);

    $run = DocumentGenerationRun::query()->create([
        'company_id' => $company->id,
        'document_generation_template_id' => $template->id,
        'document_generation_template_version_id' => $draftVersion->id,
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

    $renderer = Mockery::mock(CustomTemplatePdfRenderer::class);
    $renderer->shouldNotReceive('render');

    $job = new GenerateCustomDocumentsJob($company->id, $user->id, $run->id, false);
    $job->handle($renderer, app(SyncGeneratedEmployeeDocument::class));

    expect($run->fresh()->status)->toBe('failed')
        ->and($run->fresh()->finished_at)->not->toBeNull();

    Notification::assertSentTo($user, DocumentGenerationFinishedWebPushNotification::class, function (DocumentGenerationFinishedWebPushNotification $notification): bool {
        return $notification->title === 'Document generation failed';
    });
});

test('failed() does not overwrite an already completed run', function () {
    Notification::fake();
    ['user' => $user, 'company' => $company, 'template' => $template, 'version' => $version] = makeFinishedPushFixtures();

    $run = DocumentGenerationRun::query()->create([
        'company_id' => $company->id,
        'document_generation_template_id' => $template->id,
        'document_generation_template_version_id' => $version->id,
        'status' => 'completed',
        'total_targeted' => 1,
        'generated_count' => 1,
        'correlation_id' => (string) Str::uuid(),
        'triggered_by' => $user->id,
        'finished_at' => now(),
    ]);

    $job = new GenerateCustomDocumentsJob($company->id, $user->id, $run->id, false);
    $job->failed(new RuntimeException('worker crash'));

    expect($run->fresh()->status)->toBe('completed')
        ->and($run->fresh()->generated_count)->toBe(1);

    Notification::assertNothingSent();
});

test('push delivery failure does not fail generation', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $company = setupBulkDocumentsCompany($user, ['bulk_documents.generate']);
    $template = DocumentGenerationTemplate::factory()->forCompany($company)->create([
        'name' => 'Salary Declaration',
    ]);
    $run = DocumentGenerationRun::query()->create([
        'company_id' => $company->id,
        'document_generation_template_id' => $template->id,
        'document_generation_template_version_id' => DocumentGenerationTemplateVersion::factory()->forTemplate($template)->published()->create()->id,
        'status' => 'completed',
        'total_targeted' => 1,
        'generated_count' => 1,
        'correlation_id' => (string) Str::uuid(),
        'triggered_by' => $user->id,
    ]);

    Notification::shouldReceive('send')->once()->andThrow(new RuntimeException('push transport down'));

    expect(fn () => (new NotifyDocumentGenerationFinished)->handle($run->fresh(['template'])))
        ->not->toThrow(RuntimeException::class);
});
