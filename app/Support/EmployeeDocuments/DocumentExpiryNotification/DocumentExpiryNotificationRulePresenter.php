<?php

namespace App\Support\EmployeeDocuments\DocumentExpiryNotification;

use App\Enums\DocumentExpiryNotificationRecipientKind;
use App\Models\DocumentExpiryNotificationRule;
use App\Models\DocumentExpiryNotificationRuleRecipient;
use App\Models\DocumentType;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DocumentExpiryNotificationRulePresenter
{
    /**
     * @return array{
     *     id: int,
     *     name: string,
     *     enabled: bool,
     *     all_document_types: bool,
     *     document_types: list<array{id: int, title: string, is_active: bool}>,
     *     document_types_summary: string,
     *     to: list<array{id: int, kind: string, user_id: int|null, name: string|null, email: string|null, label: string, eligible: bool}>,
     *     cc: list<array{id: int, kind: string, user_id: int|null, name: string|null, email: string|null, label: string, eligible: bool}>,
     *     to_summary: string,
     *     cc_summary: string,
     *     status_label: string
     * }
     */
    public function present(DocumentExpiryNotificationRule $rule): array
    {
        $rule->loadMissing([
            'documentTypes:id,title,is_active',
            'toRecipients.user:id,name,email',
            'ccRecipients.user:id,name,email',
        ]);

        $eligibleUserIds = array_fill_keys(
            app(ResolveDocumentExpiryNotificationRecipients::class)
                ->eligibleUserIdsForCompany(
                    (int) $rule->company_id,
                    $this->configuredUserIds($rule),
                ),
            true,
        );

        $companyMemberUserIds = $this->companyMemberUserIds(
            (int) $rule->company_id,
            $this->configuredUserIds($rule),
        );

        $documentTypes = $rule->all_document_types
            ? []
            : $rule->documentTypes
                ->sortBy('title')
                ->values()
                ->map(fn (DocumentType $type): array => [
                    'id' => (int) $type->id,
                    'title' => (string) $type->title,
                    'is_active' => (bool) $type->is_active,
                ])
                ->all();

        $to = $this->presentRecipients($rule->toRecipients, $eligibleUserIds, $companyMemberUserIds);
        $cc = $this->presentRecipients($rule->ccRecipients, $eligibleUserIds, $companyMemberUserIds);

        return [
            'id' => (int) $rule->id,
            'name' => (string) $rule->name,
            'enabled' => (bool) $rule->enabled,
            'all_document_types' => (bool) $rule->all_document_types,
            'document_types' => $documentTypes,
            'document_types_summary' => $rule->all_document_types
                ? 'All document types'
                : (count($documentTypes) === 1
                    ? $documentTypes[0]['title']
                    : count($documentTypes).' document types'),
            'to' => $to,
            'cc' => $cc,
            'to_summary' => $this->recipientSummary($to),
            'cc_summary' => $this->recipientSummary($cc),
            'status_label' => $rule->enabled ? 'Active' : 'Inactive',
        ];
    }

    /**
     * @param  Collection<int, DocumentExpiryNotificationRule>  $rules
     * @return list<array{
     *     id: int,
     *     name: string,
     *     enabled: bool,
     *     to_summary: string,
     *     cc_summary: string
     * }>
     */
    public function presentForDocumentType(Collection $rules): array
    {
        return $rules
            ->map(function (DocumentExpiryNotificationRule $rule): array {
                $presented = $this->present($rule);

                return [
                    'id' => $presented['id'],
                    'name' => $presented['name'],
                    'enabled' => $presented['enabled'],
                    'to_summary' => $presented['to_summary'],
                    'cc_summary' => $presented['cc_summary'],
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return list<int>
     */
    private function configuredUserIds(DocumentExpiryNotificationRule $rule): array
    {
        return $rule->toRecipients
            ->merge($rule->ccRecipients)
            ->filter(fn (DocumentExpiryNotificationRuleRecipient $recipient): bool => $recipient->user_id !== null)
            ->pluck('user_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  list<int>  $userIds
     * @return array<int, true>
     */
    private function companyMemberUserIds(int $companyId, array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        return DB::table('company_user')
            ->where('company_id', $companyId)
            ->whereIn('user_id', $userIds)
            ->pluck('user_id')
            ->mapWithKeys(fn ($id): array => [(int) $id => true])
            ->all();
    }

    /**
     * @param  Collection<int, DocumentExpiryNotificationRuleRecipient>  $recipients
     * @param  array<int, true>  $eligibleUserIds
     * @param  array<int, true>  $companyMemberUserIds
     * @return list<array{id: int, kind: string, user_id: int|null, name: string|null, email: string|null, label: string, eligible: bool}>
     */
    private function presentRecipients(
        Collection $recipients,
        array $eligibleUserIds,
        array $companyMemberUserIds,
    ): array {
        return $recipients
            ->map(function (DocumentExpiryNotificationRuleRecipient $recipient) use ($eligibleUserIds, $companyMemberUserIds): array {
                $kind = $recipient->recipient_kind instanceof DocumentExpiryNotificationRecipientKind
                    ? $recipient->recipient_kind->value
                    : (string) $recipient->recipient_kind;
                $recipientId = (int) $recipient->id;

                if ($kind === DocumentExpiryNotificationRecipientKind::User->value) {
                    $userId = $recipient->user_id !== null ? (int) $recipient->user_id : null;

                    // User soft-deleted from the platform (FK nullOnDelete) — keep visible, never mail.
                    if ($userId === null) {
                        return [
                            'id' => $recipientId,
                            'kind' => $kind,
                            'user_id' => null,
                            'name' => null,
                            'email' => null,
                            'label' => 'Deleted / unavailable user',
                            'eligible' => false,
                        ];
                    }

                    $belongsToCompany = isset($companyMemberUserIds[$userId]);
                    $eligible = isset($eligibleUserIds[$userId]);

                    // Never expose another company's user profile from a stale foreign ID.
                    if (! $belongsToCompany) {
                        return [
                            'id' => $recipientId,
                            'kind' => $kind,
                            'user_id' => $userId,
                            'name' => null,
                            'email' => null,
                            'label' => 'Unknown user',
                            'eligible' => false,
                        ];
                    }

                    $user = $recipient->user;

                    // Soft-deleted or otherwise unloadable users stay visible but never mail.
                    if (! $user instanceof User) {
                        return [
                            'id' => $recipientId,
                            'kind' => $kind,
                            'user_id' => $userId,
                            'name' => null,
                            'email' => null,
                            'label' => 'Deleted / unavailable user',
                            'eligible' => false,
                        ];
                    }

                    $name = $user->name;
                    $email = $user->email;

                    return [
                        'id' => $recipientId,
                        'kind' => $kind,
                        'user_id' => $userId,
                        'name' => $name,
                        'email' => $email,
                        'label' => $name !== null && $name !== ''
                            ? $name
                            : ($email ?? 'Unknown user'),
                        'eligible' => $eligible,
                    ];
                }

                $email = (string) ($recipient->email ?? '');

                return [
                    'id' => $recipientId,
                    'kind' => $kind,
                    'user_id' => null,
                    'name' => null,
                    'email' => $email,
                    'label' => $email !== '' ? $email : 'External email',
                    'eligible' => true,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  list<array{label: string}>  $recipients
     */
    private function recipientSummary(array $recipients): string
    {
        if ($recipients === []) {
            return '—';
        }

        return collect($recipients)
            ->pluck('label')
            ->filter()
            ->implode(', ');
    }
}
