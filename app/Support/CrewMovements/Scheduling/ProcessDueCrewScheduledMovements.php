<?php

namespace App\Support\CrewMovements\Scheduling;

use App\Enums\CrewScheduledMovementStatus;
use App\Models\CrewScheduledMovement;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Cron-compatible processor for due scheduled crew movements.
 *
 * Designed for shared hosting where `schedule:run` may be the only reliable
 * runner — work is bounded and executed inline without requiring a long-lived
 * queue worker.
 *
 * Due comparison uses UTC instants stored in scheduled_at.
 * Each movement captures a fresh execution timestamp at claim/execute time so
 * slow batches do not reuse a stale batch-start clock for later rows.
 */
final class ProcessDueCrewScheduledMovements
{
    public function __construct(
        private readonly ExecuteCrewScheduledMovement $executor,
    ) {}

    /**
     * @return array{claimed: int, executed: int, needs_attention: int, recovered: int}
     */
    public function handle(int $limit = 25, ?Carbon $now = null, ?int $companyId = null): array
    {
        $selectionNow = ($now ?? CrewScheduledMovementTimestamp::nowUtc())->copy()->utc();
        $selectionSql = CrewScheduledMovementTimestamp::sqlUtc($selectionNow);

        $recovered = $this->executor->recoverStaleProcessing($selectionNow, companyId: $companyId);

        $dueQuery = CrewScheduledMovement::query()
            ->where('status', CrewScheduledMovementStatus::Scheduled)
            ->where('scheduled_at', '<=', $selectionSql)
            ->orderBy('scheduled_at')
            ->orderBy('id')
            ->limit($limit);

        if ($companyId !== null) {
            $dueQuery->where('company_id', $companyId);
        }

        $dueIds = $dueQuery->pluck('id');

        $claimed = 0;
        $executed = 0;
        $needsAttention = 0;

        foreach ($dueIds as $id) {
            // Fresh per-movement clock (honours Carbon::setTestNow in tests).
            // Avoids assigning a stale batch-start timestamp to later executions.
            $movementNow = CrewScheduledMovementTimestamp::nowUtc();

            $schedule = $this->executor->claim((int) $id, $movementNow);

            if ($schedule === null) {
                continue;
            }

            $claimed++;

            $executionNow = CrewScheduledMovementTimestamp::nowUtc();
            $result = $this->executor->execute($schedule, $executionNow);

            if ($result->status === CrewScheduledMovementStatus::Executed) {
                $executed++;
            } elseif ($result->status === CrewScheduledMovementStatus::NeedsAttention) {
                $needsAttention++;
            }
        }

        if ($claimed > 0 || $recovered > 0) {
            Log::info('crew.scheduled_movements.processed', [
                'claimed' => $claimed,
                'executed' => $executed,
                'needs_attention' => $needsAttention,
                'recovered' => $recovered,
                'company_id' => $companyId,
            ]);
        }

        return [
            'claimed' => $claimed,
            'executed' => $executed,
            'needs_attention' => $needsAttention,
            'recovered' => $recovered,
        ];
    }
}
