<?php

namespace App\Models;

use App\Models\Concerns\LogsActivityWithCompany;
use Database\Factories\ContractSalaryRevisionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Support\LogOptions;

class ContractSalaryRevision extends Model
{
    /** @use HasFactory<ContractSalaryRevisionFactory> */
    use HasFactory;

    use LogsActivityWithCompany;
    use SoftDeletes;

    protected $guarded = [];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'company_id',
                'contract_id',
                'employee_id',
                'version',
                'effective_from',
                'apply_to_payroll',
                'payroll_effective_from',
                'reason',
                'created_by',
            ])
            ->logOnlyDirty();
    }

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'effective_from' => 'date',
            'apply_to_payroll' => 'boolean',
            'payroll_effective_from' => 'date',
        ];
    }

    public function appliesToPayroll(): bool
    {
        return (bool) $this->apply_to_payroll;
    }

    public function payrollEffectiveDateString(): ?string
    {
        return $this->payroll_effective_from?->toDateString()
            ?? ($this->appliesToPayroll() ? $this->effective_from?->toDateString() : null);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(EmployeeContract::class, 'contract_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(ContractSalaryRevisionLine::class, 'revision_id');
    }
}
