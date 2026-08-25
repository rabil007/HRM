<?php

namespace App\Console\Commands;

use App\Models\Company;
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
use Illuminate\Support\Facades\Storage;
use Throwable;

#[Signature('employee-files:migrate-to-private {--dry-run : Report what would be migrated without copying or deleting} {--company= : Limit to a company ID}')]
#[Description('Copy legacy public employee documents and training certificates to private storage')]
class MigrateEmployeeFilesToPrivateCommand extends Command
{
    private int $moved = 0;

    private int $alreadyPrivate = 0;

    private int $safeSkipped = 0;

    private int $needsReview = 0;

    private int $failed = 0;

    private int $publicLeftovers = 0;

    /** @var array<string, int> */
    private array $skipReasons = [
        'remote_url' => 0,
        'empty_path' => 0,
        'invalid_prefix' => 0,
        'missing_both_disks' => 0,
        'orphan_public_file' => 0,
        'other' => 0,
    ];

    /** @var array<string, true> */
    private array $referencedPublicPaths = [];

    /**
     * @var list<array{
     *     record_type: string,
     *     record_id: int,
     *     company_id: int,
     *     reason: string,
     *     public_leftover: bool,
     *     count?: int
     * }>
     */
    private array $reviewItems = [];

    public function handle(): int
    {
        $this->resetTally();

        $dryRun = (bool) $this->option('dry-run');
        $companyOption = $this->option('company');
        $companyId = is_string($companyOption) && $companyOption !== ''
            ? (int) $companyOption
            : null;

        $this->migrateDocuments($companyId, $dryRun);
        $this->migrateDocumentVersions($companyId, $dryRun);
        $this->migrateTrainings($companyId, $dryRun);
        $this->migrateTrainingVersions($companyId, $dryRun);
        $this->reportOrphanPublicFiles($companyId);

        $this->printSummary($dryRun);
        $this->printSkipReasonBreakdown();
        $this->printReviewItems();

        if ($this->failed > 0 || $this->publicLeftovers > 0) {
            $this->error('Public employee files still need review. Do not treat this run as complete.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function resetTally(): void
    {
        $this->moved = 0;
        $this->alreadyPrivate = 0;
        $this->safeSkipped = 0;
        $this->needsReview = 0;
        $this->failed = 0;
        $this->publicLeftovers = 0;
        $this->skipReasons = [
            'remote_url' => 0,
            'empty_path' => 0,
            'invalid_prefix' => 0,
            'missing_both_disks' => 0,
            'orphan_public_file' => 0,
            'other' => 0,
        ];
        $this->referencedPublicPaths = [];
        $this->reviewItems = [];
    }

    private function migrateDocuments(?int $companyId, bool $dryRun): void
    {
        EmployeeDocument::query()
            ->withTrashed()
            ->when($companyId !== null, fn ($query) => $query->where('company_id', $companyId))
            ->eachById(function (EmployeeDocument $document) use ($dryRun): void {
                $this->tally($this->migrateRecord(
                    EmployeePrivateFileKind::Document,
                    (string) ($document->file_path ?? ''),
                    (int) $document->company_id,
                    'employee_document',
                    (int) $document->id,
                    $dryRun,
                ));
            });
    }

    private function migrateDocumentVersions(?int $companyId, bool $dryRun): void
    {
        EmployeeDocumentVersion::query()
            ->withTrashed()
            ->when($companyId !== null, fn ($query) => $query->where('company_id', $companyId))
            ->eachById(function (EmployeeDocumentVersion $version) use ($dryRun): void {
                $this->tally($this->migrateRecord(
                    EmployeePrivateFileKind::Document,
                    (string) ($version->file_path ?? ''),
                    (int) $version->company_id,
                    'employee_document_version',
                    (int) $version->id,
                    $dryRun,
                ));
            });
    }

    private function migrateTrainings(?int $companyId, bool $dryRun): void
    {
        EmployeeTraining::query()
            ->withTrashed()
            ->when($companyId !== null, fn ($query) => $query->where('company_id', $companyId))
            ->eachById(function (EmployeeTraining $training) use ($dryRun): void {
                $this->tally($this->migrateRecord(
                    EmployeePrivateFileKind::TrainingCertificate,
                    (string) ($training->certificate_path ?? ''),
                    (int) $training->company_id,
                    'employee_training',
                    (int) $training->id,
                    $dryRun,
                ));
            });
    }

    private function migrateTrainingVersions(?int $companyId, bool $dryRun): void
    {
        EmployeeTrainingVersion::query()
            ->withTrashed()
            ->when($companyId !== null, fn ($query) => $query->where('company_id', $companyId))
            ->eachById(function (EmployeeTrainingVersion $version) use ($dryRun): void {
                $this->tally($this->migrateRecord(
                    EmployeePrivateFileKind::TrainingCertificate,
                    (string) ($version->file_path ?? ''),
                    (int) $version->company_id,
                    'employee_training_version',
                    (int) $version->id,
                    $dryRun,
                ));
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
        $relativePath = trim($relativePath);

        if ($relativePath === '') {
            $this->skipReasons['empty_path']++;

            return 'safe_skipped';
        }

        if (EmployeePrivateFile::isRemoteUrl($relativePath)) {
            $this->skipReasons['remote_url']++;
            $this->logInformational($recordType, $recordId, $companyId, 'remote_url');

            return 'safe_skipped';
        }

        $normalized = EmployeePrivateFile::normalizedRelativePath($relativePath);

        if ($normalized !== null) {
            $this->referencedPublicPaths[$normalized] = true;
        }

        $validated = EmployeePrivateFile::validatedRelativePath($relativePath, $companyId, $kind);

        if ($validated === null) {
            $publicLeftover = EmployeePrivateFile::legacyPublicExists($relativePath);
            $this->flagReview($recordType, $recordId, $companyId, 'invalid_prefix', $publicLeftover);

            return 'needs_review';
        }

        $onPrivate = EmployeePrivateFile::resolve($validated, $companyId, $kind)?->disk === EmployeePrivateFile::DISK;
        $onPublic = EmployeePrivateFile::hasLegacyPublicCopy($validated, $companyId, $kind);

        if (! $onPrivate && ! $onPublic) {
            $this->flagReview($recordType, $recordId, $companyId, 'missing_both_disks', false);

            return 'needs_review';
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

    private function reportOrphanPublicFiles(?int $companyId): void
    {
        foreach ($this->companyIdsForScan($companyId) as $scanCompanyId) {
            foreach (EmployeePrivateFileKind::cases() as $kind) {
                $files = EmployeePrivateFile::legacyPublicFilesInPrefix($kind->directoryPrefix($scanCompanyId));
                $orphans = array_values(array_filter(
                    $files,
                    fn (string $path): bool => ! isset($this->referencedPublicPaths[$path]),
                ));

                if ($orphans === []) {
                    continue;
                }

                $count = count($orphans);
                $this->skipReasons['orphan_public_file'] += $count;
                $this->needsReview += $count;
                $this->publicLeftovers += $count;
                $this->reviewItems[] = [
                    'record_type' => 'orphan_public_file',
                    'record_id' => 0,
                    'company_id' => $scanCompanyId,
                    'reason' => 'orphan_public_file',
                    'public_leftover' => true,
                    'count' => $count,
                ];

                Log::warning('Employee private file migration needs review.', [
                    'record_type' => 'orphan_public_file',
                    'record_id' => 0,
                    'company_id' => $scanCompanyId,
                    'reason' => 'orphan_public_file',
                    'count' => $count,
                    'kind' => $kind->value,
                    'public_leftover' => true,
                ]);
            }
        }
    }

    /**
     * @return list<int>
     */
    private function companyIdsForScan(?int $companyId): array
    {
        if ($companyId !== null) {
            return [$companyId];
        }

        $ids = Company::query()->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();

        foreach (['employee-documents', 'employees'] as $root) {
            foreach (Storage::disk(EmployeePrivateFile::LEGACY_DISK)->directories($root) as $directory) {
                $segment = basename($directory);

                if (ctype_digit($segment)) {
                    $ids[] = (int) $segment;
                }
            }
        }

        $ids = array_values(array_unique($ids));
        sort($ids);

        return $ids;
    }

    private function tally(string $result): void
    {
        match ($result) {
            'moved' => $this->moved++,
            'already_private' => $this->alreadyPrivate++,
            'safe_skipped' => $this->safeSkipped++,
            'needs_review' => $this->needsReview++,
            default => $this->failed++,
        };
    }

    private function flagReview(
        string $recordType,
        int $recordId,
        int $companyId,
        string $reason,
        bool $publicLeftover,
    ): void {
        $this->skipReasons[$reason] = ($this->skipReasons[$reason] ?? 0) + 1;
        $this->reviewItems[] = [
            'record_type' => $recordType,
            'record_id' => $recordId,
            'company_id' => $companyId,
            'reason' => $reason,
            'public_leftover' => $publicLeftover,
        ];

        if ($publicLeftover) {
            $this->publicLeftovers++;
        }

        Log::warning('Employee private file migration needs review.', [
            'record_type' => $recordType,
            'record_id' => $recordId,
            'company_id' => $companyId,
            'reason' => $reason,
            'public_leftover' => $publicLeftover,
        ]);
    }

    private function logInformational(string $recordType, int $recordId, int $companyId, string $reason): void
    {
        Log::info('Employee private file migration skipped a safe remote URL.', [
            'record_type' => $recordType,
            'record_id' => $recordId,
            'company_id' => $companyId,
            'reason' => $reason,
        ]);
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

    private function printSummary(bool $dryRun): void
    {
        $this->info($dryRun
            ? "Dry run complete. Would move {$this->moved} file(s)."
            : "Moved {$this->moved} file(s) to private storage.");
        $this->line("Already private: {$this->alreadyPrivate}.");
        $this->line("Safe skipped: {$this->safeSkipped}.");
        $this->line("Needs review: {$this->needsReview}.");
        $this->line("Failed: {$this->failed}.");
    }

    private function printSkipReasonBreakdown(): void
    {
        $parts = [];

        foreach ($this->skipReasons as $reason => $count) {
            if ($count > 0) {
                $parts[] = "{$reason}={$count}";
            }
        }

        if ($parts !== []) {
            $this->line('Skip reasons: '.implode(', ', $parts).'.');
        }
    }

    private function printReviewItems(): void
    {
        foreach ($this->reviewItems as $item) {
            if ($item['reason'] === 'orphan_public_file') {
                $count = $item['count'] ?? 0;
                $this->warn("Needs review (orphan_public_file): {$count} file(s) in company {$item['company_id']} prefix (filenames omitted). Public leftover: yes.");

                continue;
            }

            $leftover = $item['public_leftover'] ? ' Public leftover: yes.' : '';
            $this->warn("Needs review ({$item['reason']}): {$item['record_type']} #{$item['record_id']} (company {$item['company_id']}).{$leftover}");
        }
    }
}
