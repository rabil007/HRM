<?php

namespace App\Support\Documents\Integrity;

use Spatie\Activitylog\Models\Activity;

final class DocumentIntegrityActivityLogger
{
    public function logRepair(
        int $companyId,
        string $entityType,
        int $entityId,
        string $repairCode,
    ): void {
        activity('document_integrity')
            ->event('document_integrity_repaired')
            ->tap(function (Activity $activity) use ($companyId): void {
                $activity->company_id = $companyId;
            })
            ->withProperties([
                'action' => 'document_integrity_repaired',
                'company_id' => $companyId,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'repair_code' => $repairCode,
            ])
            ->log('Document integrity safe repair applied');
    }
}
