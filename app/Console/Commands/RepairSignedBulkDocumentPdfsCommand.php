<?php

namespace App\Console\Commands;

use App\Enums\BulkDocumentSignatureRequestStatus;
use App\Models\BulkDocumentSignatureRequest;
use App\Support\BulkDocuments\RegenerateSignedBulkDocumentPdf;
use Illuminate\Console\Command;

class RepairSignedBulkDocumentPdfsCommand extends Command
{
    protected $signature = 'bulk-documents:repair-signed-pdfs
        {--company= : Limit to a company ID}
        {--request= : Repair a single signature request ID}
        {--dry-run : Show what would be repaired without writing files}';

    protected $description = 'Re-render signed salary declaration PDFs from stored signature images';

    public function handle(RegenerateSignedBulkDocumentPdf $regenerator): int
    {
        $query = BulkDocumentSignatureRequest::query()
            ->where('document_type_key', 'salary_declaration')
            ->whereIn('status', [
                BulkDocumentSignatureRequestStatus::Submitted,
                BulkDocumentSignatureRequestStatus::Approved,
            ])
            ->whereNotNull('signature_image_path')
            ->whereNotNull('signed_pdf_path');

        if ($companyId = $this->option('company')) {
            $query->where('company_id', (int) $companyId);
        }

        if ($requestId = $this->option('request')) {
            $query->whereKey((int) $requestId);
        }

        $requests = $query->with(['employee', 'employeeDocument'])->get();
        $dryRun = (bool) $this->option('dry-run');
        $repaired = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($requests as $request) {
            $employeeName = (string) ($request->employee?->name ?? 'unknown');

            if ($dryRun) {
                $this->line("Would repair request #{$request->id} ({$employeeName})");

                continue;
            }

            $result = $regenerator->handle($request, forceTemplateRender: true);

            if ($result === 'repaired') {
                $this->info("Repaired request #{$request->id} ({$employeeName})");
                $repaired++;

                continue;
            }

            if ($result === 'skipped') {
                $this->warn("Skipping request #{$request->id}: signature image missing.");
                $skipped++;

                continue;
            }

            $this->error("Failed request #{$request->id} ({$employeeName})");
            $failed++;
        }

        $this->info($dryRun
            ? "Dry run complete. {$requests->count()} request(s) matched."
            : "Repaired {$repaired} signed PDF(s). Skipped {$skipped}. Failed {$failed}.");

        return self::SUCCESS;
    }
}
