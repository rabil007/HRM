<?php

namespace App\Console\Commands;

use App\Jobs\SendCompanyDocumentExpiryAlertJob;
use App\Jobs\SendDocumentExpiryAlertJob;
use App\Models\Company;
use App\Services\CompanyDocumentExpiryAlertService;
use App\Services\DocumentExpiryAlertService;
use Illuminate\Console\Command;

class DispatchDocumentExpiryAlertsCommand extends Command
{
    protected $signature = 'documents:dispatch-expiry-alerts {--company= : Limit to a single company ID}';

    protected $description = 'Dispatch queued document expiry alert jobs for companies with newly eligible documents';

    public function handle(
        DocumentExpiryAlertService $employeeAlertService,
        CompanyDocumentExpiryAlertService $companyAlertService,
    ): int {
        $companyId = $this->option('company');

        $companies = Company::query()
            ->when($companyId !== null, fn ($query) => $query->whereKey((int) $companyId))
            ->orderBy('name')
            ->get();

        if ($companies->isEmpty()) {
            $this->warn('No companies matched.');

            return self::SUCCESS;
        }

        $employeeJobsDispatched = 0;
        $companyJobsDispatched = 0;

        // Guard employee document alerts on template configuration.
        $employeeRecipientsConfigured = $employeeAlertService->resolveRecipients()['recipient'] !== '';

        if (! $employeeRecipientsConfigured) {
            $this->warn('Employee document expiry alert template has no To preset or is disabled. Configure it under Settings → Email templates.');
        }

        foreach ($companies as $company) {
            if ($employeeRecipientsConfigured && $employeeAlertService->hasPendingDocuments((int) $company->id)) {
                SendDocumentExpiryAlertJob::dispatch((int) $company->id);
                $employeeJobsDispatched++;
                $this->line("Dispatched employee document expiry alert job for {$company->name}.");
            }

            if ($companyAlertService->hasPendingDocuments((int) $company->id)) {
                SendCompanyDocumentExpiryAlertJob::dispatch((int) $company->id);
                $companyJobsDispatched++;
                $this->line("Dispatched company document expiry alert job for {$company->name}.");
            }
        }

        $this->info("Finished. {$employeeJobsDispatched} employee job(s) and {$companyJobsDispatched} company job(s) dispatched.");

        return self::SUCCESS;
    }
}
