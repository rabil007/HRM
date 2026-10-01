<?php

namespace App\Http\Requests\Organization\DocumentExpiryNotification;

use App\Enums\DocumentExpiryNotificationDeliveryType;
use App\Enums\DocumentExpiryNotificationRecipientKind;
use App\Models\DocumentExpiryNotificationRule;

class UpdateDocumentExpiryNotificationRuleRequest extends StoreDocumentExpiryNotificationRuleRequest
{
    /**
     * @return list<int>
     */
    protected function grandfatheredUserIds(): array
    {
        $rule = $this->routeRule();

        if ($rule === null) {
            return [];
        }

        $rule->loadMissing(['toRecipients:id,rule_id,user_id', 'ccRecipients:id,rule_id,user_id']);

        return $rule->toRecipients
            ->merge($rule->ccRecipients)
            ->pluck('user_id')
            ->filter()
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return list<int>
     */
    protected function grandfatheredOrphanedRecipientIds(string $deliveryType): array
    {
        $rule = $this->routeRule();

        if ($rule === null) {
            return [];
        }

        $rule->loadMissing(['toRecipients', 'ccRecipients']);

        $recipients = $deliveryType === DocumentExpiryNotificationDeliveryType::Cc->value
            ? $rule->ccRecipients
            : $rule->toRecipients;

        return $recipients
            ->filter(function ($recipient) {
                $kind = $recipient->recipient_kind instanceof DocumentExpiryNotificationRecipientKind
                    ? $recipient->recipient_kind->value
                    : (string) $recipient->recipient_kind;

                return $kind === DocumentExpiryNotificationRecipientKind::User->value
                    && $recipient->user_id === null;
            })
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();
    }

    /**
     * @return list<int>
     */
    protected function grandfatheredDocumentTypeIds(): array
    {
        $rule = $this->routeRule();

        if ($rule === null || $rule->all_document_types) {
            return [];
        }

        return $rule->documentTypes()
            ->pluck('document_types.id')
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();
    }

    private function routeRule(): ?DocumentExpiryNotificationRule
    {
        $rule = $this->route('rule');

        return $rule instanceof DocumentExpiryNotificationRule ? $rule : null;
    }
}
