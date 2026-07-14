<?php

namespace App\Support\Contracts;

use Illuminate\Http\Request;

final class ContractShowBackNavigation
{
    /**
     * @return array{href: string, label: string}
     */
    public static function resolve(Request $request): array
    {
        $from = (string) $request->query('from', 'index');

        if ($from === 'employee') {
            $employeeId = (int) $request->query('employee_id', 0);

            if ($employeeId > 0) {
                return [
                    'href' => route('organization.contracts.employee', [
                        'employee' => $employeeId,
                        ...self::preservedIndexQuery($request),
                    ]),
                    'label' => 'Employee contracts',
                ];
            }
        }

        if ($from === 'profile') {
            $employeeId = (int) $request->query('employee_id', 0);

            if ($employeeId > 0) {
                return [
                    'href' => route('organization.employees.show', [
                        'employee' => $employeeId,
                        'tab' => 'contract',
                    ]),
                    'label' => 'Employee profile',
                ];
            }
        }

        return [
            'href' => route('organization.contracts', self::preservedIndexQuery($request)),
            'label' => 'Contracts',
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function preservedIndexQuery(Request $request): array
    {
        $query = [];

        foreach (['search', 'lifecycle', 'status', 'payroll_category', 'salary_structure', 'branch_id', 'department_id', 'page'] as $key) {
            $value = $request->query($key);

            if ($value !== null && $value !== '') {
                $query[$key] = (string) $value;
            }
        }

        return $query;
    }
}
