<?php

namespace App\Support\BulkDocuments;

use App\Models\BulkDocumentSignatureRequest;
use Illuminate\Support\Facades\URL;

final class BulkDocumentSignatureLinkService
{
    public function signUrl(BulkDocumentSignatureRequest $request): string
    {
        return URL::temporarySignedRoute(
            'organization.documents.bulk.sign.show',
            $request->expires_at,
            ['token' => $request->token],
        );
    }

    public function downloadUnsignedUrl(BulkDocumentSignatureRequest $request): string
    {
        return URL::temporarySignedRoute(
            'organization.documents.bulk.sign.download',
            $request->expires_at,
            ['token' => $request->token],
        );
    }

    public function submitUrl(BulkDocumentSignatureRequest $request): string
    {
        return URL::temporarySignedRoute(
            'organization.documents.bulk.sign.submit',
            $request->expires_at,
            ['token' => $request->token],
        );
    }
}
