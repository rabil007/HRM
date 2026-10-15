<?php

namespace App\Console\Commands;

use App\Models\EmployeeTraining;
use App\Support\EmployeeTrainings\TrainingCertificateDocumentIntegration;
use Illuminate\Console\Command;

class BackfillTrainingCertificateDocumentsCommand extends Command
{
    protected $signature = 'training:backfill-certificate-documents
                            {--company= : Limit to one company id}
                            {--dry-run : Report actions without writing data}';

    protected $description = 'Link legacy training certificate files to employee documents (idempotent).';

    /** @var list<string> */
    private const REPORT_CATEGORIES = [
        'already_linked',
        'skipped_no_file',
        'invalid_file_path',
        'missing_current_file',
        'missing_historical_version',
        'duplicate_conflicting_reference',
        'would_link',
        'linked',
        'failed',
        'skipped',
    ];

    public function handle(TrainingCertificateDocumentIntegration $integration): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $companyId = $this->option('company');

        $query = EmployeeTraining::query()
            ->whereNull('employee_document_id')
            ->whereNotNull('certificate_path')
            ->where('certificate_path', '!=', '');

        if (is_numeric($companyId)) {
            $query->where('company_id', (int) $companyId);
        }

        $counts = array_fill_keys(self::REPORT_CATEGORIES, 0);

        $query->orderBy('id')->chunkById(100, function ($trainings) use ($integration, $dryRun, &$counts): void {
            foreach ($trainings as $training) {
                if (! $training instanceof EmployeeTraining) {
                    continue;
                }

                $result = $integration->migrateLegacyTrainingCertificate($training, $dryRun);
                $counts[$result] = ($counts[$result] ?? 0) + 1;
            }
        });

        $rows = collect($counts)
            ->filter(fn (int $count): bool => $count > 0)
            ->map(fn (int $count, string $result): array => [$result, $count])
            ->values()
            ->all();

        if ($rows === []) {
            $this->info('No legacy training certificates matched the backfill criteria.');
        } else {
            $this->table(['Result', 'Count'], $rows);
        }

        $this->line('Totals: '.array_sum($counts).' training row(s) evaluated.');

        if ($dryRun) {
            $this->info('Dry run complete. No records were modified.');
        }

        return self::SUCCESS;
    }
}
