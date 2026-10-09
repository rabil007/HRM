<?php

namespace App\Models;

use App\Enums\CrewMovementAction;
use App\Enums\CrewScheduledMovementStatus;
use App\Models\Concerns\LogsActivityWithCompany;
use Database\Factories\CrewScheduledMovementFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Support\LogOptions;

class CrewScheduledMovement extends Model
{
    /** @use HasFactory<CrewScheduledMovementFactory> */
    use HasFactory;

    use LogsActivityWithCompany;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'crew_assignment_id',
        'employee_id',
        'movement_action',
        'action_payload',
        'scheduled_at',
        'scheduled_timezone',
        'status',
        'expected_current_phase_id',
        'expected_current_phase_code',
        'expected_current_phase_sequence',
        'expected_vessel_id',
        'expected_result_phase_code',
        'created_by',
        'updated_by',
        'executed_at',
        'effective_occurred_at',
        'cancelled_at',
        'cancelled_by',
        'execution_attempts',
        'last_error_code',
        'last_error_message',
        'processing_started_at',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'movement_action',
                'scheduled_at',
                'scheduled_timezone',
                'status',
                'action_payload',
                'expected_current_phase_id',
                'expected_current_phase_code',
                'expected_vessel_id',
                'expected_result_phase_code',
                'executed_at',
                'effective_occurred_at',
                'cancelled_at',
                'cancelled_by',
                'last_error_code',
                'last_error_message',
                'updated_by',
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
            'employee_id' => 'integer',
            'movement_action' => CrewMovementAction::class,
            'action_payload' => 'array',
            'scheduled_at' => 'datetime',
            'status' => CrewScheduledMovementStatus::class,
            'expected_current_phase_id' => 'integer',
            'expected_current_phase_sequence' => 'integer',
            'expected_vessel_id' => 'integer',
            'created_by' => 'integer',
            'updated_by' => 'integer',
            'executed_at' => 'datetime',
            'effective_occurred_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'cancelled_by' => 'integer',
            'execution_attempts' => 'integer',
            'processing_started_at' => 'datetime',
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

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function expectedCurrentPhase(): BelongsTo
    {
        return $this->belongsTo(CrewAssignmentPhase::class, 'expected_current_phase_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /**
     * @param  Builder<CrewScheduledMovement>  $query
     * @return Builder<CrewScheduledMovement>
     */
    public function scopeForCompany(Builder $query, int $companyId): Builder
    {
        return $query->where('company_id', $companyId);
    }

    /**
     * @param  Builder<CrewScheduledMovement>  $query
     * @return Builder<CrewScheduledMovement>
     */
    public function scopeUnresolved(Builder $query): Builder
    {
        return $query->whereIn('status', CrewScheduledMovementStatus::unresolvedValues());
    }

    /**
     * @param  Builder<CrewScheduledMovement>  $query
     * @return Builder<CrewScheduledMovement>
     */
    public function scopeDue(Builder $query, $asOf = null): Builder
    {
        return $query
            ->where('status', CrewScheduledMovementStatus::Scheduled)
            ->where('scheduled_at', '<=', $asOf ?? now());
    }

    public function isUnresolved(): bool
    {
        return $this->status instanceof CrewScheduledMovementStatus
            && $this->status->isUnresolved();
    }
}
