<?php

namespace App\Console\Commands;

use App\Jobs\SendDocumentExpiryAlertJob;
use App\Models\Company;
use App\Services\DocumentExpiryAlertService;
use Illuminate\Console\Command;

class DispatchDocumentExpiryAlertsCommand extends Command
{
    protected $signature = 'documents:dispatch-expiry-alerts {--company= : Limit to a single company ID}';

    protected $description = 'Dispatch queued document expiry alert jobs for companies with newly eligible documents';

    public function handle(DocumentExpiryAlertService $alertService): int
    {
        if ($alertService->resolveRecipients()['recipient'] === '') {
            $this->warn('Document expiry alert template has no To preset or is disabled. Configure it under Settings → Email templates.');

            return self::SUCCESS;
        }

        $companyId = $this->option('company');

        $companies = Company::query()
            ->when($companyId !== null, fn ($query) => $query->whereKey((int) $companyId))
            ->orderBy('name')
            ->get();

        if ($companies->isEmpty()) {
            $this->warn('No companies matched.');

            return self::SUCCESS;
        }

        $jobsDispatched = 0;

        foreach ($companies as $company) {
            if (! $alertService->hasPendingDocuments((int) $company->id)) {
                continue;
            }

            SendDocumentExpiryAlertJob::dispatch((int) $company->id);
            $jobsDispatched++;
            $this->line("Dispatched expiry alert job for {$company->name}.");
        }

        $this->info("Finished. {$jobsDispatched} job(s) dispatched.");

        return self::SUCCESS;
    }
}
