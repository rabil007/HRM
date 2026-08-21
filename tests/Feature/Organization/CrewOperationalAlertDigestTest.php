<?php

use App\Enums\CrewOperationalAlertEmailDeliveryStatus;
use App\Enums\CrewOperationalAlertSeverity;
use App\Enums\CrewOperationalAlertStatus;
use App\Enums\CrewOperationalAlertType;
use App\Enums\EmailTemplateCategory;
use App\Jobs\DeliverCrewOperationalAlertEmailJob;
use App\Mail\CrewOperationalAlertEmailMail;
use App\Models\Company;
use App\Models\CrewOperationalAlert;
use App\Models\CrewOperationalAlertEmailDelivery;
use App\Models\CrewOperationalAlertRecipient;
use App\Models\EmailTemplate;
use App\Models\User;
use App\Services\Settings\MailSettingsService;
use App\Services\Settings\SettingService;
use App\Support\CrewOperations\CrewOperationalAlertDigestPresenter;
use App\Support\CrewOperations\CrewOperationsSettings;
use App\Support\CrewOperations\DispatchCrewOperationalAlertEmailDigests;
use App\Support\CrewOperations\QueueCrewOperationalAlertEmails;
use App\Support\CrewOperations\ReconcileCrewOperationalAlerts;
use App\Support\CrewOperations\ResolveCrewOperationalAlertUrl;
use App\Support\Email\EmailTemplatePreview;
use App\Support\Settings\SettingKey;
use Database\Seeders\EmailTemplatesSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

function configureSmtpForDigestTests(): void
{
    app(SettingService::class)->set(SettingKey::MailHost, 'smtp.crew-digest.test');
}

/**
 * Creates 5 distinct active alerts in the database for a company.
 *
 * @return list<CrewOperationalAlert>
 */
function createFiveAlertsForCompany(int $companyId): array
{
    $now = now();

    $a1 = CrewOperationalAlert::query()->create([
        'company_id' => $companyId,
        'type' => CrewOperationalAlertType::SignoffOverdue,
        'severity' => CrewOperationalAlertSeverity::Critical,
        'status' => CrewOperationalAlertStatus::Active,
        'dedupe_key' => 'signoff_overdue:assignment:101',
        'title' => 'Sign-off overdue',
        'message' => 'John Doe overdue.',
        'context' => ['employee_name' => 'John Doe'],
        'detected_at' => $now,
        'last_detected_at' => $now,
        'notification_version' => 1,
    ]);

    $a2 = CrewOperationalAlert::query()->create([
        'company_id' => $companyId,
        'type' => CrewOperationalAlertType::SignoffNoRelief,
        'severity' => CrewOperationalAlertSeverity::Warning,
        'status' => CrewOperationalAlertStatus::Active,
        'dedupe_key' => 'signoff_no_relief:assignment:102',
        'title' => 'Sign-off approaching — no relief',
        'message' => 'Jane Smith approaching sign-off.',
        'context' => ['employee_name' => 'Jane Smith'],
        'detected_at' => $now,
        'last_detected_at' => $now,
        'notification_version' => 1,
    ]);

    $a3 = CrewOperationalAlert::query()->create([
        'company_id' => $companyId,
        'type' => CrewOperationalAlertType::ReliefNotReady,
        'severity' => CrewOperationalAlertSeverity::Warning,
        'status' => CrewOperationalAlertStatus::Active,
        'dedupe_key' => 'relief_not_ready:assignment:103',
        'title' => 'Relief not ready',
        'message' => 'Relief has not confirmed.',
        'context' => ['employee_name' => 'Bob Builder'],
        'detected_at' => $now,
        'last_detected_at' => $now,
        'notification_version' => 1,
    ]);

    $a4 = CrewOperationalAlert::query()->create([
        'company_id' => $companyId,
        'type' => CrewOperationalAlertType::CurrentManningGap,
        'severity' => CrewOperationalAlertSeverity::Critical,
        'status' => CrewOperationalAlertStatus::Active,
        'dedupe_key' => 'current_manning_gap:vessel:1:rank:2',
        'title' => 'Current manning gap',
        'message' => 'Pacific Trader has a gap.',
        'context' => ['vessel_name' => 'Pacific Trader'],
        'detected_at' => $now,
        'last_detected_at' => $now,
        'notification_version' => 1,
    ]);

    $a5 = CrewOperationalAlert::query()->create([
        'company_id' => $companyId,
        'type' => CrewOperationalAlertType::ProjectedManningGap,
        'severity' => CrewOperationalAlertSeverity::Warning,
        'status' => CrewOperationalAlertStatus::Active,
        'dedupe_key' => 'projected_manning_gap:vessel:2:rank:3',
        'title' => 'Projected manning gap',
        'message' => 'Ocean Star has a projected gap.',
        'context' => ['vessel_name' => 'Ocean Star'],
        'detected_at' => $now,
        'last_detected_at' => $now,
        'notification_version' => 1,
    ]);

    return [$a1, $a2, $a3, $a4, $a5];
}

// ─────────────────────────────────────────────────────────────────────────────
// RECONCILIATION & QUEUEING (DIGEST BEHAVIOR)
// ─────────────────────────────────────────────────────────────────────────────

test('queued alerts for single recipient are grouped into ONE digest email job', function () {
    Queue::fake();
    configureSmtpForDigestTests();
    EmailTemplatesSeeder::seedCrewOperationalAlertDigestTemplate();

    $fixtures = makeCrewAssignmentFixtures();
    $companyId = (int) $fixtures['company']->id;
    $user = $fixtures['user'];

    enableCrewNotificationsForUser($companyId, (int) $user->id, [
        'notification_email_delivery_mode' => 'immediate',
    ]);

    $alerts = createFiveAlertsForCompany($companyId);
    $alertIds = collect($alerts)->pluck('id')->all();

    foreach ($alerts as $alert) {
        CrewOperationalAlertRecipient::query()->create([
            'company_id' => $companyId,
            'crew_operational_alert_id' => $alert->id,
            'user_id' => $user->id,
        ]);
    }

    $queuedIds = app(QueueCrewOperationalAlertEmails::class)->forAlerts($companyId, $alertIds);
    app(DispatchCrewOperationalAlertEmailDigests::class)->forCompany($companyId);

    expect($queuedIds)->toHaveCount(5);
    Queue::assertPushed(DeliverCrewOperationalAlertEmailJob::class, 1);
    Queue::assertPushed(DeliverCrewOperationalAlertEmailJob::class, function (DeliverCrewOperationalAlertEmailJob $job) use ($queuedIds): bool {
        return $job->deliveryIds === $queuedIds;
    });
});

test('two recipients receive one digest job each', function () {
    Queue::fake();
    configureSmtpForDigestTests();
    EmailTemplatesSeeder::seedCrewOperationalAlertDigestTemplate();

    $fixtures = makeCrewAssignmentFixtures();
    $companyId = (int) $fixtures['company']->id;
    $u1 = $fixtures['user'];
    $u2 = User::factory()->create(['email' => 'u2@example.test']);
    $u2->companies()->attach($companyId, ['status' => 'active']);

    CrewOperationsSettings::saveSettings($companyId, [], 30, true, [
        'notifications_enabled' => true,
        'notification_recipient_user_ids' => [(int) $u1->id, (int) $u2->id],
        'alert_signoff_overdue' => true,
        'alert_signoff_no_relief' => true,
        'alert_relief_not_ready' => true,
        'alert_current_manning_gap' => true,
        'alert_projected_manning_gap' => true,
        'notification_email_delivery_mode' => 'immediate',
    ]);

    $alerts = createFiveAlertsForCompany($companyId);
    $alertIds = collect($alerts)->pluck('id')->all();

    foreach ($alerts as $alert) {
        CrewOperationalAlertRecipient::query()->create([
            'company_id' => $companyId,
            'crew_operational_alert_id' => $alert->id,
            'user_id' => $u1->id,
        ]);
        CrewOperationalAlertRecipient::query()->create([
            'company_id' => $companyId,
            'crew_operational_alert_id' => $alert->id,
            'user_id' => $u2->id,
        ]);
    }

    app(QueueCrewOperationalAlertEmails::class)->forAlerts($companyId, $alertIds);
    app(DispatchCrewOperationalAlertEmailDigests::class)->forCompany($companyId);

    // 5 alerts * 2 users = 10 delivery rows, but only 2 jobs (1 per user)
    expect(CrewOperationalAlertEmailDelivery::query()->where('company_id', $companyId)->count())->toBe(10);
    Queue::assertPushed(DeliverCrewOperationalAlertEmailJob::class, 2);
});

test('same user in two companies gets separate company digests', function () {
    Queue::fake();
    configureSmtpForDigestTests();
    EmailTemplatesSeeder::seedCrewOperationalAlertDigestTemplate();

    $fixturesA = makeCrewAssignmentFixtures();
    $companyA = (int) $fixturesA['company']->id;
    $user = $fixturesA['user'];

    $fixturesB = makeCrewAssignmentFixtures();
    $companyB = (int) $fixturesB['company']->id;
    $user->companies()->attach($companyB, ['status' => 'active']);

    enableCrewNotificationsForUser($companyA, (int) $user->id, [
        'notification_email_delivery_mode' => 'immediate',
    ]);
    enableCrewNotificationsForUser($companyB, (int) $user->id, [
        'notification_email_delivery_mode' => 'immediate',
    ]);

    $alertsA = createFiveAlertsForCompany($companyA);
    $alertsB = createFiveAlertsForCompany($companyB);

    foreach ($alertsA as $alert) {
        CrewOperationalAlertRecipient::query()->create(['company_id' => $companyA, 'crew_operational_alert_id' => $alert->id, 'user_id' => $user->id]);
    }
    foreach ($alertsB as $alert) {
        CrewOperationalAlertRecipient::query()->create(['company_id' => $companyB, 'crew_operational_alert_id' => $alert->id, 'user_id' => $user->id]);
    }

    app(QueueCrewOperationalAlertEmails::class)->forAlerts($companyA, collect($alertsA)->pluck('id')->all());
    app(QueueCrewOperationalAlertEmails::class)->forAlerts($companyB, collect($alertsB)->pluck('id')->all());
    app(DispatchCrewOperationalAlertEmailDigests::class)->forCompany($companyA);
    app(DispatchCrewOperationalAlertEmailDigests::class)->forCompany($companyB);

    Queue::assertPushed(DeliverCrewOperationalAlertEmailJob::class, 2);
});

test('one meaningful alert results in one digest job containing one row', function () {
    Queue::fake();
    configureSmtpForDigestTests();
    EmailTemplatesSeeder::seedCrewOperationalAlertDigestTemplate();

    $fixtures = makeCrewAssignmentFixtures();
    $companyId = (int) $fixtures['company']->id;
    enableCrewNotificationsForUser($companyId, (int) $fixtures['user']->id);
    createOverdueAssignmentForAlerts($fixtures);

    app(ReconcileCrewOperationalAlerts::class)->forCompany($companyId);
    app(DispatchCrewOperationalAlertEmailDigests::class)->forCompany($companyId);

    expect(CrewOperationalAlertEmailDelivery::query()->where('company_id', $companyId)->count())->toBe(1);
    Queue::assertPushed(DeliverCrewOperationalAlertEmailJob::class, 1);
});

test('unchanged reconciliation sends no email', function () {
    Queue::fake();
    configureSmtpForDigestTests();
    EmailTemplatesSeeder::seedCrewOperationalAlertDigestTemplate();

    $fixtures = makeCrewAssignmentFixtures();
    $companyId = (int) $fixtures['company']->id;
    enableCrewNotificationsForUser($companyId, (int) $fixtures['user']->id);
    createOverdueAssignmentForAlerts($fixtures);

    app(ReconcileCrewOperationalAlerts::class)->forCompany($companyId);
    app(DispatchCrewOperationalAlertEmailDigests::class)->forCompany($companyId);
    app(ReconcileCrewOperationalAlerts::class)->forCompany($companyId);
    app(DispatchCrewOperationalAlertEmailDigests::class)->forCompany($companyId);

    expect(CrewOperationalAlertEmailDelivery::query()->where('company_id', $companyId)->count())->toBe(1);
    Queue::assertPushed(DeliverCrewOperationalAlertEmailJob::class, 1);
});

test('severity escalation and reactivated resolved alert send new digest jobs', function () {
    Queue::fake();
    configureSmtpForDigestTests();
    EmailTemplatesSeeder::seedCrewOperationalAlertDigestTemplate();

    $fixtures = makeCrewAssignmentFixtures();
    $companyId = (int) $fixtures['company']->id;
    enableCrewNotificationsForUser($companyId, (int) $fixtures['user']->id);
    $assignment = createOverdueAssignmentForAlerts($fixtures);

    app(ReconcileCrewOperationalAlerts::class)->forCompany($companyId);
    app(DispatchCrewOperationalAlertEmailDigests::class)->forCompany($companyId);
    expect(CrewOperationalAlertEmailDelivery::query()->where('company_id', $companyId)->count())->toBe(1);

    // Resolve alert by clearing overdue
    $assignment->update(['planned_signoff_at' => now()->addDays(30)->toDateString()]);
    app(ReconcileCrewOperationalAlerts::class)->forCompany($companyId);
    app(DispatchCrewOperationalAlertEmailDigests::class)->forCompany($companyId);
    expect(CrewOperationalAlertEmailDelivery::query()->where('company_id', $companyId)->count())->toBe(1);

    // Reactivate alert
    $assignment->update(['planned_signoff_at' => now()->subDays(5)->toDateString()]);
    app(ReconcileCrewOperationalAlerts::class)->forCompany($companyId);
    app(DispatchCrewOperationalAlertEmailDigests::class)->forCompany($companyId);

    expect(CrewOperationalAlertEmailDelivery::query()->where('company_id', $companyId)->count())->toBe(2);
    Queue::assertPushed(DeliverCrewOperationalAlertEmailJob::class, 2);
});

// ─────────────────────────────────────────────────────────────────────────────
// CONTENT & AUTHORIZATION & PRIVACY TESTS
// ─────────────────────────────────────────────────────────────────────────────

test('digest email content presents alert count company name highest severity and HTML table', function () {
    Queue::fake();
    Mail::fake();
    configureSmtpForDigestTests();
    EmailTemplatesSeeder::seedCrewOperationalAlertDigestTemplate();

    $fixtures = makeCrewAssignmentFixtures();
    $companyId = (int) $fixtures['company']->id;
    $user = $fixtures['user'];

    enableCrewNotificationsForUser($companyId, (int) $user->id);
    grantCompanyPermissions($user, $fixtures['company'], [
        'crew_operations.assignments.view',
        'crew_operations.overview.view',
    ]);

    $alerts = createFiveAlertsForCompany($companyId);
    foreach ($alerts as $alert) {
        CrewOperationalAlertRecipient::query()->create([
            'company_id' => $companyId,
            'crew_operational_alert_id' => $alert->id,
            'user_id' => $user->id,
        ]);
    }

    $queuedIds = app(QueueCrewOperationalAlertEmails::class)->forAlerts($companyId, collect($alerts)->pluck('id')->all());

    $job = new DeliverCrewOperationalAlertEmailJob($queuedIds, $companyId, (int) $user->id);
    $job->handle(app(MailSettingsService::class), app(ResolveCrewOperationalAlertUrl::class));

    Mail::assertSent(CrewOperationalAlertEmailMail::class, function (CrewOperationalAlertEmailMail $mail) use ($user): bool {
        expect($mail->hasTo($user->email))->toBeTrue()
            ->and($mail->subjectLine)->toContain('5 items require attention')
            ->and($mail->bodyHtml)->toContain('5 items require attention')
            ->and($mail->bodyHtml)->toContain('<table')
            ->and($mail->bodyHtml)->toContain('CRITICAL');

        return true;
    });

    // All 5 delivery rows marked Sent
    expect(CrewOperationalAlertEmailDelivery::query()->whereIn('id', $queuedIds)->where('status', CrewOperationalAlertEmailDeliveryStatus::Sent)->count())->toBe(5);
});

test('recipient with assignment permission sees permitted crew details in digest table', function () {
    EmailTemplatesSeeder::seedCrewOperationalAlertDigestTemplate();
    $fixtures = makeCrewAssignmentFixtures();
    $companyId = (int) $fixtures['company']->id;
    $user = $fixtures['user'];

    grantCompanyPermissions($user, $fixtures['company'], ['crew_operations.assignments.view']);

    $alert = CrewOperationalAlert::query()->create([
        'company_id' => $companyId,
        'type' => CrewOperationalAlertType::SignoffOverdue,
        'severity' => CrewOperationalAlertSeverity::Critical,
        'status' => CrewOperationalAlertStatus::Active,
        'dedupe_key' => 'signoff_overdue:assignment:'.$fixtures['employee']->id,
        'title' => 'Sign-off overdue',
        'message' => 'Past planned sign-off',
        'context' => [
            'assignment_id' => 999,
            'assignment_no' => 'CA-999',
            'employee_id' => $fixtures['employee']->id,
        ],
        'detected_at' => now(),
        'last_detected_at' => now(),
        'notification_version' => 1,
    ]);

    $delivery = CrewOperationalAlertEmailDelivery::query()->create([
        'company_id' => $companyId,
        'crew_operational_alert_id' => $alert->id,
        'user_id' => $user->id,
        'notification_version' => 1,
        'status' => CrewOperationalAlertEmailDeliveryStatus::Queued,
        'queued_at' => now(),
    ]);

    $digest = app(CrewOperationalAlertDigestPresenter::class)->forUser($user, $fixtures['company'], collect([$delivery]));

    expect($digest['alert_count'])->toBe(1)
        ->and($digest['highest_severity'])->toBe('critical')
        ->and($digest['alerts_table'])->toContain('Sign-off overdue');
});

test('recipient without assignment permission sees privacy safe fallback generic row', function () {
    EmailTemplatesSeeder::seedCrewOperationalAlertDigestTemplate();
    $fixtures = makeCrewAssignmentFixtures();
    $companyId = (int) $fixtures['company']->id;
    $user = $fixtures['user'];

    // User has NO permissions
    $alert = CrewOperationalAlert::query()->create([
        'company_id' => $companyId,
        'type' => CrewOperationalAlertType::SignoffOverdue,
        'severity' => CrewOperationalAlertSeverity::Critical,
        'status' => CrewOperationalAlertStatus::Active,
        'dedupe_key' => 'signoff_overdue:assignment:secret-employee',
        'title' => 'Sign-off overdue',
        'message' => 'Secret Employee past signoff',
        'context' => [
            'assignment_id' => 999,
            'assignment_no' => 'CA-SECRET',
        ],
        'detected_at' => now(),
        'last_detected_at' => now(),
        'notification_version' => 1,
    ]);

    $delivery = CrewOperationalAlertEmailDelivery::query()->create([
        'company_id' => $companyId,
        'crew_operational_alert_id' => $alert->id,
        'user_id' => $user->id,
        'notification_version' => 1,
        'status' => CrewOperationalAlertEmailDeliveryStatus::Queued,
        'queued_at' => now(),
    ]);

    $digest = app(CrewOperationalAlertDigestPresenter::class)->forUser($user, $fixtures['company'], collect([$delivery]));

    expect($digest['alerts_table'])->toContain('A Crew Operations item requires review')
        ->and($digest['alerts_table'])->not->toContain('Secret Employee')
        ->and($digest['alerts_table'])->not->toContain('CA-SECRET');
});

// ─────────────────────────────────────────────────────────────────────────────
// EMAIL TEMPLATE SYSTEM & PREVIEW TESTS
// ─────────────────────────────────────────────────────────────────────────────

test('crew_operational_alert_digest template is seeded under Notifications category and previewable', function () {
    EmailTemplate::query()->where('slug', 'crew_operational_alert_digest')->forceDelete();

    $template = EmailTemplatesSeeder::seedCrewOperationalAlertDigestTemplate();

    expect($template->slug)->toBe('crew_operational_alert_digest')
        ->and($template->category)->toBe(EmailTemplateCategory::Notification)
        ->and($template->subject)->toContain('Crew Operations')
        ->and($template->enabled)->toBeTrue();

    // Seeder non-overwriting check: modify subject, re-run seeder, subject must remain modified
    $template->update(['subject' => 'Customized Subject Line']);
    EmailTemplatesSeeder::seedCrewOperationalAlertDigestTemplate();

    expect(EmailTemplate::query()->where('slug', 'crew_operational_alert_digest')->value('subject'))
        ->toBe('Customized Subject Line');

    // Preview test
    $preview = app(EmailTemplatePreview::class)->render($template, $template->id);

    expect($preview['subject'])->toBe('Customized Subject Line')
        ->and($preview['html'])->toContain('<table')
        ->and($preview['html'])->toContain('CRITICAL');
});

test('disabling crew_operational_alert_digest template prevents email without breaking web push or DB alerts', function () {
    Queue::fake();
    configureSmtpForDigestTests();
    $template = EmailTemplatesSeeder::seedCrewOperationalAlertDigestTemplate();
    $template->update(['enabled' => false]);

    $fixtures = makeCrewAssignmentFixtures();
    $companyId = (int) $fixtures['company']->id;
    $user = $fixtures['user'];

    enableCrewNotificationsForUser($companyId, (int) $user->id);
    createOverdueAssignmentForAlerts($fixtures);

    app(ReconcileCrewOperationalAlerts::class)->forCompany($companyId);

    // Delivery row created
    $delivery = CrewOperationalAlertEmailDelivery::query()->where('company_id', $companyId)->firstOrFail();

    // Execute job
    $job = new DeliverCrewOperationalAlertEmailJob((int) $delivery->id, $companyId, (int) $user->id);
    $job->handle(app(MailSettingsService::class), app(ResolveCrewOperationalAlertUrl::class));

    // Job marks delivery failed due to template disabled, but alert remains active in DB!
    expect($delivery->fresh()->status)->toBe(CrewOperationalAlertEmailDeliveryStatus::Failed)
        ->and($delivery->fresh()->failure_category)->toBe('notifications_disabled')
        ->and(CrewOperationalAlert::query()->where('company_id', $companyId)->count())->toBe(1);
});
