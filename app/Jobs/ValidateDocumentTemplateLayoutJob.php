<?php

namespace App\Jobs;

use App\Enums\DocumentTemplateLayoutValidationRunStatus;
use App\Models\DocumentGenerationTemplate;
use App\Models\DocumentGenerationTemplateVersion;
use App\Models\DocumentTemplateLayoutValidationRun;
use App\Models\Employee;
use App\Support\Documents\DocumentTemplateLayoutPreflightResult;
use App\Support\Documents\DocumentTemplateLayoutValidationFailureLogger;
use App\Support\Documents\DocumentTemplateLayoutValidationFingerprint;
use App\Support\Documents\DocumentTemplateMergeFields;
use App\Support\Documents\PdfOverlayLayoutPreflight;
use App\Support\Documents\TemplateDesignEmployeePreview;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ValidateDocumentTemplateLayoutJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 90;

    public int $backoff = 5;

    public function __construct(
        public int $runId,
        public int $companyId,
    ) {
        $this->onConnection('database');
    }

    public function handle(
        PdfOverlayLayoutPreflight $preflight,
        DocumentTemplateLayoutValidationFingerprint $fingerprint,
        DocumentTemplateLayoutValidationFailureLogger $failureLogger,
    ): void {
        $claimed = DocumentTemplateLayoutValidationRun::query()
            ->whereKey($this->runId)
            ->where('company_id', $this->companyId)
            ->where('status', DocumentTemplateLayoutValidationRunStatus::Queued)
            ->update([
                'status' => DocumentTemplateLayoutValidationRunStatus::Processing,
                'started_at' => now(),
            ]);

        if ($claimed !== 1) {
            return;
        }

        /** @var DocumentTemplateLayoutValidationRun|null $run */
        $run = DocumentTemplateLayoutValidationRun::query()
            ->whereKey($this->runId)
            ->where('company_id', $this->companyId)
            ->first();

        if ($run === null) {
            return;
        }

        $template = DocumentGenerationTemplate::query()
            ->whereKey($run->document_generation_template_id)
            ->where('company_id', $this->companyId)
            ->first();

        $version = DocumentGenerationTemplateVersion::query()
            ->whereKey($run->document_generation_template_version_id)
            ->where('company_id', $this->companyId)
            ->where('document_generation_template_id', $run->document_generation_template_id)
            ->first();

        $template?->loadMissing('company');

        if ($template === null || $version === null) {
            $this->finish($run, DocumentTemplateLayoutValidationRunStatus::Stale, [], [], $run->reference);

            return;
        }

        $configToMeasure = $run->authoritative && is_array($version->placement_config)
            ? $version->placement_config
            : (is_array($run->placement_config) ? $run->placement_config : $version->placement_config);

        if ($run->authoritative) {
            $expected = $fingerprint->for(
                $template,
                $version,
                $this->companyId,
                is_array($version->placement_config) ? $version->placement_config : null,
                'sample',
                null,
                true,
            );

            if ($expected !== $run->fingerprint) {
                $this->finish($run, DocumentTemplateLayoutValidationRunStatus::Stale, [], [], $run->reference);

                return;
            }
        }

        $mergeValues = DocumentTemplateMergeFields::sampleValues($template->company?->name);

        if ($run->mode === 'employee') {
            $employee = Employee::query()
                ->where('company_id', $this->companyId)
                ->whereKey($run->employee_id)
                ->where('status', 'active')
                ->first();

            if ($employee === null) {
                $this->finish($run, DocumentTemplateLayoutValidationRunStatus::Unavailable, [], [], DocumentTemplateLayoutValidationFailureLogger::newReference());

                return;
            }

            $mergeValues = TemplateDesignEmployeePreview::valuesForCompanyEmployee($this->companyId, $employee)['values'];
        }

        $result = $preflight->evaluate(
            $template,
            $version,
            $this->companyId,
            $mergeValues,
            is_array($configToMeasure) ? $configToMeasure : null,
            allowDraft: true,
            context: [
                'mode' => $run->mode,
                'user_id' => $run->requested_by,
            ],
        );

        $this->persistResult($run, $result);
    }

    public function failed(?Throwable $exception): void
    {
        $run = DocumentTemplateLayoutValidationRun::query()
            ->whereKey($this->runId)
            ->where('company_id', $this->companyId)
            ->whereIn('status', [
                DocumentTemplateLayoutValidationRunStatus::Queued,
                DocumentTemplateLayoutValidationRunStatus::Processing,
            ])
            ->first();

        if ($run === null) {
            return;
        }

        $reference = DocumentTemplateLayoutValidationFailureLogger::newReference();

        app(DocumentTemplateLayoutValidationFailureLogger::class)->record(
            $exception ?? new \RuntimeException('Layout validation job failed without an exception.'),
            $reference,
            [
                'company_id' => $this->companyId,
                'template_id' => (int) $run->document_generation_template_id,
                'template_version_id' => (int) $run->document_generation_template_version_id,
                'validation_mode' => $run->mode,
                'user_id' => $run->requested_by,
            ],
        );

        $this->finish(
            $run,
            DocumentTemplateLayoutValidationRunStatus::Unavailable,
            [[
                'code' => PdfOverlayLayoutPreflight::CODE_LAYOUT_VALIDATION_UNAVAILABLE,
                'severity' => 'error',
                'placement_id' => null,
                'field_key' => null,
                'field_label' => null,
                'page' => null,
                'message' => 'The PDF validation engine could not complete the layout check.',
                'reference' => $reference,
            ]],
            [],
            $reference,
        );
    }

    private function persistResult(
        DocumentTemplateLayoutValidationRun $run,
        DocumentTemplateLayoutPreflightResult $result,
    ): void {
        $status = match (true) {
            $result->isUnavailable() => DocumentTemplateLayoutValidationRunStatus::Unavailable,
            $result->valid => DocumentTemplateLayoutValidationRunStatus::Valid,
            default => DocumentTemplateLayoutValidationRunStatus::Invalid,
        };

        $this->finish(
            $run,
            $status,
            $this->persistableIssues($result->issues, $run->mode),
            $result->effectiveFontSizes,
            $result->reference,
        );
    }

    /**
     * @param  list<array<string, mixed>>  $issues
     * @param  array<string, float|null>  $effectiveFontSizes
     */
    private function finish(
        DocumentTemplateLayoutValidationRun $run,
        DocumentTemplateLayoutValidationRunStatus $status,
        array $issues,
        array $effectiveFontSizes,
        ?string $reference,
    ): void {
        $locked = DocumentTemplateLayoutValidationRun::query()
            ->whereKey($run->id)
            ->where('company_id', $this->companyId)
            ->whereIn('status', [
                DocumentTemplateLayoutValidationRunStatus::Queued,
                DocumentTemplateLayoutValidationRunStatus::Processing,
            ])
            ->first();

        if ($locked === null) {
            return;
        }

        $locked->status = $status;
        $locked->issues = $issues;
        $locked->effective_font_sizes = $effectiveFontSizes;
        $locked->reference = $reference;
        $locked->finished_at = now();
        $locked->save();
    }

    /**
     * @param  list<array<string, mixed>>  $issues
     * @return list<array<string, mixed>>
     */
    private function persistableIssues(array $issues, string $mode): array
    {
        if ($mode !== 'employee') {
            return $issues;
        }

        return array_map(function (array $issue): array {
            unset($issue['test_value']);

            return $issue;
        }, $issues);
    }
}
