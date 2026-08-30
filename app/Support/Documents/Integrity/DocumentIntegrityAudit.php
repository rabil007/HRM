<?php

namespace App\Support\Documents\Integrity;

use App\Models\Company;

final class DocumentIntegrityAudit
{
    public function __construct(
        private DocumentIntegrityInspector $inspector,
        private DocumentIntegritySafeRepair $repair,
    ) {}

    /**
     * Read-only by default. Pass $repairSafe to apply deterministic repairs only.
     *
     * Safe repairs stream through every repairable issue as it is discovered
     * (not only the retained diagnostic sample).
     */
    public function handle(
        ?int $onlyCompanyId = null,
        bool $verifyFiles = false,
        bool $repairSafe = false,
    ): DocumentIntegrityAuditResult {
        $result = new DocumentIntegrityAuditResult;

        if ($repairSafe) {
            $result->setIssueConsumer(function (DocumentIntegrityIssue $issue) use ($result): void {
                if (! $issue->repairable) {
                    return;
                }

                if ($this->repair->repair($issue)) {
                    $result->incrementRepaired();
                }
            });
        }

        $this->eachCompanyId($onlyCompanyId, function (int $companyId) use ($verifyFiles, $result): void {
            $this->inspector->inspectCompany($companyId, $verifyFiles, $result);
        });

        return $result;
    }

    /**
     * @param  callable(int): void  $callback
     */
    private function eachCompanyId(?int $onlyCompanyId, callable $callback): void
    {
        if ($onlyCompanyId !== null) {
            $callback($onlyCompanyId);

            return;
        }

        Company::query()
            ->orderBy('id')
            ->chunkById(DocumentIntegrityInspector::CHUNK_SIZE, function ($companies) use ($callback): void {
                foreach ($companies as $company) {
                    $callback((int) $company->id);
                }
            });
    }
}
