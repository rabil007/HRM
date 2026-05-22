<?php

namespace App\Support\EmployeeDocuments;

use App\Models\EmployeeDocument;
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

    public function shareUrl(EmployeeDocument $document): string
    {
        if (! $this->downloads->isShareable($document)) {
            throw ValidationException::withMessages([
                'document_ids' => ["The file for \"{$this->displayName($document)}\" is not available to share."],
            ]);
        }

        return URL::temporarySignedRoute(
            'organization.documents.share',
            now()->addHours(24),
            ['document' => $document->id],
        );
    }

    /**
     * @param  Collection<int, EmployeeDocument>  $documents
     * @return list<array{id: int, name: string, share_url: string}>
     */
    public function sharePayload(Collection $documents): array
    {
        return $documents
            ->map(fn (EmployeeDocument $document): array => [
                'id' => $document->id,
                'name' => $this->displayName($document),
                'share_url' => $this->shareUrl($document),
            ])
            ->values()
            ->all();
    }
}
