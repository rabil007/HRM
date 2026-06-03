<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\DocumentExpiryAlertService;
use Illuminate\Console\Command;

class SendDocumentExpiryAlertsCommand extends Command
{
    protected $signature = 'documents:send-expiry-alerts {--company= : Limit to a single company ID}';

    protected $description = 'Email document expiry alerts for documents entering the 30-day window';

    public function handle(DocumentExpiryAlertService $alertService): int
    {
        $companyId = $this->option('company');

        $companies = Company::query()
            ->when($companyId !== null, fn ($query) => $query->whereKey((int) $companyId))
            ->orderBy('name')
            ->get();

        if ($companies->isEmpty()) {
            $this->warn('No companies matched.');

            return self::SUCCESS;
        }

        $totalDocuments = 0;
        $companiesNotified = 0;

        foreach ($companies as $company) {
            $sentCount = $alertService->sendForCompany($company);

            if ($sentCount === 0) {
                continue;
            }

            $totalDocuments += $sentCount;
            $companiesNotified++;
            $this->line("{$company->name}: alerted on {$sentCount} document(s).");
        }

        $this->info("Finished. {$companiesNotified} company(ies), {$totalDocuments} document(s) included.");

        return self::SUCCESS;
    }
}
