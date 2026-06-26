<?php

namespace App\Jobs;

use App\Models\Company;
use App\Models\JobRun;
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
        $company = Company::query()->find($this->companyId);
        $companyName = $company ? (string) $company->name : "Company #{$this->companyId}";

        $alertService->sendForCompany($this->companyId);

        $jobId = $this->job ? $this->job->uuid() : null;
        if ($jobId) {
            JobRun::query()->where('correlation_id', $jobId)->update([
                'message' => "Successfully processed document expiry alerts for {$companyName}.",
                'context' => [
                    'company_id' => $this->companyId,
                    'company_name' => $companyName,
                ],
            ]);
        }
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
