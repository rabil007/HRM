<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmployeeBankAccount;
use App\Support\EmployeeProfileTemplates\EmployeeProfileTemplateRequestRules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class EmployeeBankAccountController extends Controller
{
    public function store(Request $request, Employee $employee): RedirectResponse
    {
        $companyId = (int) $request->attributes->get('current_company_id');

        abort_unless((int) $employee->company_id === $companyId, 403);

        $validated = EmployeeProfileTemplateRequestRules::validate($request, $employee, 'employee_bank_accounts', [
            'bank_id' => ['nullable', 'integer', Rule::exists('banks', 'id')],
            'iban' => ['nullable', 'string', 'max:50'],
            'account_name' => ['nullable', 'string', 'max:200'],
            'is_primary' => ['sometimes', 'boolean'],
        ]);

        $normalizedIban = EmployeeProfileTemplateRequestRules::hasValidated($validated, 'iban')
            ? ($validated['iban'] ?? null)
            : null;
        $normalizedAccountName = EmployeeProfileTemplateRequestRules::hasValidated($validated, 'account_name')
            ? ($validated['account_name'] ?? null)
            : null;

        if ($normalizedIban !== null && trim($normalizedIban) === '') {
            $normalizedIban = null;
        }

        if ($normalizedAccountName !== null && trim($normalizedAccountName) === '') {
            $normalizedAccountName = null;
        }

        DB::transaction(function () use ($companyId, $employee, $validated, $normalizedIban, $normalizedAccountName): void {
            $existingCount = EmployeeBankAccount::query()
                ->where('company_id', $companyId)
                ->where('employee_id', $employee->id)
                ->count();

            $isPrimary = (bool) ($validated['is_primary'] ?? false);

            if ($existingCount === 0) {
                $isPrimary = true;
            }

            if ($isPrimary) {
                EmployeeBankAccount::query()
                    ->where('company_id', $companyId)
                    ->where('employee_id', $employee->id)
                    ->update(['is_primary' => false]);
            }

            EmployeeBankAccount::query()->create([
                'company_id' => $companyId,
                'employee_id' => $employee->id,
                'bank_id' => EmployeeProfileTemplateRequestRules::persistedNullableValue(
                    $validated,
                    'bank_id',
                    null,
                    asInteger: true,
                ),
                'iban' => $normalizedIban !== null && trim($normalizedIban) === '' ? null : $normalizedIban,
                'account_name' => $normalizedAccountName !== null && trim($normalizedAccountName) === ''
                    ? null
                    : $normalizedAccountName,
                'is_primary' => $isPrimary,
            ]);

            self::reconcilePrimaryAccounts($companyId, $employee->id);
        });

        return back()->with('success', 'Bank account added.');
    }

    public function update(Request $request, Employee $employee, EmployeeBankAccount $bankAccount): RedirectResponse
    {
        $companyId = (int) $request->attributes->get('current_company_id');

        abort_unless(
            (int) $employee->company_id === $companyId
                && (int) $bankAccount->employee_id === $employee->id
                && (int) $bankAccount->company_id === $companyId,
            403,
        );

        $validated = EmployeeProfileTemplateRequestRules::validate($request, $employee, 'employee_bank_accounts', [
            'bank_id' => ['nullable', 'integer', Rule::exists('banks', 'id')],
            'iban' => ['nullable', 'string', 'max:50'],
            'account_name' => ['nullable', 'string', 'max:200'],
            'is_primary' => ['sometimes', 'boolean'],
        ]);

        $normalizedIban = $validated['iban'] ?? null;
        $normalizedAccountName = $validated['account_name'] ?? null;

        if ($normalizedIban !== null && trim($normalizedIban) === '') {
            $normalizedIban = null;
        }

        if ($normalizedAccountName !== null && trim($normalizedAccountName) === '') {
            $normalizedAccountName = null;
        }

        DB::transaction(function () use ($companyId, $employee, $bankAccount, $validated, $normalizedIban, $normalizedAccountName): void {
            $isPrimary = array_key_exists('is_primary', $validated)
                ? (bool) $validated['is_primary']
                : $bankAccount->is_primary;

            $total = EmployeeBankAccount::query()
                ->where('company_id', $companyId)
                ->where('employee_id', $employee->id)
                ->count();

            if ($total === 1) {
                $isPrimary = true;
            }

            if ($isPrimary) {
                EmployeeBankAccount::query()
                    ->where('company_id', $companyId)
                    ->where('employee_id', $employee->id)
                    ->where('id', '!=', $bankAccount->id)
                    ->update(['is_primary' => false]);
            }

            $bankAccount->update([
                'bank_id' => EmployeeProfileTemplateRequestRules::persistedValue(
                    $validated,
                    'bank_id',
                    $bankAccount->bank_id,
                ),
                'iban' => EmployeeProfileTemplateRequestRules::hasValidated($validated, 'iban')
                    ? $normalizedIban
                    : $bankAccount->iban,
                'account_name' => EmployeeProfileTemplateRequestRules::hasValidated($validated, 'account_name')
                    ? $normalizedAccountName
                    : $bankAccount->account_name,
                'is_primary' => $isPrimary,
            ]);

            self::reconcilePrimaryAccounts($companyId, $employee->id);
        });

        return back()->with('success', 'Bank account updated.');
    }

    public function destroy(Request $request, Employee $employee, EmployeeBankAccount $bankAccount): RedirectResponse
    {
        $companyId = (int) $request->attributes->get('current_company_id');

        abort_unless(
            (int) $employee->company_id === $companyId
                && (int) $bankAccount->employee_id === $employee->id
                && (int) $bankAccount->company_id === $companyId,
            403,
        );

        DB::transaction(function () use ($companyId, $employee, $bankAccount): void {
            $wasPrimary = $bankAccount->is_primary;
            $bankAccount->delete();

            if ($wasPrimary) {
                $next = EmployeeBankAccount::query()
                    ->where('company_id', $companyId)
                    ->where('employee_id', $employee->id)
                    ->orderBy('id')
                    ->first();

                if ($next) {
                    EmployeeBankAccount::query()
                        ->where('company_id', $companyId)
                        ->where('employee_id', $employee->id)
                        ->update(['is_primary' => false]);

                    $next->update(['is_primary' => true]);
                }
            }

            self::reconcilePrimaryAccounts($companyId, $employee->id);
        });

        return back()->with('success', 'Bank account removed.');
    }

    private static function reconcilePrimaryAccounts(int $companyId, int $employeeId): void
    {
        $rows = EmployeeBankAccount::query()
            ->where('company_id', $companyId)
            ->where('employee_id', $employeeId)
            ->orderBy('id')
            ->get(['id', 'is_primary']);

        if ($rows->isEmpty()) {
            return;
        }

        $primaryRows = $rows->where('is_primary', true)->pluck('id')->values();

        if ($primaryRows->count() === 1) {
            return;
        }

        EmployeeBankAccount::query()
            ->where('company_id', $companyId)
            ->where('employee_id', $employeeId)
            ->update(['is_primary' => false]);

        $chosenId = $primaryRows->isNotEmpty()
            ? min($primaryRows->all())
            : (int) $rows->first()->id;

        EmployeeBankAccount::query()
            ->whereKey($chosenId)
            ->update(['is_primary' => true]);
    }
}
