<?php

namespace App\Console\Commands;

use App\Support\EmployeeDocuments\UnmappedEmployeeDocumentTypeMatcher;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use InvalidArgumentException;

#[Signature('employee-documents:backfill-document-types {--dry-run : Report what would be mapped without writing} {--company= : Limit to a company ID}')]
#[Description('Map unmapped EmployeeDocument rows to a DocumentType when exactly one normalized title (or slug) matches')]
class BackfillEmployeeDocumentTypesCommand extends Command
{
    public function handle(UnmappedEmployeeDocumentTypeMatcher $matcher): int
    {
        try {
            $companyId = $matcher->resolveCompanyOption($this->option('company'));
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $counts = $matcher->backfill($companyId, $dryRun);

        $this->info($dryRun
            ? sprintf('Dry run complete. Would map %d row(s).', $counts['mapped'])
            : sprintf('Mapped %d row(s).', $counts['mapped']));
        $this->line(sprintf('Ambiguous left unchanged: %d.', $counts['ambiguous']));
        $this->line(sprintf('Unmatched left unchanged: %d.', $counts['unmatched']));

        if ($dryRun) {
            $this->line('No database changes were written.');
        }

        $this->line('Already-mapped rows are not selected. File contents are not printed.');

        return self::SUCCESS;
    }
}
