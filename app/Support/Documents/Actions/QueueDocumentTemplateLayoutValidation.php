<?php

namespace App\Support\Documents\Actions;

use App\Enums\DocumentTemplateLayoutValidationRunStatus;
use App\Jobs\ValidateDocumentTemplateLayoutJob;
use App\Models\DocumentGenerationTemplate;
use App\Models\DocumentGenerationTemplateVersion;
use App\Models\DocumentTemplateLayoutValidationRun;
use App\Models\Employee;
use App\Support\Documents\DocumentTemplateLayoutValidationFingerprint;
use App\Support\Documents\NormalizeDraftPdfOverlayPlacements;
use App\Support\Documents\TemplateDesignEmployeePreview;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class QueueDocumentTemplateLayoutValidation
{
    public function __construct(
        private DocumentTemplateLayoutValidationFingerprint $fingerprint,
    ) {}

    /**
     * @param  array<string, mixed>|null  $placementConfig
     */
    public function handle(
        DocumentGenerationTemplate $template,
        DocumentGenerationTemplateVersion $version,
        int $companyId,
        string $mode,
        ?array $placementConfig,
        ?int $employeeId,
        bool $canPreviewEmployee,
        ?int $requestedBy,
    ): DocumentTemplateLayoutValidationRun {
        if (! $template->isPdfOverlay()) {
            throw new InvalidArgumentException('Layout validation is only available for PDF overlay templates.');
        }

        if ((int) $template->company_id !== $companyId || (int) $version->company_id !== $companyId) {
            throw new InvalidArgumentException('Template does not belong to the expected company.');
        }

        if ((int) $version->document_generation_template_id !== (int) $template->id) {
            throw new InvalidArgumentException('Template version does not belong to the expected template.');
        }

        $configToMeasure = $this->resolveConfig($version, $placementConfig);
        $authoritative = $mode === 'sample' && $this->matchesPersistedDraft($version, $configToMeasure);
        $validatedWith = ['mode' => 'sample'];
        $resolvedEmployeeId = null;

        if ($mode === 'employee') {
            $authoritative = false;

            if (! $canPreviewEmployee) {
                abort(403);
            }

            if ($employeeId === null || $employeeId < 1) {
                throw ValidationException::withMessages([
                    'employee_id' => 'Select an employee to preview.',
                ]);
            }

            $employee = Employee::query()
                ->where('company_id', $companyId)
                ->whereKey($employeeId)
                ->where('status', 'active')
                ->first();

            if ($employee === null) {
                abort(404);
            }

            $preview = TemplateDesignEmployeePreview::valuesForCompanyEmployee($companyId, $employee);
            $resolvedEmployeeId = $preview['id'];
            $validatedWith = [
                'mode' => 'employee',
                'employee_id' => $preview['id'],
                'employee_name' => $preview['name'],
                'employee_no' => $preview['employee_no'],
            ];
        }

        $fingerprint = $this->fingerprint->for(
            $template,
            $version,
            $companyId,
            $configToMeasure,
            $mode,
            $resolvedEmployeeId,
            $authoritative,
        );

        $reusable = $this->reusableRun(
            $companyId,
            (int) $template->id,
            (int) $version->id,
            $fingerprint,
            $mode,
            $resolvedEmployeeId,
            $authoritative,
        );

        if ($reusable !== null) {
            return $reusable;
        }

        $run = DocumentTemplateLayoutValidationRun::query()->create([
            'company_id' => $companyId,
            'document_generation_template_id' => $template->id,
            'document_generation_template_version_id' => $version->id,
            'requested_by' => $requestedBy,
            'mode' => $mode,
            'employee_id' => $resolvedEmployeeId,
            'authoritative' => $authoritative,
            'fingerprint' => $fingerprint,
            'status' => DocumentTemplateLayoutValidationRunStatus::Queued,
            'placement_config' => $configToMeasure,
            'validated_with' => $validatedWith,
        ]);

        $dispatch = function () use ($run, $companyId): void {
            ValidateDocumentTemplateLayoutJob::dispatch($run->id, $companyId);
        };

        if (DB::transactionLevel() > 0) {
            DB::afterCommit($dispatch);
        } else {
            $dispatch();
        }

        return $run;
    }

    /**
     * @param  array<string, mixed>|null  $placementConfig
     * @return array<string, mixed>
     */
    private function resolveConfig(DocumentGenerationTemplateVersion $version, ?array $placementConfig): array
    {
        if ($version->isDraft() && $placementConfig !== null) {
            $pageCount = (int) ($version->source_pdf_page_count ?? 0);
            $normalized = NormalizeDraftPdfOverlayPlacements::handle(
                is_array($placementConfig['placements'] ?? null) ? $placementConfig['placements'] : [],
                max(1, $pageCount),
            );

            return [
                'schema_version' => 2,
                'placements' => $normalized,
            ];
        }

        return is_array($version->placement_config) ? $version->placement_config : [
            'schema_version' => 2,
            'placements' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $configToMeasure
     */
    private function matchesPersistedDraft(DocumentGenerationTemplateVersion $version, array $configToMeasure): bool
    {
        $persisted = is_array($version->placement_config) ? $version->placement_config : [
            'schema_version' => 2,
            'placements' => [],
        ];

        return $this->fingerprint->canonicalJson($this->fingerprint->canonicalPlacementConfig($persisted))
            === $this->fingerprint->canonicalJson($this->fingerprint->canonicalPlacementConfig($configToMeasure));
    }

    private function reusableRun(
        int $companyId,
        int $templateId,
        int $versionId,
        string $fingerprint,
        string $mode,
        ?int $employeeId,
        bool $authoritative,
    ): ?DocumentTemplateLayoutValidationRun {
        $query = DocumentTemplateLayoutValidationRun::query()
            ->where('company_id', $companyId)
            ->where('document_generation_template_id', $templateId)
            ->where('document_generation_template_version_id', $versionId)
            ->where('fingerprint', $fingerprint)
            ->where('mode', $mode)
            ->where('authoritative', $authoritative)
            ->when(
                $employeeId === null,
                fn ($builder) => $builder->whereNull('employee_id'),
                fn ($builder) => $builder->where('employee_id', $employeeId),
            )
            ->orderByDesc('id');

        $latest = (clone $query)->first();

        if ($latest === null) {
            return null;
        }

        if ($latest->status->isActive()) {
            return $latest;
        }

        if ($latest->status === DocumentTemplateLayoutValidationRunStatus::Valid) {
            return $latest;
        }

        return null;
    }
}
