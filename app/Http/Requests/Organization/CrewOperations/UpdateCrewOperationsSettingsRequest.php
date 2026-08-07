<?php

namespace App\Http\Requests\Organization\CrewOperations;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCrewOperationsSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    protected function prepareForValidation(): void
    {
        foreach ([
            'sync_sea_service',
            'notifications_enabled',
            'alert_signoff_overdue',
            'alert_signoff_no_relief',
            'alert_relief_not_ready',
            'alert_current_manning_gap',
            'alert_projected_manning_gap',
        ] as $booleanField) {
            if ($this->has($booleanField)) {
                $this->merge([
                    $booleanField => filter_var(
                        $this->input($booleanField),
                        FILTER_VALIDATE_BOOLEAN,
                        FILTER_NULL_ON_FAILURE,
                    ) ?? false,
                ]);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $companyId = (int) $this->attributes->get('current_company_id');

        return [
            'pool_department_ids' => ['nullable', 'array'],
            'pool_department_ids.*' => [
                'integer',
                Rule::exists('departments', 'id')->where(fn ($query) => $query
                    ->where('company_id', $companyId)
                    ->where('status', 'active')),
            ],
            'max_home_days' => ['required', 'integer', 'min:0'],
            'sync_sea_service' => ['required', 'boolean'],
            'notifications_enabled' => ['required', 'boolean'],
            'notification_recipient_user_ids' => ['nullable', 'array'],
            'notification_recipient_user_ids.*' => ['integer'],
            'alert_signoff_overdue' => ['required', 'boolean'],
            'alert_signoff_no_relief' => ['required', 'boolean'],
            'alert_relief_not_ready' => ['required', 'boolean'],
            'alert_current_manning_gap' => ['required', 'boolean'],
            'alert_projected_manning_gap' => ['required', 'boolean'],
        ];
    }
}
