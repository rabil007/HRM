<?php

namespace App\Http\Requests\Organization;

use App\Models\CrewAssignment;
use App\Support\CrewMovements\Historical\HistoricalCrewAssignmentData;
use App\Support\Employees\HistoricalCompanyEmployeeRule;
use App\Support\Settings\CompanyTimezone;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class PreviewHistoricalCrewAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        if ($user === null) {
            return false;
        }

        return $user->can('createHistorical', CrewAssignment::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $companyId = (int) $this->attributes->get('current_company_id');

        return [
            'employee_id' => [
                'required',
                'integer',
                HistoricalCompanyEmployeeRule::exists($companyId, $this->user()),
            ],
            'vessel_id' => [
                'required',
                'integer',
                Rule::exists('vessels', 'id')->where('company_id', $companyId),
            ],
            'rank_id' => ['required', 'integer', Rule::exists('ranks', 'id')],
            'client_id' => ['nullable', 'integer', Rule::exists('clients', 'id')],
            'joined_vessel_at' => ['nullable', 'date'],
            'disembarked_at' => ['nullable', 'date'],
            'mobilisation_at' => ['nullable', 'date'],
            'mobilisation_start_at' => ['nullable', 'date'],
            'join_standby_at' => ['nullable', 'date'],
            'training_start_at' => ['nullable', 'date'],
            'training_started_at' => ['nullable', 'date'],
            'training_end_at' => ['nullable', 'date'],
            'training_ended_at' => ['nullable', 'date'],
            'travel_home_at' => ['nullable', 'date'],
            'remarks' => ['nullable', 'string', 'max:1000'],
            // Legacy / removed historical inputs are no longer accepted.
            'arrival_at' => ['prohibited'],
            'ready_to_join_at' => ['prohibited'],
            'post_training_join_standby_at' => ['prohibited'],
            'demob_standby_at' => ['prohibited'],
            'post_signoff_standby_at' => ['prohibited'],
            'assignment_closed_at' => ['prohibited'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $movementFields = [
                'mobilisation_at',
                'mobilisation_start_at',
                'join_standby_at',
                'training_start_at',
                'training_started_at',
                'training_end_at',
                'training_ended_at',
                'joined_vessel_at',
                'disembarked_at',
                'travel_home_at',
            ];

            $hasMovement = false;

            foreach ($movementFields as $field) {
                $value = $this->input($field);

                if (is_string($value) && trim($value) !== '') {
                    $hasMovement = true;
                    break;
                }
            }

            if (! $hasMovement) {
                $validator->errors()->add(
                    'dates',
                    'At least one meaningful movement date must be supplied.',
                );
            }
        });
    }

    public function toData(): HistoricalCrewAssignmentData
    {
        $companyId = (int) $this->attributes->get('current_company_id');
        $timezone = CompanyTimezone::forCompanyId($companyId);

        return HistoricalCrewAssignmentData::fromArray(
            data: $this->validated(),
            companyId: $companyId,
            timezone: $timezone,
        );
    }
}
