<?php

namespace App\Support\Documents\RecipientRequests;

use App\Enums\DocumentRecipientRequestDeliveryPurpose;
use App\Enums\DocumentRecipientRequestDeliveryStatus;
use App\Models\DocumentRecipientRequest;
use App\Models\DocumentRecipientRequestDelivery;
use App\Support\Documents\RecipientRequests\Automation\DocumentRecipientAutomationPolicy;

final class DocumentRecipientRequestPresenter
{
    public function __construct(
        private DocumentRecipientAutomationPolicy $automationPolicy = new DocumentRecipientAutomationPolicy,
    ) {}

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
            'signingFlow:id,preset_name_snapshot',
            'deliveries',
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
            'signing_flow_id' => $request->document_signing_flow_id,
            'signing_step_sequence' => $request->signing_step_sequence,
            'signing_step_label' => $request->signing_step_label_snapshot,
            'signature_slot_key' => $request->signature_slot_key,
            'signing_preset_name' => $request->signingFlow?->preset_name_snapshot,
            'email_delivery' => $this->emailDeliverySummary($request),
            'reminder_summary' => $this->reminderSummary($request),
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
            'assigned_signer' => $request->recipient_user_id ? [
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
            'respond_url' => $request->isInternalSigner() && $request->isAwaitingAction()
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
            'deliveries',
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
            'signing_step_label' => $request->signing_step_label_snapshot,
            'signature_slot_key' => $request->signature_slot_key,
            'signing_step_sequence' => $request->signing_step_sequence,
            'email_delivery' => $this->emailDeliverySummary($request),
            'reminder_summary' => $this->reminderSummary($request),
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
            'assigned_signer' => $request->recipient_user_id ? [
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
            'respond_url' => $request->isInternalSigner()
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

    /**
     * @return array{
     *     enabled: bool,
     *     days_before_expiry: list<int>,
     *     next_reminder_at: string|null
     * }|null
     */
    public function reminderSummary(DocumentRecipientRequest $request): ?array
    {
        $snapshot = $request->reminder_policy_snapshot;

        if (! is_array($snapshot)) {
            return null;
        }

        $enabled = ($snapshot['enabled'] ?? false) === true;
        $days = $this->automationPolicy->normalizeDays($snapshot['days_before_expiry'] ?? []);

        $consumedKeys = [];

        if ($request->relationLoaded('deliveries')) {
            $consumedKeys = $request->deliveries
                ->where('purpose', DocumentRecipientRequestDeliveryPurpose::Reminder)
                ->pluck('automation_key')
                ->filter()
                ->values()
                ->all();
        }

        $nextReminder = $enabled && $request->isAwaitingAction()
            ? $this->automationPolicy->nextReminderAt($request, $consumedKeys)
            : null;

        return [
            'enabled' => $enabled,
            'days_before_expiry' => $days,
            'next_reminder_at' => $nextReminder?->toIso8601String(),
        ];
    }

    /**
     * @return array{
     *     status: string,
     *     status_label: string,
     *     purpose: string,
     *     purpose_label: string,
     *     last_sent_at: string|null,
     *     can_resend: bool
     * }|null
     */
    public function emailDeliverySummary(DocumentRecipientRequest $request): ?array
    {
        if (! $request->relationLoaded('deliveries')) {
            $request->load('deliveries');
        }

        /** @var DocumentRecipientRequestDelivery|null $latest */
        $latest = $request->deliveries->sortByDesc('delivery_sequence')->first();

        if (! $latest instanceof DocumentRecipientRequestDelivery) {
            return null;
        }

        $purpose = $latest->purpose;
        $statusLabel = match (true) {
            $purpose === DocumentRecipientRequestDeliveryPurpose::Reminder
                && $latest->status === DocumentRecipientRequestDeliveryStatus::Sent => 'Email reminder sent',
            $purpose === DocumentRecipientRequestDeliveryPurpose::Reminder
                && $latest->status === DocumentRecipientRequestDeliveryStatus::Failed => 'Email reminder failed',
            $purpose === DocumentRecipientRequestDeliveryPurpose::Reminder
                && $latest->status === DocumentRecipientRequestDeliveryStatus::Suppressed => 'Email reminder unavailable',
            $purpose === DocumentRecipientRequestDeliveryPurpose::Reminder
                && $latest->status === DocumentRecipientRequestDeliveryStatus::Queued => 'Email reminder queued',
            default => $latest->status->label(),
        };

        return [
            'status' => $latest->status->value,
            'status_label' => $statusLabel,
            'purpose' => $purpose->value,
            'purpose_label' => $purpose->label(),
            'last_sent_at' => $latest->sent_at?->toIso8601String(),
            'can_resend' => $request->isAwaitingAction(),
        ];
    }
}
