<?php

namespace App\Console\Commands;

use App\Models\EmployeeDocument;
use App\Models\EmployeeDocumentVersion;
use App\Models\EmployeeTraining;
use App\Models\EmployeeTrainingVersion;
use App\Support\EmployeeFiles\EmployeePrivateFile;
use App\Support\EmployeeFiles\EmployeePrivateFileKind;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

#[Signature('employee-files:migrate-to-private {--dry-run : Report what would be migrated without copying or deleting} {--company= : Limit to a company ID}')]
#[Description('Copy legacy public employee documents and training certificates to private storage')]
class MigrateEmployeeFilesToPrivateCommand extends Command
{
    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $companyOption = $this->option('company');
        $companyId = is_string($companyOption) && $companyOption !== ''
            ? (int) $companyOption
            : null;

        $moved = 0;
        $alreadyPrivate = 0;
        $skipped = 0;
        $failed = 0;

        $this->migrateDocuments($companyId, $dryRun, $moved, $alreadyPrivate, $skipped, $failed);
        $this->migrateDocumentVersions($companyId, $dryRun, $moved, $alreadyPrivate, $skipped, $failed);
        $this->migrateTrainings($companyId, $dryRun, $moved, $alreadyPrivate, $skipped, $failed);
        $this->migrateTrainingVersions($companyId, $dryRun, $moved, $alreadyPrivate, $skipped, $failed);

        $this->info($dryRun
            ? "Dry run complete. Would move {$moved} file(s). Already private: {$alreadyPrivate}. Skipped: {$skipped}. Failed: {$failed}."
            : "Moved {$moved} file(s) to private storage. Already private: {$alreadyPrivate}. Skipped: {$skipped}. Failed: {$failed}.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function migrateDocuments(
        ?int $companyId,
        bool $dryRun,
        int &$moved,
        int &$alreadyPrivate,
        int &$skipped,
        int &$failed,
    ): void {
        EmployeeDocument::query()
            ->withTrashed()
            ->when($companyId !== null, fn ($query) => $query->where('company_id', $companyId))
            ->eachById(function (EmployeeDocument $document) use ($dryRun, &$moved, &$alreadyPrivate, &$skipped, &$failed): void {
                $this->tally($this->migrateRecord(
                    EmployeePrivateFileKind::Document,
                    (string) $document->file_path,
                    (int) $document->company_id,
                    'employee_document',
                    (int) $document->id,
                    $dryRun,
                ), $moved, $alreadyPrivate, $skipped, $failed);
            });
    }

    private function migrateDocumentVersions(
        ?int $companyId,
        bool $dryRun,
        int &$moved,
        int &$alreadyPrivate,
        int &$skipped,
        int &$failed,
    ): void {
        EmployeeDocumentVersion::query()
            ->withTrashed()
            ->when($companyId !== null, fn ($query) => $query->where('company_id', $companyId))
            ->eachById(function (EmployeeDocumentVersion $version) use ($dryRun, &$moved, &$alreadyPrivate, &$skipped, &$failed): void {
                $this->tally($this->migrateRecord(
                    EmployeePrivateFileKind::Document,
                    (string) $version->file_path,
                    (int) $version->company_id,
                    'employee_document_version',
                    (int) $version->id,
                    $dryRun,
                ), $moved, $alreadyPrivate, $skipped, $failed);
            });
    }

    private function migrateTrainings(
        ?int $companyId,
        bool $dryRun,
        int &$moved,
        int &$alreadyPrivate,
        int &$skipped,
        int &$failed,
    ): void {
        EmployeeTraining::query()
            ->withTrashed()
            ->whereNotNull('certificate_path')
            ->where('certificate_path', '!=', '')
            ->when($companyId !== null, fn ($query) => $query->where('company_id', $companyId))
            ->eachById(function (EmployeeTraining $training) use ($dryRun, &$moved, &$alreadyPrivate, &$skipped, &$failed): void {
                $this->tally($this->migrateRecord(
                    EmployeePrivateFileKind::TrainingCertificate,
                    (string) ($training->certificate_path ?? ''),
                    (int) $training->company_id,
                    'employee_training',
                    (int) $training->id,
                    $dryRun,
                ), $moved, $alreadyPrivate, $skipped, $failed);
            });
    }

    private function migrateTrainingVersions(
        ?int $companyId,
        bool $dryRun,
        int &$moved,
        int &$alreadyPrivate,
        int &$skipped,
        int &$failed,
    ): void {
        EmployeeTrainingVersion::query()
            ->withTrashed()
            ->when($companyId !== null, fn ($query) => $query->where('company_id', $companyId))
            ->eachById(function (EmployeeTrainingVersion $version) use ($dryRun, &$moved, &$alreadyPrivate, &$skipped, &$failed): void {
                $this->tally($this->migrateRecord(
                    EmployeePrivateFileKind::TrainingCertificate,
                    (string) $version->file_path,
                    (int) $version->company_id,
                    'employee_training_version',
                    (int) $version->id,
                    $dryRun,
                ), $moved, $alreadyPrivate, $skipped, $failed);
            });
    }

    private function migrateRecord(
        EmployeePrivateFileKind $kind,
        string $relativePath,
        int $companyId,
        string $recordType,
        int $recordId,
        bool $dryRun,
    ): string {
        if ($relativePath === '' || EmployeePrivateFile::isRemoteUrl($relativePath)) {
            return 'skipped';
        }

        $validated = EmployeePrivateFile::validatedRelativePath($relativePath, $companyId, $kind);

        if ($validated === null) {
            return 'skipped';
        }

        $onPrivate = EmployeePrivateFile::resolve($validated, $companyId, $kind)?->disk === EmployeePrivateFile::DISK;
        $onPublic = EmployeePrivateFile::hasLegacyPublicCopy($validated, $companyId, $kind);

        if (! $onPrivate && ! $onPublic) {
            return 'skipped';
        }

        if ($onPrivate && ! $onPublic) {
            return 'already_private';
        }

        if ($dryRun) {
            return 'moved';
        }

        try {
            if (! $onPrivate && ! EmployeePrivateFile::copyLegacyPublicToPrivate($validated, $companyId, $kind)) {
                $this->logFailure($recordType, $recordId, $companyId, 'Private destination was not verified after copy.');

                return 'failed';
            }

            if (! EmployeePrivateFile::deleteLegacyPublicCopy($validated, $companyId, $kind)) {
                $this->logFailure($recordType, $recordId, $companyId, 'Private copy verified but the public copy could not be removed.');

                return 'failed';
            }
        } catch (Throwable) {
            $this->logFailure($recordType, $recordId, $companyId, 'Copy failed.');

            return 'failed';
        }

        return 'moved';
    }

    private function tally(
        string $result,
        int &$moved,
        int &$alreadyPrivate,
        int &$skipped,
        int &$failed,
    ): void {
        match ($result) {
            'moved' => $moved++,
            'already_private' => $alreadyPrivate++,
            'skipped' => $skipped++,
            default => $failed++,
        };
    }

    private function logFailure(string $recordType, int $recordId, int $companyId, string $reason): void
    {
        Log::warning('Employee private file migration failed.', [
            'record_type' => $recordType,
            'record_id' => $recordId,
            'company_id' => $companyId,
            'reason' => $reason,
        ]);

        $this->error("Failed {$recordType} #{$recordId} (company {$companyId}).");
    }
}
