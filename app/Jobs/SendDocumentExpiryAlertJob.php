<?php

namespace App\Jobs;

use App\Models\Company;
use App\Services\DocumentExpiryAlertService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class SendDocumentExpiryAlertJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function __construct(public int $companyId) {}

    public function handle(DocumentExpiryAlertService $alertService): void
    {
        $alertService->sendForCompany($this->companyId);
    }

    public function failed(Throwable $exception): void
    {
        $company = Company::query()->find($this->companyId);

        if ($company === null) {
            report($exception);

            return;
        }

        app(DocumentExpiryAlertService::class)->logFailure($company, $exception);
    }
}
