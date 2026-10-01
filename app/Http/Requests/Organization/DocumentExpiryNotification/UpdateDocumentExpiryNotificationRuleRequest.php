<?php

namespace App\Http\Requests\Organization\DocumentExpiryNotification;

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

        $rule->loadMissing(['toRecipients:id,document_expiry_notification_rule_id,user_id', 'ccRecipients:id,document_expiry_notification_rule_id,user_id']);

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
