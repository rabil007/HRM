<?php

use App\Enums\CrewOperationalAlertPushDeliveryStatus;
use App\Enums\CrewOperationalAlertSeverity;
use App\Enums\CrewOperationalAlertStatus;
use App\Enums\CrewOperationalAlertType;
use App\Jobs\DeliverCrewOperationalAlertWebPushJob;
use App\Models\CrewOperationalAlert;
use App\Models\CrewOperationalAlertPushDelivery;
use App\Models\CrewOperationalAlertRecipient;
use App\Models\User;
use App\Notifications\CrewOperationalAlertWebPushNotification;
use App\Support\CrewOperations\CrewOperationalAlertDeliveryHandoff;
use App\Support\CrewOperations\QueueCrewOperationalAlertPushes;
use App\Support\CrewOperations\ReconcileCrewOperationalAlerts;
use App\Support\CrewOperations\ResolveCrewOperationalAlertUrl;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

test('selected recipient receives crew feed item and non-selected does not', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-07 12:00:00', 'Asia/Dubai'));
    $fixtures = makeCrewAssignmentFixtures();
    $companyId = (int) $fixtures['company']->id;
    $selected = $fixtures['user'];
    $other = User::factory()->create();
    $other->companies()->attach($companyId, ['status' => 'active']);

    enableCrewNotificationsForUser($companyId, (int) $selected->id);
    createOverdueAssignmentForAlerts($fixtures);
    app(ReconcileCrewOperationalAlerts::class)->forCompany($companyId);

    $this->actingAs($selected)
        ->getJson(route('notifications.feed'))
        ->assertOk()
        ->assertJsonPath('unread_count', 1)
        ->assertJsonPath('items.0.source', 'crew_operational_alert')
        ->assertJsonPath('items.0.is_read', false)
        ->assertJsonPath('items.0.source_label', 'Crew Operations');

    $this->actingAs($other)
        ->getJson(route('notifications.feed'))
        ->assertOk()
        ->assertJsonPath('unread_count', 0)
        ->assertJsonCount(0, 'items');

    CarbonImmutable::setTestNow();
});

test('cross-company user cannot see or mark another company crew alert', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-07 12:00:00', 'Asia/Dubai'));
    $companyA = makeCrewAssignmentFixtures();
    $companyB = makeCrewAssignmentFixtures();

    enableCrewNotificationsForUser((int) $companyA['company']->id, (int) $companyA['user']->id);
    createOverdueAssignmentForAlerts($companyA);
    app(ReconcileCrewOperationalAlerts::class)->forCompany((int) $companyA['company']->id);

    $recipient = CrewOperationalAlertRecipient::query()
        ->where('company_id', $companyA['company']->id)
        ->firstOrFail();

    $this->actingAs($companyB['user'])
        ->getJson(route('notifications.feed'))
        ->assertOk()
        ->assertJsonPath('unread_count', 0);

    $this->actingAs($companyB['user'])
        ->postJson(route('organization.notifications.crew.read', $recipient))
        ->assertNotFound();

    CarbonImmutable::setTestNow();
});

test('crew alert mark read updates unified unread count', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-07 12:00:00', 'Asia/Dubai'));
    $fixtures = makeCrewAssignmentFixtures();
    $companyId = (int) $fixtures['company']->id;
    enableCrewNotificationsForUser($companyId, (int) $fixtures['user']->id);
    createOverdueAssignmentForAlerts($fixtures);
    app(ReconcileCrewOperationalAlerts::class)->forCompany($companyId);

    $recipient = CrewOperationalAlertRecipient::query()
        ->where('company_id', $companyId)
        ->where('user_id', $fixtures['user']->id)
        ->firstOrFail();

    expect($recipient->read_at)->toBeNull();

    $this->actingAs($fixtures['user'])
        ->postJson(route('organization.notifications.crew.read', $recipient))
        ->assertOk();

    expect($recipient->fresh()->read_at)->not->toBeNull();

    $this->actingAs($fixtures['user'])
        ->getJson(route('notifications.feed'))
        ->assertOk()
        ->assertJsonPath('unread_count', 0)
        ->assertJsonPath('items.0.is_read', true);

    CarbonImmutable::setTestNow();
});

test('active alert creates recipient once and repeated reconciliation does not duplicate', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-07 12:00:00', 'Asia/Dubai'));
    $fixtures = makeCrewAssignmentFixtures();
    $companyId = (int) $fixtures['company']->id;
    enableCrewNotificationsForUser($companyId, (int) $fixtures['user']->id);
    createOverdueAssignmentForAlerts($fixtures);

    app(ReconcileCrewOperationalAlerts::class)->forCompany($companyId);
    app(ReconcileCrewOperationalAlerts::class)->forCompany($companyId);

    expect(CrewOperationalAlertRecipient::query()->where('company_id', $companyId)->count())->toBe(1)
        ->and(CrewOperationalAlert::query()->where('company_id', $companyId)->count())->toBe(1);

    CarbonImmutable::setTestNow();
});

test('new recipient gets active alert recipient state', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-07 12:00:00', 'Asia/Dubai'));
    $fixtures = makeCrewAssignmentFixtures();
    $companyId = (int) $fixtures['company']->id;
    $first = $fixtures['user'];
    $second = User::factory()->create();
    $second->companies()->attach($companyId, ['status' => 'active']);

    enableCrewNotificationsForUser($companyId, (int) $first->id);
    createOverdueAssignmentForAlerts($fixtures);
    app(ReconcileCrewOperationalAlerts::class)->forCompany($companyId);

    expect(CrewOperationalAlertRecipient::query()->where('user_id', $second->id)->count())->toBe(0);

    enableCrewNotificationsForUser($companyId, (int) $first->id, [
        'notification_recipient_user_ids' => [(int) $first->id, (int) $second->id],
    ]);

    expect(CrewOperationalAlertRecipient::query()->where('user_id', $second->id)->count())->toBe(1);

    CarbonImmutable::setTestNow();
});

test('new alert queues one push and unchanged alert does not push repeatedly', function () {
    Queue::fake();
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-07 12:00:00', 'Asia/Dubai'));
    $fixtures = makeCrewAssignmentFixtures();
    $companyId = (int) $fixtures['company']->id;
    $user = $fixtures['user'];
    $user->updatePushSubscription(
        'https://fcm.googleapis.com/fcm/send/crew-alert-1',
        'BNcRnejnsCWcu6BCNCiCyiQoXKnAJkOjvgBgzEUrvsSMesTXHsYELfY35xZjFcRp27YWPBMBcIvP1uvxS9Xn1gE',
        'tBHItJI5svbpez7KI4CCXg',
        'aes128gcm',
    );

    enableCrewNotificationsForUser($companyId, (int) $user->id);
    createOverdueAssignmentForAlerts($fixtures);

    app(ReconcileCrewOperationalAlerts::class)->forCompany($companyId);
    Queue::assertPushed(DeliverCrewOperationalAlertWebPushJob::class, 1);

    app(ReconcileCrewOperationalAlerts::class)->forCompany($companyId);
    Queue::assertPushed(DeliverCrewOperationalAlertWebPushJob::class, 1);

    expect(CrewOperationalAlertPushDelivery::query()->where('company_id', $companyId)->count())->toBe(1);

    CarbonImmutable::setTestNow();
});

test('reactivated alert and severity escalation push again', function () {
    Queue::fake();
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-07 12:00:00', 'Asia/Dubai'));
    $fixtures = makeCrewAssignmentFixtures();
    $companyId = (int) $fixtures['company']->id;
    $user = $fixtures['user'];
    $user->updatePushSubscription(
        'https://fcm.googleapis.com/fcm/send/crew-alert-2',
        'BNcRnejnsCWcu6BCNCiCyiQoXKnAJkOjvgBgzEUrvsSMesTXHsYELfY35xZjFcRp27YWPBMBcIvP1uvxS9Xn1gE',
        'tBHItJI5svbpez7KI4CCXg',
        'aes128gcm',
    );

    enableCrewNotificationsForUser($companyId, (int) $user->id);
    $assignment = createOverdueAssignmentForAlerts($fixtures);

    app(ReconcileCrewOperationalAlerts::class)->forCompany($companyId);
    $alert = CrewOperationalAlert::query()->where('company_id', $companyId)->firstOrFail();
    expect($alert->notification_version)->toBe(1);

    $assignment->update(['planned_signoff_at' => '2026-09-01 00:00:00']);
    app(ReconcileCrewOperationalAlerts::class)->forCompany($companyId);
    expect($alert->fresh()->status)->toBe(CrewOperationalAlertStatus::Resolved);

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-08 12:00:00', 'Asia/Dubai'));
    $assignment->update(['planned_signoff_at' => '2026-08-01 00:00:00']);
    app(ReconcileCrewOperationalAlerts::class)->forCompany($companyId);

    expect($alert->fresh()->status)->toBe(CrewOperationalAlertStatus::Active)
        ->and($alert->fresh()->notification_version)->toBe(2)
        ->and(CrewOperationalAlertPushDelivery::query()->where('company_id', $companyId)->count())->toBe(2);

    Queue::assertPushed(DeliverCrewOperationalAlertWebPushJob::class, 2);

    CarbonImmutable::setTestNow();
});

test('removed recipient does not receive future push and no subscription skips push', function () {
    Queue::fake();
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-07 12:00:00', 'Asia/Dubai'));
    $fixtures = makeCrewAssignmentFixtures();
    $companyId = (int) $fixtures['company']->id;
    $user = $fixtures['user'];
    $user->updatePushSubscription(
        'https://fcm.googleapis.com/fcm/send/crew-alert-3',
        'BNcRnejnsCWcu6BCNCiCyiQoXKnAJkOjvgBgzEUrvsSMesTXHsYELfY35xZjFcRp27YWPBMBcIvP1uvxS9Xn1gE',
        'tBHItJI5svbpez7KI4CCXg',
        'aes128gcm',
    );

    enableCrewNotificationsForUser($companyId, (int) $user->id);
    createOverdueAssignmentForAlerts($fixtures);
    app(ReconcileCrewOperationalAlerts::class)->forCompany($companyId);
    Queue::assertPushed(DeliverCrewOperationalAlertWebPushJob::class, 1);

    enableCrewNotificationsForUser($companyId, (int) $user->id, [
        'notification_recipient_user_ids' => [],
    ]);

    $assignment = CrewOperationalAlert::query()->where('company_id', $companyId)->firstOrFail();
    $assignment->update([
        'status' => CrewOperationalAlertStatus::Resolved,
        'resolved_at' => now(),
        'notification_version' => 1,
    ]);

    // Re-enable notifications without this user selected; recreate condition via reconcile path.
    $other = User::factory()->create();
    $other->companies()->attach($companyId, ['status' => 'active']);
    enableCrewNotificationsForUser($companyId, (int) $other->id);
    // other has no subscription → no push queue
    app(ReconcileCrewOperationalAlerts::class)->forCompany($companyId);

    expect(CrewOperationalAlertPushDelivery::query()
        ->where('company_id', $companyId)
        ->where('user_id', $user->id)
        ->count())->toBe(1)
        ->and(CrewOperationalAlertPushDelivery::query()
            ->where('company_id', $companyId)
            ->where('user_id', $other->id)
            ->count())->toBe(0);

    CarbonImmutable::setTestNow();
});

test('push payload is privacy safe and open route is permission aware', function () {
    Notification::fake();
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-07 12:00:00', 'Asia/Dubai'));
    $fixtures = makeCrewAssignmentFixtures();
    $companyId = (int) $fixtures['company']->id;
    $user = $fixtures['user'];
    $user->updatePushSubscription(
        'https://fcm.googleapis.com/fcm/send/crew-alert-4',
        'BNcRnejnsCWcu6BCNCiCyiQoXKnAJkOjvgBgzEUrvsSMesTXHsYELfY35xZjFcRp27YWPBMBcIvP1uvxS9Xn1gE',
        'tBHItJI5svbpez7KI4CCXg',
        'aes128gcm',
    );

    enableCrewNotificationsForUser($companyId, (int) $user->id);
    createOverdueAssignmentForAlerts($fixtures);
    app(ReconcileCrewOperationalAlerts::class)->forCompany($companyId);

    $delivery = CrewOperationalAlertPushDelivery::query()->where('company_id', $companyId)->firstOrFail();
    app(DeliverCrewOperationalAlertWebPushJob::class, ['deliveryId' => (int) $delivery->id])->handle();

    Notification::assertSentTo($user, CrewOperationalAlertWebPushNotification::class, function (CrewOperationalAlertWebPushNotification $notification) use ($user): bool {
        $message = $notification->toWebPush($user, $notification)->toArray();

        expect($message['title'])->toBe('Crew Operations')
            ->and($message['body'])->toBe('Crew Operations requires attention. Open OMS-HRM to review.')
            ->and($message['body'])->not->toContain('Notify Vessel')
            ->and($message['body'])->not->toContain('Chief');

        return true;
    });

    $recipient = CrewOperationalAlertRecipient::query()->where('company_id', $companyId)->firstOrFail();
    $alert = $recipient->alert;
    grantCompanyPermissions($user, $fixtures['company'], [
        'crew_operations.assignments.view',
    ]);

    $url = app(ResolveCrewOperationalAlertUrl::class)->forUser($user, $alert);
    expect($url)->toContain('/organization/crew/');

    $this->actingAs($user)
        ->get(route('notifications.crew-operational-alerts.open', $recipient))
        ->assertRedirect();

    expect($recipient->fresh()->read_at)->not->toBeNull();

    CarbonImmutable::setTestNow();
});

test('inactive membership is excluded from push queueing', function () {
    Queue::fake();
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-07 12:00:00', 'Asia/Dubai'));
    $fixtures = makeCrewAssignmentFixtures();
    $companyId = (int) $fixtures['company']->id;
    $user = $fixtures['user'];
    $user->updatePushSubscription(
        'https://fcm.googleapis.com/fcm/send/crew-alert-5',
        'BNcRnejnsCWcu6BCNCiCyiQoXKnAJkOjvgBgzEUrvsSMesTXHsYELfY35xZjFcRp27YWPBMBcIvP1uvxS9Xn1gE',
        'tBHItJI5svbpez7KI4CCXg',
        'aes128gcm',
    );

    enableCrewNotificationsForUser($companyId, (int) $user->id);
    createOverdueAssignmentForAlerts($fixtures);

    // Force inactive membership after settings saved.
    $user->companies()->updateExistingPivot($companyId, ['status' => 'inactive']);

    app(QueueCrewOperationalAlertPushes::class)->forAlerts(
        $companyId,
        CrewOperationalAlert::query()->where('company_id', $companyId)->pluck('id')->all(),
    );

    // First reconcile may have queued before we deactivate; clear and ensure inactive is skipped.
    CrewOperationalAlertPushDelivery::query()->where('company_id', $companyId)->delete();
    Queue::fake();

    $alert = CrewOperationalAlert::query()->create([
        'company_id' => $companyId,
        'type' => CrewOperationalAlertType::SignoffOverdue,
        'severity' => CrewOperationalAlertSeverity::Critical,
        'status' => CrewOperationalAlertStatus::Active,
        'dedupe_key' => 'signoff_overdue:assignment:inactive-test',
        'title' => 'Sign-off overdue',
        'message' => 'Test',
        'context' => ['assignment_id' => 1],
        'detected_at' => now(),
        'last_detected_at' => now(),
        'notification_version' => 1,
    ]);

    CrewOperationalAlertRecipient::query()->create([
        'company_id' => $companyId,
        'crew_operational_alert_id' => $alert->id,
        'user_id' => $user->id,
    ]);

    // Settings still list user, but activeCompanyUsers excludes inactive membership.
    app(QueueCrewOperationalAlertPushes::class)->forAlerts($companyId, [(int) $alert->id]);

    Queue::assertNothingPushed();

    CarbonImmutable::setTestNow();
});

test('legacy announcement inbox feed route still returns unified payload', function () {
    $fixtures = makeCrewAssignmentFixtures();

    $this->actingAs($fixtures['user'])
        ->getJson(route('organization.announcements.inbox.feed'))
        ->assertOk()
        ->assertJsonStructure(['unread_count', 'items']);
});

test('successful web push is not resent when ledger persist fails and the job retries', function () {
    Notification::fake();
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-07 12:00:00', 'Asia/Dubai'));
    $fixtures = makeCrewAssignmentFixtures();
    $companyId = (int) $fixtures['company']->id;
    $user = $fixtures['user'];
    $user->updatePushSubscription(
        'https://fcm.googleapis.com/fcm/send/crew-alert-persist',
        'BNcRnejnsCWcu6BCNCiCyiQoXKnAJkOjvgBgzEUrvsSMesTXHsYELfY35xZjFcRp27YWPBMBcIvP1uvxS9Xn1gE',
        'tBHItJI5svbpez7KI4CCXg',
        'aes128gcm',
    );

    enableCrewNotificationsForUser($companyId, (int) $user->id);
    createOverdueAssignmentForAlerts($fixtures);
    app(ReconcileCrewOperationalAlerts::class)->forCompany($companyId);

    $delivery = CrewOperationalAlertPushDelivery::query()->where('company_id', $companyId)->firstOrFail();
    $job = app(DeliverCrewOperationalAlertWebPushJob::class, ['deliveryId' => (int) $delivery->id]);
    $job->handle();

    Notification::assertSentToTimes($user, CrewOperationalAlertWebPushNotification::class, 1);
    expect($delivery->fresh()->status)->toBe(CrewOperationalAlertPushDeliveryStatus::Sent)
        ->and(CrewOperationalAlertDeliveryHandoff::wasHandedOff(
            CrewOperationalAlertDeliveryHandoff::webPushKey((int) $delivery->id),
        ))->toBeTrue();

    CrewOperationalAlertPushDelivery::query()->whereKey($delivery->id)->update([
        'status' => CrewOperationalAlertPushDeliveryStatus::Queued->value,
        'sent_at' => null,
        'failed_at' => null,
        'failure_category' => null,
    ]);

    expect($delivery->fresh()->status)->toBe(CrewOperationalAlertPushDeliveryStatus::Queued);

    $job->handle();

    Notification::assertSentToTimes($user, CrewOperationalAlertWebPushNotification::class, 1);
    expect($delivery->fresh()->status)->toBe(CrewOperationalAlertPushDeliveryStatus::Sent);

    CarbonImmutable::setTestNow();
});
