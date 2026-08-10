<?php

use App\Enums\CrewOperationalAlertEmailDeliveryStatus;
use App\Enums\CrewOperationalAlertSeverity;
use App\Enums\CrewOperationalAlertStatus;
use App\Enums\CrewOperationalAlertType;
use App\Jobs\DeliverCrewOperationalAlertEmailJob;
use App\Jobs\DeliverCrewOperationalAlertWebPushJob;
use App\Mail\CrewOperationalAlertEmailMail;
use App\Models\CrewOperationalAlert;
use App\Models\CrewOperationalAlertEmailDelivery;
use App\Models\CrewOperationalAlertPushDelivery;
use App\Models\CrewOperationalAlertRecipient;
use App\Models\User;
use App\Services\Settings\MailSettingsService;
use App\Services\Settings\SettingService;
use App\Support\CrewOperations\CrewOperationsSettings;
use App\Support\CrewOperations\QueueCrewOperationalAlertEmails;
use App\Support\CrewOperations\ReconcileCrewOperationalAlerts;
use App\Support\CrewOperations\ResolveCrewOperationalAlertUrl;
use App\Support\Settings\SettingKey;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

function configureAppSmtpForCrewEmailTests(): void
{
    app(SettingService::class)->set(SettingKey::MailHost, 'smtp.crew-email.test');
}

function clearAppSmtpForCrewEmailTests(): void
{
    app(SettingService::class)->set(SettingKey::MailHost, null);
}

function assertCrewEmailMigrationIdentifiersAreShort(): void
{
    $path = database_path('migrations/2026_08_07_173206_create_crew_operational_alert_email_deliveries_table.php');
    $contents = file_get_contents($path);
    preg_match_all("/indexName:\\s*'([^']+)'/", $contents, $fk);
    preg_match_all("/'(crew_alert_email_[^']+)'/", $contents, $named);

    foreach (array_unique(array_merge($fk[1] ?? [], $named[1] ?? [])) as $name) {
        expect(strlen($name))->toBeLessThanOrEqual(64);
    }
}

test('crew alert email migration identifiers are mysql-safe', function () {
    assertCrewEmailMigrationIdentifiersAreShort();
});

test('new alert creates one email delivery for each eligible selected recipient', function () {
    Queue::fake();
    Mail::fake();
    configureAppSmtpForCrewEmailTests();
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-07 12:00:00', 'Asia/Dubai'));

    $fixtures = makeCrewAssignmentFixtures();
    $companyId = (int) $fixtures['company']->id;
    $selected = $fixtures['user'];
    $second = User::factory()->create(['email' => 'second-crew@example.test']);
    $second->companies()->attach($companyId, ['status' => 'active']);

    CrewOperationsSettings::saveSettings(
        $companyId,
        [],
        30,
        true,
        [
            'notifications_enabled' => true,
            'notification_recipient_user_ids' => [(int) $selected->id, (int) $second->id],
            'alert_signoff_overdue' => true,
            'alert_signoff_no_relief' => true,
            'alert_relief_not_ready' => true,
            'alert_current_manning_gap' => true,
            'alert_projected_manning_gap' => true,
        ],
    );

    createOverdueAssignmentForAlerts($fixtures);
    app(ReconcileCrewOperationalAlerts::class)->forCompany($companyId);

    expect(CrewOperationalAlertEmailDelivery::query()->where('company_id', $companyId)->count())->toBe(2);
    Queue::assertPushed(DeliverCrewOperationalAlertEmailJob::class, 2);
    Queue::assertPushed(DeliverCrewOperationalAlertWebPushJob::class, 0);

    CarbonImmutable::setTestNow();
});

test('repeated unchanged reconciliation does not create duplicate email', function () {
    Queue::fake();
    configureAppSmtpForCrewEmailTests();
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-07 12:00:00', 'Asia/Dubai'));

    $fixtures = makeCrewAssignmentFixtures();
    $companyId = (int) $fixtures['company']->id;
    enableCrewNotificationsForUser($companyId, (int) $fixtures['user']->id);
    createOverdueAssignmentForAlerts($fixtures);

    app(ReconcileCrewOperationalAlerts::class)->forCompany($companyId);
    app(ReconcileCrewOperationalAlerts::class)->forCompany($companyId);

    expect(CrewOperationalAlertEmailDelivery::query()->where('company_id', $companyId)->count())->toBe(1);
    Queue::assertPushed(DeliverCrewOperationalAlertEmailJob::class, 1);

    CarbonImmutable::setTestNow();
});

test('unique alert user version dedupe prevents concurrent duplicate email rows', function () {
    $fixtures = makeCrewAssignmentFixtures();
    $companyId = (int) $fixtures['company']->id;
    $userId = (int) $fixtures['user']->id;

    $alert = CrewOperationalAlert::query()->create([
        'company_id' => $companyId,
        'type' => CrewOperationalAlertType::SignoffOverdue,
        'severity' => CrewOperationalAlertSeverity::Critical,
        'status' => CrewOperationalAlertStatus::Active,
        'dedupe_key' => 'signoff_overdue:assignment:email-dedupe',
        'title' => 'Sign-off overdue',
        'message' => 'Test',
        'context' => ['assignment_id' => 1],
        'detected_at' => now(),
        'last_detected_at' => now(),
        'notification_version' => 1,
    ]);

    CrewOperationalAlertEmailDelivery::query()->create([
        'company_id' => $companyId,
        'crew_operational_alert_id' => $alert->id,
        'user_id' => $userId,
        'notification_version' => 1,
        'status' => CrewOperationalAlertEmailDeliveryStatus::Queued,
        'queued_at' => now(),
    ]);

    expect(fn () => CrewOperationalAlertEmailDelivery::query()->create([
        'company_id' => $companyId,
        'crew_operational_alert_id' => $alert->id,
        'user_id' => $userId,
        'notification_version' => 1,
        'status' => CrewOperationalAlertEmailDeliveryStatus::Queued,
        'queued_at' => now(),
    ]))->toThrow(UniqueConstraintViolationException::class);
});

test('severity escalation and reactivation create new email for new notification_version', function () {
    Queue::fake();
    configureAppSmtpForCrewEmailTests();
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-07 12:00:00', 'Asia/Dubai'));

    $fixtures = makeCrewAssignmentFixtures();
    $companyId = (int) $fixtures['company']->id;
    enableCrewNotificationsForUser($companyId, (int) $fixtures['user']->id);
    $assignment = createOverdueAssignmentForAlerts($fixtures);

    app(ReconcileCrewOperationalAlerts::class)->forCompany($companyId);
    $alert = CrewOperationalAlert::query()->where('company_id', $companyId)->firstOrFail();
    expect($alert->notification_version)->toBe(1)
        ->and(CrewOperationalAlertEmailDelivery::query()->where('company_id', $companyId)->count())->toBe(1);

    $assignment->update(['planned_signoff_at' => '2026-09-01 00:00:00']);
    app(ReconcileCrewOperationalAlerts::class)->forCompany($companyId);
    expect($alert->fresh()->status)->toBe(CrewOperationalAlertStatus::Resolved);

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-08 12:00:00', 'Asia/Dubai'));
    $assignment->update(['planned_signoff_at' => '2026-08-01 00:00:00']);
    app(ReconcileCrewOperationalAlerts::class)->forCompany($companyId);

    expect($alert->fresh()->status)->toBe(CrewOperationalAlertStatus::Active)
        ->and($alert->fresh()->notification_version)->toBe(2)
        ->and(CrewOperationalAlertEmailDelivery::query()->where('company_id', $companyId)->count())->toBe(2);

    Queue::assertPushed(DeliverCrewOperationalAlertEmailJob::class, 2);

    CarbonImmutable::setTestNow();
});

test('resolved alert and disabled notifications produce no email', function () {
    Queue::fake();
    configureAppSmtpForCrewEmailTests();
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-07 12:00:00', 'Asia/Dubai'));

    $fixtures = makeCrewAssignmentFixtures();
    $companyId = (int) $fixtures['company']->id;

    enableCrewNotificationsForUser($companyId, (int) $fixtures['user']->id, [
        'notifications_enabled' => false,
    ]);
    createOverdueAssignmentForAlerts($fixtures);
    app(ReconcileCrewOperationalAlerts::class)->forCompany($companyId);
    expect(CrewOperationalAlertEmailDelivery::query()->where('company_id', $companyId)->count())->toBe(0);

    enableCrewNotificationsForUser($companyId, (int) $fixtures['user']->id, [
        'alert_signoff_overdue' => false,
        'alert_signoff_no_relief' => false,
        'alert_relief_not_ready' => false,
        'alert_current_manning_gap' => false,
        'alert_projected_manning_gap' => false,
    ]);
    app(ReconcileCrewOperationalAlerts::class)->forCompany($companyId);
    expect(CrewOperationalAlertEmailDelivery::query()->where('company_id', $companyId)->count())->toBe(0);

    CarbonImmutable::setTestNow();
});

test('unselected inactive membership cross-company and missing email are rejected', function () {
    Queue::fake();
    configureAppSmtpForCrewEmailTests();
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-07 12:00:00', 'Asia/Dubai'));

    $fixtures = makeCrewAssignmentFixtures();
    $companyId = (int) $fixtures['company']->id;
    $selected = $fixtures['user'];
    $unselected = User::factory()->create(['email' => 'unselected@example.test']);
    $unselected->companies()->attach($companyId, ['status' => 'active']);
    $noEmail = User::factory()->create(['email' => 'not-a-valid-email']);
    $noEmail->companies()->attach($companyId, ['status' => 'active']);

    enableCrewNotificationsForUser($companyId, (int) $selected->id, [
        'notification_recipient_user_ids' => [(int) $selected->id, (int) $noEmail->id],
    ]);
    createOverdueAssignmentForAlerts($fixtures);
    app(ReconcileCrewOperationalAlerts::class)->forCompany($companyId);

    expect(CrewOperationalAlertEmailDelivery::query()->where('company_id', $companyId)->count())->toBe(1)
        ->and(CrewOperationalAlertEmailDelivery::query()->where('user_id', $selected->id)->count())->toBe(1)
        ->and(CrewOperationalAlertEmailDelivery::query()->where('user_id', $unselected->id)->count())->toBe(0)
        ->and(CrewOperationalAlertEmailDelivery::query()->where('user_id', $noEmail->id)->count())->toBe(0);

    $selected->companies()->updateExistingPivot($companyId, ['status' => 'inactive']);
    CrewOperationalAlertEmailDelivery::query()->where('company_id', $companyId)->delete();
    $alert = CrewOperationalAlert::query()->where('company_id', $companyId)->firstOrFail();
    CrewOperationalAlertRecipient::query()->updateOrCreate(
        [
            'company_id' => $companyId,
            'crew_operational_alert_id' => $alert->id,
            'user_id' => $selected->id,
        ],
        [],
    );
    app(QueueCrewOperationalAlertEmails::class)->forAlerts($companyId, [(int) $alert->id]);
    expect(CrewOperationalAlertEmailDelivery::query()->where('company_id', $companyId)->count())->toBe(0);

    $foreignCompany = makeCrewAssignmentFixtures();
    $foreignUser = $foreignCompany['user'];
    CrewOperationalAlertRecipient::query()->create([
        'company_id' => $companyId,
        'crew_operational_alert_id' => $alert->id,
        'user_id' => $foreignUser->id,
    ]);
    enableCrewNotificationsForUser($companyId, (int) $unselected->id);
    // Foreign user is not an active member of company A, so activeCompanyUsers excludes them.
    app(QueueCrewOperationalAlertEmails::class)->forAlerts($companyId, [(int) $alert->id]);
    expect(CrewOperationalAlertEmailDelivery::query()
        ->where('company_id', $companyId)
        ->where('user_id', $foreignUser->id)
        ->count())->toBe(0);

    CarbonImmutable::setTestNow();
});

test('email job marks sent and respects stale business state', function () {
    Queue::fake();
    Mail::fake();
    configureAppSmtpForCrewEmailTests();
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-07 12:00:00', 'Asia/Dubai'));

    $fixtures = makeCrewAssignmentFixtures();
    $companyId = (int) $fixtures['company']->id;
    $user = $fixtures['user'];
    enableCrewNotificationsForUser($companyId, (int) $user->id);
    createOverdueAssignmentForAlerts($fixtures);
    app(ReconcileCrewOperationalAlerts::class)->forCompany($companyId);

    $delivery = CrewOperationalAlertEmailDelivery::query()->where('company_id', $companyId)->firstOrFail();
    app(DeliverCrewOperationalAlertEmailJob::class, ['deliveryId' => (int) $delivery->id])->handle(
        app(MailSettingsService::class),
        app(ResolveCrewOperationalAlertUrl::class),
    );

    Mail::assertSent(CrewOperationalAlertEmailMail::class, function (CrewOperationalAlertEmailMail $mail) use ($user): bool {
        expect($mail->envelope()->subject)->toContain('Crew Operations')
            ->and($mail->envelope()->subject)->not->toContain('Notify Vessel')
            ->and($mail->hasTo($user->email))->toBeTrue();

        return true;
    });

    expect($delivery->fresh()->status)->toBe(CrewOperationalAlertEmailDeliveryStatus::Sent)
        ->and($delivery->fresh()->attempt_count)->toBe(1)
        ->and($delivery->fresh()->failure_category)->toBeNull();

    $stale = CrewOperationalAlertEmailDelivery::query()->create([
        'company_id' => $companyId,
        'crew_operational_alert_id' => $delivery->crew_operational_alert_id,
        'user_id' => $user->id,
        'notification_version' => 99,
        'status' => CrewOperationalAlertEmailDeliveryStatus::Queued,
        'queued_at' => now(),
        'attempt_count' => 0,
    ]);

    app(DeliverCrewOperationalAlertEmailJob::class, ['deliveryId' => (int) $stale->id])->handle(
        app(MailSettingsService::class),
        app(ResolveCrewOperationalAlertUrl::class),
    );

    expect($stale->fresh()->status)->toBe(CrewOperationalAlertEmailDeliveryStatus::Failed)
        ->and($stale->fresh()->failure_category)->toBe('alert_unavailable');

    CarbonImmutable::setTestNow();
});

test('email job fails safely when recipient removed notifications disabled or mail unavailable', function () {
    Queue::fake();
    Mail::fake();
    configureAppSmtpForCrewEmailTests();
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-07 12:00:00', 'Asia/Dubai'));

    $fixtures = makeCrewAssignmentFixtures();
    $companyId = (int) $fixtures['company']->id;
    $user = $fixtures['user'];
    enableCrewNotificationsForUser($companyId, (int) $user->id);
    createOverdueAssignmentForAlerts($fixtures);
    app(ReconcileCrewOperationalAlerts::class)->forCompany($companyId);

    $base = CrewOperationalAlertEmailDelivery::query()->where('company_id', $companyId)->firstOrFail();
    $alertId = (int) $base->crew_operational_alert_id;

    $makeQueued = function (int $version) use ($companyId, $alertId, $user): CrewOperationalAlertEmailDelivery {
        return CrewOperationalAlertEmailDelivery::query()->create([
            'company_id' => $companyId,
            'crew_operational_alert_id' => $alertId,
            'user_id' => $user->id,
            'notification_version' => $version,
            'status' => CrewOperationalAlertEmailDeliveryStatus::Queued,
            'queued_at' => now(),
            'attempt_count' => 0,
        ]);
    };

    $other = User::factory()->create(['email' => 'other-selected@example.test']);
    $other->companies()->attach($companyId, ['status' => 'active']);
    enableCrewNotificationsForUser($companyId, (int) $other->id);

    $notSelected = $makeQueued(10);
    CrewOperationalAlert::query()->whereKey($alertId)->update(['notification_version' => 10]);
    app(DeliverCrewOperationalAlertEmailJob::class, ['deliveryId' => (int) $notSelected->id])->handle(
        app(MailSettingsService::class),
        app(ResolveCrewOperationalAlertUrl::class),
    );
    expect($notSelected->fresh()->failure_category)->toBe('recipient_not_selected');

    CrewOperationsSettings::saveSettings($companyId, [], 30, true, [
        'notifications_enabled' => false,
        'notification_recipient_user_ids' => [(int) $user->id],
    ]);
    expect(CrewOperationsSettings::notificationsEnabled($companyId))->toBeFalse();

    $disabled = $makeQueued(11);
    CrewOperationalAlert::query()->whereKey($alertId)->update(['notification_version' => 11]);
    app(DeliverCrewOperationalAlertEmailJob::class, ['deliveryId' => (int) $disabled->id])->handle(
        app(MailSettingsService::class),
        app(ResolveCrewOperationalAlertUrl::class),
    );
    expect($disabled->fresh()->failure_category)->toBe('notifications_disabled');

    enableCrewNotificationsForUser($companyId, (int) $user->id);
    clearAppSmtpForCrewEmailTests();
    $noMail = $makeQueued(12);
    CrewOperationalAlert::query()->whereKey($alertId)->update(['notification_version' => 12]);
    app(DeliverCrewOperationalAlertEmailJob::class, ['deliveryId' => (int) $noMail->id])->handle(
        app(MailSettingsService::class),
        app(ResolveCrewOperationalAlertUrl::class),
    );
    expect($noMail->fresh()->failure_category)->toBe('mail_unavailable');
    Mail::assertNothingSent();

    CarbonImmutable::setTestNow();
});

test('transport exceptions retry and exhausted retries mark failed without leaking smtp secrets', function () {
    Queue::fake();
    configureAppSmtpForCrewEmailTests();
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-07 12:00:00', 'Asia/Dubai'));

    $fixtures = makeCrewAssignmentFixtures();
    $companyId = (int) $fixtures['company']->id;
    $user = $fixtures['user'];
    enableCrewNotificationsForUser($companyId, (int) $user->id);
    createOverdueAssignmentForAlerts($fixtures);
    app(ReconcileCrewOperationalAlerts::class)->forCompany($companyId);

    $delivery = CrewOperationalAlertEmailDelivery::query()->where('company_id', $companyId)->firstOrFail();

    Log::spy();
    Mail::shouldReceive('to')->once()->andThrow(new RuntimeException('SMTP auth failed secret-pass smtp.crew-email.test'));

    $job = app(DeliverCrewOperationalAlertEmailJob::class, ['deliveryId' => (int) $delivery->id]);

    expect(fn () => $job->handle(
        app(MailSettingsService::class),
        app(ResolveCrewOperationalAlertUrl::class),
    ))->toThrow(RuntimeException::class);

    expect($delivery->fresh()->status)->toBe(CrewOperationalAlertEmailDeliveryStatus::Queued)
        ->and($delivery->fresh()->attempt_count)->toBe(1);

    Log::shouldHaveReceived('warning')->withArgs(function (string $message, array $context): bool {
        $encoded = json_encode($context);

        return str_contains($message, 'email delivery failed')
            && ! str_contains((string) $encoded, 'secret-pass')
            && ($context['failure_category'] ?? null) === 'email_transport'
            && isset($context['exception_class']);
    });

    $job->failed(new RuntimeException('SMTP auth failed secret-pass'));
    expect($delivery->fresh()->status)->toBe(CrewOperationalAlertEmailDeliveryStatus::Failed)
        ->and($delivery->fresh()->failure_category)->toBe('email_transport_exhausted')
        ->and($delivery->fresh()->failure_category)->not->toContain('secret');

    CarbonImmutable::setTestNow();
});

test('permission-safe cta uses ResolveCrewOperationalAlertUrl and omits unauthorized destinations', function () {
    Queue::fake();
    configureAppSmtpForCrewEmailTests();
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-07 12:00:00', 'Asia/Dubai'));

    $fixtures = makeCrewAssignmentFixtures();
    $companyId = (int) $fixtures['company']->id;
    $user = $fixtures['user'];
    enableCrewNotificationsForUser($companyId, (int) $user->id);
    createOverdueAssignmentForAlerts($fixtures);
    app(ReconcileCrewOperationalAlerts::class)->forCompany($companyId);

    $delivery = CrewOperationalAlertEmailDelivery::query()->where('company_id', $companyId)->firstOrFail();
    $alert = $delivery->alert;

    expect(app(ResolveCrewOperationalAlertUrl::class)->forUser($user, $alert))->toBeNull();

    Mail::fake();
    app(DeliverCrewOperationalAlertEmailJob::class, ['deliveryId' => (int) $delivery->id])->handle(
        app(MailSettingsService::class),
        app(ResolveCrewOperationalAlertUrl::class),
    );

    Mail::assertSent(CrewOperationalAlertEmailMail::class, function (CrewOperationalAlertEmailMail $mail): bool {
        expect($mail->ctaUrl)->toBeNull();

        return true;
    });

    grantCompanyPermissions($user, $fixtures['company'], ['crew_operations.assignments.view']);
    $cta = app(ResolveCrewOperationalAlertUrl::class)->forUser($user->fresh(), $alert->fresh());
    expect($cta)->toBeString()->and($cta)->toContain('/organization/crew/');

    $alert->update(['notification_version' => 50]);
    $withCta = CrewOperationalAlertEmailDelivery::query()->create([
        'company_id' => $companyId,
        'crew_operational_alert_id' => $alert->id,
        'user_id' => $user->id,
        'notification_version' => 50,
        'status' => CrewOperationalAlertEmailDeliveryStatus::Queued,
        'queued_at' => now(),
        'attempt_count' => 0,
    ]);

    Mail::fake();
    app(DeliverCrewOperationalAlertEmailJob::class, ['deliveryId' => (int) $withCta->id])->handle(
        app(MailSettingsService::class),
        app(ResolveCrewOperationalAlertUrl::class),
    );

    Mail::assertSent(CrewOperationalAlertEmailMail::class, function (CrewOperationalAlertEmailMail $mail) use ($cta): bool {
        expect($mail->ctaUrl)->toBe($cta);

        return true;
    });

    CarbonImmutable::setTestNow();
});

test('web push and email queues remain independent for the same notify versions', function () {
    Queue::fake();
    configureAppSmtpForCrewEmailTests();
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-07 12:00:00', 'Asia/Dubai'));

    $fixtures = makeCrewAssignmentFixtures();
    $companyId = (int) $fixtures['company']->id;
    $user = $fixtures['user'];
    $user->updatePushSubscription(
        'https://fcm.googleapis.com/fcm/send/crew-email-independence',
        'BNcRnejnsCWcu6BCNCiCyiQoXKnAJkOjvgBgzEUrvsSMesTXHsYELfY35xZjFcRp27YWPBMBcIvP1uvxS9Xn1gE',
        'tBHItJI5svbpez7KI4CCXg',
        'aes128gcm',
    );

    enableCrewNotificationsForUser($companyId, (int) $user->id);
    createOverdueAssignmentForAlerts($fixtures);
    app(ReconcileCrewOperationalAlerts::class)->forCompany($companyId);

    expect(CrewOperationalAlertPushDelivery::query()->where('company_id', $companyId)->count())->toBe(1)
        ->and(CrewOperationalAlertEmailDelivery::query()->where('company_id', $companyId)->count())->toBe(1);

    Queue::assertPushed(DeliverCrewOperationalAlertWebPushJob::class, 1);
    Queue::assertPushed(DeliverCrewOperationalAlertEmailJob::class, 1);

    CarbonImmutable::setTestNow();
});
