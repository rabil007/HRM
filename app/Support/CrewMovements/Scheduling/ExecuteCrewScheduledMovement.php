<?php

namespace App\Support\CrewMovements\Scheduling;

use App\Enums\CrewScheduledMovementErrorCode;
use App\Enums\CrewScheduledMovementStatus;
use App\Exceptions\CrewMovementException;
use App\Models\CrewAssignment;
use App\Models\CrewScheduledMovement;
use App\Support\CrewMovements\CrewMovementAvailableActions;
use App\Support\CrewMovements\CrewMovementService;
use App\Support\Settings\CompanyTimezone;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Executes one claimed scheduled movement through CrewMovementService.
 *
 * The background executor never trusts browser/request company context.
 * Actor identity for the operational transition is null (system execution);
 * scheduling actor remains on created_by / activity history.
 */
final class ExecuteCrewScheduledMovement
{
    public function __construct(
        private readonly CrewMovementService $movements,
    ) {}

    /**
     * Claim a due scheduled row (status scheduled → processing) if still claimable.
     */
    public function claim(int $scheduleId, Carbon $now): ?CrewScheduledMovement
    {
        return DB::transaction(function () use ($scheduleId, $now): ?CrewScheduledMovement {
            /** @var CrewScheduledMovement|null $schedule */
            $schedule = CrewScheduledMovement::query()
                ->whereKey($scheduleId)
                ->where('status', CrewScheduledMovementStatus::Scheduled)
                ->lockForUpdate()
                ->first();

            if ($schedule === null) {
                return null;
            }

            $timezone = $schedule->scheduled_timezone
                ?: CompanyTimezone::forCompanyId((int) $schedule->company_id);
            $scheduledLocal = Carbon::parse(
                $schedule->scheduled_at?->format('Y-m-d H:i:s') ?? '',
                $timezone,
            );
            $nowLocal = $now->copy()->timezone($timezone);

            if ($scheduledLocal->greaterThan($nowLocal)) {
                return null;
            }

            $schedule->update([
                'status' => CrewScheduledMovementStatus::Processing,
                'processing_started_at' => $nowLocal->format('Y-m-d H:i:s'),
                'execution_attempts' => ((int) $schedule->execution_attempts) + 1,
            ]);

            return $schedule->fresh();
        });
    }

    public function recoverStaleProcessing(Carbon $now, int $staleAfterSeconds = 300): int
    {
        return CrewScheduledMovement::query()
            ->where('status', CrewScheduledMovementStatus::Processing)
            ->where('processing_started_at', '<=', $now->copy()->subSeconds($staleAfterSeconds))
            ->update([
                'status' => CrewScheduledMovementStatus::NeedsAttention->value,
                'last_error_code' => CrewScheduledMovementErrorCode::StaleProcessing->value,
                'last_error_message' => 'Processing did not complete; recovered as Needs Attention.',
                'processing_started_at' => null,
            ]);
    }

    public function execute(CrewScheduledMovement $schedule, Carbon $now): CrewScheduledMovement
    {
        $companyId = (int) $schedule->company_id;
        $timezone = $schedule->scheduled_timezone
            ?: CompanyTimezone::forCompanyId($companyId);

        // scheduled_at is stored as company-local wall time; compare in that zone.
        $scheduledLocal = Carbon::parse(
            $schedule->scheduled_at?->format('Y-m-d H:i:s') ?? '',
            $timezone,
        );
        $nowLocal = $now->copy()->timezone($timezone);

        if (! CrewScheduledMovementLatenessPolicy::isWithinTolerance($scheduledLocal, $nowLocal)) {
            return $this->markNeedsAttention(
                $schedule,
                CrewScheduledMovementErrorCode::LatenessExceeded,
                sprintf(
                    'Automatic execution was delayed beyond the %d-minute tolerance. The movement was not backdated. Review and record the actual movement or reschedule.',
                    CrewScheduledMovementLatenessPolicy::toleranceMinutes(),
                ),
            );
        }

        try {
            return DB::transaction(function () use ($schedule, $companyId, $now, $nowLocal): CrewScheduledMovement {
                /** @var CrewScheduledMovement|null $locked */
                $locked = CrewScheduledMovement::query()
                    ->whereKey($schedule->id)
                    ->where('company_id', $companyId)
                    ->lockForUpdate()
                    ->first();

                if ($locked === null || $locked->status !== CrewScheduledMovementStatus::Processing) {
                    return $schedule->fresh() ?? $schedule;
                }

                $assignment = CrewAssignment::query()
                    ->where('company_id', $companyId)
                    ->whereKey($locked->crew_assignment_id)
                    ->lockForUpdate()
                    ->with('currentPhase')
                    ->first();

                if ($assignment === null) {
                    return $this->markNeedsAttentionLocked(
                        $locked,
                        CrewScheduledMovementErrorCode::AssignmentStateChanged,
                        'The crew assignment no longer exists for this company.',
                    );
                }

                if ((int) $assignment->employee_id !== (int) $locked->employee_id) {
                    return $this->markNeedsAttentionLocked(
                        $locked,
                        CrewScheduledMovementErrorCode::AssignmentStateChanged,
                        'The assignment employee no longer matches the scheduled snapshot.',
                    );
                }

                $current = $assignment->currentPhase;

                if ($locked->expected_current_phase_id !== null
                    && (int) ($current?->id) !== (int) $locked->expected_current_phase_id) {
                    return $this->markNeedsAttentionLocked(
                        $locked,
                        CrewScheduledMovementErrorCode::PhaseChanged,
                        sprintf(
                            'Expected phase snapshot no longer matches (expected phase id %d, current %s).',
                            $locked->expected_current_phase_id,
                            $current?->id ?? 'none',
                        ),
                    );
                }

                if ($locked->expected_current_phase_code !== null
                    && ($current?->phase_code?->value) !== $locked->expected_current_phase_code) {
                    return $this->markNeedsAttentionLocked(
                        $locked,
                        CrewScheduledMovementErrorCode::PhaseChanged,
                        sprintf(
                            'Current phase changed from %s to %s before scheduled execution.',
                            $locked->expected_current_phase_code,
                            $current?->phase_code?->value ?? 'none',
                        ),
                    );
                }

                if ($locked->expected_vessel_id !== null
                    && (int) ($assignment->vessel_id ?? 0) !== (int) $locked->expected_vessel_id
                    && ! in_array($locked->movement_action->value, ['transfer_vessel', 'redeploy'], true)) {
                    return $this->markNeedsAttentionLocked(
                        $locked,
                        CrewScheduledMovementErrorCode::AssignmentStateChanged,
                        'Assignment vessel changed incompatibly before scheduled execution.',
                    );
                }

                $available = CrewMovementAvailableActions::for($assignment);

                if (! in_array($locked->movement_action->value, $available, true)) {
                    return $this->markNeedsAttentionLocked(
                        $locked,
                        CrewScheduledMovementErrorCode::ActionNoLongerEligible,
                        sprintf(
                            '%s is no longer available for the current assignment state.',
                            $locked->movement_action->label(),
                        ),
                    );
                }

                $occurredAtLocal = $nowLocal->format('Y-m-d H:i:s');
                $payload = CrewScheduledMovementPayload::forExecution(
                    $locked->action_payload ?? [],
                    $occurredAtLocal,
                );

                // Nested perform() opens its own transaction (savepoint).
                $this->movements->perform(
                    $companyId,
                    (int) $assignment->id,
                    $locked->movement_action,
                    $payload,
                    null,
                );

                $locked->update([
                    'status' => CrewScheduledMovementStatus::Executed,
                    'executed_at' => $nowLocal->format('Y-m-d H:i:s'),
                    'effective_occurred_at' => $occurredAtLocal,
                    'processing_started_at' => null,
                    'last_error_code' => null,
                    'last_error_message' => null,
                ]);

                activity()
                    ->performedOn($locked)
                    ->withProperties([
                        'event' => 'crew_scheduled_movement_executed',
                        'company_id' => $companyId,
                        'crew_assignment_id' => $locked->crew_assignment_id,
                        'movement_action' => $locked->movement_action->value,
                        'scheduled_at' => $locked->scheduled_at?->toIso8601String(),
                        'executed_at' => $now->toIso8601String(),
                        'effective_occurred_at' => $occurredAtLocal,
                        'executor' => 'system_automatic',
                        'scheduled_by' => $locked->created_by,
                    ])
                    ->log('Scheduled crew movement executed automatically');

                return $locked->fresh() ?? $locked;
            });
        } catch (CrewMovementException $e) {
            Log::warning('crew.scheduled_movement.failed', [
                'schedule_id' => $schedule->id,
                'company_id' => $companyId,
                'error_code' => $e->errorCode,
                'message' => $e->getMessage(),
            ]);

            $code = match ($e->errorCode) {
                'occurred_at_in_future', 'invalid_timestamp', 'chronology_violation' => CrewScheduledMovementErrorCode::ChronologyInvalid,
                'assignment_conflict', 'active_on_vessel_conflict' => CrewScheduledMovementErrorCode::ConflictDetected,
                'accommodation_invalid', 'hotel_checkout_invalid' => CrewScheduledMovementErrorCode::AccommodationInvalid,
                default => CrewScheduledMovementErrorCode::MovementFailed,
            };

            return $this->markNeedsAttention($schedule, $code, $this->sanitizeMessage($e->getMessage()));
        } catch (Throwable $e) {
            Log::error('crew.scheduled_movement.unexpected', [
                'schedule_id' => $schedule->id,
                'company_id' => $companyId,
                'message' => $e->getMessage(),
            ]);

            return $this->markNeedsAttention(
                $schedule,
                CrewScheduledMovementErrorCode::MovementFailed,
                $this->sanitizeMessage(
                    'Automatic execution failed: '.$e->getMessage(),
                ),
            );
        }
    }

    private function markNeedsAttention(
        CrewScheduledMovement $schedule,
        CrewScheduledMovementErrorCode $code,
        string $message,
    ): CrewScheduledMovement {
        return DB::transaction(function () use ($schedule, $code, $message): CrewScheduledMovement {
            /** @var CrewScheduledMovement|null $locked */
            $locked = CrewScheduledMovement::query()
                ->whereKey($schedule->id)
                ->lockForUpdate()
                ->first();

            if ($locked === null) {
                return $schedule;
            }

            if ($locked->status === CrewScheduledMovementStatus::Executed
                || $locked->status === CrewScheduledMovementStatus::Cancelled) {
                return $locked;
            }

            return $this->markNeedsAttentionLocked($locked, $code, $message);
        });
    }

    private function markNeedsAttentionLocked(
        CrewScheduledMovement $locked,
        CrewScheduledMovementErrorCode $code,
        string $message,
    ): CrewScheduledMovement {
        $locked->update([
            'status' => CrewScheduledMovementStatus::NeedsAttention,
            'last_error_code' => $code->value,
            'last_error_message' => $this->sanitizeMessage($message),
            'processing_started_at' => null,
        ]);

        activity()
            ->performedOn($locked)
            ->withProperties([
                'event' => 'crew_scheduled_movement_needs_attention',
                'company_id' => $locked->company_id,
                'crew_assignment_id' => $locked->crew_assignment_id,
                'movement_action' => $locked->movement_action->value,
                'error_code' => $code->value,
                'executor' => 'system_automatic',
            ])
            ->log('Scheduled crew movement needs attention');

        return $locked->fresh() ?? $locked;
    }

    private function sanitizeMessage(string $message): string
    {
        return mb_substr(trim(preg_replace('/\s+/', ' ', $message) ?? $message), 0, 1000);
    }
}
