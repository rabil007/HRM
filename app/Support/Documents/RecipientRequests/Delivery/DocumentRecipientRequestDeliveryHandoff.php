<?php

namespace App\Support\Documents\RecipientRequests\Delivery;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

final class DocumentRecipientRequestDeliveryHandoff
{
    public const REMEMBER_SECONDS = 86400;

    public const PERSIST_ATTEMPTS = 3;

    public static function emailKey(int $deliveryId): string
    {
        return 'document-recipient-email-handoff:'.$deliveryId;
    }

    public static function queueKey(int $deliveryId): string
    {
        return 'document-recipient-email-queue-handoff:'.$deliveryId;
    }

    public static function wasHandedOff(string $key): bool
    {
        return Cache::has($key);
    }

    public static function remember(string $key): void
    {
        try {
            Cache::put($key, true, self::REMEMBER_SECONDS);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * Retry ledger persistence without throwing.
     *
     * @param  callable(): void  $persist
     * @param  array<string, mixed>  $context
     */
    public static function persistLedger(callable $persist, array $context): bool
    {
        $lastException = null;

        for ($attempt = 1; $attempt <= self::PERSIST_ATTEMPTS; $attempt++) {
            try {
                $persist();

                return true;
            } catch (Throwable $exception) {
                $lastException = $exception;
                report($exception);
            }
        }

        Log::critical('Document recipient email handed off but delivery ledger persist failed', [
            ...$context,
            'exception_class' => $lastException instanceof Throwable ? $lastException::class : null,
            'failure_category' => $context['failure_category'] ?? 'delivery_ledger_persist',
        ]);

        return false;
    }
}
