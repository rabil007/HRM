<?php

namespace App\Support\Payroll;

use App\Models\SalaryInputType;

final class ProvisionDefaultSalaryInputTypes
{
    /**
     * @return list<array{code: string, name: string, is_addition: bool, sort_order: int}>
     */
    public static function defaults(): array
    {
        return [
            ['code' => 'bonus', 'name' => 'Bonus', 'is_addition' => true, 'sort_order' => 1],
            ['code' => 'commission', 'name' => 'Commission', 'is_addition' => true, 'sort_order' => 2],
            ['code' => 'unpaid_leave', 'name' => 'Unpaid leave', 'is_addition' => false, 'sort_order' => 3],
            ['code' => 'late', 'name' => 'Late', 'is_addition' => false, 'sort_order' => 4],
            ['code' => 'loan', 'name' => 'Loan', 'is_addition' => false, 'sort_order' => 5],
            ['code' => 'other', 'name' => 'Other', 'is_addition' => false, 'sort_order' => 6],
        ];
    }

    public function handle(int $companyId): void
    {
        foreach (self::defaults() as $default) {
            SalaryInputType::query()->firstOrCreate(
                ['company_id' => $companyId, 'code' => $default['code']],
                [
                    'name' => $default['name'],
                    'is_addition' => $default['is_addition'],
                    'status' => 'active',
                    'sort_order' => $default['sort_order'],
                ],
            );
        }
    }

    public function findByLegacyCode(int $companyId, string $code): ?SalaryInputType
    {
        $this->handle($companyId);

        return SalaryInputType::query()
            ->where('company_id', $companyId)
            ->where('code', $code)
            ->first();
    }
}
