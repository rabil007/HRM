<?php

namespace App\Support\Documents\RecipientRequests;

use App\Models\DocumentRecipientRequest;

final class DocumentRecipientRequestPresenter
{
    /**
     * @return array<string, mixed>
     */
    public function listItem(DocumentRecipientRequest $request): array
    {
        $request->loadMissing([
            'documentInstance.employeeDocument',
            'requestedBy:id,name',
            'employee:id,name,employee_no',
            'recipientUser:id,name',
            'sourceVersion:id,version',
            'resultVersion:id,version',
        ]);

        $document = $request->documentInstance?->employeeDocument;

        return [
            'id' => $request->id,
            'action' => $request->action->value,
            'action_label' => $request->action->label(),
            'status' => $request->status->value,
            'status_label' => $request->status->label(),
            'recipient_type' => $request->recipient_type->value,
            'recipient_type_label' => $request->recipient_type->label(),
            'recipient_role' => $request->recipient_role->value,
            'recipient_role_label' => $request->recipient_role->label(),
            'recipient_name' => $request->recipient_name_snapshot,
            'requested_at' => $request->requested_at?->toIso8601String(),
            'expires_at' => $request->expires_at?->toIso8601String(),
            'completed_at' => $request->completed_at?->toIso8601String(),
            'requested_by' => [
                'id' => $request->requested_by,
                'name' => $request->requestedBy?->name,
            ],
            'document' => [
                'id' => $document?->id,
                'title' => $document?->title ?? $request->documentInstance?->title_snapshot,
            ],
            'employee' => [
                'id' => $request->employee_id,
                'name' => $request->employee?->name ?? $request->recipient_name_snapshot,
                'employee_no' => $request->employee?->employee_no,
            ],
            'company_signatory' => $request->recipient_user_id ? [
                'id' => $request->recipient_user_id,
                'name' => $request->recipientUser?->name ?? $request->recipient_name_snapshot,
            ] : null,
            'source_version' => [
                'id' => $request->source_document_instance_version_id,
                'version' => $request->sourceVersion?->version,
            ],
            'result_version' => $request->resultVersion ? [
                'id' => $request->resultVersion->id,
                'version' => $request->resultVersion->version,
            ] : null,
            'respond_url' => $request->isInternalCompanySignatory() && $request->isAwaitingAction()
                ? route('organization.documents.recipient-requests.respond', [
                    'recipientRequest' => $request->id,
                ])
                : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function detail(DocumentRecipientRequest $request): array
    {
        $request->loadMissing([
            'documentInstance.employeeDocument.employee',
            'documentInstance.currentVersion',
            'sourceVersion',
            'resultVersion',
            'requestedBy:id,name',
            'cancelledBy:id,name',
            'recipientUser:id,name',
            'events.actor:id,name',
        ]);

        $document = $request->documentInstance?->employeeDocument;

        return [
            'id' => $request->id,
            'action' => $request->action->value,
            'action_label' => $request->action->label(),
            'status' => $request->status->value,
            'status_label' => $request->status->label(),
            'recipient_type' => $request->recipient_type->value,
            'recipient_type_label' => $request->recipient_type->label(),
            'recipient_role' => $request->recipient_role->value,
            'recipient_role_label' => $request->recipient_role->label(),
            'recipient_name' => $request->recipient_name_snapshot,
            'is_public_token_recipient' => $request->isPublicTokenRecipient(),
            'requested_at' => $request->requested_at?->toIso8601String(),
            'expires_at' => $request->expires_at?->toIso8601String(),
            'first_viewed_at' => $request->first_viewed_at?->toIso8601String(),
            'completed_at' => $request->completed_at?->toIso8601String(),
            'cancelled_at' => $request->cancelled_at?->toIso8601String(),
            'requested_by' => [
                'id' => $request->requested_by,
                'name' => $request->requestedBy?->name,
            ],
            'cancelled_by' => $request->cancelled_by ? [
                'id' => $request->cancelled_by,
                'name' => $request->cancelledBy?->name,
            ] : null,
            'document' => [
                'id' => $document?->id,
                'title' => $document?->title ?? $request->documentInstance?->title_snapshot,
                'employee_id' => $document?->employee_id,
            ],
            'employee' => [
                'id' => $request->employee_id,
                'name' => $request->employee?->name,
                'employee_no' => $request->employee?->employee_no,
            ],
            'company_signatory' => $request->recipient_user_id ? [
                'id' => $request->recipient_user_id,
                'name' => $request->recipientUser?->name ?? $request->recipient_name_snapshot,
            ] : null,
            'source_version' => [
                'id' => $request->source_document_instance_version_id,
                'version' => $request->sourceVersion?->version,
                'checksum_abbrev' => $this->abbreviateChecksum($request->source_checksum_sha256),
            ],
            'result_version' => $request->resultVersion ? [
                'id' => $request->resultVersion->id,
                'version' => $request->resultVersion->version,
                'checksum_abbrev' => $this->abbreviateChecksum($request->result_checksum_sha256),
            ] : null,
            'signed_name' => $request->signed_name,
            'acknowledgement_text_snapshot' => $request->acknowledgement_text_snapshot,
            'respond_url' => $request->isInternalCompanySignatory()
                ? route('organization.documents.recipient-requests.respond', [
                    'recipientRequest' => $request->id,
                ])
                : null,
            'timeline' => $request->events->map(fn ($event) => [
                'event' => $event->event->value,
                'occurred_at' => $event->occurred_at?->toIso8601String(),
                'actor_name' => $event->actor?->name,
            ])->values()->all(),
        ];
    }

    private function abbreviateChecksum(?string $checksum): ?string
    {
        if ($checksum === null || strlen($checksum) < 12) {
            return $checksum;
        }

        return substr($checksum, 0, 8).'…'.substr($checksum, -4);
    }
}
