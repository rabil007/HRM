<?php

use App\Jobs\DeliverAnnouncementEmailJob;
use App\Jobs\DeliverAnnouncementInAppJob;
use App\Jobs\DeliverAnnouncementWebPushJob;
use App\Jobs\DeliverAnnouncementWhatsAppJob;
use App\Jobs\SendTestWebPushJob;
use App\Models\Announcement;
use App\Models\AnnouncementDelivery;
use App\Models\AnnouncementRecipient;
use App\Models\User;
use App\Notifications\TestWebPushNotification;
use App\Support\Notifications\SinglePushSubscriptionNotifiable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use NotificationChannels\WebPush\PushSubscription;
use NotificationChannels\WebPush\WebPushChannel;

/**
 * @return array{endpoint: string}
 */
function sampleTestPushEndpoint(string $suffix = 'test'): array
{
    return [
        'endpoint' => "https://fcm.googleapis.com/fcm/send/oms-hrm-test-{$suffix}",
    ];
}

/**
 * @return array{endpoint: string, keys: array{p256dh: string, auth: string}, contentEncoding: string}
 */
function sampleTestPushPayload(string $suffix = 'test'): array
{
    return [
        'endpoint' => "https://fcm.googleapis.com/fcm/send/oms-hrm-test-{$suffix}",
        'keys' => [
            'p256dh' => 'BNcRnejnsCWcu6BCNCiCyiQoXKnAJkOjvgBgzEUrvsSMesTXHsYELfY35xZjFcRp27YWPBMBcIvP1uvxS9Xn1gE',
            'auth' => 'tBHItJI5svbpez7KI4CCXg',
        ],
        'contentEncoding' => 'aes128gcm',
    ];
}

beforeEach(function () {
    config([
        'webpush.vapid.public_key' => 'BNcRnejnsCWcu6BCNCiCyiQoXKnAJkOjvgBgzEUrvsSMesTXHsYELfY35xZjFcRp27YWPBMBcIvP1uvxS9Xn1gE',
        'webpush.vapid.private_key' => str_repeat('a', 43),
    ]);
});

test('guest cannot send a test push notification', function () {
    $this->postJson('/notification-settings/push-subscription/test', sampleTestPushEndpoint())
        ->assertUnauthorized();
});

test('authenticated user can queue a test for their own subscription', function () {
    Queue::fake();

    $user = User::factory()->create();
    $payload = sampleTestPushPayload('own');

    $this->actingAs($user)
        ->postJson('/notification-settings/push-subscription', $payload)
        ->assertOk();

    $subscription = $user->pushSubscriptions()->first();

    $response = $this->actingAs($user)
        ->postJson('/notification-settings/push-subscription/test', [
            'endpoint' => $payload['endpoint'],
        ])
        ->assertOk()
        ->assertJson([
            'ok' => true,
            'message' => 'Test notification queued.',
        ]);

    expect($response->getContent())->not->toContain($payload['endpoint'])
        ->and($response->getContent())->not->toContain($payload['keys']['p256dh'])
        ->and($response->getContent())->not->toContain($payload['keys']['auth']);

    Queue::assertPushed(SendTestWebPushJob::class, function (SendTestWebPushJob $job) use ($user, $subscription): bool {
        return $job->userId === $user->id
            && $job->subscriptionId === $subscription->id;
    });
});

test('test targets only the submitted current-device subscription', function () {
    Queue::fake();

    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/notification-settings/push-subscription', sampleTestPushPayload('device-a'))
        ->assertOk();
    $this->actingAs($user)
        ->postJson('/notification-settings/push-subscription', sampleTestPushPayload('device-b'))
        ->assertOk();

    $target = $user->pushSubscriptions()
        ->where('endpoint', sampleTestPushPayload('device-a')['endpoint'])
        ->firstOrFail();
    $other = $user->pushSubscriptions()
        ->where('endpoint', sampleTestPushPayload('device-b')['endpoint'])
        ->firstOrFail();

    $this->actingAs($user)
        ->postJson('/notification-settings/push-subscription/test', [
            'endpoint' => $target->endpoint,
        ])
        ->assertOk();

    Queue::assertPushed(SendTestWebPushJob::class, function (SendTestWebPushJob $job) use ($user, $target, $other): bool {
        return $job->userId === $user->id
            && $job->subscriptionId === $target->id
            && $job->subscriptionId !== $other->id;
    });
});

test('job sends only through the single verified subscription notifiable', function () {
    $user = User::factory()->create();
    $user->updatePushSubscription(
        sampleTestPushPayload('solo')['endpoint'],
        sampleTestPushPayload('solo')['keys']['p256dh'],
        sampleTestPushPayload('solo')['keys']['auth'],
        'aes128gcm',
    );
    $user->updatePushSubscription(
        sampleTestPushPayload('extra')['endpoint'],
        sampleTestPushPayload('extra')['keys']['p256dh'],
        sampleTestPushPayload('extra')['keys']['auth'],
        'aes128gcm',
    );

    $target = $user->pushSubscriptions()
        ->where('endpoint', sampleTestPushPayload('solo')['endpoint'])
        ->firstOrFail();

    $this->mock(WebPushChannel::class, function ($mock) use ($target): void {
        $mock->shouldReceive('send')
            ->once()
            ->withArgs(function (object $notifiable, object $notification) use ($target): bool {
                expect($notifiable)->toBeInstanceOf(SinglePushSubscriptionNotifiable::class)
                    ->and($notification)->toBeInstanceOf(TestWebPushNotification::class);

                $subscriptions = $notifiable->routeNotificationForWebPush();

                expect($subscriptions)->toHaveCount(1)
                    ->and($subscriptions->first()?->is($target))->toBeTrue();

                return true;
            });
    });

    (new SendTestWebPushJob($user->id, $target->id))->handle(app(WebPushChannel::class));
});

test('user cannot test another users subscription', function () {
    Queue::fake();

    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $payload = sampleTestPushPayload('foreign');

    $this->actingAs($owner)
        ->postJson('/notification-settings/push-subscription', $payload)
        ->assertOk();

    $this->actingAs($intruder)
        ->postJson('/notification-settings/push-subscription/test', [
            'endpoint' => $payload['endpoint'],
        ])
        ->assertNotFound()
        ->assertJson([
            'ok' => false,
            'expired' => true,
        ])
        ->assertJsonMissingPath('endpoint');

    Queue::assertNotPushed(SendTestWebPushJob::class);
});

test('missing endpoint is rejected', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/notification-settings/push-subscription/test', [])
        ->assertUnprocessable();
});

test('invalid or unsafe endpoints are rejected', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/notification-settings/push-subscription/test', [
            'endpoint' => 'http://fcm.googleapis.com/fcm/send/x',
        ])
        ->assertUnprocessable();

    $this->actingAs($user)
        ->postJson('/notification-settings/push-subscription/test', [
            'endpoint' => 'https://127.0.0.1/push',
        ])
        ->assertUnprocessable();
});

test('client-supplied ownership fields are rejected on test', function () {
    $user = User::factory()->create();
    $payload = sampleTestPushEndpoint('owned');
    $payload['user_id'] = 999;
    $payload['company_id'] = 999;

    $this->actingAs($user)
        ->postJson('/notification-settings/push-subscription/test', $payload)
        ->assertUnprocessable();
});

test('test action creates no announcement records or delivery jobs', function () {
    Queue::fake();

    $user = User::factory()->create();
    $payload = sampleTestPushPayload('clean');

    $this->actingAs($user)
        ->postJson('/notification-settings/push-subscription', $payload)
        ->assertOk();

    $beforeAnnouncements = Announcement::query()->count();
    $beforeRecipients = AnnouncementRecipient::query()->count();
    $beforeDeliveries = AnnouncementDelivery::query()->count();

    $this->actingAs($user)
        ->postJson('/notification-settings/push-subscription/test', [
            'endpoint' => $payload['endpoint'],
        ])
        ->assertOk();

    expect(Announcement::query()->count())->toBe($beforeAnnouncements)
        ->and(AnnouncementRecipient::query()->count())->toBe($beforeRecipients)
        ->and(AnnouncementDelivery::query()->count())->toBe($beforeDeliveries);

    Queue::assertPushed(SendTestWebPushJob::class);
    Queue::assertNotPushed(DeliverAnnouncementInAppJob::class);
    Queue::assertNotPushed(DeliverAnnouncementEmailJob::class);
    Queue::assertNotPushed(DeliverAnnouncementWhatsAppJob::class);
    Queue::assertNotPushed(DeliverAnnouncementWebPushJob::class);
});

test('test push route is throttled', function () {
    Queue::fake();

    $user = User::factory()->create();
    $payload = sampleTestPushPayload('throttle');

    $user->updatePushSubscription(
        $payload['endpoint'],
        $payload['keys']['p256dh'],
        $payload['keys']['auth'],
        $payload['contentEncoding'],
    );

    $this->actingAs($user);

    for ($i = 0; $i < 5; $i++) {
        $this->postJson('/notification-settings/push-subscription/test', [
            'endpoint' => $payload['endpoint'],
        ])
            ->assertOk();
    }

    $this->postJson('/notification-settings/push-subscription/test', [
        'endpoint' => $payload['endpoint'],
    ])
        ->assertStatus(429);
});

test('missing subscription is treated as expired without logging the endpoint', function () {
    Queue::fake();
    Log::spy();

    $user = User::factory()->create();
    $endpoint = sampleTestPushEndpoint('missing')['endpoint'];

    $response = $this->actingAs($user)
        ->postJson('/notification-settings/push-subscription/test', [
            'endpoint' => $endpoint,
        ])
        ->assertNotFound()
        ->assertJson([
            'ok' => false,
            'expired' => true,
            'message' => 'This browser is no longer subscribed. Enable notifications again.',
        ]);

    expect($response->getContent())->not->toContain($endpoint);
    Queue::assertNotPushed(SendTestWebPushJob::class);
    Log::shouldNotHaveReceived('warning');
});

test('expired subscription cleanup during job does not affect other subscriptions', function () {
    $user = User::factory()->create();
    $user->updatePushSubscription(
        sampleTestPushPayload('keep')['endpoint'],
        sampleTestPushPayload('keep')['keys']['p256dh'],
        sampleTestPushPayload('keep')['keys']['auth'],
        'aes128gcm',
    );
    $user->updatePushSubscription(
        sampleTestPushPayload('expire')['endpoint'],
        sampleTestPushPayload('expire')['keys']['p256dh'],
        sampleTestPushPayload('expire')['keys']['auth'],
        'aes128gcm',
    );

    $target = $user->pushSubscriptions()
        ->where('endpoint', sampleTestPushPayload('expire')['endpoint'])
        ->firstOrFail();
    $kept = $user->pushSubscriptions()
        ->where('endpoint', sampleTestPushPayload('keep')['endpoint'])
        ->firstOrFail();

    $this->mock(WebPushChannel::class, function ($mock) use ($target): void {
        $mock->shouldReceive('send')
            ->once()
            ->andReturnUsing(function (object $notifiable) use ($target): void {
                expect($notifiable->routeNotificationForWebPush()->first()?->is($target))->toBeTrue();
                $target->delete();
            });
    });

    (new SendTestWebPushJob($user->id, $target->id))->handle(app(WebPushChannel::class));

    expect(PushSubscription::query()->whereKey($target->id)->exists())->toBeFalse()
        ->and(PushSubscription::query()->whereKey($kept->id)->exists())->toBeTrue()
        ->and(Announcement::query()->count())->toBe(0)
        ->and(AnnouncementDelivery::query()->count())->toBe(0);
});

test('provider failure does not affect announcements or other subscriptions', function () {
    Log::shouldReceive('warning')
        ->once()
        ->withArgs(function (string $message, array $context): bool {
            expect($message)->toBe('Test web push delivery failed')
                ->and($context)->not->toHaveKey('endpoint')
                ->and(json_encode($context))->not->toContain('fcm.googleapis.com')
                ->and($context['failure_category'] ?? null)->toBe('test_web_push_transport')
                ->and($context)->toHaveKeys(['user_id', 'subscription_id', 'attempt', 'exception_class']);

            return true;
        });

    $user = User::factory()->create();
    $user->updatePushSubscription(
        sampleTestPushPayload('fail-a')['endpoint'],
        sampleTestPushPayload('fail-a')['keys']['p256dh'],
        sampleTestPushPayload('fail-a')['keys']['auth'],
        'aes128gcm',
    );
    $user->updatePushSubscription(
        sampleTestPushPayload('fail-b')['endpoint'],
        sampleTestPushPayload('fail-b')['keys']['p256dh'],
        sampleTestPushPayload('fail-b')['keys']['auth'],
        'aes128gcm',
    );

    $target = $user->pushSubscriptions()
        ->where('endpoint', sampleTestPushPayload('fail-a')['endpoint'])
        ->firstOrFail();
    $other = $user->pushSubscriptions()
        ->where('endpoint', sampleTestPushPayload('fail-b')['endpoint'])
        ->firstOrFail();

    $this->mock(WebPushChannel::class, function ($mock): void {
        $mock->shouldReceive('send')
            ->once()
            ->andThrow(new RuntimeException('provider boom with https://fcm.googleapis.com/secret'));
    });

    expect(fn () => (new SendTestWebPushJob($user->id, $target->id))->handle(app(WebPushChannel::class)))
        ->toThrow(RuntimeException::class);

    expect(PushSubscription::query()->whereKey($target->id)->exists())->toBeTrue()
        ->and(PushSubscription::query()->whereKey($other->id)->exists())->toBeTrue()
        ->and(Announcement::query()->count())->toBe(0)
        ->and(AnnouncementDelivery::query()->count())->toBe(0);
});

test('job exits cleanly when subscription is missing', function () {
    $user = User::factory()->create();

    $this->mock(WebPushChannel::class, function ($mock): void {
        $mock->shouldNotReceive('send');
    });

    (new SendTestWebPushJob($user->id, 999_999))->handle(app(WebPushChannel::class));
});

test('notification facade is not used for all-user subscriptions', function () {
    Notification::fake();

    $user = User::factory()->create();
    $user->updatePushSubscription(
        sampleTestPushPayload('facade')['endpoint'],
        sampleTestPushPayload('facade')['keys']['p256dh'],
        sampleTestPushPayload('facade')['keys']['auth'],
        'aes128gcm',
    );
    $subscription = $user->pushSubscriptions()->firstOrFail();

    $this->mock(WebPushChannel::class, function ($mock): void {
        $mock->shouldReceive('send')->once();
    });

    (new SendTestWebPushJob($user->id, $subscription->id))->handle(app(WebPushChannel::class));

    Notification::assertNothingSent();
});
