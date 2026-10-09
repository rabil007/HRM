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
 */
final class ProcessDueCrewScheduledMovements
{
    public function __construct(
        private readonly ExecuteCrewScheduledMovement $executor,
    ) {}

    /**
     * @return array{claimed: int, executed: int, needs_attention: int, recovered: int}
     */
    public function handle(int $limit = 25, ?Carbon $now = null): array
    {
        $now = ($now ?? CrewScheduledMovementTimestamp::nowUtc())->copy()->utc();
        $nowSql = CrewScheduledMovementTimestamp::sqlUtc($now);

        $recovered = $this->executor->recoverStaleProcessing($now);

        $dueIds = CrewScheduledMovement::query()
            ->where('status', CrewScheduledMovementStatus::Scheduled)
            ->where('scheduled_at', '<=', $nowSql)
            ->orderBy('scheduled_at')
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');

        $claimed = 0;
        $executed = 0;
        $needsAttention = 0;

        foreach ($dueIds as $id) {
            $schedule = $this->executor->claim((int) $id, $now);

            if ($schedule === null) {
                continue;
            }

            $claimed++;

            $result = $this->executor->execute($schedule, $now);

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
