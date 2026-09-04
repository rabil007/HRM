<?php

use App\Models\DocumentGenerationRun;
use App\Models\DocumentGenerationRunItem;
use App\Models\DocumentGenerationTemplate;
use App\Models\DocumentGenerationTemplateVersion;
use App\Models\Employee;
use App\Models\User;
use App\Support\BulkDocuments\DocumentGenerationItemErrorPresenter;
use App\Support\Documents\Exceptions\DocumentTemplateLayoutException;
use Database\Seeders\PermissionsSeeder;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->seed(PermissionsSeeder::class);
});

test('layout overflow copy names the merge field and page', function () {
    $message = DocumentGenerationItemErrorPresenter::layoutOverflowMessage(
        new DocumentTemplateLayoutException(
            fieldKey: '{{employee_name}}',
            pageNumber: 1,
            placementId: 'placement-001',
        ),
    );

    expect($message)->toBe('Employee Full Name does not fit the configured field on page 1.');
});

test('layout overflow copy treats static text as a text box', function () {
    expect(DocumentGenerationItemErrorPresenter::layoutOverflowMessageForField('', 2))
        ->toBe('A text box does not fit the configured area on page 2.');
});

test('user messages stay business-safe and never echo exception text', function () {
    expect(DocumentGenerationItemErrorPresenter::userMessage('GENERATION_FAILED', 'SQLSTATE[HY000] /private/var/tmp'))
        ->toBe('PDF generation failed. Check system logs if the problem continues.')
        ->and(DocumentGenerationItemErrorPresenter::userMessage('TEMPLATE_SOURCE_UNAVAILABLE'))
        ->toContain('template source PDF')
        ->and(DocumentGenerationItemErrorPresenter::userMessage('JOB_FAILED'))
        ->toBe(DocumentGenerationItemErrorPresenter::JOB_FAILED_MESSAGE);
});

test('failure summary caps detailed rows and keeps employee identity only', function () {
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
        'total_targeted' => 6,
        'generated_count' => 0,
        'failed_count' => 6,
        'correlation_id' => (string) Str::uuid(),
        'triggered_by' => $user->id,
        'finished_at' => now(),
    ]);

    for ($i = 1; $i <= 6; $i++) {
        DocumentGenerationRunItem::query()->create([
            'company_id' => $company->id,
            'document_generation_run_id' => $run->id,
            'employee_id' => Employee::factory()->forCompany($company)->create(['name' => "Employee {$i}"])->id,
            'status' => 'failed',
            'error_code' => 'TEMPLATE_LAYOUT_OVERFLOW',
            'error_message' => 'Employee Full Name does not fit the configured field on page 1.',
        ]);
    }

    $summary = DocumentGenerationItemErrorPresenter::failureSummary($run);

    expect($summary)->not->toBeNull()
        ->and($summary['count'])->toBe(6)
        ->and($summary['additional_failure_count'])->toBe(1)
        ->and($summary['items'])->toHaveCount(5)
        ->and($summary['show_edit_template'])->toBeTrue()
        ->and($summary['headline'])->toContain('does not fit the configured template field')
        ->and($summary['items'][0])->toHaveKeys(['employee_id', 'employee_name', 'error_code', 'message'])
        ->and($summary['items'][0])->not->toHaveKey('file_path');
});
