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

        if ($from !== 'index') {
            return [
                'href' => route('organization.contracts'),
                'label' => 'Contracts',
            ];
        }

        $query = [];

        foreach (['search', 'lifecycle', 'status', 'payroll_category', 'salary_structure', 'branch_id', 'department_id', 'page'] as $key) {
            $value = $request->query($key);

            if ($value !== null && $value !== '') {
                $query[$key] = (string) $value;
            }
        }

        return [
            'href' => route('organization.contracts', $query),
            'label' => 'Contracts',
        ];
    }
}
