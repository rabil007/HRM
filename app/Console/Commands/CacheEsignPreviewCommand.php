<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\Employee;
use App\Services\SalaryDeclaration\SalaryDeclarationPdfRenderer;
use App\Support\BulkDocuments\BulkDocumentTypeRegistry;
use App\Support\BulkDocuments\EsignPreviewPdfCache;
use Illuminate\Console\Command;
use Throwable;

class CacheEsignPreviewCommand extends Command
{
    protected $signature = 'esign-preview:cache {documentType? : Document type key such as salary_declaration}';

    protected $description = 'Generate cached e-sign placement preview PDFs via Browsershot (CLI only)';

    public function handle(SalaryDeclarationPdfRenderer $renderer): int
    {
        $documentType = (string) ($this->argument('documentType') ?? 'salary_declaration');

        if (! BulkDocumentTypeRegistry::supportsEsignature($documentType)) {
            $this->components->error("Document type [{$documentType}] does not support e-signatures.");

            return self::FAILURE;
        }

        $company = Company::query()->orderBy('id')->first();

        if ($company === null) {
            $this->components->error('No company found. Create a company before caching preview PDFs.');

            return self::FAILURE;
        }

        $employee = Employee::query()
            ->where('company_id', $company->id)
            ->orderBy('id')
            ->first();

        if ($employee === null) {
            $employee = Employee::factory()->forCompany($company)->create([
                'name' => 'Jane Smith',
                'status' => 'active',
                'emirates_id' => '784-1990-0000000-1',
            ]);
        }

        foreach ([true, false] as $showGuides) {
            try {
                $pdf = $renderer->render($employee, (int) $company->id, null, $showGuides);
                EsignPreviewPdfCache::put($documentType, $showGuides, $pdf);

                $this->components->info(sprintf(
                    'Cached %s preview (%s).',
                    $documentType,
                    $showGuides ? 'with guides' : 'without guides',
                ));
            } catch (Throwable $exception) {
                $this->components->error(sprintf(
                    'Failed to cache %s preview (%s): %s',
                    $documentType,
                    $showGuides ? 'with guides' : 'without guides',
                    $exception->getMessage(),
                ));

                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }
}
