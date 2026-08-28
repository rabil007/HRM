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
        ]);

        $document = $request->documentInstance?->employeeDocument;

        return [
            'id' => $request->id,
            'action' => $request->action->value,
            'action_label' => $request->action->label(),
            'status' => $request->status->value,
            'status_label' => $request->status->label(),
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
                'name' => $request->recipient_name_snapshot,
                'employee_no' => $request->employee?->employee_no,
            ],
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
            'events.actor:id,name',
        ]);

        $document = $request->documentInstance?->employeeDocument;

        return [
            'id' => $request->id,
            'action' => $request->action->value,
            'action_label' => $request->action->label(),
            'status' => $request->status->value,
            'status_label' => $request->status->label(),
            'recipient_name' => $request->recipient_name_snapshot,
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
                'name' => $request->recipient_name_snapshot,
                'employee_no' => $request->employee?->employee_no,
            ],
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
