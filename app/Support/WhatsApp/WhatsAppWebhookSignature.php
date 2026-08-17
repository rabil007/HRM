<?php

namespace App\Support\WhatsApp;

final class WhatsAppWebhookSignature
{
    public static function generate(string $secret, string $payload): string
    {
        return 'sha256='.hash_hmac('sha256', $payload, $secret);
    }

    public static function verify(string $secret, string $payload, string $signature): bool
    {
        if ($secret === '' || $signature === '') {
            return false;
        }

        return hash_equals(self::generate($secret, $payload), $signature);
    }
}
