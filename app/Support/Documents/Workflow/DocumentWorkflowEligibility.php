<?php

namespace App\Support\Documents\Workflow;

use App\Enums\DocumentWorkflowRequestStatus;
use App\Models\DocumentWorkflowRequest;
use App\Models\EmployeeDocument;

final class DocumentWorkflowEligibility
{
    public function canCreateForDocument(EmployeeDocument $document, int $companyId): bool
    {
        $document->loadMissing('documentInstance');

        $instance = $document->documentInstance;
        if ($instance === null) {
            return false;
        }

        if ((int) $instance->company_id !== $companyId) {
            return false;
        }

        if ($instance->current_version_id === null) {
            return false;
        }

        return ! DocumentWorkflowRequest::query()
            ->where('company_id', $companyId)
            ->where('document_instance_id', $instance->id)
            ->where('document_instance_version_id', $instance->current_version_id)
            ->where('status', DocumentWorkflowRequestStatus::Pending)
            ->exists();
    }

    /**
     * @return list<array{id: int, name: string, email: string|null}>
     */
    public function assigneeOptions(int $companyId): array
    {
        return (new DocumentWorkflowAssigneeOptionsQuery)->forCompany($companyId);
    }
}
