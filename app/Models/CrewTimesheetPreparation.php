<?php

namespace App\Models;

use App\Enums\CrewTimesheetPreparationStatus;
use App\Models\Concerns\LogsActivityWithCompany;
use Database\Factories\CrewTimesheetPreparationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Support\LogOptions;

class CrewTimesheetPreparation extends Model
{
    /** @use HasFactory<CrewTimesheetPreparationFactory> */
    use HasFactory;

    use LogsActivityWithCompany;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'payroll_period_id',
        'version',
        'status',
        'cutoff_date',
        'source_hash',
        'prepared_by',
        'prepared_at',
        'submitted_by',
        'submitted_at',
        'approved_by',
        'approved_at',
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
                'source_hash',
                'prepared_by',
                'prepared_at',
                'submitted_by',
                'submitted_at',
                'approved_by',
                'approved_at',
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
            'applied_by' => 'integer',
            'status' => CrewTimesheetPreparationStatus::class,
            'cutoff_date' => 'date',
            'prepared_at' => 'datetime',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'applied_at' => 'datetime',
        ];
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

    public function appliedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applied_by');
    }
}
