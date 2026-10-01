<?php

use App\Enums\DocumentExpiryNotificationDeliveryType;
use App\Enums\DocumentExpiryNotificationRecipientKind;
use App\Models\DocumentExpiryNotificationRule;
use App\Models\DocumentExpiryNotificationRuleRecipient;
use App\Models\EmailTemplate;
use App\Models\User;

/**
 * @param  array{to_preset?: string|null, cc_preset?: string|null, enabled?: bool}  $overrides
 */
function configureDocumentExpiryAlertTemplate(array $overrides = []): void
{
    $attributes = array_merge([
        'label' => 'Document expiry alert',
        'category' => 'notification',
        'to_preset' => null,
        'cc_preset' => null,
        'subject' => 'Document Expiry Alert - Next 30 Days',
        'body_html' => 'Automated expiry summary email.',
        'is_default' => true,
        'enabled' => true,
        'sort_order' => 0,
    ], $overrides);

    EmailTemplate::query()->updateOrCreate(
        ['slug' => 'document_expiry_alert'],
        $attributes,
    );
}

/**
 * Create a company-scoped employee document expiry notification routing rule.
 *
 * @param  array{
 *     name?: string,
 *     enabled?: bool,
 *     all_document_types?: bool,
 *     document_type_ids?: list<int>,
 *     to_user_ids?: list<int>,
 *     to_emails?: list<string>,
 *     cc_user_ids?: list<int>,
 *     cc_emails?: list<string>
 * }  $overrides
 */
function createDocumentExpiryNotificationRule(int $companyId, array $overrides = []): DocumentExpiryNotificationRule
{
    $rule = DocumentExpiryNotificationRule::query()->create([
        'company_id' => $companyId,
        'name' => $overrides['name'] ?? 'Test routing rule',
        'enabled' => $overrides['enabled'] ?? true,
        'all_document_types' => $overrides['all_document_types'] ?? true,
        'created_by' => null,
        'updated_by' => null,
    ]);

    $documentTypeIds = $overrides['document_type_ids'] ?? [];

    if (($overrides['all_document_types'] ?? true) === false && $documentTypeIds !== []) {
        $rule->documentTypes()->sync($documentTypeIds);
    }

    $now = now();
    $inserts = [];

    foreach (($overrides['to_user_ids'] ?? []) as $userId) {
        $inserts[] = [
            'rule_id' => $rule->id,
            'company_id' => $companyId,
            'recipient_kind' => DocumentExpiryNotificationRecipientKind::User->value,
            'user_id' => (int) $userId,
            'email' => null,
            'delivery_type' => DocumentExpiryNotificationDeliveryType::To->value,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    $toEmails = array_key_exists('to_emails', $overrides)
        ? $overrides['to_emails']
        : (array_key_exists('to_user_ids', $overrides) ? [] : ['hr@example.com']);

    foreach ($toEmails as $email) {
        $inserts[] = [
            'rule_id' => $rule->id,
            'company_id' => $companyId,
            'recipient_kind' => DocumentExpiryNotificationRecipientKind::Email->value,
            'user_id' => null,
            'email' => strtolower(trim((string) $email)),
            'delivery_type' => DocumentExpiryNotificationDeliveryType::To->value,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    foreach (($overrides['cc_user_ids'] ?? []) as $userId) {
        $inserts[] = [
            'rule_id' => $rule->id,
            'company_id' => $companyId,
            'recipient_kind' => DocumentExpiryNotificationRecipientKind::User->value,
            'user_id' => (int) $userId,
            'email' => null,
            'delivery_type' => DocumentExpiryNotificationDeliveryType::Cc->value,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    $ccEmails = array_key_exists('cc_emails', $overrides)
        ? $overrides['cc_emails']
        : (array_key_exists('cc_user_ids', $overrides) ? [] : ['manager@example.com']);

    foreach ($ccEmails as $email) {
        $inserts[] = [
            'rule_id' => $rule->id,
            'company_id' => $companyId,
            'recipient_kind' => DocumentExpiryNotificationRecipientKind::Email->value,
            'user_id' => null,
            'email' => strtolower(trim((string) $email)),
            'delivery_type' => DocumentExpiryNotificationDeliveryType::Cc->value,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    if ($inserts !== []) {
        DocumentExpiryNotificationRuleRecipient::query()->insert($inserts);
    }

    return $rule->fresh([
        'documentTypes',
        'toRecipients.user',
        'ccRecipients.user',
    ]) ?? $rule;
}

/**
 * Attach an active company membership so a user can be selected as a routing recipient.
 */
function attachActiveCompanyMember(User $user, int $companyId): void
{
    if ($user->companies()->whereKey($companyId)->exists()) {
        $user->companies()->updateExistingPivot($companyId, ['status' => 'active']);

        return;
    }

    $user->companies()->attach($companyId, ['status' => 'active']);
}
