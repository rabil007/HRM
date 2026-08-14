<?php

namespace App\Models;

use App\Enums\CrewTimesheetPayCategory;
use App\Enums\CrewTimesheetSource;
use App\Models\Concerns\LogsActivityWithCompany;
use Database\Factories\CrewTimesheetSegmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Support\LogOptions;

class CrewTimesheetSegment extends Model
{
    /** @use HasFactory<CrewTimesheetSegmentFactory> */
    use HasFactory;

    use LogsActivityWithCompany;
    use SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'crew_timesheet_id',
        'sequence',
        'pay_category',
        'from_date',
        'to_date',
        'days',
        'source',
        'crew_assignment_id',
        'crew_assignment_phase_id',
        'crew_timesheet_preparation_line_id',
        'remarks',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'company_id',
                'crew_timesheet_id',
                'sequence',
                'pay_category',
                'from_date',
                'to_date',
                'days',
                'source',
                'crew_assignment_id',
                'crew_assignment_phase_id',
                'crew_timesheet_preparation_line_id',
                'remarks',
            ])
            ->logOnlyDirty();
    }

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'pay_category' => CrewTimesheetPayCategory::class,
            'from_date' => 'date',
            'to_date' => 'date',
            'days' => 'decimal:2',
            'source' => CrewTimesheetSource::class,
            'crew_assignment_id' => 'integer',
            'crew_assignment_phase_id' => 'integer',
            'crew_timesheet_preparation_line_id' => 'integer',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function timesheet(): BelongsTo
    {
        return $this->belongsTo(CrewTimesheet::class, 'crew_timesheet_id');
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(CrewAssignment::class, 'crew_assignment_id');
    }

    public function phase(): BelongsTo
    {
        return $this->belongsTo(CrewAssignmentPhase::class, 'crew_assignment_phase_id');
    }

    public function preparationLine(): BelongsTo
    {
        return $this->belongsTo(CrewTimesheetPreparationLine::class, 'crew_timesheet_preparation_line_id');
    }
}
