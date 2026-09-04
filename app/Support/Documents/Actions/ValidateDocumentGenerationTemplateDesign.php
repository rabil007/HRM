<?php

namespace App\Support\Documents\Actions;

use App\Models\DocumentGenerationTemplate;
use App\Models\DocumentGenerationTemplateVersion;
use App\Models\Employee;
use App\Support\Documents\DocumentTemplateLayoutPreflightResult;
use App\Support\Documents\DocumentTemplateMergeFields;
use App\Support\Documents\NormalizeDraftPdfOverlayPlacements;
use App\Support\Documents\PdfOverlayLayoutPreflight;
use App\Support\Documents\TemplateDesignEmployeePreview;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class ValidateDocumentGenerationTemplateDesign
{
    public function __construct(
        private PdfOverlayLayoutPreflight $preflight = new PdfOverlayLayoutPreflight,
    ) {}

    /**
     * @param  array<string, mixed>|null  $placementConfig
     * @return array{
     *     valid: bool,
     *     mode: string,
     *     validated_with: array<string, mixed>,
     *     effective_font_sizes: array<string, float|null>,
     *     issues: list<array<string, mixed>>,
     *     fit_count: int,
     *     overflow_count: int
     * }
     */
    public function handle(
        DocumentGenerationTemplate $template,
        DocumentGenerationTemplateVersion $version,
        int $companyId,
        string $mode,
        ?array $placementConfig,
        ?int $employeeId,
        bool $canPreviewEmployee,
    ): array {
        if (! $template->isPdfOverlay()) {
            throw new InvalidArgumentException('Layout validation is only available for PDF overlay templates.');
        }

        if ((int) $template->company_id !== $companyId || (int) $version->company_id !== $companyId) {
            throw new InvalidArgumentException('Template does not belong to the expected company.');
        }

        if ((int) $version->document_generation_template_id !== (int) $template->id) {
            throw new InvalidArgumentException('Template version does not belong to the expected template.');
        }

        $template->loadMissing('company');
        $mergeValues = DocumentTemplateMergeFields::sampleValues($template->company?->name);
        $validatedWith = [
            'mode' => 'sample',
        ];

        if ($mode === 'employee') {
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
            $mergeValues = $preview['values'];
            $validatedWith = [
                'mode' => 'employee',
                'employee_id' => $preview['id'],
                'employee_name' => $preview['name'],
                'employee_no' => $preview['employee_no'],
            ];
        }

        $configToMeasure = $version->placement_config;

        if ($version->isDraft() && $placementConfig !== null) {
            $pageCount = (int) ($version->source_pdf_page_count ?? 0);
            $normalized = NormalizeDraftPdfOverlayPlacements::handle(
                is_array($placementConfig['placements'] ?? null) ? $placementConfig['placements'] : [],
                max(1, $pageCount),
            );
            $configToMeasure = [
                'schema_version' => 2,
                'placements' => $normalized,
            ];
        }

        $result = $this->preflight->evaluate(
            $template,
            $version,
            $companyId,
            $mergeValues,
            is_array($configToMeasure) ? $configToMeasure : null,
            allowDraft: true,
        );

        return $this->present($result, $mode, $validatedWith);
    }

    /**
     * @param  array<string, mixed>  $validatedWith
     * @return array{
     *     valid: bool,
     *     mode: string,
     *     validated_with: array<string, mixed>,
     *     effective_font_sizes: array<string, float|null>,
     *     issues: list<array<string, mixed>>,
     *     fit_count: int,
     *     overflow_count: int
     * }
     */
    private function present(
        DocumentTemplateLayoutPreflightResult $result,
        string $mode,
        array $validatedWith,
    ): array {
        $overflowCount = count(array_filter(
            $result->issues,
            fn (array $issue): bool => ($issue['code'] ?? '') === PdfOverlayLayoutPreflight::ISSUE_LAYOUT_OVERFLOW,
        ));
        $measured = count($result->effectiveFontSizes);

        return [
            'valid' => $result->valid,
            'mode' => $mode,
            'validated_with' => $validatedWith,
            'effective_font_sizes' => $result->effectiveFontSizes,
            'issues' => $result->issues,
            'fit_count' => max(0, $measured - $overflowCount),
            'overflow_count' => $overflowCount,
        ];
    }
}
