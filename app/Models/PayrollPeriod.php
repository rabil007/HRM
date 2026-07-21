<?php

namespace App\Models;

use App\Enums\CrewTimesheetMode;
use App\Enums\PayrollCategory;
use App\Enums\PayrollPeriodCreationSource;
use App\Enums\PayrollPeriodStatus;
use App\Models\Concerns\LogsActivityWithCompany;
use Database\Factories\PayrollPeriodFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Support\LogOptions;

class PayrollPeriod extends Model
{
    /** @use HasFactory<PayrollPeriodFactory> */
    use HasFactory;

    use LogsActivityWithCompany;
    use SoftDeletes;

    protected $guarded = [];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'company_id',
                'payroll_category',
                'crew_timesheet_mode',
                'name',
                'start_date',
                'end_date',
                'payment_date',
                'generated_at',
                'status',
                'creation_source',
                'automatic_period_key',
                'notes',
                'created_by',
                'approved_by',
                'approved_at',
                'excluded_employee_ids',
                'payment_proof_paths',
            ])
            ->logOnlyDirty();
    }

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'payment_date' => 'date',
            'generated_at' => 'datetime',
            'payroll_category' => PayrollCategory::class,
            'crew_timesheet_mode' => CrewTimesheetMode::class,
            'status' => PayrollPeriodStatus::class,
            'creation_source' => PayrollPeriodCreationSource::class,
            'approved_at' => 'datetime',
            'excluded_employee_ids' => 'array',
            'payment_proof_paths' => 'array',
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

    /**
     * @return HasMany<CrewTimesheetPreparation, $this>
     */
    public function crewTimesheetPreparations(): HasMany
    {
        return $this->hasMany(CrewTimesheetPreparation::class, 'payroll_period_id');
    }

    public function payrollRecords(): HasMany
    {
        return $this->hasMany(PayrollRecord::class, 'period_id');
    }

    public function salaryInputs(): HasMany
    {
        return $this->hasMany(SalaryInput::class, 'period_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function isEditable(): bool
    {
        return $this->status === PayrollPeriodStatus::Draft;
    }

    public function canGenerateCrewPayroll(): bool
    {
        return $this->isCrew()
            && in_array($this->status, [PayrollPeriodStatus::Draft, PayrollPeriodStatus::Processing], true);
    }

    public function canGenerateOfficePayroll(): bool
    {
        return $this->isOffice()
            && in_array($this->status, [PayrollPeriodStatus::Draft, PayrollPeriodStatus::Processing], true);
    }

    public function canGeneratePayroll(): bool
    {
        return $this->isCrew()
            ? $this->canGenerateCrewPayroll()
            : $this->canGenerateOfficePayroll();
    }

    public function canRevertToDraft(): bool
    {
        return $this->status === PayrollPeriodStatus::Processing;
    }

    public function canApprove(): bool
    {
        return $this->status === PayrollPeriodStatus::Processing
            && $this->hasPayrollRecords();
    }

    public function canMarkPaid(): bool
    {
        return $this->status === PayrollPeriodStatus::Approved
            && $this->hasPayrollRecords();
    }

    public function canRevertToApproved(): bool
    {
        return $this->status === PayrollPeriodStatus::Paid;
    }

    public function canRevertToProcessing(): bool
    {
        return $this->status === PayrollPeriodStatus::Approved;
    }

    public function canCancel(): bool
    {
        return in_array($this->status, [
            PayrollPeriodStatus::Draft,
            PayrollPeriodStatus::Processing,
            PayrollPeriodStatus::Approved,
        ], true);
    }

    public function isAutomatic(): bool
    {
        return $this->creation_source === PayrollPeriodCreationSource::Automatic;
    }

    public function isCrew(): bool
    {
        return ($this->payroll_category ?? PayrollCategory::Crew) === PayrollCategory::Crew;
    }

    public function isOffice(): bool
    {
        return ($this->payroll_category ?? PayrollCategory::Crew) === PayrollCategory::Office;
    }

    public function usesCrewOperationsTimesheets(): bool
    {
        return $this->isCrew()
            && $this->crew_timesheet_mode === CrewTimesheetMode::CrewOperations;
    }

    public function usesManualTimesheets(): bool
    {
        return $this->isCrew()
            && ($this->crew_timesheet_mode === null
                || $this->crew_timesheet_mode === CrewTimesheetMode::Manual);
    }

    public function crewTimesheetModeLabel(): ?string
    {
        return $this->crew_timesheet_mode?->label();
    }

    public function calendarDayCount(): int
    {
        $startDate = $this->start_date;
        $endDate = $this->end_date;

        if ($startDate === null || $endDate === null) {
            return 0;
        }

        return (int) $startDate->diffInDays($endDate) + 1;
    }

    private function hasPayrollRecords(): bool
    {
        if (isset($this->payroll_records_count)) {
            return (int) $this->payroll_records_count > 0;
        }

        return $this->payrollRecords()->exists();
    }
}
