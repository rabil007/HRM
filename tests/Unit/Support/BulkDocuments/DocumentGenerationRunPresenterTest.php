<?php

use App\Models\DocumentGenerationRun;
use App\Models\DocumentGenerationRunItem;
use App\Models\DocumentGenerationTemplate;
use App\Models\DocumentGenerationTemplateVersion;
use App\Models\Employee;
use App\Models\User;
use App\Support\BulkDocuments\DocumentGenerationRunPresenter;
use Database\Seeders\PermissionsSeeder;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->seed(PermissionsSeeder::class);
});

test('active company template run aggregates item statuses and percent', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $company = setupBulkDocumentsCompany($user, ['bulk_documents.view']);
    $template = DocumentGenerationTemplate::factory()->forCompany($company)->active()->create([
        'name' => 'Salary Declaration',
    ]);
    $version = DocumentGenerationTemplateVersion::factory()->forTemplate($template)->published()->create(['version' => 2]);
    $template->update(['published_version_id' => $version->id]);

    $run = DocumentGenerationRun::query()->create([
        'company_id' => $company->id,
        'document_generation_template_id' => $template->id,
        'document_generation_template_version_id' => $version->id,
        'status' => 'running',
        'total_targeted' => 5,
        'generated_count' => 0,
        'skipped_count' => 0,
        'failed_count' => 0,
        'correlation_id' => (string) Str::uuid(),
        'triggered_by' => $user->id,
        'started_at' => now(),
    ]);

    foreach (['completed', 'completed', 'skipped', 'failed', 'processing'] as $status) {
        DocumentGenerationRunItem::query()->create([
            'company_id' => $company->id,
            'document_generation_run_id' => $run->id,
            'employee_id' => Employee::factory()->forCompany($company)->create()->id,
            'status' => $status,
        ]);
    }

    $payload = (new DocumentGenerationRunPresenter)->fromCompanyTemplateRun($run->fresh());

    expect($payload['source'])->toBe('company_template')
        ->and($payload['status'])->toBe('running')
        ->and($payload['generated_count'])->toBe(2)
        ->and($payload['skipped_count'])->toBe(1)
        ->and($payload['failed_count'])->toBe(1)
        ->and($payload['processing_count'])->toBe(1)
        ->and($payload['pending_count'])->toBe(0)
        ->and($payload['processed_count'])->toBe(4)
        ->and($payload['progress_percent'])->toBe(80)
        ->and($payload['template_name'])->toBe('Salary Declaration')
        ->and($payload['template_version'])->toBe(2)
        ->and($payload['triggered_by']['id'])->toBe($user->id)
        ->and($payload['triggered_by']['name'])->toBe($user->name)
        ->and($payload['failure_summary']['count'])->toBe(1)
        ->and($payload['failure_summary']['items'])->toHaveCount(1);
});

test('completed company template run uses stored counters', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $company = setupBulkDocumentsCompany($user, ['bulk_documents.view']);
    $template = DocumentGenerationTemplate::factory()->forCompany($company)->active()->create();
    $version = DocumentGenerationTemplateVersion::factory()->forTemplate($template)->published()->create();
    $template->update(['published_version_id' => $version->id]);

    $run = DocumentGenerationRun::query()->create([
        'company_id' => $company->id,
        'document_generation_template_id' => $template->id,
        'document_generation_template_version_id' => $version->id,
        'status' => 'completed',
        'total_targeted' => 20,
        'generated_count' => 18,
        'skipped_count' => 1,
        'failed_count' => 1,
        'correlation_id' => (string) Str::uuid(),
        'triggered_by' => $user->id,
        'finished_at' => now(),
    ]);

    DocumentGenerationRunItem::query()->create([
        'company_id' => $company->id,
        'document_generation_run_id' => $run->id,
        'employee_id' => Employee::factory()->forCompany($company)->create(['name' => 'Overflow Emp'])->id,
        'status' => 'failed',
        'error_code' => 'TEMPLATE_LAYOUT_OVERFLOW',
        'error_message' => 'Employee Full Name does not fit the configured field on page 1.',
    ]);

    $payload = (new DocumentGenerationRunPresenter)->fromCompanyTemplateRun($run);

    expect($payload['processed_count'])->toBe(20)
        ->and($payload['progress_percent'])->toBe(100)
        ->and($payload['generated_count'])->toBe(18)
        ->and($payload['failed_count'])->toBe(1)
        ->and($payload['failure_summary']['count'])->toBe(1)
        ->and($payload['failure_summary']['items'][0]['employee_name'])->toBe('Overflow Emp')
        ->and($payload['failure_summary']['items'][0]['message'])->toContain('Employee Full Name');
});

test('progress percent is zero when nothing is targeted', function () {
    expect((new DocumentGenerationRunPresenter)->progressPercent(0, 0))->toBe(0)
        ->and((new DocumentGenerationRunPresenter)->progressPercent(3, 10))->toBe(30);
});
