<?php

namespace App\Http\Requests\Organization;

use App\Enums\CrewAssignmentStatus;
use App\Models\CrewAssignment;
use App\Support\CrewMovements\CrewAssignmentAccess;
use App\Support\CrewMovements\CrewAssignmentConflictContext;
use App\Support\CrewMovements\CrewAssignmentConflictEvaluator;
use App\Support\MasterData\ClientAssignmentRules;
use App\Support\Settings\CompanyTimezone;
use Carbon\Carbon;
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
            $companyId = (int) $this->attributes->get('current_company_id');

            $this->assertArrivalNotAfterExpectedJoin($validator, $assignment, $companyId);
            $this->assertExpectedJoinNotAfterPlannedSignOff($validator, $assignment, $companyId);

            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            // If updating a Planned assignment, rerun conflict detection
            if ($assignment !== null && $assignment->status === CrewAssignmentStatus::Planned) {
                $timezone = CompanyTimezone::forCompanyId($companyId);
                $plannedJoin = $this->filled('planned_join_at')
                    ? Carbon::parse((string) $this->input('planned_join_at'), $timezone)
                    : $assignment->planned_join_at;
                $plannedSignoff = $this->filled('planned_signoff_at')
                    ? Carbon::parse((string) $this->input('planned_signoff_at'), $timezone)
                    : $assignment->planned_signoff_at;
                $plannedArrival = $this->filled('planned_arrival_at')
                    ? Carbon::parse((string) $this->input('planned_arrival_at'), $timezone)
                    : $assignment->planned_arrival_at;

                $conflictEvaluator = new CrewAssignmentConflictEvaluator;
                $conflictContext = new CrewAssignmentConflictContext(
                    companyId: $companyId,
                    employeeId: (int) $assignment->employee_id,
                    action: 'plan',
                    plannedJoinAt: $plannedJoin,
                    plannedSignoffAt: $plannedSignoff,
                    plannedArrivalAt: $plannedArrival,
                    vesselId: $this->filled('vessel_id') ? (int) $this->input('vessel_id') : (int) $assignment->vessel_id,
                    rankId: $this->filled('rank_id') ? (int) $this->input('rank_id') : (int) $assignment->rank_id,
                    clientId: $this->filled('client_id') ? (int) $this->input('client_id') : ($assignment->client_id ? (int) $assignment->client_id : null),
                    currentAssignmentId: (int) $assignment->id,
                    actor: $this->user(),
                );

                $result = $conflictEvaluator->evaluate($conflictContext);
                if ($result->blocking) {
                    $validator->errors()->add('employee_id', $result->message);
                    $validator->errors()->add('conflict', json_encode($result->toArray()));

                    return;
                }
            }

            $clientId = $this->nullableInt($this->input('client_id'));
            $vesselId = $this->nullableInt($this->input('vessel_id'));

            $existingClientId = $assignment?->client_id !== null ? (int) $assignment->client_id : null;
            $existingVesselId = $assignment?->vessel_id !== null ? (int) $assignment->vessel_id : null;

            // Preserve an unchanged legacy/inactive Vessel/Client pair on editable records.
            // Changing either field applies today's strict operational rules.
            if ($clientId === $existingClientId && $vesselId === $existingVesselId) {
                return;
            }

            if ($vesselId !== null) {
                $vessel = ClientAssignmentRules::findCompanyVessel($companyId, $vesselId);

                if ($vessel === null) {
                    $validator->errors()->add('vessel_id', 'The selected vessel is invalid.');

                    return;
                }

                // Existing inactive Vessel IDs may pass Rule::exists for continuity,
                // but must not participate in a new Client/Vessel relationship.
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

    private function assertExpectedJoinNotAfterPlannedSignOff(
        Validator $validator,
        ?CrewAssignment $assignment,
        int $companyId,
    ): void {
        if ($assignment === null || ! $this->filled('planned_join_at') || $assignment->planned_signoff_at === null) {
            return;
        }

        $timezone = CompanyTimezone::forCompanyId($companyId);
        $joinDate = Carbon::parse((string) $this->input('planned_join_at'), $timezone)
            ->timezone($timezone)
            ->toDateString();
        $signoffDate = $assignment->planned_signoff_at->copy()->timezone($timezone)->toDateString();

        if ($joinDate > $signoffDate) {
            $validator->errors()->add(
                'planned_join_at',
                'Expected Vessel Join cannot be after the existing Planned Sign-Off. Update the sign-off plan through the appropriate movement or planning workflow first.',
            );
        }
    }

    private function assertArrivalNotAfterExpectedJoin(
        Validator $validator,
        ?CrewAssignment $assignment,
        int $companyId,
    ): void {
        $rawArrival = $this->has('planned_arrival_at')
            ? $this->input('planned_arrival_at')
            : $assignment?->planned_arrival_at;

        $rawJoin = $this->has('planned_join_at')
            ? $this->input('planned_join_at')
            : $assignment?->planned_join_at;

        if ($rawArrival === null || $rawArrival === '' || $rawJoin === null || $rawJoin === '') {
            return;
        }

        $timezone = CompanyTimezone::forCompanyId($companyId);
        $arrivalDate = Carbon::parse($rawArrival, $timezone)->toDateString();
        $joinDate = Carbon::parse($rawJoin, $timezone)->toDateString();

        if ($arrivalDate > $joinDate) {
            $validator->errors()->add(
                'planned_arrival_at',
                'Arrival Date cannot be after Expected Vessel Join.',
            );
        }
    }

    private function existingAssignment(): ?CrewAssignment
    {
        $assignment = $this->route('assignment');

        return $assignment instanceof CrewAssignment ? $assignment : null;
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }
}
