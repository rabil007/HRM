<?php

namespace App\Models;

use App\Enums\CrewAssignmentStatus;
use App\Models\Concerns\LogsActivityWithCompany;
use Database\Factories\CrewAssignmentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Support\LogOptions;

class CrewAssignment extends Model
{
    /** @use HasFactory<CrewAssignmentFactory> */
    use HasFactory;

    use LogsActivityWithCompany;
    use SoftDeletes;

    protected $guarded = [];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'employee_id',
                'rank_id',
                'client_id',
                'vessel_id',
                'company_visa_type_id',
                'status',
                'current_phase_id',
                'planned_join_at',
                'planned_signoff_at',
                'planned_travel_at',
                'started_at',
                'closed_at',
                'previous_assignment_id',
                'employee_deployment_id',
                'crew_planning_assignment_id',
                'source',
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
            'employee_id' => 'integer',
            'rank_id' => 'integer',
            'client_id' => 'integer',
            'vessel_id' => 'integer',
            'company_visa_type_id' => 'integer',
            'current_phase_id' => 'integer',
            'previous_assignment_id' => 'integer',
            'employee_deployment_id' => 'integer',
            'crew_planning_assignment_id' => 'integer',
            'created_by' => 'integer',
            'updated_by' => 'integer',
            'status' => CrewAssignmentStatus::class,
            'planned_join_at' => 'datetime',
            'planned_signoff_at' => 'datetime',
            'planned_travel_at' => 'datetime',
            'started_at' => 'datetime',
            'closed_at' => 'datetime',
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

    public function rank(): BelongsTo
    {
        return $this->belongsTo(Rank::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function vessel(): BelongsTo
    {
        return $this->belongsTo(Vessel::class);
    }

    public function companyVisaType(): BelongsTo
    {
        return $this->belongsTo(CompanyVisaType::class);
    }

    /**
     * @return HasMany<CrewAssignmentPhase, $this>
     */
    public function phases(): HasMany
    {
        return $this->hasMany(CrewAssignmentPhase::class)->orderBy('sequence');
    }

    public function currentPhase(): BelongsTo
    {
        return $this->belongsTo(CrewAssignmentPhase::class, 'current_phase_id');
    }

    public function previousAssignment(): BelongsTo
    {
        return $this->belongsTo(self::class, 'previous_assignment_id');
    }

    /**
     * @return HasMany<CrewAssignment, $this>
     */
    public function nextAssignments(): HasMany
    {
        return $this->hasMany(self::class, 'previous_assignment_id');
    }

    public function employeeDeployment(): BelongsTo
    {
        return $this->belongsTo(EmployeeDeployment::class);
    }

    public function crewPlanningAssignment(): BelongsTo
    {
        return $this->belongsTo(CrewPlanningAssignment::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * @param  Builder<CrewAssignment>  $query
     * @return Builder<CrewAssignment>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', CrewAssignmentStatus::Active);
    }

    /**
     * @param  Builder<CrewAssignment>  $query
     * @return Builder<CrewAssignment>
     */
    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', CrewAssignmentStatus::Completed);
    }
}
