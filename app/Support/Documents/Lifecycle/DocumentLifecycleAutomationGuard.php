<?php

namespace App\Support\Documents\Lifecycle;

use App\Enums\DocumentLifecycleAutomationStatus;
use App\Models\DocumentInstance;
use App\Models\DocumentLifecycleAutomation;
use Illuminate\Validation\ValidationException;

final class DocumentLifecycleAutomationGuard
{
    public const MANUAL_BLOCKED_MESSAGE = 'This document is managed by automatic lifecycle automation. Retry from the lifecycle card if it is blocked, or wait until the automatic workflow finishes.';

    public function assertManualWorkflowAllowed(DocumentInstance $instance, int $companyId): void
    {
        $this->assertManualActionAllowed($instance, $companyId);
    }

    public function assertManualSigningAllowed(DocumentInstance $instance, int $companyId): void
    {
        $this->assertManualActionAllowed($instance, $companyId);
    }

    private function assertManualActionAllowed(DocumentInstance $instance, int $companyId): void
    {
        $lifecycle = DocumentLifecycleAutomation::query()
            ->forCompany($companyId)
            ->where('document_instance_id', $instance->id)
            ->first();

        if (! $lifecycle instanceof DocumentLifecycleAutomation) {
            return;
        }

        if (in_array($lifecycle->status, [
            DocumentLifecycleAutomationStatus::Pending,
            DocumentLifecycleAutomationStatus::Active,
            DocumentLifecycleAutomationStatus::Blocked,
        ], true)) {
            throw ValidationException::withMessages([
                'action' => self::MANUAL_BLOCKED_MESSAGE,
            ]);
        }
    }
}
