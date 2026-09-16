<?php

namespace App\Http\Requests\Organization;

use App\Enums\CrewAssignmentSubmissionIntent;
use App\Support\Employees\ActiveCompanyEmployeeRule;
use App\Support\MasterData\ClientAssignmentRules;
use App\Support\Settings\CompanyTimezone;
use Carbon\Carbon;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreCrewAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        if ($user === null) {
            return false;
        }

        if (! $user->can('crew_operations.assignments.create')) {
            return false;
        }

        if ($this->submissionIntent() === CrewAssignmentSubmissionIntent::Start) {
            return $user->can('crew_operations.movements.perform');
        }

        return true;
    }

    protected function prepareForValidation(): void
    {
        $companyId = (int) $this->attributes->get('current_company_id');
        $vesselId = $this->input('vessel_id');
        $clientId = $this->input('client_id');
        $intent = $this->input('submission_intent');
        $merge = [];

        if ($intent === null || $intent === '') {
            $intent = CrewAssignmentSubmissionIntent::Draft->value;
            $merge['submission_intent'] = $intent;
        }

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
            'submission_intent' => ['required', 'string', Rule::in(CrewAssignmentSubmissionIntent::values())],
            'employee_id' => [
                'required',
                'integer',
                ActiveCompanyEmployeeRule::exists($companyId),
            ],
            'rank_id' => ['nullable', 'integer', Rule::exists('ranks', 'id')->where('is_active', true)],
            'client_id' => ['nullable', 'integer', Rule::exists('clients', 'id')->where('is_active', true)],
            'vessel_id' => ['nullable', 'integer', Rule::exists('vessels', 'id')->where('company_id', $companyId)->where('is_active', true)],
            'planned_arrival_at' => ['nullable', 'date'],
            'planned_join_at' => ['nullable', 'date'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [];
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

            $plannedArrival = $this->input('planned_arrival_at');
            $plannedJoin = $this->input('planned_join_at');

            if ($plannedArrival !== null && $plannedArrival !== '' && $plannedJoin !== null && $plannedJoin !== '') {
                $timezone = CompanyTimezone::forCompanyId($companyId);
                $arrivalDate = Carbon::parse($plannedArrival, $timezone)->toDateString();
                $joinDate = Carbon::parse($plannedJoin, $timezone)->toDateString();

                if ($arrivalDate > $joinDate) {
                    $validator->errors()->add('planned_arrival_at', 'Arrival Date cannot be after Expected Vessel Join.');
                }
            }
        });
    }

    public function submissionIntent(): CrewAssignmentSubmissionIntent
    {
        return CrewAssignmentSubmissionIntent::tryFrom((string) $this->input('submission_intent'))
            ?? CrewAssignmentSubmissionIntent::Draft;
    }
}
