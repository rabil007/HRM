<?php

namespace App\Support\Documents;

use App\Enums\DocumentTemplateLayoutValidationRunStatus;
use App\Models\DocumentTemplateLayoutValidationRun;

final class PresentDocumentTemplateLayoutValidationRun
{
    /**
     * @return array{
     *     id: int,
     *     status: string,
     *     mode: string,
     *     authoritative: bool,
     *     valid: bool,
     *     validated_with: array<string, mixed>,
     *     effective_font_sizes: array<string, float|null>,
     *     issues: list<array<string, mixed>>,
     *     fit_count: int,
     *     overflow_count: int,
     *     reference: string|null,
     *     started_at: string|null,
     *     finished_at: string|null
     * }
     */
    public function handle(DocumentTemplateLayoutValidationRun $run): array
    {
        $issues = is_array($run->issues) ? array_values($run->issues) : [];
        $effectiveFontSizes = is_array($run->effective_font_sizes) ? $run->effective_font_sizes : [];
        $overflowCount = count(array_filter(
            $issues,
            fn (array $issue): bool => ($issue['code'] ?? '') === PdfOverlayLayoutPreflight::ISSUE_LAYOUT_OVERFLOW,
        ));
        $validatedWith = is_array($run->validated_with) ? $run->validated_with : ['mode' => $run->mode];

        return [
            'id' => (int) $run->id,
            'status' => $run->status->value,
            'mode' => $run->mode,
            'authoritative' => (bool) $run->authoritative,
            'valid' => $run->status === DocumentTemplateLayoutValidationRunStatus::Valid,
            'validated_with' => $validatedWith,
            'effective_font_sizes' => $effectiveFontSizes,
            'issues' => $issues,
            'fit_count' => max(0, count($effectiveFontSizes) - $overflowCount),
            'overflow_count' => $overflowCount,
            'reference' => $run->reference,
            'started_at' => $run->started_at?->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(),
        ];
    }
}
