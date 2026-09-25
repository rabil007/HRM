<?php

namespace App\Http\Requests\Organization;

use App\Enums\CrewAssignmentStatus;
use App\Models\CrewAssignment;
use App\Support\CrewMovements\CrewAssignmentAccess;
use App\Support\CrewMovements\CrewAssignmentConflictContext;
use App\Support\CrewMovements\CrewAssignmentConflictEvaluator;
use App\Support\CrewMovements\CrewAssignmentUpdateCandidate;
use App\Support\MasterData\ClientAssignmentRules;
use App\Support\Settings\CompanyTimezone;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateCrewAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        if ($this->user() === null) {
            return false;
        }

        $companyId = (int) $this->attributes->get('current_company_id');
        $assignment = $this->route('assignment');

        if (! $assignment instanceof CrewAssignment) {
            return false;
        }

        CrewAssignmentAccess::assertInCompany($assignment, $companyId, $this->user());

        Gate::authorize('update', $assignment);

        return true;
    }

    protected function prepareForValidation(): void
    {
        $companyId = (int) $this->attributes->get('current_company_id');
        $vesselId = $this->input('vessel_id');
        $clientId = $this->input('client_id');

        if (($clientId === null || $clientId === '')
            && $vesselId !== null
            && $vesselId !== ''
            && $companyId > 0) {
            $resolved = ClientAssignmentRules::resolveClientIdFromVessel($companyId, (int) $vesselId);

            if ($resolved !== null) {
                $this->merge(['client_id' => $resolved]);
            }
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $companyId = (int) $this->attributes->get('current_company_id');
        $existingVesselId = $this->existingAssignment()?->vessel_id;
        $existingClientId = $this->existingAssignment()?->client_id;

        return [
            'rank_id' => ['nullable', 'integer', Rule::exists('ranks', 'id')->where('is_active', true)],
            'client_id' => [
                'nullable',
                'integer',
                Rule::exists('clients', 'id')->where(function ($query) use ($existingClientId): void {
                    $query->where('is_active', true);

                    if ($existingClientId !== null) {
                        $query->orWhere('id', (int) $existingClientId);
                    }
                }),
            ],
            'vessel_id' => [
                'nullable',
                'integer',
                Rule::exists('vessels', 'id')->where(function ($query) use ($companyId, $existingVesselId): void {
                    $query->where('company_id', $companyId)
                        ->where(function ($inner) use ($existingVesselId): void {
                            $inner->where('is_active', true);

                            if ($existingVesselId !== null) {
                                $inner->orWhere('id', (int) $existingVesselId);
                            }
                        });
                }),
            ],
            'planned_arrival_at' => ['nullable', 'date'],
            'planned_join_at' => ['nullable', 'date'],
            'planned_signoff_at' => ['nullable', 'date'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $assignment = $this->existingAssignment();

            if ($assignment === null) {
                return;
            }

            $companyId = (int) $this->attributes->get('current_company_id');
            $timezone = CompanyTimezone::forCompanyId($companyId);
            $submitted = $this->submittedUpdatePayload();
            $candidate = CrewAssignmentUpdateCandidate::resolve($assignment, $submitted, $timezone);

            $this->assertCandidateDateOrder($validator, $assignment, $candidate, $timezone);

            if ($assignment->status === CrewAssignmentStatus::Planned) {
                if ($candidate['vessel_id'] === null) {
                    $validator->errors()->add('vessel_id', 'Vessel is required for Planned assignments.');
                }

                if ($candidate['rank_id'] === null) {
                    $validator->errors()->add('rank_id', 'Rank is required for Planned assignments.');
                }

                if ($candidate['planned_join_at'] === null) {
                    $validator->errors()->add('planned_join_at', 'Expected Vessel Join is required for Planned assignments.');
                }

                if ($candidate['planned_signoff_at'] === null) {
                    $validator->errors()->add('planned_signoff_at', 'Expected Sign-Off is required for Planned assignments.');
                }
            }

            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            if (in_array($assignment->status, [CrewAssignmentStatus::Planned, CrewAssignmentStatus::Active], true)) {
                $action = $assignment->status === CrewAssignmentStatus::Planned ? 'plan' : 'start';
                $conflictContext = new CrewAssignmentConflictContext(
                    companyId: $companyId,
                    employeeId: (int) $assignment->employee_id,
                    action: $action,
                    plannedJoinAt: $candidate['planned_join_at'],
                    plannedSignoffAt: $candidate['planned_signoff_at'],
                    plannedArrivalAt: $candidate['planned_arrival_at'],
                    operationalStartAt: $action === 'start' ? $assignment->started_at : null,
                    vesselId: $candidate['vessel_id'],
                    rankId: $candidate['rank_id'],
                    clientId: $candidate['client_id'],
                    currentAssignmentId: (int) $assignment->id,
                    actor: $this->user(),
                );

                $result = (new CrewAssignmentConflictEvaluator)->evaluate($conflictContext);
                if ($result->blocking) {
                    $validator->errors()->add('employee_id', $result->message);
                    $validator->errors()->add('conflict', json_encode($result->toArray()));

                    return;
                }
            }

            $clientId = $candidate['client_id'];
            $vesselId = $candidate['vessel_id'];
            $existingClientId = $assignment->client_id !== null ? (int) $assignment->client_id : null;
            $existingVesselId = $assignment->vessel_id !== null ? (int) $assignment->vessel_id : null;

            if ($clientId === $existingClientId && $vesselId === $existingVesselId) {
                return;
            }

            if ($vesselId !== null) {
                $vessel = ClientAssignmentRules::findCompanyVessel($companyId, $vesselId);

                if ($vessel === null) {
                    $validator->errors()->add('vessel_id', 'The selected vessel is invalid.');

                    return;
                }

                if (! $vessel->is_active) {
                    $validator->errors()->add(
                        'vessel_id',
                        'The selected vessel is inactive. Activate or choose another vessel before changing Client or Vessel.',
                    );

                    return;
                }
            }

            ClientAssignmentRules::vesselBelongsToClient(
                $validator,
                $companyId,
                $clientId,
                $vesselId,
            );
        });
    }

    /**
     * Keys present on the request that participate in assignment updates.
     *
     * @return array<string, mixed>
     */
    public function submittedUpdatePayload(): array
    {
        $payload = [];

        foreach ([
            'rank_id',
            'client_id',
            'vessel_id',
            'planned_arrival_at',
            'planned_join_at',
            'planned_signoff_at',
            'remarks',
        ] as $key) {
            if ($this->has($key)) {
                $payload[$key] = $this->input($key);
            }
        }

        return $payload;
    }

    /**
     * @param  array{
     *     planned_arrival_at: CarbonInterface|null,
     *     planned_join_at: CarbonInterface|null,
     *     planned_signoff_at: CarbonInterface|null,
     * }  $candidate
     */
    private function assertCandidateDateOrder(
        Validator $validator,
        CrewAssignment $assignment,
        array $candidate,
        string $timezone,
    ): void {
        $arrival = $candidate['planned_arrival_at']?->copy()->timezone($timezone)->toDateString();
        $join = $candidate['planned_join_at']?->copy()->timezone($timezone)->toDateString();
        $signoff = $candidate['planned_signoff_at']?->copy()->timezone($timezone)->toDateString();

        if ($arrival !== null && $join !== null && $arrival > $join) {
            $validator->errors()->add('planned_arrival_at', 'Arrival Date cannot be after Expected Vessel Join.');
        }

        if ($join !== null && $signoff !== null && $signoff < $join) {
            // When only Expected Join is being changed against an existing Sign-Off,
            // keep the join-field error so operators know to adjust sign-off first.
            if ($this->has('planned_join_at') && ! $this->has('planned_signoff_at') && $assignment->planned_signoff_at !== null) {
                $validator->errors()->add(
                    'planned_join_at',
                    'Expected Vessel Join cannot be after the existing Planned Sign-Off. Update the sign-off plan through the appropriate movement or planning workflow first.',
                );
            } else {
                $validator->errors()->add('planned_signoff_at', 'Expected Sign-off cannot be before Expected Vessel Join.');
            }
        }

        if ($assignment->status === CrewAssignmentStatus::Active
            && $assignment->started_at !== null
            && $signoff !== null) {
            $startDate = $assignment->started_at->copy()->timezone($timezone)->toDateString();

            if ($signoff < $startDate) {
                $validator->errors()->add(
                    'planned_signoff_at',
                    'Expected Sign-Off cannot be before Assignment Start.',
                );
            }
        }
    }

    private function existingAssignment(): ?CrewAssignment
    {
        $assignment = $this->route('assignment');

        return $assignment instanceof CrewAssignment ? $assignment : null;
    }
}
