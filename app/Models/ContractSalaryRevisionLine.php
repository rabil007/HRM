<?php

namespace App\Models;

use App\Enums\SalaryComponentCode;
use App\Enums\SalaryComponentRateType;
use Database\Factories\ContractSalaryRevisionLineFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContractSalaryRevisionLine extends Model
{
    /** @use HasFactory<ContractSalaryRevisionLineFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'component_code' => SalaryComponentCode::class,
            'rate_type' => SalaryComponentRateType::class,
            'amount' => 'decimal:2',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function revision(): BelongsTo
    {
        return $this->belongsTo(ContractSalaryRevision::class, 'revision_id');
    }
}
