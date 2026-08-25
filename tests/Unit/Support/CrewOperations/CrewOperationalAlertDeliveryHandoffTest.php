<?php

use App\Support\CrewOperations\CrewOperationalAlertDeliveryHandoff;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

test('remember marks a handoff key that wasHandedOff can see', function () {
    $key = CrewOperationalAlertDeliveryHandoff::webPushKey(42);

    expect(CrewOperationalAlertDeliveryHandoff::wasHandedOff($key))->toBeFalse();

    CrewOperationalAlertDeliveryHandoff::remember($key);

    expect(CrewOperationalAlertDeliveryHandoff::wasHandedOff($key))->toBeTrue()
        ->and(Cache::has($key))->toBeTrue();
});

test('email key is stable regardless of delivery id order', function () {
    expect(CrewOperationalAlertDeliveryHandoff::emailKey(9, [3, 1, 2]))
        ->toBe(CrewOperationalAlertDeliveryHandoff::emailKey(9, [1, 2, 3]))
        ->and(CrewOperationalAlertDeliveryHandoff::emailKey(9, [1, 2, 3]))
        ->not->toBe(CrewOperationalAlertDeliveryHandoff::emailKey(8, [1, 2, 3]));
});

test('persistLedger retries a failed persist and then succeeds', function () {
    $attempts = 0;

    CrewOperationalAlertDeliveryHandoff::persistLedger(function () use (&$attempts): void {
        $attempts++;

        if ($attempts === 1) {
            throw new RuntimeException('ledger persist failed');
        }
    }, ['failure_category' => 'test_ledger']);

    expect($attempts)->toBe(2);
});

test('persistLedger swallows exhausted persist failures without throwing', function () {
    Log::spy();
    $attempts = 0;

    CrewOperationalAlertDeliveryHandoff::persistLedger(function () use (&$attempts): void {
        $attempts++;

        throw new RuntimeException('ledger persist failed');
    }, [
        'company_id' => 1,
        'failure_category' => 'test_ledger',
    ]);

    expect($attempts)->toBe(CrewOperationalAlertDeliveryHandoff::PERSIST_ATTEMPTS);

    Log::shouldHaveReceived('critical')->withArgs(function (string $message, array $context): bool {
        return str_contains($message, 'delivery ledger persist failed')
            && ($context['failure_category'] ?? null) === 'test_ledger'
            && ($context['exception_class'] ?? null) === RuntimeException::class;
    });
});
