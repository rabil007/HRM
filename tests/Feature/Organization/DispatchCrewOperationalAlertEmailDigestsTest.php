<?php

use App\Enums\CrewOperationalAlertEmailDeliveryStatus;
use App\Enums\CrewOperationalAlertSeverity;
use App\Enums\CrewOperationalAlertStatus;
use App\Enums\CrewOperationalAlertType;
use App\Jobs\DeliverCrewOperationalAlertEmailJob;
use App\Models\CrewOperationalAlert;
use App\Models\CrewOperationalAlertEmailDelivery;
use App\Models\User;
use App\Support\CrewOperations\ClaimCrewOperationalAlertEmailDeliveries;
use App\Support\CrewOperations\CrewOperationsSettings;
use App\Support\CrewOperations\DispatchCrewOperationalAlertEmailDigests;
use Carbon\CarbonImmutable;
use Database\Seeders\EmailTemplatesSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Testing\Fakes\QueueFake;

final class FlakyQueueFake extends QueueFake
{
    public bool $shouldThrow = false;

    public ?int $failUserId = null;

    public function push($job, $data = '', $queue = null): mixed
    {
        if ($this->shouldThrow) {
            throw new RuntimeException('Queue broker connection failed');
        }

        if ($this->failUserId !== null && $job instanceof DeliverCrewOperationalAlertEmailJob && $job->userId === $this->failUserId) {
            throw new RuntimeException('Queue broker failed for user '.$this->failUserId);
        }

        return parent::push($job, $data, $queue);
    }
}

function failNextCrewEmailDispatchLedgerPersists(int $times): void
{
    $remaining = $times;
    $pdo = DB::connection()->getPdo();

    expect($pdo->getAttribute(PDO::ATTR_DRIVER_NAME))->toBe('sqlite');

    $pdo->sqliteCreateFunction(
        'crew_alert_fail_dispatch_persist',
        function () use (&$remaining): int {
            if ($remaining < 1) {
                return 0;
            }

            $remaining--;

            return 1;
        },
    );

    DB::unprepared('DROP TRIGGER IF EXISTS fail_crew_email_dispatch_persist');
    DB::unprepared(<<<'SQL'
        CREATE TRIGGER fail_crew_email_dispatch_persist
        BEFORE UPDATE ON crew_operational_alert_email_deliveries
        WHEN NEW.dispatched_at IS NOT NULL
         AND OLD.dispatched_at IS NULL
         AND crew_alert_fail_dispatch_persist() = 1
        BEGIN
            SELECT RAISE(ABORT, 'ledger persist failed');
        END
    SQL);
}

function createFiveAlertsForDigestDispatch(int $companyId): array
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
// 1. 07:30 WARNING -> DISPATCHED AT 08:00 LOCAL
// ─────────────────────────────────────────────────────────────────────────────

test('07:30 warning is not dispatched at 07:59 and is dispatched at 08:00 company local time', function () {
    Queue::fake();
    EmailTemplatesSeeder::seedCrewOperationalAlertDigestTemplate();

    $fixtures = makeCrewAssignmentFixtures();
    $company = $fixtures['company'];
    $company->update(['timezone' => 'Asia/Dubai']);
    $companyId = (int) $company->id;
    $user = $fixtures['user'];

    CrewOperationsSettings::saveSettings($companyId, [], 30, true, [
        'notifications_enabled' => true,
        'notification_recipient_user_ids' => [(int) $user->id],
        'alert_signoff_overdue' => true,
        'alert_signoff_no_relief' => true,
        'alert_relief_not_ready' => true,
        'alert_current_manning_gap' => true,
        'alert_projected_manning_gap' => true,
        'notification_email_delivery_mode' => 'scheduled',
        'notification_email_digest_at' => '08:00',
        'notification_email_critical_immediate' => true,
    ]);

    $alert = CrewOperationalAlert::query()->create([
        'company_id' => $companyId,
        'type' => CrewOperationalAlertType::SignoffNoRelief,
        'severity' => CrewOperationalAlertSeverity::Warning,
        'status' => CrewOperationalAlertStatus::Active,
        'dedupe_key' => 'signoff_no_relief:test:730am',
        'title' => 'Sign-off approaching — no relief',
        'message' => 'Approaching in 14 days',
        'context' => [],
        'detected_at' => CarbonImmutable::parse('2026-08-21 07:30:00', 'Asia/Dubai'),
        'last_detected_at' => CarbonImmutable::parse('2026-08-21 07:30:00', 'Asia/Dubai'),
        'notification_version' => 1,
    ]);

    $delivery = CrewOperationalAlertEmailDelivery::query()->create([
        'company_id' => $companyId,
        'crew_operational_alert_id' => $alert->id,
        'user_id' => $user->id,
        'notification_version' => 1,
        'status' => CrewOperationalAlertEmailDeliveryStatus::Queued,
        'queued_at' => CarbonImmutable::parse('2026-08-21 07:30:00', 'Asia/Dubai'),
        'attempt_count' => 0,
    ]);

    // 07:59 in Asia/Dubai -> NOT eligible yet
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-21 07:59:00', 'Asia/Dubai'));
    $resultBefore = app(DispatchCrewOperationalAlertEmailDigests::class)->forCompany($companyId);
    expect($resultBefore['dispatched'])->toBeFalse()
        ->and($resultBefore['reason'])->toBe('no_eligible_deliveries');
    Queue::assertNothingPushed();

    // 08:00 in Asia/Dubai -> ELIGIBLE and dispatched
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-21 08:00:00', 'Asia/Dubai'));
    $resultAtTime = app(DispatchCrewOperationalAlertEmailDigests::class)->forCompany($companyId);
    expect($resultAtTime['dispatched'])->toBeTrue()
        ->and($resultAtTime['jobs_count'])->toBe(1)
        ->and($resultAtTime['delivery_count'])->toBe(1);

    Queue::assertPushed(DeliverCrewOperationalAlertEmailJob::class, 1);
    Queue::assertPushed(DeliverCrewOperationalAlertEmailJob::class, function (DeliverCrewOperationalAlertEmailJob $job) use ($delivery, $companyId, $user): bool {
        return $job->deliveryIds === [(int) $delivery->id]
            && $job->companyId === $companyId
            && $job->userId === (int) $user->id;
    });

    $delivery->refresh();
    expect($delivery->dispatched_at)->not->toBeNull()
        ->and($delivery->dispatch_claimed_at)->toBeNull();

    CarbonImmutable::setTestNow();
});

// ─────────────────────────────────────────────────────────────────────────────
// 2. 09:30 WARNING -> WAITS UNTIL NEXT DAY 08:00
// ─────────────────────────────────────────────────────────────────────────────

test('warning detected after 08:00 digest is NOT sent at 09:31 and waits until next day 08:00', function () {
    Queue::fake();
    EmailTemplatesSeeder::seedCrewOperationalAlertDigestTemplate();

    $fixtures = makeCrewAssignmentFixtures();
    $company = $fixtures['company'];
    $company->update(['timezone' => 'Asia/Dubai']);
    $companyId = (int) $company->id;
    $user = $fixtures['user'];

    CrewOperationsSettings::saveSettings($companyId, [], 30, true, [
        'notifications_enabled' => true,
        'notification_recipient_user_ids' => [(int) $user->id],
        'alert_signoff_overdue' => true,
        'alert_signoff_no_relief' => true,
        'alert_relief_not_ready' => true,
        'alert_current_manning_gap' => true,
        'alert_projected_manning_gap' => true,
        'notification_email_delivery_mode' => 'scheduled',
        'notification_email_digest_at' => '08:00',
        'notification_email_critical_immediate' => true,
    ]);

    // 1. Alert 1 is detected at 07:30
    $alert1 = CrewOperationalAlert::query()->create([
        'company_id' => $companyId,
        'type' => CrewOperationalAlertType::SignoffNoRelief,
        'severity' => CrewOperationalAlertSeverity::Warning,
        'status' => CrewOperationalAlertStatus::Active,
        'dedupe_key' => 'signoff_no_relief:test:after_digest_1',
        'title' => 'Sign-off approaching #1',
        'message' => 'Test 1',
        'context' => [],
        'detected_at' => CarbonImmutable::parse('2026-08-21 07:30:00', 'Asia/Dubai'),
        'last_detected_at' => CarbonImmutable::parse('2026-08-21 07:30:00', 'Asia/Dubai'),
        'notification_version' => 1,
    ]);

    $del1 = CrewOperationalAlertEmailDelivery::query()->create([
        'company_id' => $companyId,
        'crew_operational_alert_id' => $alert1->id,
        'user_id' => $user->id,
        'notification_version' => 1,
        'status' => CrewOperationalAlertEmailDeliveryStatus::Queued,
        'queued_at' => CarbonImmutable::parse('2026-08-21 07:30:00', 'Asia/Dubai'),
        'attempt_count' => 0,
    ]);

    // 2. Dispatch runs at 08:00 Asia/Dubai -> Alert 1 is dispatched
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-21 08:00:00', 'Asia/Dubai'));
    $res8am = app(DispatchCrewOperationalAlertEmailDigests::class)->forCompany($companyId);
    expect($res8am['dispatched'])->toBeTrue()
        ->and($res8am['delivery_count'])->toBe(1);
    Queue::assertPushed(DeliverCrewOperationalAlertEmailJob::class, 1);

    // 3. New Warning Alert 2 is detected at 09:30 Asia/Dubai
    $alert2 = CrewOperationalAlert::query()->create([
        'company_id' => $companyId,
        'type' => CrewOperationalAlertType::SignoffNoRelief,
        'severity' => CrewOperationalAlertSeverity::Warning,
        'status' => CrewOperationalAlertStatus::Active,
        'dedupe_key' => 'signoff_no_relief:test:after_digest_2',
        'title' => 'Sign-off approaching #2',
        'message' => 'Test 2',
        'context' => [],
        'detected_at' => CarbonImmutable::parse('2026-08-21 09:30:00', 'Asia/Dubai'),
        'last_detected_at' => CarbonImmutable::parse('2026-08-21 09:30:00', 'Asia/Dubai'),
        'notification_version' => 1,
    ]);

    $del2 = CrewOperationalAlertEmailDelivery::query()->create([
        'company_id' => $companyId,
        'crew_operational_alert_id' => $alert2->id,
        'user_id' => $user->id,
        'notification_version' => 1,
        'status' => CrewOperationalAlertEmailDeliveryStatus::Queued,
        'queued_at' => CarbonImmutable::parse('2026-08-21 09:30:00', 'Asia/Dubai'),
        'attempt_count' => 0,
    ]);

    // 4. Scheduler runs at 09:31 Asia/Dubai -> must NOT dispatch Alert 2 (queued >= 08:00 cutoff -> target is tomorrow 08:00)
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-21 09:31:00', 'Asia/Dubai'));
    $res931am = app(DispatchCrewOperationalAlertEmailDigests::class)->forCompany($companyId);
    expect($res931am['dispatched'])->toBeFalse()
        ->and($res931am['reason'])->toBe('no_eligible_deliveries');

    // Total jobs pushed remains 1 (from 08:00)
    Queue::assertPushed(DeliverCrewOperationalAlertEmailJob::class, 1);

    // Alert 2 delivery row is still queued with dispatched_at null
    $del2->refresh();
    expect($del2->status)->toBe(CrewOperationalAlertEmailDeliveryStatus::Queued)
        ->and($del2->dispatched_at)->toBeNull()
        ->and($del2->sent_at)->toBeNull();

    // 5. At 23:59 same day -> still NOT eligible
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-21 23:59:00', 'Asia/Dubai'));
    $res2359 = app(DispatchCrewOperationalAlertEmailDigests::class)->forCompany($companyId);
    expect($res2359['dispatched'])->toBeFalse()
        ->and($res2359['reason'])->toBe('no_eligible_deliveries');

    // 6. Next morning at 07:59 Asia/Dubai -> still NOT eligible
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-22 07:59:00', 'Asia/Dubai'));
    $res759 = app(DispatchCrewOperationalAlertEmailDigests::class)->forCompany($companyId);
    expect($res759['dispatched'])->toBeFalse()
        ->and($res759['reason'])->toBe('no_eligible_deliveries');

    // 7. Next morning at 08:00 Asia/Dubai (2026-08-22 08:00:00) -> Alert 2 is dispatched!
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-22 08:00:00', 'Asia/Dubai'));
    $resNextDay = app(DispatchCrewOperationalAlertEmailDigests::class)->forCompany($companyId);
    expect($resNextDay['dispatched'])->toBeTrue()
        ->and($resNextDay['delivery_count'])->toBe(1);

    Queue::assertPushed(DeliverCrewOperationalAlertEmailJob::class, 2);

    $del2->refresh();
    expect($del2->dispatched_at)->not->toBeNull()
        ->and($del2->dispatch_claimed_at)->toBeNull();

    CarbonImmutable::setTestNow();
});

// ─────────────────────────────────────────────────────────────────────────────
// 3. SCHEDULED ENQUEUE THROWS AT 08:00 -> RETRY SUCCEEDS AT 08:01 SAME DAY
// ─────────────────────────────────────────────────────────────────────────────

test('scheduled enqueue throws at 08:00 and retry succeeds at 08:01 same day without losing delivery', function () {
    $fake = new FlakyQueueFake(app());
    Queue::swap($fake);
    EmailTemplatesSeeder::seedCrewOperationalAlertDigestTemplate();

    $fixtures = makeCrewAssignmentFixtures();
    $company = $fixtures['company'];
    $company->update(['timezone' => 'Asia/Dubai']);
    $companyId = (int) $company->id;
    $user = $fixtures['user'];

    CrewOperationsSettings::saveSettings($companyId, [], 30, true, [
        'notifications_enabled' => true,
        'notification_recipient_user_ids' => [(int) $user->id],
        'alert_signoff_overdue' => true,
        'alert_signoff_no_relief' => true,
        'alert_relief_not_ready' => true,
        'alert_current_manning_gap' => true,
        'alert_projected_manning_gap' => true,
        'notification_email_delivery_mode' => 'scheduled',
        'notification_email_digest_at' => '08:00',
        'notification_email_critical_immediate' => true,
    ]);

    $alert = CrewOperationalAlert::query()->create([
        'company_id' => $companyId,
        'type' => CrewOperationalAlertType::SignoffNoRelief,
        'severity' => CrewOperationalAlertSeverity::Warning,
        'status' => CrewOperationalAlertStatus::Active,
        'dedupe_key' => 'signoff_no_relief:test:retry:1',
        'title' => 'Sign-off approaching',
        'message' => 'Approaching',
        'context' => [],
        'detected_at' => CarbonImmutable::parse('2026-08-21 07:30:00', 'Asia/Dubai'),
        'last_detected_at' => CarbonImmutable::parse('2026-08-21 07:30:00', 'Asia/Dubai'),
        'notification_version' => 1,
    ]);

    $delivery = CrewOperationalAlertEmailDelivery::query()->create([
        'company_id' => $companyId,
        'crew_operational_alert_id' => $alert->id,
        'user_id' => $user->id,
        'notification_version' => 1,
        'status' => CrewOperationalAlertEmailDeliveryStatus::Queued,
        'queued_at' => CarbonImmutable::parse('2026-08-21 07:30:00', 'Asia/Dubai'),
        'attempt_count' => 0,
    ]);

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-21 08:00:00', 'Asia/Dubai'));

    // Step 1: Simulate Queue enqueue failure during DeliverCrewOperationalAlertEmailJob::dispatch
    $fake->shouldThrow = true;

    $resultFailed = app(DispatchCrewOperationalAlertEmailDigests::class)->forCompany($companyId);
    expect($resultFailed['dispatched'])->toBeFalse()
        ->and($resultFailed['jobs_count'])->toBe(0);

    // The claim MUST be released immediately so it can retry next minute
    $delivery->refresh();
    expect($delivery->status)->toBe(CrewOperationalAlertEmailDeliveryStatus::Queued)
        ->and($delivery->dispatched_at)->toBeNull()
        ->and($delivery->dispatch_claimed_at)->toBeNull();

    // Step 2: Next minute at 08:01 SAME DAY, queue broker is back online
    $fake->shouldThrow = false;
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-21 08:01:00', 'Asia/Dubai'));

    $resultRetry = app(DispatchCrewOperationalAlertEmailDigests::class)->forCompany($companyId);
    expect($resultRetry['dispatched'])->toBeTrue()
        ->and($resultRetry['jobs_count'])->toBe(1)
        ->and($resultRetry['delivery_count'])->toBe(1);

    Queue::assertPushed(DeliverCrewOperationalAlertEmailJob::class, 1);

    $delivery->refresh();
    expect($delivery->dispatched_at)->not->toBeNull()
        ->and($delivery->dispatch_claimed_at)->toBeNull();

    CarbonImmutable::setTestNow();
});

// ─────────────────────────────────────────────────────────────────────────────
// 4. TWO-RECIPIENT DIGEST WITH ONE ENQUEUE FAILURE -> ONLY FAILED RECIPIENT RETRIES
// ─────────────────────────────────────────────────────────────────────────────

test('two-recipient digest with one enqueue failure retries ONLY the failed recipient without duplicating the successful recipient', function () {
    $fake = new FlakyQueueFake(app());
    Queue::swap($fake);
    EmailTemplatesSeeder::seedCrewOperationalAlertDigestTemplate();

    $fixtures = makeCrewAssignmentFixtures();
    $company = $fixtures['company'];
    $company->update(['timezone' => 'Asia/Dubai']);
    $companyId = (int) $company->id;
    $u1 = $fixtures['user'];
    $u2 = User::factory()->create(['email' => 'u2-fail-test@example.test']);
    $u2->companies()->attach($companyId, ['status' => 'active']);

    CrewOperationsSettings::saveSettings($companyId, [], 30, true, [
        'notifications_enabled' => true,
        'notification_recipient_user_ids' => [(int) $u1->id, (int) $u2->id],
        'alert_signoff_overdue' => true,
        'alert_signoff_no_relief' => true,
        'alert_relief_not_ready' => true,
        'alert_current_manning_gap' => true,
        'alert_projected_manning_gap' => true,
        'notification_email_delivery_mode' => 'scheduled',
        'notification_email_digest_at' => '08:00',
        'notification_email_critical_immediate' => true,
    ]);

    $alert = CrewOperationalAlert::query()->create([
        'company_id' => $companyId,
        'type' => CrewOperationalAlertType::SignoffNoRelief,
        'severity' => CrewOperationalAlertSeverity::Warning,
        'status' => CrewOperationalAlertStatus::Active,
        'dedupe_key' => 'signoff_no_relief:test:partial:1',
        'title' => 'Sign-off approaching',
        'message' => 'Approaching',
        'context' => [],
        'detected_at' => CarbonImmutable::parse('2026-08-21 07:30:00', 'Asia/Dubai'),
        'last_detected_at' => CarbonImmutable::parse('2026-08-21 07:30:00', 'Asia/Dubai'),
        'notification_version' => 1,
    ]);

    $del1 = CrewOperationalAlertEmailDelivery::query()->create([
        'company_id' => $companyId,
        'crew_operational_alert_id' => $alert->id,
        'user_id' => $u1->id,
        'notification_version' => 1,
        'status' => CrewOperationalAlertEmailDeliveryStatus::Queued,
        'queued_at' => CarbonImmutable::parse('2026-08-21 07:30:00', 'Asia/Dubai'),
        'attempt_count' => 0,
    ]);

    $del2 = CrewOperationalAlertEmailDelivery::query()->create([
        'company_id' => $companyId,
        'crew_operational_alert_id' => $alert->id,
        'user_id' => $u2->id,
        'notification_version' => 1,
        'status' => CrewOperationalAlertEmailDeliveryStatus::Queued,
        'queued_at' => CarbonImmutable::parse('2026-08-21 07:30:00', 'Asia/Dubai'),
        'attempt_count' => 0,
    ]);

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-21 08:00:00', 'Asia/Dubai'));

    // Step 1: Fails ONLY for User 2
    $fake->failUserId = (int) $u2->id;

    $res8am = app(DispatchCrewOperationalAlertEmailDigests::class)->forCompany($companyId);
    expect($res8am['dispatched'])->toBeTrue()
        ->and($res8am['jobs_count'])->toBe(1)
        ->and($res8am['delivery_count'])->toBe(1);

    Queue::assertPushed(DeliverCrewOperationalAlertEmailJob::class, 1);

    $del1->refresh();
    $del2->refresh();
    expect($del1->dispatched_at)->not->toBeNull()
        ->and($del1->dispatch_claimed_at)->toBeNull()
        ->and($del2->dispatched_at)->toBeNull()
        ->and($del2->dispatch_claimed_at)->toBeNull();

    // Step 2: At 08:01, user 2 issue resolved -> dispatcher runs again
    $fake->failUserId = null;
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-21 08:01:00', 'Asia/Dubai'));

    $res801am = app(DispatchCrewOperationalAlertEmailDigests::class)->forCompany($companyId);
    expect($res801am['dispatched'])->toBeTrue()
        ->and($res801am['jobs_count'])->toBe(1)
        ->and($res801am['delivery_count'])->toBe(1);

    // Total jobs pushed is now exactly 2: 1 for u1, 1 for u2. Zero duplicates!
    Queue::assertPushed(DeliverCrewOperationalAlertEmailJob::class, 2);

    $del2->refresh();
    expect($del2->dispatched_at)->not->toBeNull()
        ->and($del2->dispatch_claimed_at)->toBeNull();

    CarbonImmutable::setTestNow();
});

// ─────────────────────────────────────────────────────────────────────────────
// 5. IMMEDIATE ENQUEUE FAILURE -> NEXT-MINUTE RETRY
// ─────────────────────────────────────────────────────────────────────────────

test('immediate delivery mode with enqueue failure retries on next-minute dispatch', function () {
    $fake = new FlakyQueueFake(app());
    Queue::swap($fake);
    EmailTemplatesSeeder::seedCrewOperationalAlertDigestTemplate();

    $fixtures = makeCrewAssignmentFixtures();
    $company = $fixtures['company'];
    $company->update(['timezone' => 'Asia/Dubai']);
    $companyId = (int) $company->id;
    $user = $fixtures['user'];

    CrewOperationsSettings::saveSettings($companyId, [], 30, true, [
        'notifications_enabled' => true,
        'notification_recipient_user_ids' => [(int) $user->id],
        'alert_signoff_overdue' => true,
        'alert_signoff_no_relief' => true,
        'alert_relief_not_ready' => true,
        'alert_current_manning_gap' => true,
        'alert_projected_manning_gap' => true,
        'notification_email_delivery_mode' => 'immediate',
    ]);

    $alert = CrewOperationalAlert::query()->create([
        'company_id' => $companyId,
        'type' => CrewOperationalAlertType::SignoffNoRelief,
        'severity' => CrewOperationalAlertSeverity::Warning,
        'status' => CrewOperationalAlertStatus::Active,
        'dedupe_key' => 'signoff_no_relief:test:imm_retry:1',
        'title' => 'Sign-off approaching',
        'message' => 'Immediate test',
        'context' => [],
        'detected_at' => CarbonImmutable::parse('2026-08-21 14:00:00', 'Asia/Dubai'),
        'last_detected_at' => CarbonImmutable::parse('2026-08-21 14:00:00', 'Asia/Dubai'),
        'notification_version' => 1,
    ]);

    $delivery = CrewOperationalAlertEmailDelivery::query()->create([
        'company_id' => $companyId,
        'crew_operational_alert_id' => $alert->id,
        'user_id' => $user->id,
        'notification_version' => 1,
        'status' => CrewOperationalAlertEmailDeliveryStatus::Queued,
        'queued_at' => CarbonImmutable::parse('2026-08-21 14:00:00', 'Asia/Dubai'),
        'attempt_count' => 0,
    ]);

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-21 14:00:00', 'Asia/Dubai'));

    // Step 1: Dispatch throws
    $fake->shouldThrow = true;

    $resFail = app(DispatchCrewOperationalAlertEmailDigests::class)->forCompany($companyId);
    expect($resFail['dispatched'])->toBeFalse();

    $delivery->refresh();
    expect($delivery->dispatched_at)->toBeNull()
        ->and($delivery->dispatch_claimed_at)->toBeNull();

    // Step 2: Next minute at 14:01, retry succeeds
    $fake->shouldThrow = false;
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-21 14:01:00', 'Asia/Dubai'));

    $resSuccess = app(DispatchCrewOperationalAlertEmailDigests::class)->forCompany($companyId);
    expect($resSuccess['dispatched'])->toBeTrue()
        ->and($resSuccess['jobs_count'])->toBe(1);

    Queue::assertPushed(DeliverCrewOperationalAlertEmailJob::class, 1);

    $delivery->refresh();
    expect($delivery->dispatched_at)->not->toBeNull();

    CarbonImmutable::setTestNow();
});

// ─────────────────────────────────────────────────────────────────────────────
// 6. CRITICAL IMMEDIATE ENQUEUE FAILURE -> NEXT-MINUTE RETRY
// ─────────────────────────────────────────────────────────────────────────────

test('critical alert in scheduled mode with critical_immediate ON retries on next-minute dispatch after enqueue failure', function () {
    $fake = new FlakyQueueFake(app());
    Queue::swap($fake);
    EmailTemplatesSeeder::seedCrewOperationalAlertDigestTemplate();

    $fixtures = makeCrewAssignmentFixtures();
    $company = $fixtures['company'];
    $company->update(['timezone' => 'Asia/Dubai']);
    $companyId = (int) $company->id;
    $user = $fixtures['user'];

    CrewOperationsSettings::saveSettings($companyId, [], 30, true, [
        'notifications_enabled' => true,
        'notification_recipient_user_ids' => [(int) $user->id],
        'alert_signoff_overdue' => true,
        'alert_signoff_no_relief' => true,
        'alert_relief_not_ready' => true,
        'alert_current_manning_gap' => true,
        'alert_projected_manning_gap' => true,
        'notification_email_delivery_mode' => 'scheduled',
        'notification_email_digest_at' => '08:00',
        'notification_email_critical_immediate' => true,
    ]);

    // Critical alert detected at 15:30 (outside 08:00 digest window)
    $alert = CrewOperationalAlert::query()->create([
        'company_id' => $companyId,
        'type' => CrewOperationalAlertType::SignoffOverdue,
        'severity' => CrewOperationalAlertSeverity::Critical,
        'status' => CrewOperationalAlertStatus::Active,
        'dedupe_key' => 'signoff_overdue:test:crit_retry:1',
        'title' => 'Sign-off overdue',
        'message' => 'Critical immediate test',
        'context' => [],
        'detected_at' => CarbonImmutable::parse('2026-08-21 15:30:00', 'Asia/Dubai'),
        'last_detected_at' => CarbonImmutable::parse('2026-08-21 15:30:00', 'Asia/Dubai'),
        'notification_version' => 1,
    ]);

    $delivery = CrewOperationalAlertEmailDelivery::query()->create([
        'company_id' => $companyId,
        'crew_operational_alert_id' => $alert->id,
        'user_id' => $user->id,
        'notification_version' => 1,
        'status' => CrewOperationalAlertEmailDeliveryStatus::Queued,
        'queued_at' => CarbonImmutable::parse('2026-08-21 15:30:00', 'Asia/Dubai'),
        'attempt_count' => 0,
    ]);

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-21 15:30:00', 'Asia/Dubai'));

    // Step 1: Enqueue fails
    $fake->shouldThrow = true;

    $resFail = app(DispatchCrewOperationalAlertEmailDigests::class)->forCompany($companyId);
    expect($resFail['dispatched'])->toBeFalse();

    $delivery->refresh();
    expect($delivery->dispatched_at)->toBeNull()
        ->and($delivery->dispatch_claimed_at)->toBeNull();

    // Step 2: Next minute at 15:31, retry succeeds
    $fake->shouldThrow = false;
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-21 15:31:00', 'Asia/Dubai'));

    $resSuccess = app(DispatchCrewOperationalAlertEmailDigests::class)->forCompany($companyId);
    expect($resSuccess['dispatched'])->toBeTrue()
        ->and($resSuccess['jobs_count'])->toBe(1);

    Queue::assertPushed(DeliverCrewOperationalAlertEmailJob::class, 1);

    $delivery->refresh();
    expect($delivery->dispatched_at)->not->toBeNull();

    CarbonImmutable::setTestNow();
});

// ─────────────────────────────────────────────────────────────────────────────
// 7. CONCURRENT DISPATCHER INSTANCES CANNOT DUPLICATE DELIVERY
// ─────────────────────────────────────────────────────────────────────────────

test('concurrent dispatcher instances cannot duplicate email jobs for the same delivery', function () {
    Queue::fake();
    EmailTemplatesSeeder::seedCrewOperationalAlertDigestTemplate();

    $fixtures = makeCrewAssignmentFixtures();
    $company = $fixtures['company'];
    $company->update(['timezone' => 'Asia/Dubai']);
    $companyId = (int) $company->id;
    $user = $fixtures['user'];

    CrewOperationsSettings::saveSettings($companyId, [], 30, true, [
        'notifications_enabled' => true,
        'notification_recipient_user_ids' => [(int) $user->id],
        'alert_signoff_overdue' => true,
        'alert_signoff_no_relief' => true,
        'alert_relief_not_ready' => true,
        'alert_current_manning_gap' => true,
        'alert_projected_manning_gap' => true,
        'notification_email_delivery_mode' => 'scheduled',
        'notification_email_digest_at' => '08:00',
        'notification_email_critical_immediate' => true,
    ]);

    $alert = CrewOperationalAlert::query()->create([
        'company_id' => $companyId,
        'type' => CrewOperationalAlertType::SignoffNoRelief,
        'severity' => CrewOperationalAlertSeverity::Warning,
        'status' => CrewOperationalAlertStatus::Active,
        'dedupe_key' => 'signoff_no_relief:test:concurrent:1',
        'title' => 'Sign-off approaching',
        'message' => 'Test',
        'context' => [],
        'detected_at' => CarbonImmutable::parse('2026-08-21 07:30:00', 'Asia/Dubai'),
        'last_detected_at' => CarbonImmutable::parse('2026-08-21 07:30:00', 'Asia/Dubai'),
        'notification_version' => 1,
    ]);

    $delivery = CrewOperationalAlertEmailDelivery::query()->create([
        'company_id' => $companyId,
        'crew_operational_alert_id' => $alert->id,
        'user_id' => $user->id,
        'notification_version' => 1,
        'status' => CrewOperationalAlertEmailDeliveryStatus::Queued,
        'queued_at' => CarbonImmutable::parse('2026-08-21 07:30:00', 'Asia/Dubai'),
        'attempt_count' => 0,
    ]);

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-21 08:00:00', 'Asia/Dubai'));

    // Process 1 claims the delivery
    $claimed1 = ClaimCrewOperationalAlertEmailDeliveries::claimByIds([(int) $delivery->id]);
    expect($claimed1)->toHaveCount(1);

    // Process 2 runs concurrently while Process 1 has claimed the delivery
    $claimed2 = ClaimCrewOperationalAlertEmailDeliveries::claimByIds([(int) $delivery->id]);
    expect($claimed2)->toHaveCount(0);

    // Process 1 dispatches the job and marks dispatched
    DeliverCrewOperationalAlertEmailJob::dispatch([(int) $delivery->id], $companyId, (int) $user->id);
    ClaimCrewOperationalAlertEmailDeliveries::markDispatched([(int) $delivery->id]);

    // Subsequent runner for company finds 0 pending deliveries
    $resultSubsequent = app(DispatchCrewOperationalAlertEmailDigests::class)->forCompany($companyId);
    expect($resultSubsequent['dispatched'])->toBeFalse()
        ->and($resultSubsequent['reason'])->toBe('no_pending_deliveries');

    // Exactly 1 job pushed total
    Queue::assertPushed(DeliverCrewOperationalAlertEmailJob::class, 1);

    CarbonImmutable::setTestNow();
});

// ─────────────────────────────────────────────────────────────────────────────
// 8. DELAYED SUCCESSFULLY-ENQUEUED JOB >1 HOUR IS NOT RECLAIMED
// ─────────────────────────────────────────────────────────────────────────────

test('delayed successfully-enqueued job older than one hour is NOT reclaimed by the dispatcher', function () {
    Queue::fake();
    EmailTemplatesSeeder::seedCrewOperationalAlertDigestTemplate();

    $fixtures = makeCrewAssignmentFixtures();
    $company = $fixtures['company'];
    $company->update(['timezone' => 'Asia/Dubai']);
    $companyId = (int) $company->id;
    $user = $fixtures['user'];

    CrewOperationsSettings::saveSettings($companyId, [], 30, true, [
        'notifications_enabled' => true,
        'notification_recipient_user_ids' => [(int) $user->id],
        'alert_signoff_overdue' => true,
        'alert_signoff_no_relief' => true,
        'alert_relief_not_ready' => true,
        'alert_current_manning_gap' => true,
        'alert_projected_manning_gap' => true,
        'notification_email_delivery_mode' => 'scheduled',
        'notification_email_digest_at' => '08:00',
        'notification_email_critical_immediate' => true,
    ]);

    $alert = CrewOperationalAlert::query()->create([
        'company_id' => $companyId,
        'type' => CrewOperationalAlertType::SignoffNoRelief,
        'severity' => CrewOperationalAlertSeverity::Warning,
        'status' => CrewOperationalAlertStatus::Active,
        'dedupe_key' => 'signoff_no_relief:test:delayed:1',
        'title' => 'Sign-off approaching',
        'message' => 'Test',
        'context' => [],
        'detected_at' => CarbonImmutable::parse('2026-08-21 07:30:00', 'Asia/Dubai'),
        'last_detected_at' => CarbonImmutable::parse('2026-08-21 07:30:00', 'Asia/Dubai'),
        'notification_version' => 1,
    ]);

    // Delivery was successfully enqueued at 08:00 (dispatched_at is set, dispatch_claimed_at is null)
    // but the background worker queue is slow/delayed so status is still 'queued' at 10:00
    $delivery = CrewOperationalAlertEmailDelivery::query()->create([
        'company_id' => $companyId,
        'crew_operational_alert_id' => $alert->id,
        'user_id' => $user->id,
        'notification_version' => 1,
        'status' => CrewOperationalAlertEmailDeliveryStatus::Queued,
        'queued_at' => CarbonImmutable::parse('2026-08-21 07:30:00', 'Asia/Dubai'),
        'dispatched_at' => CarbonImmutable::parse('2026-08-21 08:00:00', 'Asia/Dubai'),
        'dispatch_claimed_at' => null,
        'attempt_count' => 0,
    ]);

    // Dispatcher runs at 10:00 (2 hours later)
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-21 10:00:00', 'Asia/Dubai'));

    $result = app(DispatchCrewOperationalAlertEmailDigests::class)->forCompany($companyId);
    expect($result['dispatched'])->toBeFalse()
        ->and($result['reason'])->toBe('no_pending_deliveries');

    // 0 jobs pushed!
    Queue::assertNothingPushed();

    CarbonImmutable::setTestNow();
});

// ─────────────────────────────────────────────────────────────────────────────
// 9. STALE CLAIM RECOVERY (>5 MINS) WHERE JOB WAS NEVER ENQUEUED
// ─────────────────────────────────────────────────────────────────────────────

test('stale un-enqueued claim older than 5 minutes is reclaimed and dispatched', function () {
    Queue::fake();
    EmailTemplatesSeeder::seedCrewOperationalAlertDigestTemplate();

    $fixtures = makeCrewAssignmentFixtures();
    $company = $fixtures['company'];
    $company->update(['timezone' => 'Asia/Dubai']);
    $companyId = (int) $company->id;
    $user = $fixtures['user'];

    CrewOperationsSettings::saveSettings($companyId, [], 30, true, [
        'notifications_enabled' => true,
        'notification_recipient_user_ids' => [(int) $user->id],
        'alert_signoff_overdue' => true,
        'alert_signoff_no_relief' => true,
        'alert_relief_not_ready' => true,
        'alert_current_manning_gap' => true,
        'alert_projected_manning_gap' => true,
        'notification_email_delivery_mode' => 'scheduled',
        'notification_email_digest_at' => '08:00',
        'notification_email_critical_immediate' => true,
    ]);

    $alert = CrewOperationalAlert::query()->create([
        'company_id' => $companyId,
        'type' => CrewOperationalAlertType::SignoffNoRelief,
        'severity' => CrewOperationalAlertSeverity::Warning,
        'status' => CrewOperationalAlertStatus::Active,
        'dedupe_key' => 'signoff_no_relief:test:stale_reclaim:1',
        'title' => 'Sign-off approaching',
        'message' => 'Test',
        'context' => [],
        'detected_at' => CarbonImmutable::parse('2026-08-21 07:30:00', 'Asia/Dubai'),
        'last_detected_at' => CarbonImmutable::parse('2026-08-21 07:30:00', 'Asia/Dubai'),
        'notification_version' => 1,
    ]);

    // Delivery was claimed 10 minutes ago (07:50) but worker process crashed before enqueuing (dispatched_at is null)
    $delivery = CrewOperationalAlertEmailDelivery::query()->create([
        'company_id' => $companyId,
        'crew_operational_alert_id' => $alert->id,
        'user_id' => $user->id,
        'notification_version' => 1,
        'status' => CrewOperationalAlertEmailDeliveryStatus::Queued,
        'queued_at' => CarbonImmutable::parse('2026-08-21 07:30:00', 'Asia/Dubai'),
        'dispatched_at' => null,
        'dispatch_claimed_at' => CarbonImmutable::parse('2026-08-21 07:50:00', 'Asia/Dubai'),
        'attempt_count' => 0,
    ]);

    // At 08:00 Asia/Dubai -> Stale claim is reclaimed and successfully dispatched
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-21 08:00:00', 'Asia/Dubai'));

    $result = app(DispatchCrewOperationalAlertEmailDigests::class)->forCompany($companyId);
    expect($result['dispatched'])->toBeTrue()
        ->and($result['delivery_count'])->toBe(1);

    Queue::assertPushed(DeliverCrewOperationalAlertEmailJob::class, 1);

    $delivery->refresh();
    expect($delivery->dispatched_at)->not->toBeNull()
        ->and($delivery->dispatch_claimed_at)->toBeNull();

    CarbonImmutable::setTestNow();
});

// ─────────────────────────────────────────────────────────────────────────────
// 10. COMPANIES IN DIFFERENT TIMEZONES DISPATCH AT THEIR OWN LOCAL TIMES
// ─────────────────────────────────────────────────────────────────────────────

test('companies in different timezones dispatch at their own local scheduled times', function () {
    Queue::fake();
    EmailTemplatesSeeder::seedCrewOperationalAlertDigestTemplate();

    $fixturesDubai = makeCrewAssignmentFixtures();
    $companyDubai = $fixturesDubai['company'];
    $companyDubai->update(['timezone' => 'Asia/Dubai']);
    $userDubai = $fixturesDubai['user'];

    $fixturesNy = makeCrewAssignmentFixtures();
    $companyNy = $fixturesNy['company'];
    $companyNy->update(['timezone' => 'America/New_York']);
    $userNy = $fixturesNy['user'];

    CrewOperationsSettings::saveSettings((int) $companyDubai->id, [], 30, true, [
        'notifications_enabled' => true,
        'notification_recipient_user_ids' => [(int) $userDubai->id],
        'alert_signoff_overdue' => true,
        'alert_signoff_no_relief' => true,
        'alert_relief_not_ready' => true,
        'alert_current_manning_gap' => true,
        'alert_projected_manning_gap' => true,
        'notification_email_delivery_mode' => 'scheduled',
        'notification_email_digest_at' => '08:00',
        'notification_email_critical_immediate' => true,
    ]);

    CrewOperationsSettings::saveSettings((int) $companyNy->id, [], 30, true, [
        'notifications_enabled' => true,
        'notification_recipient_user_ids' => [(int) $userNy->id],
        'alert_signoff_overdue' => true,
        'alert_signoff_no_relief' => true,
        'alert_relief_not_ready' => true,
        'alert_current_manning_gap' => true,
        'alert_projected_manning_gap' => true,
        'notification_email_delivery_mode' => 'scheduled',
        'notification_email_digest_at' => '08:00',
        'notification_email_critical_immediate' => true,
    ]);

    $alertDubai = CrewOperationalAlert::query()->create([
        'company_id' => $companyDubai->id,
        'type' => CrewOperationalAlertType::SignoffNoRelief,
        'severity' => CrewOperationalAlertSeverity::Warning,
        'status' => CrewOperationalAlertStatus::Active,
        'dedupe_key' => 'signoff_no_relief:dubai:1',
        'title' => 'Sign-off approaching Dubai',
        'message' => 'Test',
        'context' => [],
        'detected_at' => CarbonImmutable::parse('2026-08-21 03:00:00', 'UTC'),
        'last_detected_at' => CarbonImmutable::parse('2026-08-21 03:00:00', 'UTC'),
        'notification_version' => 1,
    ]);

    CrewOperationalAlertEmailDelivery::query()->create([
        'company_id' => $companyDubai->id,
        'crew_operational_alert_id' => $alertDubai->id,
        'user_id' => $userDubai->id,
        'notification_version' => 1,
        'status' => CrewOperationalAlertEmailDeliveryStatus::Queued,
        'queued_at' => CarbonImmutable::parse('2026-08-21 03:00:00', 'UTC'),
        'attempt_count' => 0,
    ]);

    $alertNy = CrewOperationalAlert::query()->create([
        'company_id' => $companyNy->id,
        'type' => CrewOperationalAlertType::SignoffNoRelief,
        'severity' => CrewOperationalAlertSeverity::Warning,
        'status' => CrewOperationalAlertStatus::Active,
        'dedupe_key' => 'signoff_no_relief:ny:1',
        'title' => 'Sign-off approaching NY',
        'message' => 'Test',
        'context' => [],
        'detected_at' => CarbonImmutable::parse('2026-08-21 03:00:00', 'UTC'),
        'last_detected_at' => CarbonImmutable::parse('2026-08-21 03:00:00', 'UTC'),
        'notification_version' => 1,
    ]);

    CrewOperationalAlertEmailDelivery::query()->create([
        'company_id' => $companyNy->id,
        'crew_operational_alert_id' => $alertNy->id,
        'user_id' => $userNy->id,
        'notification_version' => 1,
        'status' => CrewOperationalAlertEmailDeliveryStatus::Queued,
        'queued_at' => CarbonImmutable::parse('2026-08-21 03:00:00', 'UTC'),
        'attempt_count' => 0,
    ]);

    // At 04:00 UTC:
    // Asia/Dubai (UTC+4) is 08:00 => DUE!
    // America/New_York (UTC-4) is 00:00 => NOT DUE!
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-21 04:00:00', 'UTC'));

    $dispatcher = app(DispatchCrewOperationalAlertEmailDigests::class);
    $resDubai = $dispatcher->forCompany((int) $companyDubai->id);
    $resNy = $dispatcher->forCompany((int) $companyNy->id);

    expect($resDubai['dispatched'])->toBeTrue()
        ->and($resNy['dispatched'])->toBeFalse()
        ->and($resNy['reason'])->toBe('no_eligible_deliveries');

    Queue::assertPushed(DeliverCrewOperationalAlertEmailJob::class, 1);

    CarbonImmutable::setTestNow();
});

// ─────────────────────────────────────────────────────────────────────────────
// 11. ALREADY SENT DELIVERY NEVER REDISPATCHES
// ─────────────────────────────────────────────────────────────────────────────

test('already Sent delivery is never reclaimed or redispatched', function () {
    Queue::fake();
    EmailTemplatesSeeder::seedCrewOperationalAlertDigestTemplate();

    $fixtures = makeCrewAssignmentFixtures();
    $company = $fixtures['company'];
    $company->update(['timezone' => 'Asia/Dubai']);
    $companyId = (int) $company->id;
    $user = $fixtures['user'];

    CrewOperationsSettings::saveSettings($companyId, [], 30, true, [
        'notifications_enabled' => true,
        'notification_recipient_user_ids' => [(int) $user->id],
        'alert_signoff_overdue' => true,
        'alert_signoff_no_relief' => true,
        'alert_relief_not_ready' => true,
        'alert_current_manning_gap' => true,
        'alert_projected_manning_gap' => true,
        'notification_email_delivery_mode' => 'scheduled',
        'notification_email_digest_at' => '08:00',
        'notification_email_critical_immediate' => true,
    ]);

    $alert = CrewOperationalAlert::query()->create([
        'company_id' => $companyId,
        'type' => CrewOperationalAlertType::SignoffNoRelief,
        'severity' => CrewOperationalAlertSeverity::Warning,
        'status' => CrewOperationalAlertStatus::Active,
        'dedupe_key' => 'signoff_no_relief:test:already_sent:1',
        'title' => 'Sign-off approaching',
        'message' => 'Test',
        'context' => [],
        'detected_at' => CarbonImmutable::parse('2026-08-21 07:30:00', 'Asia/Dubai'),
        'last_detected_at' => CarbonImmutable::parse('2026-08-21 07:30:00', 'Asia/Dubai'),
        'notification_version' => 1,
    ]);

    CrewOperationalAlertEmailDelivery::query()->create([
        'company_id' => $companyId,
        'crew_operational_alert_id' => $alert->id,
        'user_id' => $user->id,
        'notification_version' => 1,
        'status' => CrewOperationalAlertEmailDeliveryStatus::Sent,
        'queued_at' => CarbonImmutable::parse('2026-08-21 07:30:00', 'Asia/Dubai'),
        'dispatched_at' => CarbonImmutable::parse('2026-08-21 08:00:00', 'Asia/Dubai'),
        'sent_at' => CarbonImmutable::parse('2026-08-21 08:01:00', 'Asia/Dubai'),
        'attempt_count' => 1,
    ]);

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-21 08:05:00', 'Asia/Dubai'));

    $result = app(DispatchCrewOperationalAlertEmailDigests::class)->forCompany($companyId);
    expect($result['dispatched'])->toBeFalse()
        ->and($result['reason'])->toBe('no_pending_deliveries');

    Queue::assertNothingPushed();

    CarbonImmutable::setTestNow();
});

// ─────────────────────────────────────────────────────────────────────────────
// 12. ARTISAN COMMAND AND FORCE FLAG
// ─────────────────────────────────────────────────────────────────────────────

test('artisan command crew:dispatch-operational-alert-email-digests runs successfully', function () {
    Queue::fake();
    EmailTemplatesSeeder::seedCrewOperationalAlertDigestTemplate();

    $fixtures = makeCrewAssignmentFixtures();
    $company = $fixtures['company'];
    $company->update(['timezone' => 'UTC']);
    $companyId = (int) $company->id;
    $user = $fixtures['user'];

    CrewOperationsSettings::saveSettings($companyId, [], 30, true, [
        'notifications_enabled' => true,
        'notification_recipient_user_ids' => [(int) $user->id],
        'alert_signoff_overdue' => true,
        'alert_signoff_no_relief' => true,
        'alert_relief_not_ready' => true,
        'alert_current_manning_gap' => true,
        'alert_projected_manning_gap' => true,
        'notification_email_delivery_mode' => 'scheduled',
        'notification_email_digest_at' => '08:00',
        'notification_email_critical_immediate' => true,
    ]);

    $alert = CrewOperationalAlert::query()->create([
        'company_id' => $companyId,
        'type' => CrewOperationalAlertType::SignoffNoRelief,
        'severity' => CrewOperationalAlertSeverity::Warning,
        'status' => CrewOperationalAlertStatus::Active,
        'dedupe_key' => 'signoff_no_relief:artisan:1',
        'title' => 'Sign-off approaching',
        'message' => 'Test',
        'context' => [],
        'detected_at' => CarbonImmutable::parse('2026-08-21 07:30:00', 'UTC'),
        'last_detected_at' => CarbonImmutable::parse('2026-08-21 07:30:00', 'UTC'),
        'notification_version' => 1,
    ]);

    CrewOperationalAlertEmailDelivery::query()->create([
        'company_id' => $companyId,
        'crew_operational_alert_id' => $alert->id,
        'user_id' => $user->id,
        'notification_version' => 1,
        'status' => CrewOperationalAlertEmailDeliveryStatus::Queued,
        'queued_at' => CarbonImmutable::parse('2026-08-21 07:30:00', 'UTC'),
        'attempt_count' => 0,
    ]);

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-21 08:05:00', 'UTC'));

    $this->artisan('crew:dispatch-operational-alert-email-digests', ['--company' => $companyId])
        ->expectsOutputToContain('dispatched 1 digest(s) across 1 job(s)')
        ->assertSuccessful();

    Queue::assertPushed(DeliverCrewOperationalAlertEmailJob::class, 1);

    CarbonImmutable::setTestNow();
});

test('force flag bypasses time-of-day checks', function () {
    Queue::fake();
    EmailTemplatesSeeder::seedCrewOperationalAlertDigestTemplate();

    $fixtures = makeCrewAssignmentFixtures();
    $company = $fixtures['company'];
    $company->update(['timezone' => 'Asia/Dubai']);
    $companyId = (int) $company->id;
    $user = $fixtures['user'];

    CrewOperationsSettings::saveSettings($companyId, [], 30, true, [
        'notifications_enabled' => true,
        'notification_recipient_user_ids' => [(int) $user->id],
        'alert_signoff_overdue' => true,
        'alert_signoff_no_relief' => true,
        'alert_relief_not_ready' => true,
        'alert_current_manning_gap' => true,
        'alert_projected_manning_gap' => true,
        'notification_email_delivery_mode' => 'scheduled',
        'notification_email_digest_at' => '08:00',
        'notification_email_critical_immediate' => true,
    ]);

    $alert = CrewOperationalAlert::query()->create([
        'company_id' => $companyId,
        'type' => CrewOperationalAlertType::SignoffNoRelief,
        'severity' => CrewOperationalAlertSeverity::Warning,
        'status' => CrewOperationalAlertStatus::Active,
        'dedupe_key' => 'signoff_no_relief:force:1',
        'title' => 'Sign-off approaching',
        'message' => 'Test',
        'context' => [],
        'detected_at' => CarbonImmutable::parse('2026-08-21 01:00:00', 'Asia/Dubai'),
        'last_detected_at' => CarbonImmutable::parse('2026-08-21 01:00:00', 'Asia/Dubai'),
        'notification_version' => 1,
    ]);

    CrewOperationalAlertEmailDelivery::query()->create([
        'company_id' => $companyId,
        'crew_operational_alert_id' => $alert->id,
        'user_id' => $user->id,
        'notification_version' => 1,
        'status' => CrewOperationalAlertEmailDeliveryStatus::Queued,
        'queued_at' => CarbonImmutable::parse('2026-08-21 01:00:00', 'Asia/Dubai'),
        'attempt_count' => 0,
    ]);

    // Test at 02:00 (way before 08:00) with force => SHOULD dispatch
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-21 02:00:00', 'Asia/Dubai'));

    $this->artisan('crew:dispatch-operational-alert-email-digests', [
        '--company' => $companyId,
        '--force' => true,
    ])->assertSuccessful();

    Queue::assertPushed(DeliverCrewOperationalAlertEmailJob::class, 1);

    CarbonImmutable::setTestNow();
});

test('successful queue handoff is not re-enqueued when dispatched_at persist fails once', function () {
    Queue::fake();
    EmailTemplatesSeeder::seedCrewOperationalAlertDigestTemplate();

    $fixtures = makeCrewAssignmentFixtures();
    $company = $fixtures['company'];
    $company->update(['timezone' => 'Asia/Dubai']);
    $companyId = (int) $company->id;
    $user = $fixtures['user'];

    CrewOperationsSettings::saveSettings($companyId, [], 30, true, [
        'notifications_enabled' => true,
        'notification_recipient_user_ids' => [(int) $user->id],
        'alert_signoff_overdue' => true,
        'alert_signoff_no_relief' => true,
        'alert_relief_not_ready' => true,
        'alert_current_manning_gap' => true,
        'alert_projected_manning_gap' => true,
        'notification_email_delivery_mode' => 'immediate',
    ]);

    $alert = CrewOperationalAlert::query()->create([
        'company_id' => $companyId,
        'type' => CrewOperationalAlertType::SignoffNoRelief,
        'severity' => CrewOperationalAlertSeverity::Warning,
        'status' => CrewOperationalAlertStatus::Active,
        'dedupe_key' => 'signoff_no_relief:test:handoff-persist:1',
        'title' => 'Sign-off approaching',
        'message' => 'Test',
        'context' => [],
        'detected_at' => CarbonImmutable::parse('2026-08-21 14:00:00', 'Asia/Dubai'),
        'last_detected_at' => CarbonImmutable::parse('2026-08-21 14:00:00', 'Asia/Dubai'),
        'notification_version' => 1,
    ]);

    $delivery = CrewOperationalAlertEmailDelivery::query()->create([
        'company_id' => $companyId,
        'crew_operational_alert_id' => $alert->id,
        'user_id' => $user->id,
        'notification_version' => 1,
        'status' => CrewOperationalAlertEmailDeliveryStatus::Queued,
        'queued_at' => CarbonImmutable::parse('2026-08-21 14:00:00', 'Asia/Dubai'),
        'attempt_count' => 0,
    ]);

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-21 14:00:00', 'Asia/Dubai'));
    failNextCrewEmailDispatchLedgerPersists(1);

    try {
        $result = app(DispatchCrewOperationalAlertEmailDigests::class)->forCompany($companyId);
        expect($result['dispatched'])->toBeTrue()
            ->and($result['jobs_count'])->toBe(1);

        Queue::assertPushed(DeliverCrewOperationalAlertEmailJob::class, 1);

        $delivery->refresh();
        expect($delivery->dispatched_at)->not->toBeNull()
            ->and($delivery->dispatch_claimed_at)->toBeNull()
            ->and($delivery->status)->toBe(CrewOperationalAlertEmailDeliveryStatus::Queued);

        $second = app(DispatchCrewOperationalAlertEmailDigests::class)->forCompany($companyId);
        expect($second['dispatched'])->toBeFalse();
        Queue::assertPushed(DeliverCrewOperationalAlertEmailJob::class, 1);
    } finally {
        DB::unprepared('DROP TRIGGER IF EXISTS fail_crew_email_dispatch_persist');
        CarbonImmutable::setTestNow();
    }
});

test('successful queue handoff does not release the claim when dispatched_at persist is exhausted', function () {
    Queue::fake();
    EmailTemplatesSeeder::seedCrewOperationalAlertDigestTemplate();

    $fixtures = makeCrewAssignmentFixtures();
    $company = $fixtures['company'];
    $company->update(['timezone' => 'Asia/Dubai']);
    $companyId = (int) $company->id;
    $user = $fixtures['user'];

    CrewOperationsSettings::saveSettings($companyId, [], 30, true, [
        'notifications_enabled' => true,
        'notification_recipient_user_ids' => [(int) $user->id],
        'alert_signoff_overdue' => true,
        'alert_signoff_no_relief' => true,
        'alert_relief_not_ready' => true,
        'alert_current_manning_gap' => true,
        'alert_projected_manning_gap' => true,
        'notification_email_delivery_mode' => 'immediate',
    ]);

    $alert = CrewOperationalAlert::query()->create([
        'company_id' => $companyId,
        'type' => CrewOperationalAlertType::SignoffNoRelief,
        'severity' => CrewOperationalAlertSeverity::Warning,
        'status' => CrewOperationalAlertStatus::Active,
        'dedupe_key' => 'signoff_no_relief:test:handoff-persist-exhausted:1',
        'title' => 'Sign-off approaching',
        'message' => 'Test',
        'context' => [],
        'detected_at' => CarbonImmutable::parse('2026-08-21 14:00:00', 'Asia/Dubai'),
        'last_detected_at' => CarbonImmutable::parse('2026-08-21 14:00:00', 'Asia/Dubai'),
        'notification_version' => 1,
    ]);

    $delivery = CrewOperationalAlertEmailDelivery::query()->create([
        'company_id' => $companyId,
        'crew_operational_alert_id' => $alert->id,
        'user_id' => $user->id,
        'notification_version' => 1,
        'status' => CrewOperationalAlertEmailDeliveryStatus::Queued,
        'queued_at' => CarbonImmutable::parse('2026-08-21 14:00:00', 'Asia/Dubai'),
        'attempt_count' => 0,
    ]);

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-21 14:00:00', 'Asia/Dubai'));
    failNextCrewEmailDispatchLedgerPersists(99);

    try {
        $result = app(DispatchCrewOperationalAlertEmailDigests::class)->forCompany($companyId);
        expect($result['dispatched'])->toBeTrue()
            ->and($result['jobs_count'])->toBe(1);

        Queue::assertPushed(DeliverCrewOperationalAlertEmailJob::class, 1);

        $delivery->refresh();
        expect($delivery->dispatched_at)->toBeNull()
            ->and($delivery->dispatch_claimed_at)->not->toBeNull()
            ->and($delivery->status)->toBe(CrewOperationalAlertEmailDeliveryStatus::Queued);

        $second = app(DispatchCrewOperationalAlertEmailDigests::class)->forCompany($companyId);
        expect($second['dispatched'])->toBeFalse();
        Queue::assertPushed(DeliverCrewOperationalAlertEmailJob::class, 1);
    } finally {
        DB::unprepared('DROP TRIGGER IF EXISTS fail_crew_email_dispatch_persist');
        CarbonImmutable::setTestNow();
    }
});
