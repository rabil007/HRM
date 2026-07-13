<?php

namespace App\Support\EmployeeTrainings;

use App\Models\Employee;
use Illuminate\Http\Request;

class TrainingShowBackNavigation
{
    /**
     * @return array{href: string, label: string}
     */
    public static function resolve(Request $request, Employee $employee): array
    {
        $from = (string) $request->query('from', 'profile');

        return match ($from) {
            'index' => [
                'href' => route('organization.training', self::indexQuery($request)),
                'label' => 'Back to training',
            ],
            'employee-browse' => [
                'href' => route('organization.training.employee', $employee),
                'label' => 'Back to employee training',
            ],
            default => [
                'href' => route('organization.employees.show', $employee).'#training',
                'label' => 'Back to employee profile',
            ],
        };
    }

    /**
     * @return array{href: string, label: string}
     */
    public static function resolveIndex(Request $request): array
    {
        return [
            'href' => route('organization.training', self::indexQuery($request)),
            'label' => 'Training',
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function indexQuery(Request $request): array
    {
        $query = [];

        foreach (['search', 'expiry', 'issue_date', 'branch_id', 'department_id', 'page'] as $key) {
            $value = $request->query($key);

            if ($value === null || $value === '') {
                continue;
            }

            if ($key === 'expiry' && ! TrainingExpiry::isValidFilter((string) $value)) {
                continue;
            }

            if ($key === 'expiry' && (string) $value === 'all') {
                continue;
            }

            if ($key === 'page' && (int) $value <= 1) {
                continue;
            }

            $query[$key] = (string) $value;
        }

        return $query;
    }
}
