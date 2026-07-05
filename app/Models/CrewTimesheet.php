<?php

namespace App\Models;

use Database\Factories\CrewTimesheetFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CrewTimesheet extends Model
{
    /** @use HasFactory<CrewTimesheetFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'standby_from' => 'date',
            'standby_to' => 'date',
            'standby_days' => 'decimal:2',
            'onsite_from' => 'date',
            'onsite_to' => 'date',
            'onsite_days' => 'decimal:2',
            'overtime_hours' => 'decimal:2',
            'overtime_amount' => 'decimal:2',
            'additional_amount' => 'decimal:2',
            'deduction_amount' => 'decimal:2',
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
