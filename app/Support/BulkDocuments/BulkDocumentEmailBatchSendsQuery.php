<?php

namespace App\Support\BulkDocuments;

use App\Models\BulkDocumentEmailBatch;
use App\Models\BulkDocumentEmailSend;
use App\Models\User;
use App\Support\Employees\EmployeeVisibilityScope;

final class BulkDocumentEmailBatchSendsQuery
{
    /**
     * @return array{
     *     batch: array<string, mixed>,
     *     sends: list<array<string, mixed>>
     * }
     */
    public static function forBatch(BulkDocumentEmailBatch $batch, ?User $user, int $companyId): ?array
    {
        $batch->loadMissing(['triggeredBy:id,name', 'emailTemplate:id,label']);

        $restricted = ! EmployeeVisibilityScope::hasUnrestrictedAccess($user, $companyId);

        $query = $batch->sends()
            ->with('employee:id,name,employee_no');

        if ($restricted) {
            EmployeeVisibilityScope::whereHas($query, $user, $companyId);
        }

        $visibleSends = $query
            ->orderBy('id')
            ->get();

        if ($restricted && $visibleSends->isEmpty()) {
            return null;
        }

        $sends = $visibleSends
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

        $sentCount = $restricted
            ? $visibleSends->where('status', 'sent')->count()
            : (int) $batch->sent_count;
        $failedCount = $restricted
            ? $visibleSends->where('status', 'failed')->count()
            : (int) $batch->failed_count;
        $skippedCount = $restricted
            ? $visibleSends->where('status', 'skipped')->count()
            : (int) $batch->skipped_no_email_count;
        $totalSelected = $restricted
            ? $visibleSends->count()
            : (int) $batch->total_selected;

        return [
            'batch' => [
                'id' => $batch->id,
                'document_type_key' => $batch->document_type_key,
                'document_type_label' => self::labelForKey($batch->document_type_key),
                'subject' => $batch->subject,
                'template_label' => $batch->emailTemplate?->label,
                'status' => $batch->status ?? 'completed',
                'total_selected' => $totalSelected,
                'sent_count' => $sentCount,
                'failed_count' => $failedCount,
                'skipped_no_email_count' => $skippedCount,
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
