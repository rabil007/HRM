<?php

namespace App\Support\Documents\RecipientRequests;

use App\Models\DocumentRecipientRequest;

final class DocumentRecipientRequestLinkService
{
    public function publicUrl(string $rawToken): string
    {
        return route('public.document-action.show', ['token' => $rawToken]);
    }

    public function documentUrl(DocumentRecipientRequest $request, string $rawToken): string
    {
        return route('public.document-action.document', ['token' => $rawToken]);
    }

    public function signUrl(string $rawToken): string
    {
        return route('public.document-action.sign', ['token' => $rawToken]);
    }

    public function acknowledgeUrl(string $rawToken): string
    {
        return route('public.document-action.acknowledge', ['token' => $rawToken]);
    }
}
