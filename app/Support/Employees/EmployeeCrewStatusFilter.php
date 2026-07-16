<?php

namespace App\Support\Employees;

use App\Models\Employee;
use App\Support\CrewMovements\CrewAssignmentStatusResolver;

final class EmployeeCrewStatusFilter
{
    public const AVAILABLE = 'available';

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [
            'in_home' => 'In home',
            self::AVAILABLE => 'Available',
            'pre_mobilisation' => 'Pre-mobilisation',
            'travel_in' => 'Travel in',
            'join_standby' => 'Join standby',
            'training' => 'Training',
            'ready_to_join' => 'Ready to join',
            'on_vessel' => 'On vessel',
            'demob_standby' => 'Demob standby',
            'home_redeploy' => 'Home / redeploy',
            'movement_update_required' => 'Needs update',
        ];
    }

    public static function isValid(string $crewStatus): bool
    {
        return array_key_exists($crewStatus, self::options());
    }

    /**
     * @return list<int>
     */
    public static function matchingEmployeeIds(int $companyId, string $crewStatus): array
    {
        if (! self::isValid($crewStatus)) {
            return [];
        }

        $employees = Employee::query()
            ->where('company_id', $companyId)
            ->active()
            ->with(['company'])
            ->get();

        if ($employees->isEmpty()) {
            return [];
        }

        $resolver = new CrewAssignmentStatusResolver;
        $matching = [];

        foreach ($employees as $employee) {
            $resolved = $resolver->forEmployee($employee);

            if ($crewStatus === self::AVAILABLE) {
                if ($resolved['status'] === 'in_home' && $resolved['assignment_id'] === null) {
                    $matching[] = $employee->id;
                }
            } elseif ($resolved['status'] === $crewStatus) {
                $matching[] = $employee->id;
            }
        }

        return $matching;
    }
}
