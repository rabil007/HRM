<?php

namespace App\Models;

use App\Enums\PayrollCategory;
use App\Enums\SalaryPaymentMethod;
use App\Enums\WpsStatus;
use Database\Factories\PayrollRecordFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollRecord extends Model
{
    /** @use HasFactory<PayrollRecordFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'payroll_category' => PayrollCategory::class,
            'salary_payment_method' => SalaryPaymentMethod::class,
            'basic_salary' => 'decimal:2',
            'housing_allowance' => 'decimal:2',
            'transport_allowance' => 'decimal:2',
            'other_allowances' => 'decimal:2',
            'overtime_pay' => 'decimal:2',
            'bonus' => 'decimal:2',
            'gross_salary' => 'decimal:2',
            'unpaid_leave_deduction' => 'decimal:2',
            'late_deduction' => 'decimal:2',
            'loan_deduction' => 'decimal:2',
            'other_deductions' => 'decimal:2',
            'total_deductions' => 'decimal:2',
            'net_salary' => 'decimal:2',
            'gratuity_accrued' => 'decimal:2',
            'gratuity_total' => 'decimal:2',
            'leave_days' => 'decimal:2',
            'overtime_hours' => 'decimal:2',
            'calculation_breakdown' => 'array',
            'wps_status' => WpsStatus::class,
            'wps_submitted_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(PayrollPeriod::class, 'period_id');
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(EmployeeContract::class, 'contract_id');
    }

    public function bank(): BelongsTo
    {
        return $this->belongsTo(Bank::class);
    }

    public function employeeBankAccount(): BelongsTo
    {
        return $this->belongsTo(EmployeeBankAccount::class, 'employee_bank_account_id');
    }

    public function resolvedEmployeeBankAccount(): ?EmployeeBankAccount
    {
        if ($this->employee_bank_account_id !== null) {
            $this->loadMissing([
                'employeeBankAccount' => fn ($query) => $query->withTrashed(),
                'employeeBankAccount.bank',
            ]);

            if ($this->employeeBankAccount !== null) {
                return $this->employeeBankAccount;
            }
        }

        $this->loadMissing('employee.primaryBankAccount.bank');

        return $this->employee?->primaryBankAccount;
    }

    public function resolvedBank(): ?Bank
    {
        $account = $this->resolvedEmployeeBankAccount();

        if ($account?->bank !== null) {
            return $account->bank;
        }

        $this->loadMissing('bank');

        return $this->bank;
    }

    public function resolvedSalaryPaymentMethod(): SalaryPaymentMethod
    {
        if ($this->salary_payment_method !== null) {
            return $this->salary_payment_method;
        }

        $this->loadMissing('employee');

        return $this->employee?->salary_payment_method ?? SalaryPaymentMethod::BankTransfer;
    }

    /**
     * @param  Builder<PayrollRecord>  $query
     * @return Builder<PayrollRecord>
     */
    public function scopeCrewMonthly(Builder $query): Builder
    {
        return $query
            ->where('payroll_category', PayrollCategory::Crew)
            ->where('calculation_breakdown->salary_structure', 'monthly');
    }

    /**
     * @param  Builder<PayrollRecord>  $query
     * @return Builder<PayrollRecord>
     */
    public function scopeCrewDaily(Builder $query): Builder
    {
        return $query
            ->where('payroll_category', PayrollCategory::Crew)
            ->where(function (Builder $inner): void {
                $inner->whereNull('calculation_breakdown->salary_structure')
                    ->orWhere('calculation_breakdown->salary_structure', '!=', 'monthly');
            });
    }
}
