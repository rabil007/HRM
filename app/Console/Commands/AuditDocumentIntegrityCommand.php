<?php

namespace App\Console\Commands;

use App\Support\Documents\Integrity\DocumentIntegrityAudit;
use App\Support\Documents\Integrity\DocumentIntegrityAuditResult;
use Illuminate\Console\Command;

class AuditDocumentIntegrityCommand extends Command
{
    protected $signature = 'documents:audit-integrity {--company=} {--verify-files} {--repair-safe}';

    protected $description = 'Audit unified document integrity (read-only by default; optional file verification and safe repair)';

    public function handle(DocumentIntegrityAudit $audit): int
    {
        $onlyCompanyId = null;

        if ($this->input->hasParameterOption('--company')) {
            $raw = $this->option('company');
            $normalized = is_int($raw)
                ? (string) $raw
                : (is_string($raw) ? $raw : '');

            if ($normalized === '' || ! ctype_digit($normalized) || (int) $normalized <= 0) {
                $this->error('The --company option must be a positive integer.');

                return self::FAILURE;
            }

            $onlyCompanyId = (int) $normalized;
        }

        $result = $audit->handle(
            $onlyCompanyId,
            (bool) $this->option('verify-files'),
            (bool) $this->option('repair-safe'),
        );

        $this->line('Critical: '.$result->criticalCount());
        $this->line('High: '.$result->highCount());
        $this->line('Warning: '.$result->warningCount());
        $this->line('Repairable: '.$result->repairableCount());
        $this->line('Repaired: '.$result->repaired());

        $rows = $result->tableRows(DocumentIntegrityAuditResult::TABLE_LIMIT);

        if ($rows !== []) {
            $this->newLine();
            $this->table(
                ['code', 'entity', 'id', 'severity'],
                array_map(
                    fn ($issue): array => [
                        $issue->code,
                        $issue->entityType,
                        (string) $issue->entityId,
                        $issue->severity->value,
                    ],
                    $rows,
                ),
            );

            $remaining = $result->totalIssueCount() - count($rows);

            if ($remaining > 0) {
                $this->line('Showing first '.DocumentIntegrityAuditResult::TABLE_LIMIT." issues ({$remaining} more omitted).");
            }
        }

        return self::SUCCESS;
    }
}
