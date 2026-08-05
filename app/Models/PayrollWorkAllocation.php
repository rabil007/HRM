<?php

namespace App\Models;

use App\Enums\CrewTimesheetPayCategory;
use App\Enums\CrewTimesheetSource;
use App\Enums\PayrollWorkAllocationStatus;
use App\Enums\PayrollWorkPeriodClassification;
use App\Models\Concerns\LogsActivityWithCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Support\LogOptions;

class PayrollWorkAllocation extends Model
{
    use LogsActivityWithCompany;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'employee_id',
        'payroll_period_id',
        'payroll_record_id',
        'crew_timesheet_id',
        'crew_timesheet_segment_id',
        'work_date',
        'pay_category',
        'period_classification',
        'status',
        'source',
        'crew_assignment_id',
        'crew_assignment_phase_id',
        'contract_id',
        // Maps to contract_salary_revisions.id for the work-date rate package.
        'salary_revision_id',
        'basic_daily_rate',
        'site_allowance_daily_rate',
        'supplementary_allowance_daily_rate',
        'basic_amount',
        'site_allowance_amount',
        'supplementary_allowance_amount',
        'total_amount',
        'approved_at',
        'paid_at',
        'reversed_at',
        'reversal_reason',
    ];

    protected static function booted(): void
    {
        static::saving(function (PayrollWorkAllocation $allocation): void {
            $status = $allocation->status instanceof PayrollWorkAllocationStatus
                ? $allocation->status
                : PayrollWorkAllocationStatus::tryFrom((string) $allocation->status);

            if ($status !== null && $status->isActive()) {
                $workDate = $allocation->work_date;
                $dateString = is_string($workDate)
                    ? substr($workDate, 0, 10)
                    : $workDate?->toDateString();

                $allocation->active_allocation_key = sprintf(
                    '%d:%d:%s',
                    (int) $allocation->company_id,
                    (int) $allocation->employee_id,
                    (string) $dateString,
                );

                return;
            }

            $allocation->active_allocation_key = null;
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'company_id',
                'employee_id',
                'payroll_period_id',
                'payroll_record_id',
                'work_date',
                'pay_category',
                'period_classification',
                'status',
                'source',
                'contract_id',
                'salary_revision_id',
                'basic_daily_rate',
                'site_allowance_daily_rate',
                'supplementary_allowance_daily_rate',
                'total_amount',
                'approved_at',
                'paid_at',
                'reversed_at',
                'reversal_reason',
                'active_allocation_key',
            ])
            ->logOnlyDirty();
    }

    protected function casts(): array
    {
        return [
            'work_date' => 'date',
            'pay_category' => CrewTimesheetPayCategory::class,
            'period_classification' => PayrollWorkPeriodClassification::class,
            'status' => PayrollWorkAllocationStatus::class,
            'source' => CrewTimesheetSource::class,
            'contract_id' => 'integer',
            'salary_revision_id' => 'integer',
            'crew_assignment_id' => 'integer',
            'crew_assignment_phase_id' => 'integer',
            'basic_daily_rate' => 'decimal:2',
            'site_allowance_daily_rate' => 'decimal:2',
            'supplementary_allowance_daily_rate' => 'decimal:2',
            'basic_amount' => 'decimal:2',
            'site_allowance_amount' => 'decimal:2',
            'supplementary_allowance_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'approved_at' => 'datetime',
            'paid_at' => 'datetime',
            'reversed_at' => 'datetime',
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
        return $this->belongsTo(PayrollPeriod::class, 'payroll_period_id');
    }

    public function payrollRecord(): BelongsTo
    {
        return $this->belongsTo(PayrollRecord::class);
    }

    public function timesheet(): BelongsTo
    {
        return $this->belongsTo(CrewTimesheet::class, 'crew_timesheet_id');
    }

    public function segment(): BelongsTo
    {
        return $this->belongsTo(CrewTimesheetSegment::class, 'crew_timesheet_segment_id');
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(CrewAssignment::class, 'crew_assignment_id');
    }

    public function assignmentPhase(): BelongsTo
    {
        return $this->belongsTo(CrewAssignmentPhase::class, 'crew_assignment_phase_id');
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(EmployeeContract::class, 'contract_id');
    }

    public function salaryRevision(): BelongsTo
    {
        return $this->belongsTo(ContractSalaryRevision::class, 'salary_revision_id');
    }
}
