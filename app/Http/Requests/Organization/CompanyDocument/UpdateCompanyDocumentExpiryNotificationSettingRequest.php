<?php

namespace App\Http\Requests\Organization\CompanyDocument;

use App\Models\Company;
use App\Support\CompanyDocuments\CompanyDocumentAccess;
use App\Support\CompanyDocuments\ResolveCompanyDocumentExpiryRecipients;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateCompanyDocumentExpiryNotificationSettingRequest extends FormRequest
{
    public const IneligibleRecipientMessage = 'One or more selected recipients are not active members of this company.';

    public function authorize(): bool
    {
        $user = $this->user();
        $company = $this->company();

        if ($user === null || $company === null) {
            return false;
        }

        app(CompanyDocumentAccess::class)->authorize(
            $user,
            $company,
            CompanyDocumentAccess::Abilities['manage_notifications'],
        );

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
            'to_user_ids' => ['nullable', 'array'],
            'to_user_ids.*' => ['integer'],
            'cc_user_ids' => ['nullable', 'array'],
            'cc_user_ids.*' => ['integer'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $company = $this->company();

                if ($company === null) {
                    return;
                }

                $toUserIds = $this->uniqueIds('to_user_ids');
                $ccUserIds = $this->uniqueIds('cc_user_ids');

                $this->rejectIneligible($validator, $company, $toUserIds, 'to_user_ids');
                $this->rejectIneligible($validator, $company, $ccUserIds, 'cc_user_ids');

                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                if ($this->boolean('enabled') && $toUserIds === []) {
                    $validator->errors()->add(
                        'to_user_ids',
                        'At least one valid TO recipient is required to enable notifications.',
                    );
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'to_user_ids.*.integer' => self::IneligibleRecipientMessage,
            'cc_user_ids.*.integer' => self::IneligibleRecipientMessage,
        ];
    }

    public function company(): ?Company
    {
        $company = $this->route('company');

        return $company instanceof Company ? $company : null;
    }

    /**
     * @return list<int>
     */
    public function toUserIds(): array
    {
        return $this->uniqueIds('to_user_ids');
    }

    /**
     * @return list<int>
     */
    public function ccUserIds(): array
    {
        return $this->uniqueIds('cc_user_ids');
    }

    /**
     * @return list<int>
     */
    private function uniqueIds(string $key): array
    {
        return array_values(array_unique(array_map('intval', (array) $this->input($key, []))));
    }

    /**
     * @param  list<int>  $userIds
     */
    private function rejectIneligible(Validator $validator, Company $company, array $userIds, string $field): void
    {
        if ($userIds === []) {
            return;
        }

        $eligibleIds = app(ResolveCompanyDocumentExpiryRecipients::class)
            ->eligibleUserIdsForCompany($company->id, $userIds);

        if (count($eligibleIds) !== count($userIds)) {
            $validator->errors()->add($field, self::IneligibleRecipientMessage);
        }
    }
}
