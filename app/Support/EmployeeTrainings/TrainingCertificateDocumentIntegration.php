<?php

namespace App\Support\EmployeeTrainings;

use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\EmployeeDocumentVersion;
use App\Models\EmployeeTraining;
use App\Models\EmployeeTrainingVersion;
use App\Models\User;
use App\Support\EmployeeDocuments\DocumentExpiry;
use App\Support\EmployeeDocuments\StoresEmployeeDocument;
use App\Support\EmployeeDocuments\TrainingCertificateDocumentTypeResolver;
use App\Support\EmployeeFiles\EmployeePrivateFile;
use App\Support\EmployeeFiles\EmployeePrivateFileKind;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class TrainingCertificateDocumentIntegration
{
    public function __construct(
        private StoresEmployeeDocument $documentStore,
        private TrainingCertificateDocumentTypeResolver $documentTypeResolver,
    ) {}

    public function attachUploadedCertificate(
        EmployeeTraining $training,
        Employee $employee,
        UploadedFile $file,
        int $companyId,
        ?int $userId,
    ): EmployeeDocument {
        TrainingCertificateDocumentPermissions::assertCanUploadOrLink(auth()->user());

        $training->loadMissing('course:id,name');

        return DB::transaction(function () use ($training, $employee, $file, $companyId, $userId) {
            $documentType = $this->documentTypeResolver->resolve();

            $document = $this->documentStore->create(
                $employee,
                $documentType,
                $file,
                $this->documentMetadataFromTraining($training),
                $companyId,
                $userId,
            );

            $this->assignDocumentToTraining($training, $document);

            return $document;
        });
    }

    public function linkExistingDocument(
        EmployeeTraining $training,
        Employee $employee,
        EmployeeDocument $document,
        int $companyId,
        ?User $user,
        bool $confirmReplaceLink = false,
    ): void {
        TrainingCertificateDocumentPermissions::assertCanUploadOrLink($user);

        $this->assertDocumentLinkable($training, $employee, $document, $companyId, $confirmReplaceLink);

        DB::transaction(function () use ($training, $document): void {
            EmployeeTraining::query()->whereKey($training->id)->lockForUpdate()->firstOrFail();
            EmployeeDocument::query()->whereKey($document->id)->lockForUpdate()->firstOrFail();

            $training->refresh();
            $document->refresh();

            $this->reconcileLegacyCertificateHistoryBeforeLink($training, $document);
            $this->assignDocumentToTraining($training, $document);
            $this->syncTrainingDatesFromDocument($training, $document);
        });
    }

    /**
     * @param  array<string, mixed>  $trainingMetadata
     */
    public function replaceLinkedCertificate(
        EmployeeTraining $training,
        Employee $employee,
        UploadedFile $file,
        int $companyId,
        ?int $userId,
        array $trainingMetadata = [],
    ): EmployeeTraining {
        TrainingCertificateDocumentPermissions::assertCanReplace(auth()->user());

        $document = $this->resolveLinkedDocument($training);

        if ($document === null) {
            throw ValidationException::withMessages([
                'file' => 'This training record does not have a linked employee document.',
            ]);
        }

        return DB::transaction(function () use ($training, $employee, $document, $file, $companyId, $userId, $trainingMetadata) {
            $documentData = [];

            if (array_key_exists('issue_date', $trainingMetadata)) {
                $documentData['issue_date'] = $trainingMetadata['issue_date'] ?? null;
            }

            if (array_key_exists('expiry_date', $trainingMetadata)) {
                $documentData['expiry_date'] = $trainingMetadata['expiry_date'] ?? null;
            }

            $this->documentStore->replace(
                $document,
                $file,
                $companyId,
                $employee->id,
                $userId,
                $documentData,
            );

            $document->refresh();
            $this->mirrorCertificateFieldsOntoTraining($training, $document);
            $this->applyTrainingMetadata($training, $trainingMetadata);

            return $training->refresh();
        });
    }

    public function syncTrainingDatesToLinkedDocument(EmployeeTraining $training): void
    {
        $document = $this->resolveLinkedDocument($training);

        if ($document === null) {
            return;
        }

        $document->update([
            'issue_date' => $training->issue_date?->toDateString(),
            'expiry_date' => $training->expiry_date?->toDateString(),
            'status' => DocumentExpiry::persistedStatus($training->expiry_date?->toDateString()),
            'title' => $this->documentTitleForTraining($training),
        ]);
    }

    public function syncLinkedTrainingsFromDocument(EmployeeDocument $document): void
    {
        EmployeeTraining::query()
            ->where('employee_document_id', $document->id)
            ->where('company_id', $document->company_id)
            ->each(function (EmployeeTraining $training) use ($document): void {
                $this->mirrorCertificateFieldsOntoTraining($training, $document);
                $training->update([
                    'issue_date' => $document->issue_date?->toDateString(),
                    'expiry_date' => $document->expiry_date?->toDateString(),
                ]);
            });
    }

    public function unlinkDocument(EmployeeTraining $training): void
    {
        $training->update(['employee_document_id' => null]);
    }

    public function resolveLinkedDocument(EmployeeTraining $training): ?EmployeeDocument
    {
        if ($training->employee_document_id === null) {
            return null;
        }

        return EmployeeDocument::query()
            ->whereKey($training->employee_document_id)
            ->where('company_id', $training->company_id)
            ->where('employee_id', $training->employee_id)
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    public function documentMetadataFromTraining(EmployeeTraining $training): array
    {
        return [
            'title' => $this->documentTitleForTraining($training),
            'issue_date' => $training->issue_date?->toDateString(),
            'expiry_date' => $training->expiry_date?->toDateString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function mirrorCertificateFieldsFromDocument(EmployeeDocument $document): array
    {
        return [
            'certificate_path' => $document->file_path,
            'certificate_original_filename' => $document->original_filename,
            'certificate_mime_type' => $document->mime_type,
            'certificate_size_bytes' => $document->size_bytes,
            'certificate_checksum' => $document->checksum,
            'current_version' => (int) $document->current_version,
            'replaced_at' => $document->replaced_at,
        ];
    }

    public function migrateLegacyTrainingCertificate(EmployeeTraining $training, bool $dryRun = false): string
    {
        $assessment = $this->assessLegacyTrainingCertificateMigration($training);

        if ($dryRun) {
            return $assessment === 'eligible' ? 'would_link' : $assessment;
        }

        if ($assessment !== 'eligible') {
            return $assessment;
        }

        try {
            return DB::transaction(function () use ($training): string {
                /** @var EmployeeTraining $locked */
                $locked = EmployeeTraining::query()
                    ->whereKey($training->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($locked->employee_document_id !== null) {
                    return 'already_linked';
                }

                $reassessment = $this->assessLegacyTrainingCertificateMigration($locked);

                if ($reassessment !== 'eligible') {
                    return $reassessment;
                }

                $locked->loadMissing(['course:id,name', 'versions']);

                $documentType = $this->documentTypeResolver->resolve();

                $document = EmployeeDocument::query()->create([
                    'company_id' => $locked->company_id,
                    'employee_id' => $locked->employee_id,
                    'document_type_id' => $documentType->id,
                    'type' => 'other',
                    'document_type' => (string) $documentType->id,
                    'title' => $this->documentTitleForTraining($locked),
                    'file_path' => $locked->certificate_path,
                    'original_filename' => $locked->certificate_original_filename,
                    'mime_type' => $locked->certificate_mime_type,
                    'size_bytes' => $locked->certificate_size_bytes,
                    'checksum' => $locked->certificate_checksum ?? '',
                    'current_version' => (int) ($locked->current_version ?? 1),
                    'issue_date' => $locked->issue_date?->toDateString(),
                    'expiry_date' => $locked->expiry_date?->toDateString(),
                    'status' => DocumentExpiry::persistedStatus($locked->expiry_date?->toDateString()),
                    'uploaded_by' => null,
                    'replaced_at' => $locked->replaced_at,
                ]);

                $this->importTrainingCertificateVersionsIntoDocument($locked, $document);

                $locked->update(['employee_document_id' => $document->id]);

                return 'linked';
            });
        } catch (ValidationException) {
            return 'conflicting_version_history';
        } catch (Throwable) {
            return 'failed';
        }
    }

    public function assessLegacyTrainingCertificateMigration(EmployeeTraining $training): string
    {
        if ($training->employee_document_id !== null) {
            return 'already_linked';
        }

        if ($training->certificate_path === null || $training->certificate_path === '') {
            return 'skipped_no_file';
        }

        $companyId = (int) $training->company_id;

        if (! $this->isAllowedCompanyCertificatePath($training->certificate_path, $companyId)) {
            return 'invalid_file_path';
        }

        if (! $this->certificateFileIsReadable($training->certificate_path, $companyId)) {
            return 'missing_current_file';
        }

        if ($this->certificatePathLinkedToAnotherTraining($training)) {
            return 'duplicate_conflicting_reference';
        }

        $training->loadMissing('versions');

        foreach ($training->versions as $version) {
            if (! $version instanceof EmployeeTrainingVersion) {
                continue;
            }

            $versionPath = $version->file_path;

            if ($versionPath === null || $versionPath === '') {
                continue;
            }

            if (! $this->isAllowedCompanyCertificatePath($versionPath, $companyId)) {
                return 'invalid_file_path';
            }

            if (! $this->certificateFileIsReadable($versionPath, $companyId)) {
                return 'missing_historical_version';
            }
        }

        $existingDocument = EmployeeDocument::query()
            ->where('company_id', $companyId)
            ->where('employee_id', $training->employee_id)
            ->where('file_path', $training->certificate_path)
            ->first();

        if ($existingDocument instanceof EmployeeDocument) {
            try {
                $this->assertTrainingVersionsCompatibleWithDocument($training, $existingDocument);
            } catch (ValidationException) {
                return 'conflicting_version_history';
            }
        }

        return 'eligible';
    }

    private function reconcileLegacyCertificateHistoryBeforeLink(
        EmployeeTraining $training,
        EmployeeDocument $document,
    ): void {
        $training->loadMissing('versions');
        $document->loadMissing('versions');

        $legacyPaths = $this->collectTrainingCertificatePaths($training);
        $documentPaths = $this->collectDocumentCertificatePaths($document);

        if ($legacyPaths === []) {
            return;
        }

        $sharedPaths = array_values(array_intersect($legacyPaths, $documentPaths));

        if ($sharedPaths === []) {
            throw ValidationException::withMessages([
                'employee_document_id' => 'This training record already has certificate files that do not match the selected document. Link a document that uses the same certificate files, or remove the legacy certificate first.',
            ]);
        }

        $this->importTrainingCertificateVersionsIntoDocument($training, $document);

        $maxDocumentVersion = max(
            (int) $document->current_version,
            (int) $training->current_version,
            (int) $document->versions->max('version'),
            (int) $training->versions->max('version'),
        );

        if ($maxDocumentVersion > (int) $document->current_version) {
            $document->update(['current_version' => $maxDocumentVersion]);
        }
    }

    private function importTrainingCertificateVersionsIntoDocument(
        EmployeeTraining $training,
        EmployeeDocument $document,
    ): void {
        $this->assertTrainingVersionsCompatibleWithDocument($training, $document);

        foreach ($training->versions->sortBy('version')->values() as $version) {
            if (! $version instanceof EmployeeTrainingVersion) {
                continue;
            }

            $versionNumber = (int) $version->version;

            $existing = EmployeeDocumentVersion::query()
                ->where('employee_document_id', $document->id)
                ->where('version', $versionNumber)
                ->first();

            if ($existing instanceof EmployeeDocumentVersion) {
                if (! $this->certificateAttachmentsMatch(
                    $version->file_path,
                    $version->checksum,
                    $existing->file_path,
                    $existing->checksum,
                )) {
                    throw ValidationException::withMessages([
                        'employee_document_id' => 'Version '.$versionNumber.' of this training certificate does not match the selected document history. The link was rejected to preserve both file histories.',
                    ]);
                }

                continue;
            }

            EmployeeDocumentVersion::query()->create([
                'employee_document_id' => $document->id,
                'company_id' => $training->company_id,
                'employee_id' => $training->employee_id,
                'version' => $versionNumber,
                'file_path' => $version->file_path,
                'original_filename' => $version->original_filename,
                'mime_type' => $version->mime_type,
                'size_bytes' => $version->size_bytes,
                'checksum' => $version->checksum,
                'uploaded_by' => null,
                'uploaded_at' => $version->created_at,
                'replaced_by' => $version->replaced_by,
            ]);
        }
    }

    private function assertTrainingVersionsCompatibleWithDocument(
        EmployeeTraining $training,
        EmployeeDocument $document,
    ): void {
        $training->loadMissing('versions');
        $document->loadMissing('versions');

        $documentVersionsByNumber = $document->versions->keyBy(
            fn (EmployeeDocumentVersion $row): int => (int) $row->version,
        );

        foreach ($training->versions as $trainingVersion) {
            if (! $trainingVersion instanceof EmployeeTrainingVersion) {
                continue;
            }

            $versionNumber = (int) $trainingVersion->version;
            $documentVersion = $documentVersionsByNumber->get($versionNumber);

            if (! $documentVersion instanceof EmployeeDocumentVersion) {
                continue;
            }

            if (! $this->certificateAttachmentsMatch(
                $trainingVersion->file_path,
                $trainingVersion->checksum,
                $documentVersion->file_path,
                $documentVersion->checksum,
            )) {
                throw ValidationException::withMessages([
                    'employee_document_id' => 'Version '.$versionNumber.' of this training certificate does not match the selected document history. The link was rejected to preserve both file histories.',
                ]);
            }
        }

        if (
            (int) $training->current_version === (int) $document->current_version
            && is_string($training->certificate_path)
            && $training->certificate_path !== ''
            && is_string($document->file_path)
            && $document->file_path !== ''
            && ! $this->certificateAttachmentsMatch(
                $training->certificate_path,
                $training->certificate_checksum,
                $document->file_path,
                $document->checksum,
            )
        ) {
            throw ValidationException::withMessages([
                'employee_document_id' => 'The current training certificate file does not match the selected document version. The link was rejected to preserve both file histories.',
            ]);
        }
    }

    private function certificateAttachmentsMatch(
        ?string $pathA,
        ?string $checksumA,
        ?string $pathB,
        ?string $checksumB,
    ): bool {
        $normalizedPathA = is_string($pathA) ? $pathA : '';
        $normalizedPathB = is_string($pathB) ? $pathB : '';

        if ($normalizedPathA !== '' && $normalizedPathB !== '' && $normalizedPathA === $normalizedPathB) {
            return true;
        }

        $normalizedChecksumA = trim((string) ($checksumA ?? ''));
        $normalizedChecksumB = trim((string) ($checksumB ?? ''));

        if ($normalizedChecksumA !== '' && $normalizedChecksumB !== '' && hash_equals($normalizedChecksumA, $normalizedChecksumB)) {
            return true;
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function collectTrainingCertificatePaths(EmployeeTraining $training): array
    {
        $paths = $training->versions
            ->pluck('file_path')
            ->filter(fn (?string $path): bool => is_string($path) && $path !== '')
            ->all();

        if ($training->certificate_path !== null && $training->certificate_path !== '') {
            $paths[] = $training->certificate_path;
        }

        return array_values(array_unique($paths));
    }

    /**
     * @return list<string>
     */
    private function collectDocumentCertificatePaths(EmployeeDocument $document): array
    {
        $paths = $document->versions
            ->pluck('file_path')
            ->filter(fn (?string $path): bool => is_string($path) && $path !== '')
            ->all();

        if ($document->file_path !== null && $document->file_path !== '') {
            $paths[] = $document->file_path;
        }

        return array_values(array_unique($paths));
    }

    private function isAllowedCompanyCertificatePath(string $path, int $companyId): bool
    {
        foreach (EmployeePrivateFileKind::cases() as $kind) {
            if (EmployeePrivateFile::validatedRelativePath($path, $companyId, $kind) !== null) {
                return true;
            }
        }

        return false;
    }

    private function certificateFileIsReadable(string $path, int $companyId): bool
    {
        return EmployeePrivateFile::resolveCompanyOwnedFile($path, $companyId) !== null;
    }

    private function certificatePathLinkedToAnotherTraining(EmployeeTraining $training): bool
    {
        return EmployeeTraining::query()
            ->where('employee_document_id', '>', 0)
            ->where('certificate_path', $training->certificate_path)
            ->where('company_id', $training->company_id)
            ->whereKeyNot($training->id)
            ->exists();
    }

    private function assignDocumentToTraining(EmployeeTraining $training, EmployeeDocument $document): void
    {
        abort_unless(
            (int) $document->company_id === (int) $training->company_id
            && (int) $document->employee_id === (int) $training->employee_id,
            422,
            'Document does not belong to this employee.',
        );

        $training->update([
            'employee_document_id' => $document->id,
            ...$this->mirrorCertificateFieldsFromDocument($document),
        ]);
    }

    private function assertDocumentLinkable(
        EmployeeTraining $training,
        Employee $employee,
        EmployeeDocument $document,
        int $companyId,
        bool $confirmReplaceLink,
    ): void {
        abort_unless(
            (int) $document->company_id === $companyId
            && (int) $document->employee_id === (int) $employee->id,
            403,
        );

        $document->loadMissing('documentType:id,title');

        $trainingCertificateType = $this->documentTypeResolver->resolve();

        abort_unless(
            (int) $document->document_type_id === (int) $trainingCertificateType->id,
            422,
            'Only documents with type Training Certificate can be linked to training records.',
        );

        $existingLink = EmployeeTraining::query()
            ->where('company_id', $companyId)
            ->where('employee_document_id', $document->id)
            ->where('id', '!=', $training->id)
            ->first();

        if ($existingLink !== null) {
            throw ValidationException::withMessages([
                'employee_document_id' => 'This document is already linked to another training record.',
            ]);
        }

        if (
            $training->employee_document_id !== null
            && (int) $training->employee_document_id !== (int) $document->id
            && ! $confirmReplaceLink
        ) {
            throw ValidationException::withMessages([
                'confirm_replace_link' => 'Confirm replacing the current linked document before associating a different one.',
            ]);
        }
    }

    private function syncTrainingDatesFromDocument(EmployeeTraining $training, EmployeeDocument $document): void
    {
        $training->update([
            'issue_date' => $document->issue_date?->toDateString(),
            'expiry_date' => $document->expiry_date?->toDateString(),
            ...$this->mirrorCertificateFieldsFromDocument($document),
        ]);
    }

    private function mirrorCertificateFieldsOntoTraining(EmployeeTraining $training, EmployeeDocument $document): void
    {
        $training->update($this->mirrorCertificateFieldsFromDocument($document));
    }

    /**
     * @param  array<string, mixed>  $trainingMetadata
     */
    private function applyTrainingMetadata(EmployeeTraining $training, array $trainingMetadata): void
    {
        $attributes = [];

        foreach (['issue_date', 'expiry_date', 'institute_center', 'country_id'] as $key) {
            if (array_key_exists($key, $trainingMetadata)) {
                $attributes[$key] = $trainingMetadata[$key] ?? null;
            }
        }

        if ($attributes !== []) {
            $training->update($attributes);
            $this->syncTrainingDatesToLinkedDocument($training->refresh());
        }
    }

    private function documentTitleForTraining(EmployeeTraining $training): string
    {
        $training->loadMissing('course:id,name');

        $courseName = trim((string) ($training->course?->name ?? ''));

        return $courseName !== '' ? $courseName : 'Training Certificate';
    }
}
