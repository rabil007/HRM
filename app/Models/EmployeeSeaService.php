<?php

namespace App\Models;

use App\Models\Concerns\LogsActivityWithCompany;
use Database\Factories\EmployeeSeaServiceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Support\LogOptions;

class EmployeeSeaService extends Model
{
    /** @use HasFactory<EmployeeSeaServiceFactory> */
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
                'sort_order',
                'crew_assignment_phase_id',
                'vessel_type_id',
                'vessel_id',
                'rank_id',
                'start_date',
                'end_date',
                'total_months',
                'total_days',
                'client_id',
                'is_offshore',
            ])
            ->logOnlyDirty();
    }

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'crew_assignment_phase_id' => 'integer',
            'vessel_type_id' => 'integer',
            'vessel_id' => 'integer',
            'rank_id' => 'integer',
            'start_date' => 'date',
            'end_date' => 'date',
            'client_id' => 'integer',
            'total_months' => 'integer',
            'total_days' => 'integer',
            'is_offshore' => 'boolean',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function vesselType(): BelongsTo
    {
        return $this->belongsTo(VesselType::class);
    }

    public function vessel(): BelongsTo
    {
        return $this->belongsTo(Vessel::class);
    }

    public function rank(): BelongsTo
    {
        return $this->belongsTo(Rank::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function crewAssignmentPhase(): BelongsTo
    {
        return $this->belongsTo(CrewAssignmentPhase::class);
    }
}
