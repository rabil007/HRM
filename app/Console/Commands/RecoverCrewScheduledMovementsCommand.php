<?php

namespace App\Console\Commands;

use App\Enums\CrewScheduledMovementStatus;
use App\Models\CrewScheduledMovement;
use App\Support\CrewMovements\Scheduling\CrewScheduledMovementTimestamp;
use App\Support\CrewMovements\Scheduling\ExecuteCrewScheduledMovement;
use App\Support\CrewMovements\Scheduling\ProcessDueCrewScheduledMovements;
use Illuminate\Console\Command;

/**
 * Support recovery path for overdue / stale schedules.
 * Not a product UI manual confirmation mode. Operators still use Record Now
 * or reschedule after reviewing Needs Attention items.
 */
class RecoverCrewScheduledMovementsCommand extends Command
{
    protected $signature = 'crew:recover-scheduled-movements
                            {--company= : Limit to one company id}
                            {--list : List overdue and needs-attention schedules without executing}
                            {--process-due : Also run the normal due processor after recovery}
                            {--stale-seconds=300 : Mark processing rows older than this as Needs Attention}';

    protected $description = 'List or recover overdue / stale scheduled crew movements for support use';

    public function handle(
        ExecuteCrewScheduledMovement $executor,
        ProcessDueCrewScheduledMovements $processor,
    ): int {
        $now = CrewScheduledMovementTimestamp::nowUtc();
        $nowSql = CrewScheduledMovementTimestamp::sqlUtc($now);
        $companyId = $this->option('company');
        $staleSeconds = max(60, (int) $this->option('stale-seconds'));

        $recovered = $executor->recoverStaleProcessing($now, $staleSeconds);
        $this->info("Recovered {$recovered} stale processing row(s).");

        $query = CrewScheduledMovement::query()
            ->where(function ($q) use ($nowSql): void {
                $q->where('status', CrewScheduledMovementStatus::NeedsAttention)
                    ->orWhere(function ($due) use ($nowSql): void {
                        $due->where('status', CrewScheduledMovementStatus::Scheduled)
                            ->where('scheduled_at', '<=', $nowSql);
                    });
            })
            ->orderBy('scheduled_at');

        if (is_string($companyId) && $companyId !== '') {
            $query->where('company_id', (int) $companyId);
        }

        $rows = $query->limit(100)->get([
            'id',
            'company_id',
            'crew_assignment_id',
            'movement_action',
            'status',
            'scheduled_at',
            'last_error_code',
            'last_error_message',
        ]);

        if ($this->option('list') || $rows->isNotEmpty()) {
            $this->table(
                ['id', 'company', 'assignment', 'action', 'status', 'scheduled_at', 'error'],
                $rows->map(fn (CrewScheduledMovement $row): array => [
                    $row->id,
                    $row->company_id,
                    $row->crew_assignment_id,
                    $row->movement_action->value,
                    $row->status->value,
                    $row->scheduled_at?->utc()->toDateTimeString().'Z',
                    $row->last_error_code,
                ])->all(),
            );
        }

        if ($this->option('process-due')) {
            $result = $processor->handle(25, $now);
            $this->info(sprintf(
                'Processed due: claimed %d, executed %d, needs attention %d.',
                $result['claimed'],
                $result['executed'],
                $result['needs_attention'],
            ));
        }

        return self::SUCCESS;
    }
}
