<?php

namespace App\Models;

use App\Enums\ContractSalaryStructure;
use App\Enums\PayrollCategory;
use App\Models\Concerns\LogsActivityWithCompany;
use Database\Factories\EmployeeContractFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Support\LogOptions;

class EmployeeContract extends Model
{
    /** @use HasFactory<EmployeeContractFactory> */
    use HasFactory;

    use LogsActivityWithCompany;
    use SoftDeletes;

    protected $guarded = [];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'company_id',
                'employee_id',
                'payroll_category',
                'salary_structure',
                'start_date',
                'end_date',
                'labor_contract_id',
                'status',
                'basic_salary',
                'housing_allowance',
                'transport_allowance',
                'other_allowances',
                'supplementary_allowance',
                'site_allowance',
                'note',
            ])
            ->logOnlyDirty();
    }

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'payroll_category' => PayrollCategory::class,
            'salary_structure' => ContractSalaryStructure::class,
            'basic_salary' => 'decimal:2',
            'housing_allowance' => 'decimal:2',
            'transport_allowance' => 'decimal:2',
            'other_allowances' => 'decimal:2',
            'supplementary_allowance' => 'decimal:2',
            'site_allowance' => 'decimal:2',
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

    public function salaryComponents(): HasMany
    {
        return $this->hasMany(ContractSalaryComponent::class, 'contract_id');
    }

    public function resolvedSalaryStructure(): ContractSalaryStructure
    {
        if ($this->salary_structure !== null) {
            return $this->salary_structure;
        }

        return ContractSalaryStructure::defaultFor(
            $this->payroll_category ?? PayrollCategory::Office,
        );
    }
}
