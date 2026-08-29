<?php

namespace App\Support\Documents\RecipientRequests;

use App\Enums\DocumentRecipientType;
use App\Models\DocumentRecipientRequest;
use App\Models\DocumentRecipientRequestDelivery;
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

        $byRequestToken = DocumentRecipientRequest::query()
            ->where('token_hash', $hash)
            ->first();

        if ($byRequestToken instanceof DocumentRecipientRequest) {
            return $byRequestToken;
        }

        $delivery = DocumentRecipientRequestDelivery::query()
            ->where('access_token_hash', $hash)
            ->whereNull('revoked_at')
            ->with('recipientRequest')
            ->first();

        if (! $delivery instanceof DocumentRecipientRequestDelivery || ! $delivery->isActiveAccessToken()) {
            return null;
        }

        $request = $delivery->recipientRequest;

        if (! $request instanceof DocumentRecipientRequest) {
            return null;
        }

        if ((int) $request->company_id !== (int) $delivery->company_id) {
            return null;
        }

        if ($request->recipient_type !== DocumentRecipientType::SubjectEmployee) {
            return null;
        }

        return $request;
    }
}
