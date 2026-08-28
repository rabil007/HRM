<?php

namespace App\Support\Documents\RecipientRequests;

use App\Models\DocumentRecipientRequest;
use Illuminate\Support\Str;

final class DocumentRecipientRequestToken
{
    public static function generate(): string
    {
        return Str::random(64);
    }

    public static function hash(string $rawToken): string
    {
        return hash('sha256', $rawToken);
    }

    public static function findByRawToken(string $rawToken): ?DocumentRecipientRequest
    {
        $hash = self::hash($rawToken);

        return DocumentRecipientRequest::query()
            ->where('token_hash', $hash)
            ->first();
    }
}
