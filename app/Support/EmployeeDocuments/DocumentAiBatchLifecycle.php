<?php

namespace App\Support\EmployeeDocuments;

use App\Enums\DocumentAiBatchItemStatus;
use App\Enums\DocumentAiBatchStatus;
use App\Models\DocumentAiBatch;

final class DocumentAiBatchLifecycle
{
    public static function refresh(DocumentAiBatch $batch): void
    {
        $counts = $batch->items()->selectRaw('status, count(*) as aggregate')->groupBy('status')->pluck('aggregate', 'status');
        $completed = (int) ($counts[DocumentAiBatchItemStatus::Completed->value] ?? 0);
        $failed = (int) ($counts[DocumentAiBatchItemStatus::Failed->value] ?? 0);
        $active = (int) ($counts[DocumentAiBatchItemStatus::Queued->value] ?? 0) + (int) ($counts[DocumentAiBatchItemStatus::Processing->value] ?? 0);
        $status = $active > 0 ? DocumentAiBatchStatus::Processing : ($failed > 0 ? DocumentAiBatchStatus::CompletedWithErrors : DocumentAiBatchStatus::Completed);
        $batch->update(['status' => $status, 'completed_items' => $completed, 'failed_items' => $failed]);
    }
}
