<?php

use App\Models\User;
use App\Rules\ValidWebPushEndpoint;
use App\Support\Notifications\SyncPushSubscription;
use Illuminate\Support\Facades\Validator;
use NotificationChannels\WebPush\PushSubscription;

/**
 * @return array{endpoint: string, keys: array{p256dh: string, auth: string}, contentEncoding: string}
 */
function samplePushPayload(string $suffix = 'a'): array
{
    return [
        'endpoint' => "https://fcm.googleapis.com/fcm/send/oms-hrm-{$suffix}",
        'keys' => [
            'p256dh' => 'BNcRnejnsCWcu6BCNCiCyiQoXKnAJkOjvgBgzEUrvsSMesTXHsYELfY35xZjFcRp27YWPBMBcIvP1uvxS9Xn1gE',
            'auth' => 'tBHItJI5svbpez7KI4CCXg',
        ],
        'contentEncoding' => 'aes128gcm',
    ];
}

function assertEndpointRejected(string $endpoint): void
{
    $validator = Validator::make(
        ['endpoint' => $endpoint],
        ['endpoint' => [new ValidWebPushEndpoint]],
    );

    expect($validator->fails())->toBeTrue();
}

test('guest cannot register a push subscription', function () {
    $this->postJson('/notification-settings/push-subscription', samplePushPayload())
        ->assertUnauthorized();
});

test('authenticated user can register a valid https push subscription', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/notification-settings/push-subscription', samplePushPayload('one'))
        ->assertOk()
        ->assertJson([
            'ok' => true,
            'enabled' => true,
        ])
        ->assertJsonMissingPath('endpoint')
        ->assertJsonMissingPath('keys');

    expect($user->pushSubscriptions()->count())->toBe(1)
        ->and($user->pushSubscriptions()->first()?->content_encoding?->value)->toBe('aes128gcm');
});

test('http localhost ip literal credential and malformed endpoints are rejected', function () {
    assertEndpointRejected('http://fcm.googleapis.com/fcm/send/x');
    assertEndpointRejected('https://localhost/push');
    assertEndpointRejected('https://foo.localhost/push');
    assertEndpointRejected('https://127.0.0.1/push');
    assertEndpointRejected('https://[::1]/push');
    assertEndpointRejected('https://10.0.0.5/push');
    assertEndpointRejected('https://169.254.10.1/push');
    assertEndpointRejected('https://user:pass@fcm.googleapis.com/fcm/send/x');
    assertEndpointRejected('https://fcm.googleapis.com/fcm/send/x#fragment');
    assertEndpointRejected('not-a-url');
});

test('ownership fields are prohibited on store and destroy', function () {
    $user = User::factory()->create();
    $payload = samplePushPayload('owned');
    $payload['user_id'] = 999;
    $payload['company_id'] = 999;
    $payload['subscribable_id'] = 999;
    $payload['subscribable_type'] = User::class;

    $this->actingAs($user)
        ->postJson('/notification-settings/push-subscription', $payload)
        ->assertUnprocessable();

    $this->actingAs($user)
        ->deleteJson('/notification-settings/push-subscription', [
            'endpoint' => samplePushPayload('owned')['endpoint'],
            'user_id' => 999,
        ])
        ->assertUnprocessable();
});

test('one user can own multiple device subscriptions up to the limit', function () {
    $user = User::factory()->create();

    foreach (range(1, SyncPushSubscription::MAX_SUBSCRIPTIONS_PER_USER) as $index) {
        $this->actingAs($user)
            ->postJson('/notification-settings/push-subscription', samplePushPayload("device-{$index}"))
            ->assertOk();
    }

    expect($user->pushSubscriptions()->count())->toBe(SyncPushSubscription::MAX_SUBSCRIPTIONS_PER_USER);

    $this->actingAs($user)
        ->postJson('/notification-settings/push-subscription', samplePushPayload('device-11'))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['endpoint']);

    $atLimit = samplePushPayload('device-1');
    $atLimit['keys']['auth'] = 'updatedAuthTokenValue12';

    $this->actingAs($user)
        ->postJson('/notification-settings/push-subscription', $atLimit)
        ->assertOk();

    expect($user->fresh()->pushSubscriptions()->count())->toBe(SyncPushSubscription::MAX_SUBSCRIPTIONS_PER_USER);
});

test('re-registering the same endpoint updates rather than duplicates it', function () {
    $user = User::factory()->create();
    $payload = samplePushPayload('same');

    $this->actingAs($user)
        ->postJson('/notification-settings/push-subscription', $payload)
        ->assertOk();

    $payload['keys']['auth'] = 'updatedAuthTokenValue12';

    $this->actingAs($user)
        ->postJson('/notification-settings/push-subscription', $payload)
        ->assertOk();

    expect($user->pushSubscriptions()->count())->toBe(1)
        ->and($user->pushSubscriptions()->first()?->auth_token)->toBe('updatedAuthTokenValue12');
});

test('shared browser endpoint is reassigned to the newly authenticated user', function () {
    $first = User::factory()->create();
    $second = User::factory()->create();
    $payload = samplePushPayload('shared');

    $this->actingAs($first)
        ->postJson('/notification-settings/push-subscription', $payload)
        ->assertOk();

    $this->actingAs($second)
        ->postJson('/notification-settings/push-subscription', $payload)
        ->assertOk();

    expect($first->fresh()->pushSubscriptions()->count())->toBe(0)
        ->and($second->fresh()->pushSubscriptions()->count())->toBe(1)
        ->and(PushSubscription::query()->where('endpoint', $payload['endpoint'])->count())->toBe(1);
});

test('a user cannot delete another users unrelated subscription', function () {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $payload = samplePushPayload('private');

    $this->actingAs($owner)
        ->postJson('/notification-settings/push-subscription', $payload)
        ->assertOk();

    $this->actingAs($intruder)
        ->deleteJson('/notification-settings/push-subscription', [
            'endpoint' => $payload['endpoint'],
        ])
        ->assertOk();

    expect($owner->fresh()->pushSubscriptions()->count())->toBe(1);
});

test('invalid encryption keys are rejected', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/notification-settings/push-subscription', [
            'endpoint' => samplePushPayload('bad-keys')['endpoint'],
            'keys' => [
                'p256dh' => 'short',
                'auth' => 'x',
            ],
        ])
        ->assertUnprocessable();
});

test('subscription routes are throttled', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    for ($i = 0; $i < 20; $i++) {
        $this->postJson('/notification-settings/push-subscription', samplePushPayload("throttle-{$i}"))
            ->assertOk();
        $user->pushSubscriptions()->delete();
    }

    $this->postJson('/notification-settings/push-subscription', samplePushPayload('throttle-over'))
        ->assertStatus(429);
});

test('authenticated user can detach their own subscription endpoint', function () {
    $user = User::factory()->create();
    $payload = samplePushPayload('detach');

    $this->actingAs($user)
        ->postJson('/notification-settings/push-subscription', $payload)
        ->assertOk();

    $this->actingAs($user)
        ->deleteJson('/notification-settings/push-subscription', [
            'endpoint' => $payload['endpoint'],
        ])
        ->assertOk()
        ->assertJson([
            'ok' => true,
            'enabled' => false,
        ]);

    expect($user->fresh()->pushSubscriptions()->count())->toBe(0);
});
