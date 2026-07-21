<?php

namespace App\Models;

use App\Enums\CrewPhaseCode;
use App\Enums\CrewTimesheetPayCategory;
use App\Models\Concerns\LogsActivityWithCompany;
use Database\Factories\CrewTimesheetPreparationLineFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Support\LogOptions;

class CrewTimesheetPreparationLine extends Model
{
    /** @use HasFactory<CrewTimesheetPreparationLineFactory> */
    use HasFactory;

    use LogsActivityWithCompany;
    use SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'crew_timesheet_preparation_id',
        'employee_id',
        'crew_assignment_id',
        'crew_assignment_phase_id',
        'phase_code',
        'pay_category',
        'from_date',
        'to_date',
        'days',
        'source_actual_start_at',
        'source_actual_end_at',
        'warning_code',
        'remarks',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'crew_timesheet_preparation_id',
                'employee_id',
                'crew_assignment_id',
                'crew_assignment_phase_id',
                'phase_code',
                'pay_category',
                'from_date',
                'to_date',
                'days',
                'source_actual_start_at',
                'source_actual_end_at',
                'warning_code',
                'remarks',
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
            'crew_assignment_id' => 'integer',
            'crew_assignment_phase_id' => 'integer',
            'phase_code' => CrewPhaseCode::class,
            'pay_category' => CrewTimesheetPayCategory::class,
            'from_date' => 'date',
            'to_date' => 'date',
            'days' => 'decimal:2',
            'source_actual_start_at' => 'datetime',
            'source_actual_end_at' => 'datetime',
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

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(CrewAssignment::class, 'crew_assignment_id');
    }

    public function phase(): BelongsTo
    {
        return $this->belongsTo(CrewAssignmentPhase::class, 'crew_assignment_phase_id');
    }
}
