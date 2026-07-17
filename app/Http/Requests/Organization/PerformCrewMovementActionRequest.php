<?php

namespace App\Http\Requests\Organization;

use App\Enums\CrewMovementAction;
use App\Enums\CrewPhaseCode;
use App\Models\CrewAssignment;
use Carbon\Carbon;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class PerformCrewMovementActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
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
                'close_assignment',
                'cancel_assignment',
            ], true)), 'nullable', 'date'],
            'next_phase' => array_values(array_filter([
                Rule::requiredIf(fn () => in_array($action, [
                    'record_arrival',
                    'complete_training',
                    'confirm_disembarkation',
                ], true)),
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
                            CrewPhaseCode::ReadyToJoin->value,
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
            $baseRules['vessel_id'] = ['required', 'integer', Rule::exists('vessels', 'id')->where('is_active', true)];
            $baseRules['rank_id'] = ['required', 'integer', Rule::exists('ranks', 'id')->where('is_active', true)];
            $baseRules['client_id'] = ['nullable', 'integer', Rule::exists('clients', 'id')->where('is_active', true)];
            $baseRules['company_visa_type_id'] = ['nullable', 'integer', Rule::exists('company_visa_types', 'id')->where('is_active', true)];
            $baseRules['planned_signoff_at'] = ['nullable', 'date'];
            $baseRules['remarks'] = ['nullable', 'string', 'max:1000'];
        }

        if ($action === 'send_to_training') {
            $baseRules['provider'] = ['nullable', 'string', 'max:200'];
            $baseRules['course'] = ['nullable', 'string', 'max:200'];
            $baseRules['planned_start_at'] = ['nullable', 'date'];
            $baseRules['planned_end_at'] = ['nullable', 'date'];
            $baseRules['remarks'] = ['nullable', 'string', 'max:1000'];
        }

        if ($action === 'plan_signoff') {
            $baseRules['planned_signoff_at'] = ['required', 'date'];
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

            if ($action === 'join_vessel' && $this->filled('planned_signoff_at') && $occurredAt !== null) {
                $plannedSignoff = Carbon::parse((string) $this->input('planned_signoff_at'), $timezone)->startOfDay();
                if ($plannedSignoff->lt($occurredAt->copy()->startOfDay())) {
                    $validator->errors()->add(
                        'planned_signoff_at',
                        'The planned sign-off cannot be before the actual vessel join date.',
                    );
                }
            }

            if ($action === 'plan_signoff') {
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
            'vessel_id.required' => 'Please select the vessel the employee joins.',
            'rank_id.required' => 'Please select the rank served onboard.',
        ];
    }
}
