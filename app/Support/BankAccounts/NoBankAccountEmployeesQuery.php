<?php

namespace App\Support\BankAccounts;

use App\Models\Employee;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class NoBankAccountEmployeesQuery
{
    /**
     * @return array{
     *     total_no_account: int,
     *     bank_transfer: int,
     *     cash_c3: int,
     *     cash_other: int
     * }
     */
    public function summary(int $companyId): array
    {
        $row = Employee::query()
            ->where('company_id', $companyId)
            ->whereDoesntHave('bankAccounts')
            ->selectRaw('COUNT(*) as total_no_account')
            ->selectRaw("SUM(CASE WHEN salary_payment_method IS NULL OR salary_payment_method = 'bank_transfer' THEN 1 ELSE 0 END) as bank_transfer")
            ->selectRaw("SUM(CASE WHEN salary_payment_method = 'cash_c3' THEN 1 ELSE 0 END) as cash_c3")
            ->selectRaw("SUM(CASE WHEN salary_payment_method = 'cash_other' THEN 1 ELSE 0 END) as cash_other")
            ->first();

        return [
            'total_no_account' => (int) ($row->total_no_account ?? 0),
            'bank_transfer' => (int) ($row->bank_transfer ?? 0),
            'cash_c3' => (int) ($row->cash_c3 ?? 0),
            'cash_other' => (int) ($row->cash_other ?? 0),
        ];
    }

    /**
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function paginate(int $companyId, string $search, string $paymentMethod, int $perPage): LengthAwarePaginator
    {
        return Employee::query()
            ->where('company_id', $companyId)
            ->whereDoesntHave('bankAccounts')
            ->when($paymentMethod !== '', function ($query) use ($paymentMethod) {
                if ($paymentMethod === 'bank_transfer') {
                    $query->where(function ($q) {
                        $q->whereNull('salary_payment_method')
                            ->orWhere('salary_payment_method', 'bank_transfer');
                    });
                } else {
                    $query->where('salary_payment_method', $paymentMethod);
                }
            })
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('employee_no', 'like', "%{$search}%");
                });
            })
            ->with(['department:id,name', 'position:id,title'])
            ->orderBy('name')
            ->paginate($perPage)
            ->through(fn (Employee $employee) => [
                'id' => $employee->id,
                'name' => $employee->name,
                'employee_no' => $employee->employee_no,
                'image' => $employee->image,
                'department' => $employee->department?->name,
                'position' => $employee->position?->title,
                'hire_date' => $employee->hire_date?->toDateString(),
                'salary_payment_method' => $employee->salary_payment_method?->value ?? 'bank_transfer',
                'salary_payment_method_label' => $employee->salary_payment_method?->label() ?? 'Bank transfer',
            ]);
    }
}
