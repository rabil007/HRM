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

    public static function normalizeTimestamp(string $timestamp): ?int
    {
        if (! is_numeric($timestamp)) {
            return null;
        }

        $value = (int) $timestamp;

        // Hik-Connect sends Unix time in milliseconds (see OpenAPI Java demo).
        if ($value > 9_999_999_999) {
            $value = (int) floor($value / 1000);
        }

        return $value;
    }

    public static function timestampIsFresh(string $timestamp, int $maxSkewSeconds = 60): bool
    {
        $normalized = self::normalizeTimestamp($timestamp);

        if ($normalized === null) {
            return false;
        }

        return abs(time() - $normalized) <= $maxSkewSeconds;
    }
}
