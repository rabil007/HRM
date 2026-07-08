<?php

namespace App\Support\BulkDocuments;

use App\Models\BulkDocumentEmailBatch;
use App\Models\BulkDocumentGenerationRun;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Collection;

final class BulkDocumentActivityQuery
{
    public static function paginate(
        int $companyId,
        string $documentTypeKey,
        int $perPage,
        int $page,
    ): LengthAwarePaginator {
        $items = self::items($companyId, $documentTypeKey);
        $total = $items->count();
        $page = max(1, $page);

        $slice = $items
            ->slice(($page - 1) * $perPage, $perPage)
            ->values()
            ->all();

        return new Paginator(
            $slice,
            $total,
            $perPage,
            $page,
            [
                'path' => request()->url(),
                'query' => request()->query(),
            ],
        );
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private static function items(int $companyId, string $documentTypeKey): Collection
    {
        $runs = BulkDocumentGenerationRun::query()
            ->where('company_id', $companyId)
            ->where('document_type_key', $documentTypeKey)
            ->with('triggeredBy:id,name')
            ->get()
            ->map(fn (BulkDocumentGenerationRun $run): array => [
                'kind' => 'generation',
                'id' => $run->id,
                'document_type_key' => $run->document_type_key,
                'document_type_label' => self::labelForKey($run->document_type_key),
                'status' => $run->status,
                'generated_count' => $run->generated_count,
                'replaced_count' => $run->replaced_count,
                'skipped_count' => $run->skipped_count,
                'failed_count' => $run->failed_count,
                'created_at' => $run->created_at?->toIso8601String(),
                'triggered_by' => $run->triggeredBy?->name,
            ]);

        $batches = BulkDocumentEmailBatch::query()
            ->where('company_id', $companyId)
            ->where('document_type_key', $documentTypeKey)
            ->with(['triggeredBy:id,name', 'emailTemplate:id,label'])
            ->get()
            ->map(fn (BulkDocumentEmailBatch $batch): array => [
                'kind' => 'email',
                'id' => $batch->id,
                'document_type_key' => $batch->document_type_key,
                'document_type_label' => self::labelForKey($batch->document_type_key),
                'template_label' => $batch->emailTemplate?->label,
                'sent_count' => $batch->sent_count,
                'failed_count' => $batch->failed_count,
                'skipped_no_email_count' => $batch->skipped_no_email_count,
                'created_at' => $batch->created_at?->toIso8601String(),
                'triggered_by' => $batch->triggeredBy?->name,
            ]);

        return $runs
            ->concat($batches)
            ->sortByDesc(fn (array $item): string => (string) ($item['created_at'] ?? ''))
            ->values();
    }

    private static function labelForKey(string $key): string
    {
        try {
            return BulkDocumentTypeRegistry::find($key)['label'];
        } catch (\InvalidArgumentException) {
            return $key;
        }
    }
}
