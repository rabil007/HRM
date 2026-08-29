<?php

namespace App\Support\Documents\Signing;

use App\Enums\DocumentSigningFlowStatus;
use App\Models\DocumentInstance;
use App\Models\DocumentSigningFlow;
use Illuminate\Validation\ValidationException;

final class DocumentSigningFlowOpenGuard
{
    public const OPEN_FLOW_MESSAGE = 'This document is currently managed by an active signing flow.';

    public function openFlowForInstance(DocumentInstance $instance, int $companyId): ?DocumentSigningFlow
    {
        return DocumentSigningFlow::query()
            ->forCompany($companyId)
            ->where('document_instance_id', $instance->id)
            ->open()
            ->orderByDesc('id')
            ->first();
    }

    public function hasOpenFlow(DocumentInstance $instance, int $companyId): bool
    {
        return $this->openFlowForInstance($instance, $companyId) !== null;
    }

    public function assertNoOpenFlow(DocumentInstance $instance, int $companyId): void
    {
        if ($this->hasOpenFlow($instance, $companyId)) {
            throw ValidationException::withMessages([
                'action' => self::OPEN_FLOW_MESSAGE,
            ]);
        }
    }

    /**
     * @param  list<DocumentSigningFlowStatus>  $statuses
     */
    public function assertStatus(DocumentSigningFlow $flow, array $statuses): void
    {
        if (! in_array($flow->status, $statuses, true)) {
            throw ValidationException::withMessages([
                'flow' => 'This signing flow cannot be updated in its current status.',
            ]);
        }
    }
}
