<?php

namespace App\Support\CrewPlanning;

use App\Models\CrewPlanningAssignment;
use App\Models\EmployeeDeployment;
use Illuminate\Validation\Validator;

final class ValidatesCrewPlanningReliefLink
{
    /**
     * @param  array{
     *     company_id: int,
     *     relieves_employee_deployment_id: int|string|null,
     *     vessel_id: int|string|null,
     *     rank_id: int|string|null,
     *     employee_id: int|string|null
     * }  $data
     */
    public static function validate(Validator $validator, array $data, ?CrewPlanningAssignment $existing = null): void
    {
        if ($validator->errors()->isNotEmpty()) {
            return;
        }

        if ($existing?->employee_deployment_id !== null) {
            return;
        }

        $relievesId = $data['relieves_employee_deployment_id'];

        if ($relievesId === null || $relievesId === '') {
            return;
        }

        $relievesId = (int) $relievesId;

        $deployment = EmployeeDeployment::query()
            ->where('company_id', $data['company_id'])
            ->with('employee:id,rank_id')
            ->find($relievesId);

        if ($deployment === null) {
            $validator->errors()->add(
                'relieves_employee_deployment_id',
                'The selected deployment could not be found.',
            );

            return;
        }

        $vesselId = $data['vessel_id'];
        $rankId = $data['rank_id'];

        if ($vesselId !== null && $vesselId !== '' && $deployment->vessel_id !== (int) $vesselId) {
            $validator->errors()->add(
                'relieves_employee_deployment_id',
                'The relief assignment must be on the same vessel as the deployment being relieved.',
            );
        }

        $deploymentRankId = $deployment->rank_id ?? $deployment->employee?->rank_id;

        if ($rankId !== null && $rankId !== '' && $deploymentRankId !== null && (int) $deploymentRankId !== (int) $rankId) {
            $validator->errors()->add(
                'relieves_employee_deployment_id',
                'The relief assignment must be for the same rank as the deployment being relieved.',
            );
        }

        $employeeId = $data['employee_id'];

        if ($employeeId !== null && $employeeId !== '' && (int) $employeeId === (int) $deployment->employee_id) {
            $validator->errors()->add(
                'employee_id',
                'The relief crew member cannot be the same person as the deployed crew being relieved.',
            );
        }
    }
}
