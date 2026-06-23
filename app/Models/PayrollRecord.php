<?php

namespace App\Models;

use App\Enums\PayrollCategory;
use Database\Factories\PayrollRecordFactory;
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
}
