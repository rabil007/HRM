<?php

namespace App\Support\Users\Actions;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SyncUserEmployeeLink
{
    public function handle(User $user, int $companyId, ?int $employeeId): void
    {
        DB::transaction(function () use ($user, $companyId, $employeeId): void {
            Employee::query()
                ->where('company_id', $companyId)
                ->where('user_id', $user->id)
                ->when($employeeId !== null, fn ($query) => $query->where('id', '!=', $employeeId))
                ->update(['user_id' => null]);

            if ($employeeId === null) {
                return;
            }

            $employee = Employee::query()
                ->where('company_id', $companyId)
                ->whereKey($employeeId)
                ->first();

            if ($employee === null) {
                throw ValidationException::withMessages([
                    'employee_id' => 'The selected employee is invalid.',
                ]);
            }

            if ($employee->user_id !== null && (int) $employee->user_id !== $user->id) {
                throw ValidationException::withMessages([
                    'employee_id' => 'This employee is already linked to another user.',
                ]);
            }

            $employee->update(['user_id' => $user->id]);
        });
    }
}
