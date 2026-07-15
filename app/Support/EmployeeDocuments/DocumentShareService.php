<?php

namespace App\Support\EmployeeDocuments;

use App\Enums\DocumentShareScope;
use App\Models\DocumentShare;
use App\Models\DocumentType;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class DocumentShareService
{
    public function __construct(
        private DocumentDownloadService $downloads,
        private StoresEmployeeDocument $store,
    ) {}

    public function displayName(EmployeeDocument $document): string
    {
        return (string) ($document->original_filename
            ?? $document->title
            ?? $document->document_type_label);
    }

    /**
     * @param  list<int>  $documentIds
     */
    public function createFilesShare(
        Employee $employee,
        array $documentIds,
        int $companyId,
        ?int $createdBy,
        ?string $password = null,
        ?string $expiresAt = null,
        bool $canDownload = true,
    ): DocumentShare {
        $documents = $this->documentsForEmployeeIds($documentIds, $companyId, $employee->id);

        foreach ($documents as $document) {
            if (! $this->downloads->isShareable($document)) {
                throw ValidationException::withMessages([
                    'document_ids' => ["The file for \"{$this->displayName($document)}\" is not available to share."],
                ]);
            }
        }

        return $this->createShare(
            employee: $employee,
            companyId: $companyId,
            createdBy: $createdBy,
            scope: DocumentShareScope::Files,
            documentIds: array_values(array_map('intval', $documentIds)),
            password: $password,
            expiresAt: $expiresAt,
            canDownload: $canDownload,
            canUpload: false,
        );
    }

    public function createFolderShare(
        Employee $employee,
        int $companyId,
        ?int $createdBy,
        ?string $password = null,
        ?string $expiresAt = null,
        bool $canDownload = true,
        bool $canUpload = false,
    ): DocumentShare {
        return $this->createShare(
            employee: $employee,
            companyId: $companyId,
            createdBy: $createdBy,
            scope: DocumentShareScope::Folder,
            documentIds: null,
            password: $password,
            expiresAt: $expiresAt,
            canDownload: $canDownload,
            canUpload: $canUpload,
        );
    }

    /**
     * @param  list<int>|null  $documentIds
     */
    private function createShare(
        Employee $employee,
        int $companyId,
        ?int $createdBy,
        DocumentShareScope $scope,
        ?array $documentIds,
        ?string $password,
        ?string $expiresAt,
        bool $canDownload,
        bool $canUpload,
    ): DocumentShare {
        $expiry = $expiresAt ? Carbon::parse($expiresAt) : now()->addHours(24);

        if ($expiry->lessThanOrEqualTo(now())) {
            throw ValidationException::withMessages([
                'expires_at' => ['The expiration must be in the future.'],
            ]);
        }

        return DocumentShare::query()->create([
            'company_id' => $companyId,
            'employee_id' => $employee->id,
            'scope' => $scope,
            'employee_document_ids' => $documentIds,
            'token' => Str::random(48),
            'password_hash' => filled($password) ? Hash::make($password) : null,
            'expires_at' => $expiry,
            'can_download' => $canDownload,
            'can_upload' => $scope === DocumentShareScope::Folder && $canUpload,
            'created_by' => $createdBy,
        ]);
    }

    public function shareUrl(DocumentShare $share): string
    {
        return URL::temporarySignedRoute(
            'public.documents.shared.show',
            $share->expires_at,
            ['token' => $share->token],
        );
    }

    public function downloadUrl(DocumentShare $share, EmployeeDocument $document): string
    {
        return URL::temporarySignedRoute(
            'public.documents.shared.download',
            $share->expires_at,
            [
                'token' => $share->token,
                'document' => $document->id,
            ],
        );
    }

    public function previewUrl(DocumentShare $share, EmployeeDocument $document): string
    {
        return URL::temporarySignedRoute(
            'public.documents.shared.preview',
            $share->expires_at,
            [
                'token' => $share->token,
                'document' => $document->id,
            ],
        );
    }

    public function uploadUrl(DocumentShare $share): string
    {
        return URL::temporarySignedRoute(
            'public.documents.shared.upload',
            $share->expires_at,
            ['token' => $share->token],
        );
    }

    public function unlockUrl(DocumentShare $share): string
    {
        return URL::temporarySignedRoute(
            'public.documents.shared.unlock',
            $share->expires_at,
            ['token' => $share->token],
        );
    }

    public function findByToken(string $token): ?DocumentShare
    {
        return DocumentShare::query()
            ->where('token', $token)
            ->with(['employee:id,name,employee_no,company_id', 'company:id,name'])
            ->first();
    }

    public function assertAccessible(DocumentShare $share): void
    {
        abort_unless($share->isAccessible(), 403, 'This share link is no longer available.');
    }

    public function sessionUnlockKey(DocumentShare $share): string
    {
        return 'document_share_unlocked.'.$share->token;
    }

    public function isUnlocked(DocumentShare $share): bool
    {
        if (! $share->hasPassword()) {
            return true;
        }

        return (bool) session($this->sessionUnlockKey($share), false);
    }

    public function unlock(DocumentShare $share, string $password): void
    {
        if (! $share->passwordMatches($password)) {
            throw ValidationException::withMessages([
                'password' => ['Incorrect password. Please try again.'],
            ]);
        }

        session([$this->sessionUnlockKey($share) => true]);
    }

    public function assertUnlocked(DocumentShare $share): void
    {
        abort_unless($this->isUnlocked($share), 403, 'Password required.');
    }

    /**
     * @return EloquentCollection<int, EmployeeDocument>
     */
    public function documentsForShare(DocumentShare $share): EloquentCollection
    {
        $query = EmployeeDocument::query()
            ->forCompany((int) $share->company_id)
            ->where('employee_id', $share->employee_id)
            ->with(['documentType:id,title', 'uploader:id,name'])
            ->latestUpload();

        if ($share->scope === DocumentShareScope::Files) {
            $ids = $share->documentIds();
            abort_if($ids === [], 404, 'No documents found for this share.');
            $query->whereIn('id', $ids);
        }

        return $query->get();
    }

    public function findDocumentInShare(DocumentShare $share, int $documentId): EmployeeDocument
    {
        $document = $this->documentsForShare($share)->firstWhere('id', $documentId);

        abort_if($document === null, 404);

        return $document;
    }

    /**
     * @param  list<int>  $documentIds
     * @return Collection<int, EmployeeDocument>
     */
    public function documentsForEmployeeIds(array $documentIds, int $companyId, int $employeeId): Collection
    {
        $documents = EmployeeDocument::query()
            ->forCompany($companyId)
            ->where('employee_id', $employeeId)
            ->whereIn('id', $documentIds)
            ->with(['documentType:id,title'])
            ->get();

        abort_if($documents->count() !== count(array_unique($documentIds)), 404);

        return $documents;
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    public function documentNamePayload(Collection $documents, ?array $orderedIds = null): array
    {
        $byId = $documents->keyBy('id');

        $ordered = $orderedIds !== null
            ? collect($orderedIds)->map(fn (int $id) => $byId->get($id))->filter()
            : $documents;

        return $ordered
            ->map(fn (EmployeeDocument $document): array => [
                'id' => $document->id,
                'name' => $this->displayName($document),
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function guestDocumentPayload(DocumentShare $share): array
    {
        return $this->documentsForShare($share)
            ->map(function (EmployeeDocument $document) use ($share): array {
                $browse = $document->toBrowseArray();
                unset($browse['file_url']);

                return [
                    ...$browse,
                    'download_url' => $share->can_download
                        ? $this->downloadUrl($share, $document)
                        : null,
                    'preview_url' => $document->can_preview
                        ? $this->previewUrl($share, $document)
                        : null,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  array{title?: string|null, issue_date?: string|null, expiry_date?: string|null, document_number?: string|null, notes?: string|null}  $data
     */
    public function storeGuestUpload(
        DocumentShare $share,
        ?DocumentType $documentType,
        UploadedFile $file,
        array $data = [],
    ): EmployeeDocument {
        abort_unless($share->allowsUpload(), 403);

        $employee = Employee::query()
            ->whereKey($share->employee_id)
            ->where('company_id', $share->company_id)
            ->firstOrFail();

        return $this->store->create(
            $employee,
            $documentType,
            $file,
            [
                ...$data,
                'notes' => filled($data['notes'] ?? null)
                    ? $data['notes']
                    : 'Uploaded via shared folder link',
            ],
            (int) $share->company_id,
            null,
        );
    }
}
