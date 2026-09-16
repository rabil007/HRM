<?php

namespace App\Http\Requests\Organization;

use App\Enums\CrewAssignmentStatus;
use App\Enums\CrewMovementAction;
use App\Enums\CrewPhaseCode;
use App\Models\CrewAssignment;
use App\Support\CrewMovements\CrewAssignmentAccess;
use App\Support\CrewMovements\CrewMovementAvailableActions;
use App\Support\CrewOperations\CrewOperationsSettings;
use App\Support\MasterData\ClientAssignmentRules;
use Carbon\Carbon;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class PerformCrewMovementActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        if ($this->user() === null) {
            return false;
        }

        $companyId = (int) $this->attributes->get('current_company_id');

        /** @var CrewAssignment|null $assignment */
        $assignment = $this->route('assignment');

        if (! $assignment instanceof CrewAssignment) {
            return false;
        }

        CrewAssignmentAccess::assertInCompany($assignment, $companyId);

        $action = CrewMovementAction::tryFrom((string) $this->input('action'));

        if ($action === CrewMovementAction::CancelAssignment) {
            Gate::authorize('cancel', $assignment);

            return true;
        }

        Gate::authorize('performMovement', $assignment);

        return true;
    }

    protected function prepareForValidation(): void
    {
        $action = (string) $this->input('action');
        $companyId = (int) $this->attributes->get('current_company_id');

        if (in_array($action, ['join_vessel', 'transfer_vessel', 'redeploy'], true)) {
            $vesselId = $this->input('vessel_id');
            $clientId = $this->input('client_id');

            if (($clientId === null || $clientId === '')
                && $vesselId !== null
                && $vesselId !== ''
                && $companyId > 0
                && ! ($action === 'redeploy' && $this->input('starting_phase') === CrewPhaseCode::PreMobilisation->value)) {
                $resolved = ClientAssignmentRules::resolveClientIdFromVessel($companyId, (int) $vesselId);

                if ($resolved !== null) {
                    $this->merge(['client_id' => $resolved]);
                }
            }
        }

        if ($action === 'record_arrival') {
            /** @var CrewAssignment|null $assignment */
            $assignment = $this->route('assignment');
            if ($assignment instanceof CrewAssignment) {
                $assignment->loadMissing('currentPhase');
                if (in_array($assignment->currentPhase?->phase_code, [CrewPhaseCode::PreMobilisation, CrewPhaseCode::TravelIn], true)
                    && ! $this->filled('next_phase')) {
                    $this->merge(['next_phase' => CrewPhaseCode::JoinStandby->value]);
                }
            }
        }

        if ($action === 'complete_training' && ! $this->filled('next_phase')) {
            $this->merge(['next_phase' => CrewPhaseCode::JoinStandby->value]);
        }

        if ($action !== 'redeploy') {
            return;
        }

        $startingPhase = (string) $this->input('starting_phase');
        $nullable = [];

        foreach (['vessel_id', 'rank_id', 'client_id', 'planned_signoff_at', 'remarks'] as $field) {
            if ($this->input($field) === '') {
                $nullable[$field] = null;
            }
        }

        if ($startingPhase === CrewPhaseCode::PreMobilisation->value) {
            $nullable['planned_signoff_at'] = null;
            $nullable['vessel_id'] = null;
            $nullable['rank_id'] = null;
            $nullable['client_id'] = null;
        }

        if ($nullable !== []) {
            $this->merge($nullable);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $companyId = (int) $this->attributes->get('current_company_id');
        $action = $this->input('action');

        $baseRules = [
            'action' => ['required', 'string', Rule::in(CrewMovementAction::values())],
            'occurred_at' => [Rule::requiredIf(fn () => in_array($action, [
                'approve_mobilisation',
                'record_arrival',
                'start_join_standby',
                'send_to_training',
                'complete_training',
                'mark_ready',
                'join_vessel',
                'confirm_disembarkation',
                'start_demob_standby',
                'travel_home',
                'transfer_vessel',
                'redeploy',
                'close_assignment',
                'cancel_assignment',
            ], true)), 'nullable', 'date'],
            'next_phase' => array_values(array_filter([
                Rule::requiredIf(function () use ($action) {
                    if ($action === 'record_arrival') {
                        /** @var CrewAssignment|null $assignment */
                        $assignment = $this->route('assignment');
                        if ($assignment instanceof CrewAssignment) {
                            $assignment->loadMissing('currentPhase');

                            return $assignment->currentPhase?->phase_code === CrewPhaseCode::TravelIn;
                        }

                        return false;
                    }

                    return in_array($action, ['complete_training', 'confirm_disembarkation'], true);
                }),
                'nullable',
                'string',
                in_array($action, [
                    'record_arrival',
                    'complete_training',
                    'confirm_disembarkation',
                ], true)
                    ? Rule::in(match ($action) {
                        'record_arrival', 'complete_training' => [
                            CrewPhaseCode::JoinStandby->value,
                        ],
                        'confirm_disembarkation' => [
                            CrewPhaseCode::DemobStandby->value,
                            CrewPhaseCode::HomeRedeploy->value,
                        ],
                        default => [],
                    })
                    : null,
            ])),
        ];

        if ($action === 'join_vessel') {
            $baseRules['vessel_id'] = ['required', 'integer', Rule::exists('vessels', 'id')->where('company_id', $companyId)->where('is_active', true)];
            $baseRules['rank_id'] = ['required', 'integer', Rule::exists('ranks', 'id')->where('is_active', true)];
            $baseRules['client_id'] = ['nullable', 'integer', Rule::exists('clients', 'id')->where('is_active', true)];
            $baseRules['planned_signoff_choice'] = [
                'nullable',
                'string',
                Rule::in(['tour_of_duty', 'existing_plan', 'manual_override']),
            ];
            $baseRules['planned_signoff_at'] = ['nullable', 'date'];
            $baseRules['planned_signoff_override_reason'] = [
                Rule::requiredIf(fn () => $this->input('planned_signoff_choice') === 'manual_override'
                    || (! in_array($this->input('planned_signoff_choice'), ['tour_of_duty', 'existing_plan'], true) && $this->filled('planned_signoff_at'))
                ),
                'nullable',
                'string',
                'max:1000',
            ];
            $baseRules['remarks'] = ['nullable', 'string', 'max:1000'];
        }

        if ($action === 'transfer_vessel') {
            $baseRules['vessel_id'] = ['required', 'integer', Rule::exists('vessels', 'id')->where('company_id', $companyId)->where('is_active', true)];
            $baseRules['rank_id'] = ['required', 'integer', Rule::exists('ranks', 'id')->where('is_active', true)];
            $baseRules['client_id'] = ['nullable', 'integer', Rule::exists('clients', 'id')->where('is_active', true)];
            $baseRules['planned_signoff_choice'] = [
                'nullable',
                'string',
                Rule::in(['tour_of_duty', 'existing_plan', 'manual_override']),
            ];
            $baseRules['planned_signoff_at'] = ['nullable', 'date'];
            $baseRules['planned_signoff_override_reason'] = [
                Rule::requiredIf(fn () => $this->input('planned_signoff_choice') === 'manual_override'
                    || (! in_array($this->input('planned_signoff_choice'), ['tour_of_duty', 'existing_plan'], true) && $this->filled('planned_signoff_at'))
                ),
                'nullable',
                'string',
                'max:1000',
            ];
            $baseRules['remarks'] = ['nullable', 'string', 'max:1000'];
        }

        if ($action === 'redeploy') {
            $baseRules['starting_phase'] = [
                'required',
                'string',
                Rule::in([
                    CrewPhaseCode::PreMobilisation->value,
                    CrewPhaseCode::JoinStandby->value,
                    CrewPhaseCode::OnVessel->value,
                ]),
            ];
            $baseRules['planned_arrival_at'] = ['nullable', 'date'];
            $baseRules['vessel_id'] = [
                Rule::requiredIf(fn () => $this->input('starting_phase') === CrewPhaseCode::OnVessel->value),
                'nullable',
                'integer',
                Rule::exists('vessels', 'id')->where('company_id', $companyId)->where('is_active', true),
            ];
            $baseRules['rank_id'] = [
                Rule::requiredIf(fn () => $this->input('starting_phase') === CrewPhaseCode::OnVessel->value),
                'nullable',
                'integer',
                Rule::exists('ranks', 'id')->where('is_active', true),
            ];
            $baseRules['client_id'] = ['nullable', 'integer', Rule::exists('clients', 'id')->where('is_active', true)];
            $baseRules['planned_signoff_choice'] = [
                Rule::excludeIf(fn () => $this->input('starting_phase') !== CrewPhaseCode::OnVessel->value),
                'nullable',
                'string',
                Rule::in(['tour_of_duty', 'existing_plan', 'manual_override']),
            ];
            $baseRules['planned_signoff_at'] = ['nullable', 'date'];
            $baseRules['planned_signoff_override_reason'] = [
                Rule::requiredIf(fn () => $this->input('starting_phase') === CrewPhaseCode::OnVessel->value
                    && ($this->input('planned_signoff_choice') === 'manual_override'
                        || (! in_array($this->input('planned_signoff_choice'), ['tour_of_duty', 'existing_plan'], true) && $this->filled('planned_signoff_at')))
                ),
                'nullable',
                'string',
                'max:1000',
            ];
            $baseRules['remarks'] = ['nullable', 'string', 'max:1000'];
        }

        if ($action === 'send_to_training') {
            $baseRules['provider'] = ['nullable', 'string', 'max:200'];
            $baseRules['course'] = ['nullable', 'string', 'max:200'];
            $baseRules['course_id'] = ['nullable', 'integer', Rule::exists('courses', 'id')->where('is_active', true)];
            $baseRules['planned_start_at'] = ['nullable', 'date'];
            $baseRules['planned_end_at'] = ['nullable', 'date'];
            $baseRules['remarks'] = ['nullable', 'string', 'max:1000'];
        }

        if ($action === 'complete_training') {
            $baseRules['provider'] = ['nullable', 'string', 'max:200'];
            $baseRules['course'] = ['nullable', 'string', 'max:200'];
            $baseRules['course_id'] = ['nullable', 'integer', Rule::exists('courses', 'id')->where('is_active', true)];
            $baseRules['sync_training_to_employee_training'] = ['nullable', 'boolean'];
            $baseRules['remarks'] = ['nullable', 'string', 'max:1000'];
        }

        if ($action === 'plan_signoff') {
            $baseRules['planned_signoff_at'] = ['required', 'date'];
            $baseRules['planned_signoff_override_reason'] = ['required', 'string', 'max:1000'];
        }

        if ($action === 'travel_home') {
            $baseRules['planned_travel_at'] = ['nullable', 'date'];
        }

        if ($action === 'cancel_assignment') {
            $baseRules['reason'] = ['required', 'string', 'max:500'];
        }

        return $baseRules;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            /** @var CrewAssignment|null $assignment */
            $assignment = $this->route('assignment');
            $action = (string) $this->input('action');

            if (! $assignment instanceof CrewAssignment) {
                return;
            }

            $assignment->loadMissing(['currentPhase', 'phases', 'company']);

            $allowedActions = CrewMovementAvailableActions::for($assignment);

            if (! in_array($action, $allowedActions, true)) {
                $validator->errors()->add(
                    'action',
                    'This action is not available for the current assignment phase.',
                );
            }

            $timezone = (string) ($assignment->company?->timezone ?? config('app.timezone', 'UTC'));
            $occurredAt = $this->input('occurred_at')
                ? Carbon::parse((string) $this->input('occurred_at'), $timezone)
                : null;
            $currentStart = $assignment->currentPhase?->actual_start_at;

            if ($occurredAt !== null && $currentStart !== null && $occurredAt->lt($currentStart)) {
                $validator->errors()->add(
                    'occurred_at',
                    'This date cannot be before the current phase started.',
                );
            }

            if ($action === 'approve_mobilisation') {
                $isDraft = $assignment->status === CrewAssignmentStatus::Draft;
                $isPreMob = $assignment->currentPhase === null || $assignment->currentPhase->phase_code === CrewPhaseCode::PreMobilisation;

                if (! $isDraft || ! $isPreMob) {
                    $validator->errors()->add(
                        'action',
                        'Start Assignment is only valid for draft assignments in Pre-Mobilisation.',
                    );
                }
            }

            if ($action === 'record_arrival') {
                $currentCode = $assignment->currentPhase?->phase_code;

                if (! in_array($currentCode, [CrewPhaseCode::PreMobilisation, CrewPhaseCode::TravelIn], true)) {
                    $validator->errors()->add(
                        'action',
                        'Record Arrival can only be performed from Pre-Mobilisation or Travel In.',
                    );
                }

                if ($currentCode === CrewPhaseCode::PreMobilisation && $this->input('next_phase') !== CrewPhaseCode::JoinStandby->value) {
                    $validator->errors()->add(
                        'next_phase',
                        'Pre-Mobilisation arrivals must transition to Join Standby.',
                    );
                }

                if ($currentCode === CrewPhaseCode::TravelIn && $this->input('next_phase') !== CrewPhaseCode::JoinStandby->value) {
                    $validator->errors()->add(
                        'next_phase',
                        'Travel In arrivals must transition to Join Standby.',
                    );
                }
            }

            if ($action === 'redeploy' && $this->filled('planned_arrival_at') && $this->filled('planned_join_at')) {
                $arrival = Carbon::parse((string) $this->input('planned_arrival_at'), $timezone)->startOfDay();
                $join = Carbon::parse((string) $this->input('planned_join_at'), $timezone)->startOfDay();
                if ($arrival->gt($join)) {
                    $validator->errors()->add(
                        'planned_arrival_at',
                        'Planned Arrival Date cannot be after Planned Vessel Join Date.',
                    );
                }
            }

            if ($action === 'complete_training') {
                $syncEnabled = CrewOperationsSettings::syncTrainingToEmployeeTrainingEnabled((int) $assignment->company_id);
                $syncInput = $this->input('sync_training_to_employee_training');
                $isSkipped = $syncInput === false || $syncInput === '0' || $syncInput === 0 || $syncInput === 'false';
                $shouldSync = $syncEnabled && ! $isSkipped;

                if ($shouldSync) {
                    $hasCourseId = $this->filled('course_id')
                        || (! empty($assignment->currentPhase?->details['course_id']));

                    if (! $hasCourseId) {
                        $validator->errors()->add(
                            'course_id',
                            'Select a Course before adding this training to the employee\'s Training record.',
                        );
                    }
                }
            }

            if ($action === 'send_to_training') {
                $trainingStart = $occurredAt;
                $expectedCompletion = $this->input('planned_end_at')
                    ? Carbon::parse((string) $this->input('planned_end_at'), $timezone)
                    : null;

                if ($trainingStart !== null && $expectedCompletion !== null && $expectedCompletion->lt($trainingStart)) {
                    $validator->errors()->add(
                        'planned_end_at',
                        'Training completion cannot be before training started.',
                    );
                }
            }

            if (in_array($action, ['join_vessel', 'transfer_vessel', 'redeploy'], true)
                && $this->filled('planned_signoff_at')
                && $occurredAt !== null) {
                $plannedSignoff = Carbon::parse((string) $this->input('planned_signoff_at'), $timezone)->startOfDay();
                if ($plannedSignoff->lt($occurredAt->copy()->startOfDay())) {
                    $validator->errors()->add(
                        'planned_signoff_at',
                        'The planned sign-off cannot be before the actual vessel join date.',
                    );
                }
            }

            if (in_array($action, ['join_vessel', 'transfer_vessel'], true)
                || ($action === 'redeploy' && $this->input('starting_phase') === CrewPhaseCode::OnVessel->value)) {
                $choice = (string) $this->input('planned_signoff_choice', '');
                $isManualSignoff = $choice === 'manual_override'
                    || (! in_array($choice, ['tour_of_duty', 'existing_plan'], true) && $this->filled('planned_signoff_at'));

                if ($choice === 'existing_plan') {
                    if ($action === 'join_vessel' && $assignment->planned_signoff_at === null) {
                        $validator->errors()->add(
                            'planned_signoff_choice',
                            'There is no existing planned sign-off date to keep.',
                        );
                    }

                    if ($action !== 'join_vessel') {
                        $validator->errors()->add(
                            'planned_signoff_choice',
                            'Existing planned sign-off cannot be kept on a new linked assignment. Choose Tour of Duty or enter another date.',
                        );
                    }
                }

                if ($choice === 'manual_override' && ! $this->filled('planned_signoff_at')) {
                    $validator->errors()->add(
                        'planned_signoff_at',
                        'Please enter a Planned Sign-Off date.',
                    );
                }

                if ($isManualSignoff && ! $this->filled('planned_signoff_override_reason')) {
                    $validator->errors()->add(
                        'planned_signoff_override_reason',
                        'A reason is required when entering another Planned Sign-Off date.',
                    );
                }
            }

            if ($action === 'transfer_vessel' && $occurredAt !== null) {
                $actualJoin = $assignment->phases
                    ->filter(fn ($phase) => $phase->phase_code === CrewPhaseCode::OnVessel)
                    ->sortByDesc('sequence')
                    ->first()
                    ?->actual_start_at;

                if ($actualJoin !== null && $occurredAt->lt($actualJoin)) {
                    $validator->errors()->add(
                        'occurred_at',
                        'The transfer cannot occur before the employee joined the vessel.',
                    );
                }

                if ($assignment->vessel_id !== null
                    && (int) $this->input('vessel_id') === (int) $assignment->vessel_id) {
                    $validator->errors()->add(
                        'vessel_id',
                        'Destination vessel must differ from the current vessel.',
                    );
                }
            }

            if (in_array($action, ['join_vessel', 'transfer_vessel', 'redeploy'], true)) {
                $companyId = (int) $assignment->company_id;
                $clientId = $this->input('client_id');
                $vesselId = $this->input('vessel_id');

                ClientAssignmentRules::vesselBelongsToClient(
                    $validator,
                    $companyId,
                    $clientId !== null && $clientId !== '' ? (int) $clientId : null,
                    $vesselId !== null && $vesselId !== '' ? (int) $vesselId : null,
                );
            }

            if ($action === 'plan_signoff') {
                if (! $this->filled('planned_signoff_override_reason')) {
                    $validator->errors()->add(
                        'planned_signoff_override_reason',
                        'A reason is required when entering another Planned Sign-Off date.',
                    );
                }

                $actualJoin = $assignment->phases
                    ->filter(fn ($phase) => $phase->phase_code === CrewPhaseCode::OnVessel)
                    ->sortByDesc('sequence')
                    ->first()
                    ?->actual_start_at;
                $plannedSignoff = Carbon::parse((string) $this->input('planned_signoff_at'), $timezone)->startOfDay();

                if ($actualJoin !== null && $plannedSignoff->lt($actualJoin->copy()->timezone($timezone)->startOfDay())) {
                    $validator->errors()->add(
                        'planned_signoff_at',
                        'The planned sign-off cannot be before the actual vessel join date.',
                    );
                }
            }

            if ($action === 'confirm_disembarkation' && $occurredAt !== null) {
                $actualJoin = $assignment->phases
                    ->filter(fn ($phase) => $phase->phase_code === CrewPhaseCode::OnVessel)
                    ->sortByDesc('sequence')
                    ->first()
                    ?->actual_start_at;

                if ($actualJoin !== null && $occurredAt->lt($actualJoin)) {
                    $validator->errors()->add(
                        'occurred_at',
                        'The actual disembarkation cannot be before the employee joined the vessel.',
                    );
                }
            }

            if ($action === 'close_assignment' && $occurredAt !== null && $currentStart !== null && $occurredAt->lt($currentStart)) {
                $validator->errors()->add(
                    'occurred_at',
                    'The assignment cannot be closed before Home / Redeploy started.',
                );
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'occurred_at.required' => 'Please enter the date and time for this movement.',
            'next_phase.required' => 'Please choose what happens next.',
            'next_phase.in' => 'The selected next phase is not valid for this action.',
            'reason.required' => 'A cancellation reason is required.',
            'planned_signoff_at.required' => 'Please enter the planned sign-off date.',
            'planned_signoff_override_reason.required' => 'A reason is required when entering another Planned Sign-Off date.',
            'vessel_id.required' => 'Please select the vessel the employee joins.',
            'rank_id.required' => 'Please select the rank served onboard.',
            'starting_phase.required' => 'Please choose the starting phase for redeployment.',
            'starting_phase.in' => 'The selected starting phase is not valid for redeployment.',
        ];
    }
}
