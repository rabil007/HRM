<?php

namespace App\Console\Commands;

use App\Support\Documents\Lifecycle\ReconcileDocumentLifecycleAutomations;
use Illuminate\Console\Command;

class ReconcileDocumentLifecycleAutomationsCommand extends Command
{
    protected $signature = 'documents:reconcile-lifecycle-automations {--company=}';

    protected $description = 'Crash-recovery reconciliation for document lifecycle automations (pending start, review terminals, signing sync)';

    public function handle(ReconcileDocumentLifecycleAutomations $reconciler): int
    {
        $companyId = $this->option('company');
        $onlyCompanyId = is_numeric($companyId) ? (int) $companyId : null;

        $result = $reconciler->handle($onlyCompanyId);

        $this->info(sprintf(
            'Document lifecycle reconciliation: pending started %d, reviews synced %d, signings synced %d.',
            $result['pending_started'],
            $result['reviews_synced'],
            $result['signings_synced'],
        ));

        return self::SUCCESS;
    }
}
