<?php

namespace App\Models;

use App\Enums\CrewTimesheetPreparationStatus;
use App\Enums\CrewTimesheetSource;
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
                'sign_on_standby_from',
                'sign_on_standby_to',
                'sign_on_standby_days',
                'onsite_from',
                'onsite_to',
                'onsite_days',
                'sign_off_standby_from',
                'sign_off_standby_to',
                'sign_off_standby_days',
                'unpaid_leave_days',
                'overtime_hours',
                'overtime_amount',
                'additional_amount',
                'deduction_amount',
                'remarks',
                'source',
                'crew_timesheet_preparation_id',
                'operational_approved_by',
                'operational_approved_at',
                'movement_source_hash',
            ])
            ->logOnlyDirty();
    }

    protected function casts(): array
    {
        return [
            'sign_on_standby_from' => 'date',
            'sign_on_standby_to' => 'date',
            'sign_on_standby_days' => 'decimal:2',
            'onsite_from' => 'date',
            'onsite_to' => 'date',
            'onsite_days' => 'decimal:2',
            'sign_off_standby_from' => 'date',
            'sign_off_standby_to' => 'date',
            'sign_off_standby_days' => 'decimal:2',
            'unpaid_leave_days' => 'decimal:2',
            'overtime_hours' => 'decimal:2',
            'overtime_amount' => 'decimal:2',
            'additional_amount' => 'decimal:2',
            'deduction_amount' => 'decimal:2',
            'source' => CrewTimesheetSource::class,
            'crew_timesheet_preparation_id' => 'integer',
            'operational_approved_by' => 'integer',
            'operational_approved_at' => 'datetime',
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

    public function preparation(): BelongsTo
    {
        return $this->belongsTo(CrewTimesheetPreparation::class, 'crew_timesheet_preparation_id');
    }

    public function operationalApprovedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'operational_approved_by');
    }

    public function isOperationallyLocked(): bool
    {
        if ($this->source !== CrewTimesheetSource::CrewOperations) {
            return false;
        }

        $this->loadMissing('preparation');

        return $this->preparation?->status === CrewTimesheetPreparationStatus::Applied;
    }
}
