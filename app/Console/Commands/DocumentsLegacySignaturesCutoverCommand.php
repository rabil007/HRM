<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Support\BulkDocuments\LegacySalaryDeclarationSignatureCutover;
use Illuminate\Console\Command;

class DocumentsLegacySignaturesCutoverCommand extends Command
{
    protected $signature = 'documents:legacy-signatures-cutover
                            {--company= : Limit the report/export to a company ID}
                            {--export= : Optional CSV path for awaiting_signature employees}';

    protected $description = 'Read-only report of legacy Salary Declaration signature requests (no data changes)';

    public function handle(LegacySalaryDeclarationSignatureCutover $cutover): int
    {
        $companyId = $this->resolveCompanyId();

        if ($companyId === false) {
            return self::FAILURE;
        }

        $exportPath = $this->option('export');
        $exportRequested = is_string($exportPath) && $exportPath !== '';

        if ($exportRequested && $companyId === null) {
            $this->error('The --company option is required when using --export.');

            return self::FAILURE;
        }

        $this->info('Read-only legacy Salary Declaration signature report. No rows or files will be changed.');

        try {
            $reports = $cutover->report($companyId);
        } catch (\InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($reports === []) {
            $this->info('No legacy Salary Declaration signature requests found.');

            if ($exportRequested && $companyId !== null) {
                $cutover->writeEmployeeCsv($exportPath, []);
                $this->info("Wrote reissue CSV: {$exportPath}");
            }

            return self::SUCCESS;
        }

        foreach ($reports as $report) {
            $this->newLine();
            $this->info("Company {$report['company_id']} ({$report['company_name']})");
            $this->line('Total legacy requests: '.$report['total']);
            $this->line('Awaiting signature: '.$report['counts']['awaiting_signature']);
            $this->line('Submitted: '.$report['counts']['submitted']);
            $this->line('Approved: '.$report['counts']['approved']);
            $this->line('Rejected: '.$report['counts']['rejected']);
            $this->line('Expired: '.$report['counts']['expired']);
            $this->line('Cancelled: '.$report['counts']['cancelled']);

            if ($report['awaiting'] === []) {
                continue;
            }

            $this->newLine();
            $this->line('Awaiting signature (eligible for new Company Template generation):');
            $this->table(
                ['request_id', 'employee_id', 'employee_no', 'employee_name', 'employee_document_id', 'document_type_key', 'created_at', 'expires_at'],
                array_map(fn (array $row): array => [
                    $row['request_id'],
                    $row['employee_id'],
                    $row['employee_no'] ?? '',
                    $row['employee_name'],
                    $row['employee_document_id'] ?? '',
                    $row['document_type_key'],
                    $row['created_at'] ?? '',
                    $row['expires_at'] ?? '',
                ], $report['awaiting']),
            );
        }

        if ($exportRequested && $companyId !== null) {
            $awaiting = $reports[0]['awaiting'] ?? [];
            $cutover->writeEmployeeCsv($exportPath, $cutover->awaitingExportRows($awaiting));
            $this->info("Wrote reissue CSV: {$exportPath}");
        }

        return self::SUCCESS;
    }

    private function resolveCompanyId(): int|false|null
    {
        if (! $this->input->hasParameterOption('--company')) {
            return null;
        }

        $raw = $this->option('company');
        $normalized = is_int($raw)
            ? (string) $raw
            : (is_string($raw) ? $raw : '');

        if ($normalized === '' || ! ctype_digit($normalized) || (int) $normalized <= 0) {
            $this->error('The --company option must be a positive integer.');

            return false;
        }

        $companyId = (int) $normalized;

        if (! Company::query()->whereKey($companyId)->exists()) {
            $this->error("Company [{$companyId}] was not found.");

            return false;
        }

        return $companyId;
    }
}
