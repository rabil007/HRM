<?php

namespace App\Models;

use App\Enums\PayrollPeriodStatus;
use Database\Factories\PayrollPeriodFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PayrollPeriod extends Model
{
    /** @use HasFactory<PayrollPeriodFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'payment_date' => 'date',
            'status' => PayrollPeriodStatus::class,
            'approved_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function crewTimesheets(): HasMany
    {
        return $this->hasMany(CrewTimesheet::class, 'period_id');
    }

    public function isEditable(): bool
    {
        return $this->status === PayrollPeriodStatus::Draft;
    }
}
