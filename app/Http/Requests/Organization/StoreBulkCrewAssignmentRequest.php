<?php

namespace App\Http\Requests\Organization;

use App\Support\Employees\ActiveCompanyEmployeeRule;
use App\Support\MasterData\ClientAssignmentRules;
use App\Support\Settings\CompanyTimezone;
use Carbon\Carbon;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreBulkCrewAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        if ($user === null) {
            return false;
        }

        return $user->can('crew_operations.assignments.create')
            && $user->can('crew_operations.movements.perform');
    }

    protected function prepareForValidation(): void
    {
        $companyId = (int) $this->attributes->get('current_company_id');
        $vesselId = $this->input('vessel_id');
        $clientId = $this->input('client_id');
        $merge = [];

        if (($clientId === null || $clientId === '')
            && $vesselId !== null
            && $vesselId !== ''
            && $companyId > 0) {
            $resolved = ClientAssignmentRules::resolveClientIdFromVessel($companyId, (int) $vesselId);

            if ($resolved !== null) {
                $merge['client_id'] = $resolved;
            }
        }

        if ($merge !== []) {
            $this->merge($merge);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $companyId = (int) $this->attributes->get('current_company_id');

        return [
            'client_id' => ['nullable', 'integer', Rule::exists('clients', 'id')->where('is_active', true)],
            'vessel_id' => ['nullable', 'integer', Rule::exists('vessels', 'id')->where('company_id', $companyId)->where('is_active', true)],
            'planned_join_at' => ['nullable', 'date'],
            'remarks' => ['nullable', 'string', 'max:1000'],
            'crew' => ['required', 'array', 'min:1'],
            'crew.*.employee_id' => [
                'required',
                'integer',
                'distinct',
                ActiveCompanyEmployeeRule::exists($companyId),
            ],
            'crew.*.rank_id' => ['nullable', 'integer', Rule::exists('ranks', 'id')->where('is_active', true)],
            'crew.*.planned_arrival_at' => ['nullable', 'date'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'crew.required' => 'Add at least one crew member.',
            'crew.min' => 'Add at least one crew member.',
            'crew.*.employee_id.required' => 'Select an employee for this row.',
            'crew.*.employee_id.distinct' => 'This employee has already been added to the batch.',
            'crew.*.employee_id.exists' => 'The selected employee is invalid, inactive, or does not belong to this company.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'crew.*.employee_id' => 'employee',
            'crew.*.rank_id' => 'rank',
            'crew.*.planned_arrival_at' => 'arrival date',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $companyId = (int) $this->attributes->get('current_company_id');
            $clientId = $this->input('client_id');
            $vesselId = $this->input('vessel_id');

            ClientAssignmentRules::vesselBelongsToClient(
                $validator,
                $companyId,
                $clientId !== null && $clientId !== '' ? (int) $clientId : null,
                $vesselId !== null && $vesselId !== '' ? (int) $vesselId : null,
            );

            $plannedJoin = $this->input('planned_join_at');
            $crew = (array) $this->input('crew', []);

            if ($plannedJoin !== null && $plannedJoin !== '') {
                $timezone = CompanyTimezone::forCompanyId($companyId);
                $joinDate = Carbon::parse($plannedJoin, $timezone)->toDateString();

                foreach ($crew as $index => $row) {
                    $arrival = $row['planned_arrival_at'] ?? null;
                    if ($arrival !== null && $arrival !== '') {
                        $arrivalDate = Carbon::parse($arrival, $timezone)->toDateString();
                        if ($arrivalDate > $joinDate) {
                            $validator->errors()->add("crew.{$index}.planned_arrival_at", 'Arrival Date cannot be after Expected Vessel Join.');
                        }
                    }
                }
            }
        });
    }
}
