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
     */
    public function handle(
        ?int $onlyCompanyId = null,
        bool $verifyFiles = false,
        bool $repairSafe = false,
    ): DocumentIntegrityAuditResult {
        $result = new DocumentIntegrityAuditResult;

        $this->eachCompanyId($onlyCompanyId, function (int $companyId) use ($verifyFiles, $repairSafe, $result): void {
            $beforeCount = count($result->issues());
            $this->inspector->inspectCompany($companyId, $verifyFiles, $result);

            if (! $repairSafe) {
                return;
            }

            foreach (array_slice($result->issues(), $beforeCount) as $issue) {
                if (! $issue->repairable || $issue->companyId !== $companyId) {
                    continue;
                }

                if ($this->repair->repair($issue)) {
                    $result->incrementRepaired();
                }
            }
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
