<?php

namespace App\Support\CrewOperations;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Idempotency for Crew operational alert email/push after a successful external handoff.
 *
 * A successful Mail / Web Push send must not be retried when ledger persist fails.
 * Cache remembers the handoff; the delivery row remains the audit source of truth.
 */
final class CrewOperationalAlertDeliveryHandoff
{
    public const REMEMBER_SECONDS = 86400;

    public const PERSIST_ATTEMPTS = 3;

    public static function webPushKey(int $deliveryId): string
    {
        return 'crew-operational-alert-web-push-handoff:'.$deliveryId;
    }

    /**
     * @param  list<int>  $deliveryIds
     */
    public static function emailKey(int $companyId, array $deliveryIds): string
    {
        $sorted = $deliveryIds;
        sort($sorted);

        return 'crew-operational-alert-email-handoff:'.$companyId.':'.implode('-', $sorted);
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
     * Persist ledger state after a successful external or queue handoff.
     *
     * Failures are reported and retried locally. They must not propagate to the
     * queue worker, which would retry the already-successful send.
     *
     * @param  callable(): void  $persist
     * @param  array<string, mixed>  $context
     */
    public static function persistLedger(callable $persist, array $context): void
    {
        $lastException = null;

        for ($attempt = 1; $attempt <= self::PERSIST_ATTEMPTS; $attempt++) {
            try {
                $persist();

                return;
            } catch (Throwable $exception) {
                $lastException = $exception;
                report($exception);
            }
        }

        Log::critical('Crew operational alert handed off but delivery ledger persist failed', [
            ...$context,
            'exception_class' => $lastException instanceof Throwable ? $lastException::class : null,
            'failure_category' => $context['failure_category'] ?? 'delivery_ledger_persist',
        ]);
    }
}
