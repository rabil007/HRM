<?php

namespace App\Support\EmployeeDocuments\DocumentExpiryNotification;

use App\Enums\DocumentExpiryNotificationRecipientKind;
use App\Models\DocumentExpiryNotificationRule;
use App\Models\DocumentExpiryNotificationRuleRecipient;
use App\Models\DocumentType;
use Illuminate\Support\Collection;

class DocumentExpiryNotificationRulePresenter
{
    /**
     * @return array{
     *     id: int,
     *     name: string,
     *     enabled: bool,
     *     all_document_types: bool,
     *     document_types: list<array{id: int, title: string}>,
     *     document_types_summary: string,
     *     to: list<array{kind: string, user_id: int|null, name: string|null, email: string|null, label: string}>,
     *     cc: list<array{kind: string, user_id: int|null, name: string|null, email: string|null, label: string}>,
     *     to_summary: string,
     *     cc_summary: string,
     *     status_label: string
     * }
     */
    public function present(DocumentExpiryNotificationRule $rule): array
    {
        $rule->loadMissing([
            'documentTypes:id,title',
            'toRecipients.user:id,name,email',
            'ccRecipients.user:id,name,email',
        ]);

        $documentTypes = $rule->all_document_types
            ? []
            : $rule->documentTypes
                ->sortBy('title')
                ->values()
                ->map(fn (DocumentType $type): array => [
                    'id' => (int) $type->id,
                    'title' => (string) $type->title,
                ])
                ->all();

        $to = $this->presentRecipients($rule->toRecipients);
        $cc = $this->presentRecipients($rule->ccRecipients);

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
     * @param  Collection<int, DocumentExpiryNotificationRuleRecipient>  $recipients
     * @return list<array{kind: string, user_id: int|null, name: string|null, email: string|null, label: string}>
     */
    private function presentRecipients(Collection $recipients): array
    {
        return $recipients
            ->map(function (DocumentExpiryNotificationRuleRecipient $recipient): array {
                $kind = $recipient->recipient_kind instanceof DocumentExpiryNotificationRecipientKind
                    ? $recipient->recipient_kind->value
                    : (string) $recipient->recipient_kind;

                if ($kind === DocumentExpiryNotificationRecipientKind::User->value) {
                    $name = $recipient->user?->name;
                    $email = $recipient->user?->email;

                    return [
                        'kind' => $kind,
                        'user_id' => $recipient->user_id !== null ? (int) $recipient->user_id : null,
                        'name' => $name,
                        'email' => $email,
                        'label' => $name !== null && $name !== ''
                            ? $name
                            : ($email ?? 'Unknown user'),
                    ];
                }

                $email = (string) ($recipient->email ?? '');

                return [
                    'kind' => $kind,
                    'user_id' => null,
                    'name' => null,
                    'email' => $email,
                    'label' => $email !== '' ? $email : 'External email',
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
