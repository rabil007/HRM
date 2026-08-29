<?php

namespace App\Console\Commands;

use App\Support\Documents\RecipientRequests\Automation\ReconcileDocumentRecipientRequests;
use Illuminate\Console\Command;

class ReconcileDocumentRecipientRequestsCommand extends Command
{
    protected $signature = 'documents:reconcile-recipient-requests {--company=}';

    protected $description = 'Expire overdue document recipient requests and queue due automatic reminder emails';

    public function handle(ReconcileDocumentRecipientRequests $reconciler): int
    {
        $companyId = $this->option('company');
        $onlyCompanyId = is_numeric($companyId) ? (int) $companyId : null;

        $result = $reconciler->handle($onlyCompanyId);

        $this->info(sprintf(
            'Document recipient reconciliation: expired %d, flows repaired %d, reminders queued %d, reminders suppressed %d, skipped %d.',
            $result['expired'],
            $result['flows_repaired'],
            $result['reminders_queued'],
            $result['reminders_suppressed'],
            $result['skipped'],
        ));

        return self::SUCCESS;
    }
}
