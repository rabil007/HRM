<?php

namespace App\Http\Requests\Organization\CrewPlanning\Concerns;

use App\Models\CrewPlanningAssignment;
use App\Support\CrewPlanning\ValidatesCrewPlanningReliefLink;
use App\Support\MasterData\ClientAssignmentRules;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

trait ValidatesCrewPlanningAssignmentFields
{
    /**
     * Vacant Planning slots never accept a named employee.
     * Named plans use Crew Assignment → Save as Planned.
     *
     * @return array<int, mixed>
     */
    protected function crewPlanningEmployeeIdMustBeAbsentRule(): array
    {
        return [
            function (string $attribute, mixed $value, \Closure $fail): void {
                if ($value !== null && $value !== '') {
                    $fail('Named crew plans must be created using Crew Assignment → Save as Planned.');
                }
            },
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function crewPlanningRelievesAssignmentIdRule(): array
    {
        $companyId = (int) $this->attributes->get('current_company_id');

        return [
            'nullable',
            'integer',
            Rule::exists('crew_assignments', 'id')->where(fn ($query) => $query
                ->where('company_id', $companyId)),
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $companyId = (int) $this->attributes->get('current_company_id');

            $assignment = $this->route('assignment');
            $existing = $assignment instanceof CrewPlanningAssignment ? $assignment : null;

            $assignmentRankId = $this->has('rank_id')
                ? $this->input('rank_id')
                : $existing?->rank_id;

            $vesselId = $this->has('vessel_id')
                ? $this->input('vessel_id')
                : $existing?->vessel_id;

            if ($vesselId !== null && $vesselId !== '' && ! $validator->errors()->has('vessel_id')) {
                ClientAssignmentRules::vesselBelongsToClient(
                    $validator,
                    $companyId,
                    null,
                    (int) $vesselId,
                    requireAssignedClient: true,
                );
            }

            // Relief link rules always apply when present — including vacant slots.
            // employee_id is always null for vacant Planning; named plans use CrewAssignment.
            ValidatesCrewPlanningReliefLink::validate($validator, [
                'company_id' => $companyId,
                'relieves_crew_assignment_id' => $this->has('relieves_crew_assignment_id')
                    ? $this->input('relieves_crew_assignment_id')
                    : $existing?->relieves_crew_assignment_id,
                'vessel_id' => $vesselId,
                'rank_id' => $assignmentRankId,
                'employee_id' => null,
            ], $existing, $this->user());
        });
    }
}
