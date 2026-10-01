<?php

namespace App\Support\EmployeeDocuments\DocumentExpiryNotification;

use App\Models\DocumentExpiryNotificationRule;
use Illuminate\Support\Collection;

class DocumentExpiryNotificationRulesForDocumentType
{
    /**
     * Enabled and disabled rules that cover a document type for the company.
     *
     * @return Collection<int, DocumentExpiryNotificationRule>
     */
    public function handle(int $companyId, int $documentTypeId): Collection
    {
        return DocumentExpiryNotificationRule::query()
            ->where('company_id', $companyId)
            ->where(function ($query) use ($documentTypeId): void {
                $query->where('all_document_types', true)
                    ->orWhereHas('documentTypes', function ($typeQuery) use ($documentTypeId): void {
                        $typeQuery->where('document_types.id', $documentTypeId);
                    });
            })
            ->with([
                'documentTypes:id,title',
                'toRecipients.user:id,name,email',
                'ccRecipients.user:id,name,email',
            ])
            ->orderByDesc('enabled')
            ->orderBy('name')
            ->get();
    }
}
