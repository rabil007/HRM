<?php

namespace App\Support\BulkDocuments;

use App\Models\BulkDocumentSignatureRequest;
use Illuminate\Support\Facades\URL;

final class BulkDocumentSignatureLinkService
{
    public function signUrl(BulkDocumentSignatureRequest $request): string
    {
        return URL::temporarySignedRoute(
            'public.esign.show',
            $request->expires_at,
            ['token' => $request->token],
        );
    }

    public function downloadUnsignedUrl(BulkDocumentSignatureRequest $request): string
    {
        return URL::temporarySignedRoute(
            'public.esign.download',
            $request->expires_at,
            ['token' => $request->token],
        );
    }

    public function submitUrl(BulkDocumentSignatureRequest $request): string
    {
        return URL::temporarySignedRoute(
            'public.esign.submit',
            $request->expires_at,
            ['token' => $request->token],
        );
    }
}
