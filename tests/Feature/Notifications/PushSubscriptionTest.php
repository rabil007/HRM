<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use NotificationChannels\WebPush\PushSubscription;

/**
 * @return array{endpoint: string, keys: array{p256dh: string, auth: string}, contentEncoding: string}
 */
function samplePushPayload(string $suffix = 'a'): array
{
    return [
        'endpoint' => "https://push.example.test/endpoint-{$suffix}",
        'keys' => [
            'p256dh' => 'BNcRnejnsCWcu6BCNCiCyiQoXKnAJkOjvgBgzEUrvsSMesTXHsYELfY35xZjFcRp27YWPBMBcIvP1uvxS9Xn1gE',
            'auth' => 'tBHItJI5svbpez7KI4CCXg',
        ],
        'contentEncoding' => 'aesgcm',
    ];
}

test('guest cannot register a push subscription', function () {
    $this->postJson('/notification-settings/push-subscription', samplePushPayload())
        ->assertUnauthorized();
});

test('authenticated user can register a push subscription', function () {
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

    expect($user->pushSubscriptions()->count())->toBe(1);
});

test('client supplied user_id and company_id are ignored when storing subscriptions', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();

    $payload = samplePushPayload('owned');
    $payload['user_id'] = $other->id;
    $payload['company_id'] = 999;

    $this->actingAs($owner)
        ->postJson('/notification-settings/push-subscription', $payload)
        ->assertOk();

    $subscription = PushSubscription::query()->first();

    expect($subscription)->not->toBeNull()
        ->and((int) $subscription->subscribable_id)->toBe($owner->id)
        ->and($subscription->subscribable_type)->toBe($owner->getMorphClass())
        ->and(DB::table('push_subscriptions')->where('subscribable_id', $other->id)->count())->toBe(0);
});

test('one user can own multiple device subscriptions', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/notification-settings/push-subscription', samplePushPayload('desktop'))
        ->assertOk();

    $this->actingAs($user)
        ->postJson('/notification-settings/push-subscription', samplePushPayload('mobile'))
        ->assertOk();

    expect($user->pushSubscriptions()->count())->toBe(2);
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

test('invalid endpoint or missing encryption keys are rejected', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/notification-settings/push-subscription', [
            'endpoint' => 'not-a-url',
            'keys' => [
                'p256dh' => '',
                'auth' => '',
            ],
        ])
        ->assertUnprocessable();
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
