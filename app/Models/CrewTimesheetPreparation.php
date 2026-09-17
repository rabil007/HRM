<?php

namespace App\Models;

use App\Enums\CrewTimesheetPreparationStatus;
use App\Models\Concerns\LogsActivityWithCompany;
use App\Support\Settings\CompanyTimezone;
use Carbon\CarbonImmutable;
use Database\Factories\CrewTimesheetPreparationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Support\LogOptions;

class CrewTimesheetPreparation extends Model
{
    /** @use HasFactory<CrewTimesheetPreparationFactory> */
    use HasFactory;

    use LogsActivityWithCompany;
    use SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'payroll_period_id',
        'version',
        'status',
        'cutoff_date',
        'effective_cutoff_date',
        'source_hash',
        'prepared_by',
        'prepared_at',
        'submitted_by',
        'submitted_at',
        'approved_by',
        'approved_at',
        'returned_by',
        'returned_at',
        'applied_by',
        'applied_at',
        'decision_notes',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'payroll_period_id',
                'version',
                'status',
                'cutoff_date',
                'effective_cutoff_date',
                'source_hash',
                'prepared_by',
                'prepared_at',
                'submitted_by',
                'submitted_at',
                'approved_by',
                'approved_at',
                'returned_by',
                'returned_at',
                'applied_by',
                'applied_at',
                'decision_notes',
            ])
            ->logOnlyDirty();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'payroll_period_id' => 'integer',
            'version' => 'integer',
            'prepared_by' => 'integer',
            'submitted_by' => 'integer',
            'approved_by' => 'integer',
            'returned_by' => 'integer',
            'applied_by' => 'integer',
            'status' => CrewTimesheetPreparationStatus::class,
            'cutoff_date' => 'date',
            'effective_cutoff_date' => 'date',
            'prepared_at' => 'datetime',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'returned_at' => 'datetime',
            'applied_at' => 'datetime',
        ];
    }

    public function resolveEffectiveCutoffDate(?PayrollPeriod $period = null): CarbonImmutable
    {
        if ($this->effective_cutoff_date !== null) {
            return CarbonImmutable::parse($this->effective_cutoff_date->toDateString());
        }

        $period = $period ?? ($this->relationLoaded('payrollPeriod') ? $this->payrollPeriod : null);
        $timezone = CompanyTimezone::forCompany($this->relationLoaded('company') ? $this->company : (int) $this->company_id);

        if ($this->cutoff_date !== null) {
            return CarbonImmutable::parse($this->cutoff_date->toDateString(), $timezone);
        }

        $periodEnd = $period?->end_date !== null
            ? CarbonImmutable::parse($period->end_date->toDateString(), $timezone)
            : ($this->payrollPeriod?->end_date !== null
                ? CarbonImmutable::parse($this->payrollPeriod->end_date->toDateString(), $timezone)
                : CarbonImmutable::now($timezone)->startOfDay());

        $preparedDate = $this->prepared_at !== null
            ? CarbonImmutable::parse($this->prepared_at->toIso8601String(), $timezone)->startOfDay()
            : CarbonImmutable::now($timezone)->startOfDay();

        return $preparedDate->lt($periodEnd) ? $preparedDate : $periodEnd;
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function payrollPeriod(): BelongsTo
    {
        return $this->belongsTo(PayrollPeriod::class, 'payroll_period_id');
    }

    /**
     * @return HasMany<CrewTimesheetPreparationLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(CrewTimesheetPreparationLine::class);
    }

    /**
     * @return HasMany<CrewTimesheet, $this>
     */
    public function crewTimesheets(): HasMany
    {
        return $this->hasMany(CrewTimesheet::class);
    }

    /**
     * @return HasMany<CrewTimesheetPreparationSkip, $this>
     */
    public function skips(): HasMany
    {
        return $this->hasMany(CrewTimesheetPreparationSkip::class);
    }

    /**
     * @return HasMany<CrewTimesheetPreparationSkip, $this>
     */
    public function activeSkips(): HasMany
    {
        return $this->hasMany(CrewTimesheetPreparationSkip::class)->whereNull('restored_at');
    }

    public function preparedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_by');
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function returnedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'returned_by');
    }

    public function appliedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applied_by');
    }
}
