<?php

namespace App\Models;

use App\Models\Concerns\LogsActivityWithCompany;
use Database\Factories\CrewTimesheetFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Support\LogOptions;

class CrewTimesheet extends Model
{
    /** @use HasFactory<CrewTimesheetFactory> */
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
                'period_id',
                'standby_from',
                'standby_to',
                'standby_days',
                'onsite_from',
                'onsite_to',
                'onsite_days',
                'overtime_hours',
                'overtime_amount',
                'additional_amount',
                'deduction_amount',
                'remarks',
            ])
            ->logOnlyDirty();
    }

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
