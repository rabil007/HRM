<?php

namespace App\Support\EmployeeDocuments\DocumentExpiryNotification;

use App\Enums\DocumentExpiryNotificationDeliveryType;
use App\Enums\DocumentExpiryNotificationRecipientKind;
use App\Models\DocumentExpiryNotificationRule;
use App\Models\DocumentExpiryNotificationRuleRecipient;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

class UpsertDocumentExpiryNotificationRule
{
    /**
     * @param  list<int>  $documentTypeIds
     * @param  list<int>  $toUserIds
     * @param  list<string>  $toEmails
     * @param  list<int>  $ccUserIds
     * @param  list<string>  $ccEmails
     * @param  list<int>  $toOrphanedRecipientIds
     * @param  list<int>  $ccOrphanedRecipientIds
     */
    public function handle(
        DocumentExpiryNotificationRule $rule,
        int $companyId,
        string $name,
        bool $enabled,
        bool $allDocumentTypes,
        array $documentTypeIds,
        array $toUserIds,
        array $toEmails,
        array $ccUserIds,
        array $ccEmails,
        User $actor,
        bool $isCreate = false,
        array $toOrphanedRecipientIds = [],
        array $ccOrphanedRecipientIds = [],
    ): DocumentExpiryNotificationRule {
        $documentTypeIds = $allDocumentTypes
            ? []
            : array_values(array_unique(array_map('intval', $documentTypeIds)));

        $toUserIds = array_values(array_unique(array_map('intval', $toUserIds)));
        $ccUserIds = array_values(array_diff(array_unique(array_map('intval', $ccUserIds)), $toUserIds));

        $toEmails = $this->normalizeEmails($toEmails);
        $ccEmails = array_values(array_diff(
            $this->normalizeEmails($ccEmails),
            array_map(strtolower(...), $toEmails),
        ));

        $toOrphanedRecipientIds = array_values(array_unique(array_map('intval', $toOrphanedRecipientIds)));
        $ccOrphanedRecipientIds = array_values(array_unique(array_map('intval', $ccOrphanedRecipientIds)));

        // Remove CC users whose live email collides with a TO manual email (case-insensitive).
        $toEmailSet = array_map(strtolower(...), $toEmails);

        return DB::transaction(function () use (
            $rule,
            $companyId,
            $name,
            $enabled,
            $allDocumentTypes,
            $documentTypeIds,
            $toUserIds,
            $toEmails,
            $ccUserIds,
            $ccEmails,
            $toOrphanedRecipientIds,
            $ccOrphanedRecipientIds,
            $toEmailSet,
            $actor,
            $isCreate,
        ): DocumentExpiryNotificationRule {
            $rule->load([
                'documentTypes:id,title',
                'toRecipients.user:id,name,email',
                'ccRecipients.user:id,name,email',
            ]);

            $before = $this->snapshot($rule);

            $orphanedToKeep = $this->orphanedRecipientsToPreserve(
                $rule->toRecipients,
                $toOrphanedRecipientIds,
            );
            $orphanedCcKeep = $this->orphanedRecipientsToPreserve(
                $rule->ccRecipients,
                $ccOrphanedRecipientIds,
            );

            $rule->fill([
                'company_id' => $companyId,
                'name' => $name,
                'enabled' => $enabled,
                'all_document_types' => $allDocumentTypes,
                'updated_by' => $actor->id,
            ]);

            if ($isCreate || ! $rule->exists) {
                $rule->created_by = $actor->id;
            }

            $rule->save();

            $rule->documentTypes()->sync($documentTypeIds);

            $rule->recipients()->delete();

            $now = now();
            $inserts = [];

            foreach ($toUserIds as $userId) {
                $inserts[] = [
                    'rule_id' => $rule->id,
                    'company_id' => $companyId,
                    'recipient_kind' => DocumentExpiryNotificationRecipientKind::User->value,
                    'user_id' => $userId,
                    'email' => null,
                    'delivery_type' => DocumentExpiryNotificationDeliveryType::To->value,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            foreach ($toEmails as $email) {
                $inserts[] = [
                    'rule_id' => $rule->id,
                    'company_id' => $companyId,
                    'recipient_kind' => DocumentExpiryNotificationRecipientKind::Email->value,
                    'user_id' => null,
                    'email' => $email,
                    'delivery_type' => DocumentExpiryNotificationDeliveryType::To->value,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            foreach ($orphanedToKeep as $_) {
                $inserts[] = [
                    'rule_id' => $rule->id,
                    'company_id' => $companyId,
                    'recipient_kind' => DocumentExpiryNotificationRecipientKind::User->value,
                    'user_id' => null,
                    'email' => null,
                    'delivery_type' => DocumentExpiryNotificationDeliveryType::To->value,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            $ccUsers = User::query()
                ->whereIn('id', $ccUserIds)
                ->get(['id', 'email'])
                ->keyBy('id');

            foreach ($ccUserIds as $userId) {
                $userEmail = strtolower(trim((string) ($ccUsers->get($userId)?->email ?? '')));

                if ($userEmail !== '' && in_array($userEmail, $toEmailSet, true)) {
                    continue;
                }

                $inserts[] = [
                    'rule_id' => $rule->id,
                    'company_id' => $companyId,
                    'recipient_kind' => DocumentExpiryNotificationRecipientKind::User->value,
                    'user_id' => $userId,
                    'email' => null,
                    'delivery_type' => DocumentExpiryNotificationDeliveryType::Cc->value,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            foreach ($ccEmails as $email) {
                $inserts[] = [
                    'rule_id' => $rule->id,
                    'company_id' => $companyId,
                    'recipient_kind' => DocumentExpiryNotificationRecipientKind::Email->value,
                    'user_id' => null,
                    'email' => $email,
                    'delivery_type' => DocumentExpiryNotificationDeliveryType::Cc->value,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            foreach ($orphanedCcKeep as $_) {
                $inserts[] = [
                    'rule_id' => $rule->id,
                    'company_id' => $companyId,
                    'recipient_kind' => DocumentExpiryNotificationRecipientKind::User->value,
                    'user_id' => null,
                    'email' => null,
                    'delivery_type' => DocumentExpiryNotificationDeliveryType::Cc->value,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            if ($inserts !== []) {
                DocumentExpiryNotificationRuleRecipient::query()->insert($inserts);
            }

            $rule->load([
                'documentTypes:id,title',
                'toRecipients.user:id,name,email',
                'ccRecipients.user:id,name,email',
            ]);

            $after = $this->snapshot($rule);

            $this->logChange($rule, $companyId, $actor, $before, $after, $isCreate);

            return $rule;
        });
    }

    /**
     * @param  Collection<int, DocumentExpiryNotificationRuleRecipient>|iterable<int, DocumentExpiryNotificationRuleRecipient>  $recipients
     * @param  list<int>  $requestedIds
     * @return list<int>
     */
    private function orphanedRecipientsToPreserve(iterable $recipients, array $requestedIds): array
    {
        if ($requestedIds === []) {
            return [];
        }

        $requested = array_fill_keys($requestedIds, true);

        return collect($recipients)
            ->filter(function (DocumentExpiryNotificationRuleRecipient $recipient) use ($requested): bool {
                $kind = $recipient->recipient_kind instanceof DocumentExpiryNotificationRecipientKind
                    ? $recipient->recipient_kind->value
                    : (string) $recipient->recipient_kind;

                return $kind === DocumentExpiryNotificationRecipientKind::User->value
                    && $recipient->user_id === null
                    && isset($requested[(int) $recipient->id]);
            })
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();
    }

    /**
     * @return array{
     *     name: string,
     *     enabled: bool,
     *     all_document_types: bool,
     *     document_type_ids: list<int>,
     *     to: list<array{kind: string, user_id: int|null, email: string|null, name: string|null}>,
     *     cc: list<array{kind: string, user_id: int|null, email: string|null, name: string|null}>
     * }
     */
    private function snapshot(DocumentExpiryNotificationRule $rule): array
    {
        return [
            'name' => (string) $rule->name,
            'enabled' => (bool) $rule->enabled,
            'all_document_types' => (bool) $rule->all_document_types,
            'document_type_ids' => $rule->documentTypes
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->values()
                ->all(),
            'to' => $this->snapshotRecipients($rule->toRecipients),
            'cc' => $this->snapshotRecipients($rule->ccRecipients),
        ];
    }

    /**
     * @param  Collection<int, DocumentExpiryNotificationRuleRecipient>|iterable<int, DocumentExpiryNotificationRuleRecipient>  $recipients
     * @return list<array{kind: string, user_id: int|null, email: string|null, name: string|null}>
     */
    private function snapshotRecipients(iterable $recipients): array
    {
        return collect($recipients)
            ->map(function (DocumentExpiryNotificationRuleRecipient $recipient): array {
                $kind = $recipient->recipient_kind instanceof DocumentExpiryNotificationRecipientKind
                    ? $recipient->recipient_kind->value
                    : (string) $recipient->recipient_kind;

                return [
                    'kind' => $kind,
                    'user_id' => $recipient->user_id !== null ? (int) $recipient->user_id : null,
                    'email' => $recipient->email,
                    'name' => $recipient->user?->name,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    private function logChange(
        DocumentExpiryNotificationRule $rule,
        int $companyId,
        User $actor,
        array $before,
        array $after,
        bool $isCreate,
    ): void {
        if (! $isCreate && $before === $after) {
            return;
        }

        $event = $isCreate
            ? 'document_expiry_notification_rule_created'
            : 'document_expiry_notification_rule_updated';

        activity()
            ->useLog('documents')
            ->causedBy($actor)
            ->event($event)
            ->performedOn($rule)
            ->withProperties([
                'company_id' => $companyId,
                'before' => $isCreate ? null : $before,
                'after' => $after,
            ])
            ->tap(function (Activity $activity) use ($companyId): void {
                $activity->company_id = $companyId;
            })
            ->log($isCreate
                ? 'Employee document expiry notification rule created'
                : 'Employee document expiry notification rule updated');
    }

    /**
     * @param  list<string>  $emails
     * @return list<string>
     */
    private function normalizeEmails(array $emails): array
    {
        return collect($emails)
            ->map(fn (string $email): string => strtolower(trim($email)))
            ->filter(fn (string $email): bool => $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false)
            ->unique()
            ->values()
            ->all();
    }
}
