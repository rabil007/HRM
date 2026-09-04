<?php

namespace App\Support\BulkDocuments;

use App\Models\DocumentGenerationRun;
use App\Models\DocumentGenerationRunItem;
use App\Support\Documents\DocumentTemplateMergeFields;
use App\Support\Documents\Exceptions\DocumentTemplateLayoutException;

final class DocumentGenerationItemErrorPresenter
{
    public const FAILURE_SUMMARY_LIMIT = 5;

    public const JOB_FAILED_MESSAGE = 'Document generation was interrupted before completion.';

    public static function layoutOverflowMessage(DocumentTemplateLayoutException $exception): string
    {
        return self::layoutOverflowMessageForField($exception->fieldKey, $exception->pageNumber);
    }

    public static function layoutOverflowMessageForField(string $fieldKey, int $pageNumber): string
    {
        $label = DocumentTemplateMergeFields::labelFor($fieldKey);

        if ($label === null) {
            return "A text box does not fit the configured area on page {$pageNumber}.";
        }

        return "{$label} does not fit the configured field on page {$pageNumber}.";
    }

    public static function userMessage(string $code, ?string $storedMessage = null): string
    {
        $stored = is_string($storedMessage) ? trim($storedMessage) : '';

        if ($stored !== '' && $code === 'TEMPLATE_LAYOUT_OVERFLOW') {
            return $stored;
        }

        return match ($code) {
            'TEMPLATE_LAYOUT_OVERFLOW' => 'A value does not fit the configured template field.',
            'TEMPLATE_SOURCE_UNAVAILABLE' => 'The template source PDF is unavailable or invalid. Create a new template version with a valid PDF.',
            'EMPLOYEE_NOT_FOUND' => 'Employee record could not be found.',
            'JOB_FAILED' => self::JOB_FAILED_MESSAGE,
            default => 'PDF generation failed. Check system logs if the problem continues.',
        };
    }

    /**
     * @param  list<string|null>  $errorCodes
     * @return array{headline: string, show_edit_template: bool}
     */
    public static function headline(int $failedCount, array $errorCodes): array
    {
        $codes = array_values(array_unique(array_filter(
            $errorCodes,
            fn (mixed $code): bool => is_string($code) && $code !== '',
        )));

        $documentWord = $failedCount === 1 ? 'document' : 'documents';
        $showEditTemplate = in_array('TEMPLATE_LAYOUT_OVERFLOW', $codes, true)
            || in_array('TEMPLATE_SOURCE_UNAVAILABLE', $codes, true);

        if ($failedCount < 1) {
            return [
                'headline' => 'Documents could not be generated. Please try again or review the logs.',
                'show_edit_template' => false,
            ];
        }

        $headline = "{$failedCount} {$documentWord} could not be generated.";

        if (count($codes) === 1) {
            $headline = match ($codes[0]) {
                'TEMPLATE_LAYOUT_OVERFLOW' => "{$failedCount} {$documentWord} could not be generated because a value does not fit the configured template field.",
                'TEMPLATE_SOURCE_UNAVAILABLE' => 'The template source PDF is unavailable or invalid. Create a new template version with a valid PDF.',
                'JOB_FAILED' => "{$failedCount} {$documentWord} could not be generated because generation was interrupted.",
                default => $headline,
            };
        }

        return [
            'headline' => $headline,
            'show_edit_template' => $showEditTemplate,
        ];
    }

    /**
     * @return array{
     *     count: int,
     *     headline: string,
     *     show_edit_template: bool,
     *     additional_failure_count: int,
     *     items: list<array{employee_id: int, employee_name: string, error_code: string, message: string}>
     * }|null
     */
    public static function failureSummary(DocumentGenerationRun $run): ?array
    {
        $failedQuery = DocumentGenerationRunItem::query()
            ->where('company_id', $run->company_id)
            ->where('document_generation_run_id', $run->id)
            ->where('status', 'failed');

        $failedCount = (clone $failedQuery)->count();

        if ($failedCount === 0) {
            return null;
        }

        $errorCodes = (clone $failedQuery)->pluck('error_code')->all();
        $copy = self::headline($failedCount, $errorCodes);

        $items = (clone $failedQuery)
            ->with(['employee' => function ($query) use ($run): void {
                $query->where('company_id', $run->company_id)->select(['id', 'name', 'company_id']);
            }])
            ->orderBy('id')
            ->limit(self::FAILURE_SUMMARY_LIMIT)
            ->get()
            ->map(function (DocumentGenerationRunItem $item): array {
                $code = (string) ($item->error_code ?: 'GENERATION_FAILED');

                return [
                    'employee_id' => (int) $item->employee_id,
                    'employee_name' => (string) ($item->employee?->name ?: 'Employee'),
                    'error_code' => $code,
                    'message' => self::userMessage($code, $item->error_message),
                ];
            })
            ->values()
            ->all();

        return [
            'count' => $failedCount,
            'headline' => $copy['headline'],
            'show_edit_template' => $copy['show_edit_template'],
            'additional_failure_count' => max(0, $failedCount - count($items)),
            'items' => $items,
        ];
    }
}
