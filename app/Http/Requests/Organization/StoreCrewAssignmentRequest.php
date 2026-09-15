<?php

namespace App\Http\Requests\Organization;

use App\Enums\CrewAssignmentSubmissionIntent;
use App\Enums\CrewPhaseCode;
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

        if ($intent === CrewAssignmentSubmissionIntent::Start->value
            && ($this->input('current_stage') === null || $this->input('current_stage') === '')) {
            $merge['current_stage'] = CrewPhaseCode::TravelIn->value;
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
        $isStart = $this->submissionIntent() === CrewAssignmentSubmissionIntent::Start;

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
            'planned_join_at' => ['nullable', 'date'],
            'current_stage' => [
                Rule::requiredIf($isStart),
                'nullable',
                'string',
                Rule::in(array_map(
                    fn (CrewPhaseCode $phase): string => $phase->value,
                    CrewPhaseCode::directStartPhases(),
                )),
            ],
            'stage_started_at' => ['nullable', 'string'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'current_stage.in' => 'Assignments cannot start directly in this stage. Start at Travel In or Pre-Mobilisation so payable join-standby history is recorded.',
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

            if ($this->submissionIntent() !== CrewAssignmentSubmissionIntent::Start) {
                return;
            }

            $raw = trim((string) $this->input('stage_started_at', ''));

            if ($raw === '') {
                return;
            }

            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) === 1) {
                $validator->errors()->add(
                    'stage_started_at',
                    'Assignment start date and time must include a time. Midnight is not assumed.',
                );

                return;
            }

            $timezone = CompanyTimezone::forCompanyId($companyId);

            try {
                $startedAt = Carbon::parse($raw, $timezone);
            } catch (\Throwable) {
                $validator->errors()->add('stage_started_at', 'Enter a valid assignment start date and time.');

                return;
            }

            if ($startedAt->gt(now($timezone))) {
                $validator->errors()->add(
                    'stage_started_at',
                    'Assignment start date and time cannot be in the future. Future mobilisation belongs in Crew Planning.',
                );
            }
        });
    }

    public function submissionIntent(): CrewAssignmentSubmissionIntent
    {
        return CrewAssignmentSubmissionIntent::tryFrom((string) $this->input('submission_intent'))
            ?? CrewAssignmentSubmissionIntent::Draft;
    }
}
