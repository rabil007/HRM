<?php

namespace App\Console\Commands;

use App\Models\EmployeeDocument;
use App\Models\EmployeeDocumentVersion;
use App\Models\EmployeeTraining;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AuditTrainingCertificateDocumentOwnershipCommand extends Command
{
    protected $signature = 'training:audit-certificate-document-ownership
                            {--company= : Limit to one company id}';

    protected $description = 'Read-only audit of duplicate training certificate document ownership (no writes).';

    public function handle(): int
    {
        $companyId = $this->option('company');
        $companyFilter = is_numeric($companyId) ? (int) $companyId : null;

        $duplicateLinks = $this->duplicateActiveDocumentLinks($companyFilter);
        $sharedCurrentPaths = $this->sharedDocumentCurrentPaths($companyFilter);
        $sharedVersionPaths = $this->sharedPathsAcrossDocumentsAndTrainings($companyFilter);

        $this->table(
            ['Finding', 'Count'],
            [
                ['duplicate_active_document_links', $duplicateLinks->count()],
                ['shared_document_current_file_paths', $sharedCurrentPaths->count()],
                ['shared_paths_across_documents_or_trainings', $sharedVersionPaths->count()],
            ],
        );

        if ($duplicateLinks->isNotEmpty()) {
            $this->newLine();
            $this->warn('Duplicate active document links (same employee_document_id on multiple live trainings):');
            $this->table(
                ['company_id', 'employee_document_id', 'training_ids', 'count'],
                $duplicateLinks->map(fn (object $row): array => [
                    $row->company_id,
                    $row->employee_document_id,
                    $row->training_ids,
                    $row->link_count,
                ])->all(),
            );
        }

        if ($sharedCurrentPaths->isNotEmpty()) {
            $this->newLine();
            $this->warn('Multiple employee documents share the same current file_path:');
            $this->table(
                ['company_id', 'employee_id', 'file_path', 'document_ids', 'count'],
                $sharedCurrentPaths->map(fn (object $row): array => [
                    $row->company_id,
                    $row->employee_id,
                    $row->file_path,
                    $row->document_ids,
                    $row->document_count,
                ])->all(),
            );
        }

        if ($sharedVersionPaths->isNotEmpty()) {
            $this->newLine();
            $this->warn('File paths referenced by more than one document/training owner:');
            $this->table(
                ['company_id', 'file_path', 'document_owners', 'training_owners'],
                $sharedVersionPaths->map(fn (object $row): array => [
                    $row->company_id,
                    $row->file_path,
                    $row->document_owners,
                    $row->training_owners,
                ])->all(),
            );
        }

        if ($duplicateLinks->isEmpty() && $sharedCurrentPaths->isEmpty() && $sharedVersionPaths->isEmpty()) {
            $this->info('No duplicate training certificate ownership issues found.');
        } else {
            $this->newLine();
            $this->comment('This command is read-only. Do not delete or merge production records automatically.');
            $this->comment('Resolve conflicts explicitly, then re-run before applying the active document-link uniqueness migration if needed.');
        }

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, object>
     */
    private function duplicateActiveDocumentLinks(?int $companyId)
    {
        $query = DB::table('employee_trainings')
            ->whereNull('deleted_at')
            ->whereNotNull('employee_document_id')
            ->selectRaw('company_id, employee_document_id, COUNT(*) as link_count, GROUP_CONCAT(id ORDER BY id) as training_ids')
            ->groupBy('company_id', 'employee_document_id')
            ->havingRaw('COUNT(*) > 1')
            ->orderBy('company_id')
            ->orderBy('employee_document_id');

        if ($companyId !== null) {
            $query->where('company_id', $companyId);
        }

        return $query->get();
    }

    /**
     * @return Collection<int, object>
     */
    private function sharedDocumentCurrentPaths(?int $companyId)
    {
        $query = DB::table('employee_documents')
            ->whereNull('deleted_at')
            ->whereNotNull('file_path')
            ->where('file_path', '!=', '')
            ->selectRaw('company_id, employee_id, file_path, COUNT(*) as document_count, GROUP_CONCAT(id ORDER BY id) as document_ids')
            ->groupBy('company_id', 'employee_id', 'file_path')
            ->havingRaw('COUNT(*) > 1')
            ->orderBy('company_id')
            ->orderBy('employee_id')
            ->orderBy('file_path');

        if ($companyId !== null) {
            $query->where('company_id', $companyId);
        }

        return $query->get();
    }

    /**
     * @return Collection<int, object>
     */
    private function sharedPathsAcrossDocumentsAndTrainings(?int $companyId)
    {
        $documentPaths = EmployeeDocument::query()
            ->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId))
            ->whereNotNull('file_path')
            ->where('file_path', '!=', '')
            ->get(['id', 'company_id', 'file_path'])
            ->map(fn (EmployeeDocument $document): array => [
                'company_id' => (int) $document->company_id,
                'path' => (string) $document->file_path,
                'owner' => 'document:'.$document->id,
            ]);

        $documentVersionPaths = EmployeeDocumentVersion::query()
            ->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId))
            ->whereNotNull('file_path')
            ->where('file_path', '!=', '')
            ->get(['employee_document_id', 'company_id', 'file_path'])
            ->map(fn (EmployeeDocumentVersion $version): array => [
                'company_id' => (int) $version->company_id,
                'path' => (string) $version->file_path,
                'owner' => 'document:'.$version->employee_document_id,
            ]);

        $trainingPaths = EmployeeTraining::query()
            ->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId))
            ->whereNotNull('certificate_path')
            ->where('certificate_path', '!=', '')
            ->get(['id', 'company_id', 'certificate_path', 'employee_document_id'])
            ->map(fn (EmployeeTraining $training): array => [
                'company_id' => (int) $training->company_id,
                'path' => (string) $training->certificate_path,
                'owner' => $training->employee_document_id !== null
                    ? 'document:'.$training->employee_document_id
                    : 'training:'.$training->id,
            ]);

        $grouped = $documentPaths
            ->concat($documentVersionPaths)
            ->concat($trainingPaths)
            ->groupBy(fn (array $row): string => $row['company_id'].'|'.$row['path']);

        return $grouped
            ->map(function ($rows, string $key) {
                $owners = $rows->pluck('owner')->unique()->sort()->values();
                $documentOwners = $owners->filter(fn (string $owner): bool => str_starts_with($owner, 'document:'))->values();
                $trainingOwners = $owners->filter(fn (string $owner): bool => str_starts_with($owner, 'training:'))->values();

                $hasConflict = $documentOwners->count() > 1
                    || ($documentOwners->isNotEmpty() && $trainingOwners->isNotEmpty());

                if (! $hasConflict) {
                    return null;
                }

                [$companyId, $path] = explode('|', $key, 2);

                return (object) [
                    'company_id' => (int) $companyId,
                    'file_path' => $path,
                    'document_owners' => $documentOwners->implode(','),
                    'training_owners' => $trainingOwners->implode(','),
                ];
            })
            ->filter()
            ->values();
    }
}
