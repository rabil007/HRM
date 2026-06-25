<?php

namespace App\Support\EmployeeDocuments;

use App\Models\EmployeeDocument;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;

class DocumentShareLinkService
{
    public function __construct(
        private DocumentDownloadService $downloads,
    ) {}

    public function displayName(EmployeeDocument $document): string
    {
        return (string) ($document->original_filename
            ?? $document->title
            ?? $document->document_type_label);
    }

    public function shareUrl(EmployeeDocument $document, ?string $password = null, ?string $expiresAt = null): string
    {
        if (! $this->downloads->isShareable($document)) {
            throw ValidationException::withMessages([
                'document_ids' => ["The file for \"{$this->displayName($document)}\" is not available to share."],
            ]);
        }

        $expiry = $expiresAt ? Carbon::parse($expiresAt) : now()->addHours(24);

        $params = ['document' => $document->id];
        if ($password !== null && $password !== '') {
            $params['pwd_hash'] = hash_hmac('sha256', $password, config('app.key'));
        }

        return URL::temporarySignedRoute(
            'organization.documents.share',
            $expiry,
            $params,
        );
    }

    /**
     * @param  Collection<int, EmployeeDocument>  $documents
     * @return list<array{id: int, name: string, share_url: string}>
     */
    public function sharePayload(Collection $documents, ?string $password = null, ?string $expiresAt = null): array
    {
        return $documents
            ->map(fn (EmployeeDocument $document): array => [
                'id' => $document->id,
                'name' => $this->displayName($document),
                'share_url' => $this->shareUrl($document, $password, $expiresAt),
            ])
            ->values()
            ->all();
    }
}
