<?php

namespace App\Console\Commands;

use App\Support\EmployeeDocuments\UnmappedEmployeeDocumentTypeMatcher;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use InvalidArgumentException;

#[Signature('employee-documents:audit-unmapped-types {--company= : Limit to a company ID}')]
#[Description('Report EmployeeDocument rows with a null document_type_id (read-only; does not repair data)')]
class AuditUnmappedEmployeeDocumentTypesCommand extends Command
{
    public function handle(UnmappedEmployeeDocumentTypeMatcher $matcher): int
    {
        try {
            $companyId = $matcher->resolveCompanyOption($this->option('company'));
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $report = $matcher->audit($companyId);

        if ($report['total'] === 0) {
            $this->info('No unmapped employee documents found.');

            return self::SUCCESS;
        }

        $this->warn(sprintf('Found %d unmapped employee document row(s).', $report['total']));
        $this->line(sprintf(
            'Deterministic matches: %d. Ambiguous: %d. Unmatched: %d.',
            $report['match'],
            $report['ambiguous'],
            $report['unmatched'],
        ));

        $this->table(
            ['company_id', 'unmapped_rows'],
            collect($report['by_company'])
                ->map(fn (int $count, int $id): array => [$id, $count])
                ->values()
                ->all(),
        );

        $this->table(
            ['company_id', 'legacy_document_type', 'rows', 'match', 'document_type_id', 'document_type_title'],
            collect($report['legacy_values'])
                ->map(fn (array $row): array => [
                    $row['company_id'],
                    $row['legacy_value'],
                    $row['rows'],
                    $row['status'],
                    $row['document_type_id'] ?? '',
                    $row['document_type_title'] ?? '',
                ])
                ->all(),
        );

        $this->newLine();
        $this->line('Unmapped rows do not satisfy required-document compliance until they are deterministically mapped.');
        $this->line('Preview a repair with: php artisan employee-documents:backfill-document-types --dry-run');
        $this->line('Ambiguous and unmatched rows are left unchanged. File contents are not printed.');

        return self::SUCCESS;
    }
}
