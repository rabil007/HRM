<?php

namespace App\Support\WhatsApp;

final class MetaWebhookSignature
{
    public function isValid(string $rawBody, ?string $providedSignature, ?string $appSecret): bool
    {
        $providedSignature = trim((string) $providedSignature);
        $appSecret = (string) $appSecret;

        if (
            $appSecret === ''
            || preg_match('/^sha256=[a-f0-9]{64}$/D', $providedSignature) !== 1
        ) {
            return false;
        }

        $expectedSignature = 'sha256='.hash_hmac('sha256', $rawBody, $appSecret);

        return hash_equals($expectedSignature, $providedSignature);
    }
}
