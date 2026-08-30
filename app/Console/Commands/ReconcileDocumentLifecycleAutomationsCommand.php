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
        $onlyCompanyId = null;

        if ($this->input->hasParameterOption('--company')) {
            $raw = $this->option('company');
            $normalized = is_int($raw)
                ? (string) $raw
                : (is_string($raw) ? $raw : '');

            if ($normalized === '' || ! ctype_digit($normalized) || (int) $normalized <= 0) {
                $this->error('The --company option must be a positive integer.');

                return self::FAILURE;
            }

            $onlyCompanyId = (int) $normalized;
        }

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
