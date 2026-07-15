<?php

namespace App\Support\SeaServices;

use App\Models\Employee;
use Illuminate\Http\Request;

final class SeaServiceShowBackNavigation
{
    /**
     * @return array{href: string, label: string}
     */
    public static function resolve(Request $request, Employee $employee): array
    {
        $from = (string) $request->query('from', 'profile');

        return match ($from) {
            'index' => [
                'href' => route('organization.sea-services', self::indexQuery($request)),
                'label' => 'Back to sea services',
            ],
            'employee-browse' => [
                'href' => route('organization.sea-services.employee', $employee),
                'label' => 'Back to employee sea services',
            ],
            default => [
                'href' => route('organization.employees.show', $employee).'#sea-service',
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
            'href' => route('organization.sea-services', self::indexQuery($request)),
            'label' => 'Sea Services',
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function indexQuery(Request $request): array
    {
        $query = [];

        foreach ([
            'search',
            'vessel_id',
            'vessel_type_id',
            'rank_id',
            'client_id',
            'offshore',
            'active',
            'start_date',
            'end_date',
            'branch_id',
            'department_id',
            'page',
        ] as $key) {
            $value = $request->query($key);

            if ($value === null || $value === '') {
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
