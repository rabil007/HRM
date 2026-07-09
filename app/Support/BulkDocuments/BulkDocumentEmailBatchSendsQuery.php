<?php

namespace App\Support\BulkDocuments;

use App\Models\BulkDocumentEmailBatch;
use App\Models\BulkDocumentEmailSend;

final class BulkDocumentEmailBatchSendsQuery
{
    /**
     * @return array{
     *     batch: array<string, mixed>,
     *     sends: list<array<string, mixed>>
     * }
     */
    public static function forBatch(BulkDocumentEmailBatch $batch): array
    {
        $batch->loadMissing(['triggeredBy:id,name', 'emailTemplate:id,label']);

        $sends = $batch->sends()
            ->with('employee:id,name,employee_no')
            ->orderBy('id')
            ->get()
            ->map(fn (BulkDocumentEmailSend $send): array => [
                'id' => $send->id,
                'employee' => [
                    'id' => $send->employee_id,
                    'name' => $send->employee?->name,
                    'employee_no' => $send->employee?->employee_no,
                ],
                'recipient_email' => $send->recipient_email,
                'status' => $send->status,
                'error' => $send->error,
                'sent_at' => $send->sent_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        return [
            'batch' => [
                'id' => $batch->id,
                'document_type_key' => $batch->document_type_key,
                'document_type_label' => self::labelForKey($batch->document_type_key),
                'subject' => $batch->subject,
                'template_label' => $batch->emailTemplate?->label,
                'status' => $batch->status ?? 'completed',
                'total_selected' => $batch->total_selected,
                'sent_count' => $batch->sent_count,
                'failed_count' => $batch->failed_count,
                'skipped_no_email_count' => $batch->skipped_no_email_count,
                'created_at' => $batch->created_at?->toIso8601String(),
                'triggered_by' => $batch->triggeredBy?->name,
            ],
            'sends' => $sends,
        ];
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
