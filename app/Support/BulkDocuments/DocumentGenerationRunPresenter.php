<?php

namespace App\Support\BulkDocuments;

use App\Models\BulkDocumentGenerationRun;
use App\Models\DocumentGenerationRun;
use App\Models\DocumentGenerationRunItem;
use InvalidArgumentException;

final class DocumentGenerationRunPresenter
{
    /**
     * @return array<string, mixed>
     */
    public function fromCompanyTemplateRun(DocumentGenerationRun $run): array
    {
        $run->loadMissing(['triggeredBy:id,name', 'template:id,name', 'templateVersion:id,version']);

        $isActive = in_array($run->status, ['queued', 'running'], true);
        $itemCounts = $isActive
            ? $this->activeItemCounts($run)
            : [
                'generated_count' => (int) $run->generated_count,
                'skipped_count' => (int) $run->skipped_count,
                'failed_count' => (int) $run->failed_count,
                'pending_count' => 0,
                'processing_count' => 0,
            ];

        $totalTargeted = (int) $run->total_targeted;
        $processedCount = $itemCounts['generated_count'] + $itemCounts['skipped_count'] + $itemCounts['failed_count'];

        return [
            'id' => $run->id,
            'source' => 'company_template',
            'status' => $run->status,
            'total_targeted' => $totalTargeted,
            'generated_count' => $itemCounts['generated_count'],
            'replaced_count' => 0,
            'skipped_count' => $itemCounts['skipped_count'],
            'failed_count' => $itemCounts['failed_count'],
            'pending_count' => $itemCounts['pending_count'],
            'processing_count' => $itemCounts['processing_count'],
            'processed_count' => $processedCount,
            'progress_percent' => $this->progressPercent($processedCount, $totalTargeted),
            'template_id' => $run->document_generation_template_id,
            'template_version_id' => $run->document_generation_template_version_id,
            'template_name' => $run->template?->name,
            'template_version' => $run->templateVersion?->version,
            'triggered_by' => $this->triggeredByPayload($run->triggeredBy?->id, $run->triggeredBy?->name),
            'started_at' => $run->started_at?->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(),
            'failure_summary' => $itemCounts['failed_count'] > 0
                ? DocumentGenerationItemErrorPresenter::failureSummary($run)
                : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function fromBuiltInRun(BulkDocumentGenerationRun $run): array
    {
        $run->loadMissing(['triggeredBy:id,name']);

        $totalTargeted = (int) $run->total_targeted;
        $processedCount = (int) $run->generated_count
            + (int) $run->replaced_count
            + (int) $run->skipped_count
            + (int) $run->failed_count;

        $label = $this->builtInLabel((string) $run->document_type_key);

        return [
            'id' => $run->id,
            'source' => 'built_in',
            'status' => $run->status,
            'document_type_key' => $run->document_type_key,
            'total_targeted' => $totalTargeted,
            'generated_count' => (int) $run->generated_count,
            'replaced_count' => (int) $run->replaced_count,
            'skipped_count' => (int) $run->skipped_count,
            'failed_count' => (int) $run->failed_count,
            'processed_count' => $processedCount,
            'progress_percent' => $this->progressPercent($processedCount, $totalTargeted),
            'template_name' => $label,
            'triggered_by' => $this->triggeredByPayload($run->triggeredBy?->id, $run->triggeredBy?->name),
            'started_at' => $run->started_at?->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(),
        ];
    }

    /**
     * @return array{generated_count: int, skipped_count: int, failed_count: int, pending_count: int, processing_count: int}
     */
    public function activeItemCounts(DocumentGenerationRun $run): array
    {
        $counts = DocumentGenerationRunItem::query()
            ->where('company_id', $run->company_id)
            ->where('document_generation_run_id', $run->id)
            ->selectRaw('
                COUNT(CASE WHEN status = ? THEN 1 END) as `completed_count`,
                COUNT(CASE WHEN status = ? THEN 1 END) as `skipped_count`,
                COUNT(CASE WHEN status = ? THEN 1 END) as `failed_count`,
                COUNT(CASE WHEN status = ? THEN 1 END) as `pending_count`,
                COUNT(CASE WHEN status = ? THEN 1 END) as `processing_count`
            ', ['completed', 'skipped', 'failed', 'pending', 'processing'])
            ->first();

        return [
            'generated_count' => (int) ($counts->completed_count ?? 0),
            'skipped_count' => (int) ($counts->skipped_count ?? 0),
            'failed_count' => (int) ($counts->failed_count ?? 0),
            'pending_count' => (int) ($counts->pending_count ?? 0),
            'processing_count' => (int) ($counts->processing_count ?? 0),
        ];
    }

    public function progressPercent(int $processedCount, int $totalTargeted): int
    {
        if ($totalTargeted <= 0) {
            return 0;
        }

        return (int) round(($processedCount / $totalTargeted) * 100);
    }

    /**
     * @return array{id: int, name: string}|null
     */
    private function triggeredByPayload(?int $id, ?string $name): ?array
    {
        if ($id === null) {
            return null;
        }

        return [
            'id' => $id,
            'name' => (string) $name,
        ];
    }

    private function builtInLabel(string $documentTypeKey): ?string
    {
        try {
            return BulkDocumentTypeRegistry::find($documentTypeKey)['label'];
        } catch (InvalidArgumentException) {
            return null;
        }
    }
}
