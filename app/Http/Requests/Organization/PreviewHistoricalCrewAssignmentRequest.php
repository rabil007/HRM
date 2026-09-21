<?php

namespace App\Http\Requests\Organization;

use App\Models\CrewAssignment;
use App\Support\CrewMovements\Historical\HistoricalCrewAssignmentData;
use App\Support\Employees\ActiveCompanyEmployeeRule;
use App\Support\MasterData\ClientAssignmentRules;
use App\Support\Settings\CompanyTimezone;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            'employee_id' => [
                'required',
                'integer',
                ActiveCompanyEmployeeRule::exists($companyId, $this->user()),
            ],
            'vessel_id' => [
                'required',
                'integer',
                Rule::exists('vessels', 'id')->where('company_id', $companyId),
            ],
            'rank_id' => ['required', 'integer', Rule::exists('ranks', 'id')],
            'client_id' => ['nullable', 'integer', Rule::exists('clients', 'id')],
            'joined_vessel_at' => ['required', 'date'],
            'disembarked_at' => ['required', 'date'],
            'mobilisation_at' => ['nullable', 'date'],
            'arrival_at' => ['nullable', 'date'],
            'training_started_at' => ['nullable', 'date'],
            'training_ended_at' => ['nullable', 'date'],
            'ready_to_join_at' => ['nullable', 'date'],
            'post_signoff_standby_at' => ['nullable', 'date'],
            'travel_home_at' => ['nullable', 'date'],
            'assignment_closed_at' => ['nullable', 'date'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ];
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
