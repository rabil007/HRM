<?php

namespace App\Models;

use App\Enums\SalaryComponentCode;
use App\Enums\SalaryComponentRateType;
use App\Enums\SalaryComponentStatus;
use App\Models\Concerns\LogsActivityWithCompany;
use Database\Factories\ContractSalaryComponentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Support\LogOptions;

class ContractSalaryComponent extends Model
{
    /** @use HasFactory<ContractSalaryComponentFactory> */
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
                'component_code',
                'component_name',
                'rate_type',
                'amount',
                'status',
            ])
            ->logOnlyDirty();
    }

    protected function casts(): array
    {
        return [
            'component_code' => SalaryComponentCode::class,
            'rate_type' => SalaryComponentRateType::class,
            'status' => SalaryComponentStatus::class,
            'amount' => 'decimal:2',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(EmployeeContract::class, 'contract_id');
    }
}
