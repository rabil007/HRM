<?php

namespace App\Http\Requests\Organization\DocumentExpiryNotification;

use App\Models\DocumentType;
use App\Support\EmployeeDocuments\DocumentExpiryNotification\ResolveDocumentExpiryNotificationRecipients;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreDocumentExpiryNotificationRuleRequest extends FormRequest
{
    public const IneligibleRecipientMessage = 'One or more selected recipients are not active members of this company with documents.view access.';

    public const DuplicateRecipientMessage = 'The same recipient cannot appear more than once across TO and CC.';

    public function authorize(): bool
    {
        return (bool) $this->user()?->can('documents.notification-routing.update');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'enabled' => ['required', 'boolean'],
            'all_document_types' => ['required', 'boolean'],
            'document_type_ids' => ['nullable', 'array'],
            'document_type_ids.*' => ['integer'],
            'to_user_ids' => ['nullable', 'array'],
            'to_user_ids.*' => ['integer'],
            'to_emails' => ['nullable', 'array'],
            'to_emails.*' => ['string', 'email:rfc', 'max:255'],
            'cc_user_ids' => ['nullable', 'array'],
            'cc_user_ids.*' => ['integer'],
            'cc_emails' => ['nullable', 'array'],
            'cc_emails.*' => ['string', 'email:rfc', 'max:255'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $companyId = (int) $this->attributes->get('current_company_id');

                $toUserIds = $this->uniqueIds('to_user_ids');
                $ccUserIds = $this->uniqueIds('cc_user_ids');
                $toEmails = $this->normalizedEmails('to_emails');
                $ccEmails = $this->normalizedEmails('cc_emails');

                $this->rejectIneligibleUsers($validator, $companyId, $toUserIds, 'to_user_ids');
                $this->rejectIneligibleUsers($validator, $companyId, $ccUserIds, 'cc_user_ids');

                if (! $this->boolean('all_document_types')) {
                    $documentTypeIds = $this->uniqueIds('document_type_ids');

                    if ($documentTypeIds === []) {
                        $validator->errors()->add(
                            'document_type_ids',
                            'Select at least one document type, or choose all document types.',
                        );
                    } else {
                        $validCount = DocumentType::query()
                            ->whereIn('id', $documentTypeIds)
                            ->where('is_active', true)
                            ->count();

                        if ($validCount !== count($documentTypeIds)) {
                            $validator->errors()->add(
                                'document_type_ids',
                                'One or more selected document types are invalid or inactive.',
                            );
                        }
                    }
                }

                $duplicateUsers = array_intersect($toUserIds, $ccUserIds);

                if ($duplicateUsers !== []) {
                    $validator->errors()->add('cc_user_ids', self::DuplicateRecipientMessage);
                }

                $duplicateEmails = array_intersect($toEmails, $ccEmails);

                if ($duplicateEmails !== []) {
                    $validator->errors()->add('cc_emails', self::DuplicateRecipientMessage);
                }

                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                if ($this->boolean('enabled') && $toUserIds === [] && $toEmails === []) {
                    $validator->errors()->add(
                        'to_user_ids',
                        'At least one TO recipient (user or email) is required for an enabled rule.',
                    );
                }
            },
        ];
    }

    /**
     * @return list<int>
     */
    public function documentTypeIds(): array
    {
        return $this->uniqueIds('document_type_ids');
    }

    /**
     * @return list<int>
     */
    public function toUserIds(): array
    {
        return $this->uniqueIds('to_user_ids');
    }

    /**
     * @return list<string>
     */
    public function toEmails(): array
    {
        return $this->normalizedEmails('to_emails');
    }

    /**
     * @return list<int>
     */
    public function ccUserIds(): array
    {
        return $this->uniqueIds('cc_user_ids');
    }

    /**
     * @return list<string>
     */
    public function ccEmails(): array
    {
        return $this->normalizedEmails('cc_emails');
    }

    /**
     * @return list<int>
     */
    private function uniqueIds(string $key): array
    {
        return array_values(array_unique(array_map('intval', (array) $this->input($key, []))));
    }

    /**
     * @return list<string>
     */
    private function normalizedEmails(string $key): array
    {
        return collect((array) $this->input($key, []))
            ->map(fn ($email): string => strtolower(trim((string) $email)))
            ->filter(fn (string $email): bool => $email !== '')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  list<int>  $userIds
     */
    private function rejectIneligibleUsers(Validator $validator, int $companyId, array $userIds, string $field): void
    {
        if ($userIds === []) {
            return;
        }

        $eligibleIds = app(ResolveDocumentExpiryNotificationRecipients::class)
            ->eligibleUserIdsForCompany($companyId, $userIds);

        if (count($eligibleIds) !== count($userIds)) {
            $validator->errors()->add($field, self::IneligibleRecipientMessage);
        }
    }
}
