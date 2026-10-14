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

        $counts = [
            'already_linked' => 0,
            'skipped_no_file' => 0,
            'skipped_duplicate_path' => 0,
            'would_link' => 0,
            'linked' => 0,
        ];

        $query->orderBy('id')->chunkById(100, function ($trainings) use ($integration, $dryRun, &$counts): void {
            foreach ($trainings as $training) {
                if (! $training instanceof EmployeeTraining) {
                    continue;
                }

                $result = $integration->migrateLegacyTrainingCertificate($training, $dryRun);
                $counts[$result] = ($counts[$result] ?? 0) + 1;
            }
        });

        $this->table(['Result', 'Count'], collect($counts)->map(fn (int $count, string $result) => [$result, $count])->all());

        if ($dryRun) {
            $this->info('Dry run complete. No records were modified.');
        }

        return self::SUCCESS;
    }
}
