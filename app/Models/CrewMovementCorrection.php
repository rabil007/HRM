<?php

namespace App\Models;

use App\Enums\CrewMovementCorrectionStatus;
use App\Models\Concerns\LogsActivityWithCompany;
use Database\Factories\CrewMovementCorrectionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Support\LogOptions;

class CrewMovementCorrection extends Model
{
    /** @use HasFactory<CrewMovementCorrectionFactory> */
    use HasFactory;

    use LogsActivityWithCompany;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'crew_assignment_id',
        'crew_assignment_phase_id',
        'status',
        'original_values',
        'proposed_values',
        'applied_values',
        'reason',
        'decision_notes',
        'requested_by',
        'decided_by',
        'requested_at',
        'decided_at',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'status',
                'proposed_values',
                'applied_values',
                'reason',
                'decision_notes',
                'requested_by',
                'decided_by',
                'requested_at',
                'decided_at',
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
            'crew_assignment_phase_id' => 'integer',
            'requested_by' => 'integer',
            'decided_by' => 'integer',
            'status' => CrewMovementCorrectionStatus::class,
            'original_values' => 'array',
            'proposed_values' => 'array',
            'applied_values' => 'array',
            'requested_at' => 'datetime',
            'decided_at' => 'datetime',
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

    public function phase(): BelongsTo
    {
        return $this->belongsTo(CrewAssignmentPhase::class, 'crew_assignment_phase_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decisionMaker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /**
     * @param  Builder<CrewMovementCorrection>  $query
     * @return Builder<CrewMovementCorrection>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', CrewMovementCorrectionStatus::Pending);
    }

    /**
     * @param  Builder<CrewMovementCorrection>  $query
     * @return Builder<CrewMovementCorrection>
     */
    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', CrewMovementCorrectionStatus::Approved);
    }
}
