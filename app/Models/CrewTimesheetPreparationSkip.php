<?php

namespace App\Models;

use App\Models\Concerns\LogsActivityWithCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Support\LogOptions;

class CrewTimesheetPreparationSkip extends Model
{
    use HasFactory;
    use LogsActivityWithCompany;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'crew_timesheet_preparation_id',
        'employee_id',
        'reason',
        'skipped_by',
        'skipped_at',
        'restored_by',
        'restored_at',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'crew_timesheet_preparation_id',
                'employee_id',
                'reason',
                'skipped_by',
                'skipped_at',
                'restored_by',
                'restored_at',
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
            'crew_timesheet_preparation_id' => 'integer',
            'employee_id' => 'integer',
            'skipped_by' => 'integer',
            'restored_by' => 'integer',
            'skipped_at' => 'datetime',
            'restored_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function preparation(): BelongsTo
    {
        return $this->belongsTo(CrewTimesheetPreparation::class, 'crew_timesheet_preparation_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function skippedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'skipped_by');
    }

    public function restoredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'restored_by');
    }

    public function isActive(): bool
    {
        return $this->restored_at === null;
    }

    /**
     * @param  Builder<CrewTimesheetPreparationSkip>  $query
     * @return Builder<CrewTimesheetPreparationSkip>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('restored_at');
    }
}
