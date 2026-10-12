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
 *
 * Defaults without --company: inspects / recovers across all companies
 * (support-wide). Prefer --company in multi-tenant production support.
 */
class RecoverCrewScheduledMovementsCommand extends Command
{
    protected $signature = 'crew:recover-scheduled-movements
                            {--company= : Limit to one company id (recommended for production support)}
                            {--list : List overdue and needs-attention schedules without modifying any records}
                            {--process-due : Also run the normal due processor after recovery (ignored with --list)}
                            {--stale-seconds=300 : Mark processing rows older than this as Needs Attention (ignored with --list)}';

    protected $description = 'List or recover overdue / stale scheduled crew movements for support use';

    public function handle(
        ExecuteCrewScheduledMovement $executor,
        ProcessDueCrewScheduledMovements $processor,
    ): int {
        $now = CrewScheduledMovementTimestamp::nowUtc();
        $nowSql = CrewScheduledMovementTimestamp::sqlUtc($now);
        $companyOption = $this->option('company');
        $companyId = $companyOption !== null && $companyOption !== ''
            ? (int) $companyOption
            : null;
        $listOnly = (bool) $this->option('list');
        $staleSeconds = max(60, (int) $this->option('stale-seconds'));

        if ($companyId !== null) {
            $this->info("Scoped to company id {$companyId}.");
        } else {
            $this->warn('No --company filter: operating across all companies.');
        }

        if ($listOnly) {
            $this->info('Read-only mode (--list): no records will be modified.');
        } else {
            $recovered = $executor->recoverStaleProcessing($now, $staleSeconds, $companyId);
            $this->info("Recovered {$recovered} stale processing row(s).");
        }

        $query = CrewScheduledMovement::query()
            ->where(function ($q) use ($nowSql): void {
                $q->where('status', CrewScheduledMovementStatus::NeedsAttention)
                    ->orWhere(function ($due) use ($nowSql): void {
                        $due->where('status', CrewScheduledMovementStatus::Scheduled)
                            ->where('scheduled_at', '<=', $nowSql);
                    });
            })
            ->orderBy('scheduled_at');

        if ($companyId !== null) {
            $query->where('company_id', $companyId);
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

        if ($listOnly || $rows->isNotEmpty()) {
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
            $this->info($listOnly
                ? "Inspected {$rows->count()} overdue / needs-attention row(s) (no modifications)."
                : "Listed {$rows->count()} overdue / needs-attention row(s).");
        } elseif ($listOnly) {
            $this->info('No overdue or needs-attention schedules found (no modifications).');
        }

        if ($listOnly) {
            if ($this->option('process-due')) {
                $this->warn('--process-due is ignored while --list is set (read-only).');
            }

            return self::SUCCESS;
        }

        if ($this->option('process-due')) {
            $result = $processor->handle(25, $now, $companyId);
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
