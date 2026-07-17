<?php

namespace App\Models;

use App\Enums\CrewMovementCorrectionStatus;
use App\Enums\CrewPhaseCode;
use App\Enums\CrewPhaseStatus;
use App\Models\Concerns\LogsActivityWithCompany;
use Database\Factories\CrewAssignmentPhaseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Support\LogOptions;

class CrewAssignmentPhase extends Model
{
    /** @use HasFactory<CrewAssignmentPhaseFactory> */
    use HasFactory;

    use LogsActivityWithCompany;
    use SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'crew_assignment_id',
        'phase_code',
        'sequence',
        'status',
        'planned_start_at',
        'planned_end_at',
        'actual_start_at',
        'actual_end_at',
        'details',
        'remarks',
        'started_by',
        'completed_by',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'crew_assignment_id',
                'phase_code',
                'sequence',
                'status',
                'planned_start_at',
                'planned_end_at',
                'actual_start_at',
                'actual_end_at',
                'details',
                'remarks',
                'started_by',
                'completed_by',
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
            'crew_assignment_id' => 'integer',
            'sequence' => 'integer',
            'started_by' => 'integer',
            'completed_by' => 'integer',
            'phase_code' => CrewPhaseCode::class,
            'status' => CrewPhaseStatus::class,
            'planned_start_at' => 'datetime',
            'planned_end_at' => 'datetime',
            'actual_start_at' => 'datetime',
            'actual_end_at' => 'datetime',
            'details' => 'array',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(CrewAssignment::class, 'crew_assignment_id');
    }

    public function startedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by');
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function seaService(): HasOne
    {
        return $this->hasOne(EmployeeSeaService::class);
    }

    /**
     * @return HasMany<CrewMovementCorrection, $this>
     */
    public function corrections(): HasMany
    {
        return $this->hasMany(CrewMovementCorrection::class);
    }

    /**
     * @return HasMany<CrewMovementCorrection, $this>
     */
    public function pendingCorrections(): HasMany
    {
        return $this->hasMany(CrewMovementCorrection::class)
            ->where('status', CrewMovementCorrectionStatus::Pending);
    }

    /**
     * @param  Builder<CrewAssignmentPhase>  $query
     * @return Builder<CrewAssignmentPhase>
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sequence');
    }

    /**
     * @param  Builder<CrewAssignmentPhase>  $query
     * @return Builder<CrewAssignmentPhase>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', CrewPhaseStatus::Active);
    }

    /**
     * @param  Builder<CrewAssignmentPhase>  $query
     * @return Builder<CrewAssignmentPhase>
     */
    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', CrewPhaseStatus::Completed);
    }
}
