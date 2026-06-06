<?php

namespace App\Support\Hikvision;

class HikvisionWebhookSignature
{
    public static function generate(string $secret, string $timestamp, string $batchId): string
    {
        $message = $timestamp.'.'.$batchId;

        return 'sha256='.hash_hmac('sha256', $message, $secret);
    }

    public static function verify(string $secret, string $timestamp, string $batchId, string $signature): bool
    {
        if ($signature === '') {
            return false;
        }

        return hash_equals(self::generate($secret, $timestamp, $batchId), $signature);
    }

    public static function timestampIsFresh(string $timestamp, int $maxSkewSeconds = 60): bool
    {
        if (! is_numeric($timestamp)) {
            return false;
        }

        return abs(time() - (int) $timestamp) <= $maxSkewSeconds;
    }
}
