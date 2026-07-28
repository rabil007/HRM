<?php

use App\Enums\DocumentExpiryPushAlertStatus;
use App\Jobs\DeliverDocumentComplianceWebPushJob;
use App\Mail\DocumentExpiryAlertMail;
use App\Models\Announcement;
use App\Models\DocumentExpiryPushAlert;
use App\Models\EmailTemplate;
use App\Models\EmployeeDocumentExpiryAlert;
use App\Models\User;
use App\Notifications\DocumentComplianceWebPushNotification;
use App\Services\DocumentExpiryAlertService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

/**
 * @param  array{to_preset?: string|null, cc_preset?: string|null, enabled?: bool}  $overrides
 */
function configureCompliancePushAlertTemplate(array $overrides = []): void
{
    EmailTemplate::query()->updateOrCreate(
        ['slug' => 'document_expiry_alert'],
        array_merge([
            'label' => 'Document expiry alert',
            'category' => 'notification',
            'to_preset' => 'hr@example.com',
            'cc_preset' => 'manager@example.com',
            'subject' => 'Document Expiry Alert - Next 30 Days',
            'body_html' => 'Automated expiry summary email.',
            'is_default' => true,
            'enabled' => true,
            'sort_order' => 0,
        ], $overrides),
    );
}

beforeEach(function () {
    config(['documents.expiry_alert_days' => 30]);
    configureCompliancePushAlertTemplate();
    Carbon::setTestNow('2026-06-01');
});

afterEach(function () {
    Carbon::setTestNow();
});

test('multiple expiring documents create one push job per resolved user with subscriptions', function () {
    Queue::fake();
    Mail::fake();

    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();

    $hr = User::factory()->create(['email' => 'hr@example.com', 'status' => 'active']);
    $manager = User::factory()->create(['email' => 'manager@example.com', 'status' => 'active']);
    grantCompanyPermissions($hr, $company, ['documents.view']);
    grantCompanyPermissions($manager, $company, ['documents.view']);

    $hr->updatePushSubscription('https://fcm.googleapis.com/fcm/send/hr-device-1', 'BNcRnejnsCWcu6BCNCiCyiQoXKnAJkOjvgBgzEUrvsSMesTXHsYELfY35xZjFcRp27YWPBMBcIvP1uvxS9Xn1gE', 'tBHItJI5svbpez7KI4CCXg', 'aes128gcm');
    $hr->updatePushSubscription('https://fcm.googleapis.com/fcm/send/hr-device-2', 'BNcRnejnsCWcu6BCNCiCyiQoXKnAJkOjvgBgzEUrvsSMesTXHsYELfY35xZjFcRp27YWPBMBcIvP1uvxS9Xn1gE', 'tBHItJI5svbpez7KI4CCXg', 'aes128gcm');
    $manager->updatePushSubscription('https://fcm.googleapis.com/fcm/send/mgr-device-1', 'BNcRnejnsCWcu6BCNCiCyiQoXKnAJkOjvgBgzEUrvsSMesTXHsYELfY35xZjFcRp27YWPBMBcIvP1uvxS9Xn1gE', 'tBHItJI5svbpez7KI4CCXg', 'aes128gcm');

    $docA = createEmployeePdfDocument($company->id, $employee->id, $passportType->id, "employee-documents/{$company->id}/{$employee->id}/passport/a.pdf", 'A.pdf');
    $docA->update(['expiry_date' => '2026-06-20']);
    $docB = createEmployeePdfDocument($company->id, $employee->id, $passportType->id, "employee-documents/{$company->id}/{$employee->id}/passport/b.pdf", 'B.pdf');
    $docB->update(['expiry_date' => '2026-06-25']);

    app(DocumentExpiryAlertService::class)->sendForCompany($company->id);

    Mail::assertSent(DocumentExpiryAlertMail::class, 1);
    expect(EmployeeDocumentExpiryAlert::query()->count())->toBe(2);

    Queue::assertPushed(DeliverDocumentComplianceWebPushJob::class, 2);
    Queue::assertPushed(
        DeliverDocumentComplianceWebPushJob::class,
        fn (DeliverDocumentComplianceWebPushJob $job): bool => $job->userId === $hr->id
            && $job->companyId === $company->id
            && count($job->pushAlertIds) === 2
            && $job->afterCommit === true,
    );

    expect(DocumentExpiryPushAlert::query()->where('user_id', $hr->id)->count())->toBe(2)
        ->and(DocumentExpiryPushAlert::query()->where('user_id', $manager->id)->count())->toBe(2)
        ->and(Announcement::query()->count())->toBe(0);
});

test('no push job when template disabled, no recipients map, or no pending documents', function () {
    Queue::fake();
    Mail::fake();

    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();
    $doc = createEmployeePdfDocument($company->id, $employee->id, $passportType->id, "employee-documents/{$company->id}/{$employee->id}/passport/a.pdf", 'A.pdf');
    $doc->update(['expiry_date' => '2026-06-20']);

    configureCompliancePushAlertTemplate(['enabled' => false]);
    app(DocumentExpiryAlertService::class)->sendForCompany($company->id);
    Queue::assertNothingPushed();
    Mail::assertNothingSent();

    configureCompliancePushAlertTemplate(['to_preset' => 'nobody@example.com', 'cc_preset' => null, 'enabled' => true]);
    app(DocumentExpiryAlertService::class)->sendForCompany($company->id);
    Queue::assertNothingPushed();
    Mail::assertSent(DocumentExpiryAlertMail::class, 1);

    Queue::fake();
    Mail::fake();
    EmployeeDocumentExpiryAlert::query()->delete();
    DocumentExpiryPushAlert::query()->delete();
    $doc->update(['expiry_date' => '2027-01-01']);
    configureCompliancePushAlertTemplate();
    app(DocumentExpiryAlertService::class)->sendForCompany($company->id);
    Queue::assertNothingPushed();
    Mail::assertNothingSent();
});

test('user without push subscription is skipped safely while email still sends', function () {
    Queue::fake();
    Mail::fake();

    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();
    $hr = User::factory()->create(['email' => 'hr@example.com', 'status' => 'active']);
    grantCompanyPermissions($hr, $company, ['documents.view']);

    $doc = createEmployeePdfDocument($company->id, $employee->id, $passportType->id, "employee-documents/{$company->id}/{$employee->id}/passport/a.pdf", 'A.pdf');
    $doc->update(['expiry_date' => '2026-06-20']);

    app(DocumentExpiryAlertService::class)->sendForCompany($company->id);

    Mail::assertSent(DocumentExpiryAlertMail::class, 1);
    Queue::assertNothingPushed();
    expect(DocumentExpiryPushAlert::query()->count())->toBe(0);
});

test('same user document expiry is not pushed twice and changed expiry permits a new alert', function () {
    Queue::fake();
    Mail::fake();

    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();
    $hr = User::factory()->create(['email' => 'hr@example.com', 'status' => 'active']);
    grantCompanyPermissions($hr, $company, ['documents.view']);
    $hr->updatePushSubscription('https://fcm.googleapis.com/fcm/send/hr-once', 'BNcRnejnsCWcu6BCNCiCyiQoXKnAJkOjvgBgzEUrvsSMesTXHsYELfY35xZjFcRp27YWPBMBcIvP1uvxS9Xn1gE', 'tBHItJI5svbpez7KI4CCXg', 'aes128gcm');

    $doc = createEmployeePdfDocument($company->id, $employee->id, $passportType->id, "employee-documents/{$company->id}/{$employee->id}/passport/a.pdf", 'A.pdf');
    $doc->update(['expiry_date' => '2026-06-20']);

    app(DocumentExpiryAlertService::class)->sendForCompany($company->id);
    Queue::assertPushed(DeliverDocumentComplianceWebPushJob::class, 1);

    DocumentExpiryPushAlert::query()->update([
        'status' => DocumentExpiryPushAlertStatus::Sent,
        'sent_at' => now(),
    ]);

    Queue::fake();
    app(DocumentExpiryAlertService::class)->sendForCompany($company->id);
    Queue::assertNothingPushed();
    expect(DocumentExpiryPushAlert::query()->count())->toBe(1);

    $doc->update(['expiry_date' => '2026-06-22']);
    EmployeeDocumentExpiryAlert::query()->delete();
    app(DocumentExpiryAlertService::class)->sendForCompany($company->id);
    Queue::assertPushed(DeliverDocumentComplianceWebPushJob::class, 1);
    expect(DocumentExpiryPushAlert::query()->count())->toBe(2);
});

test('push payload is privacy-safe and delivery job marks ledger sent', function () {
    Notification::fake();

    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();
    $hr = User::factory()->create(['email' => 'hr@example.com', 'status' => 'active']);
    grantCompanyPermissions($hr, $company, ['documents.view']);
    $hr->updatePushSubscription('https://fcm.googleapis.com/fcm/send/hr-payload', 'BNcRnejnsCWcu6BCNCiCyiQoXKnAJkOjvgBgzEUrvsSMesTXHsYELfY35xZjFcRp27YWPBMBcIvP1uvxS9Xn1gE', 'tBHItJI5svbpez7KI4CCXg', 'aes128gcm');

    $doc = createEmployeePdfDocument($company->id, $employee->id, $passportType->id, "employee-documents/{$company->id}/{$employee->id}/passport/a.pdf", 'SecretPassport.pdf');
    $doc->update(['expiry_date' => '2026-06-20']);

    $alert = DocumentExpiryPushAlert::query()->create([
        'company_id' => $company->id,
        'employee_document_id' => $doc->id,
        'user_id' => $hr->id,
        'expiry_date_at_alert_time' => '2026-06-20',
        'status' => DocumentExpiryPushAlertStatus::Queued,
        'queued_at' => now(),
    ]);

    (new DeliverDocumentComplianceWebPushJob($company->id, $hr->id, [$alert->id]))->handle();

    Notification::assertSentTo($hr, DocumentComplianceWebPushNotification::class, function (DocumentComplianceWebPushNotification $notification) use ($company, $hr): bool {
        $message = $notification->toWebPush($hr, $notification)->toArray();

        expect($message['title'])->toBe('Document compliance alert')
            ->and($message['body'])->toBe('Documents require expiry or compliance attention.')
            ->and($message['title'])->not->toContain('Secret')
            ->and($message['body'])->not->toContain('Passport')
            ->and($message['body'])->not->toContain((string) $company->name)
            ->and($message['tag'])->toBe('document-compliance-'.$company->id);

        return true;
    });

    expect($alert->fresh()?->status)->toBe(DocumentExpiryPushAlertStatus::Sent);
});

test('email failure does not prevent push queueing and push never creates announcements', function () {
    Queue::fake();
    Mail::shouldReceive('to')->andThrow(new RuntimeException('SMTP down'));

    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();
    $hr = User::factory()->create(['email' => 'hr@example.com', 'status' => 'active']);
    grantCompanyPermissions($hr, $company, ['documents.view']);
    $hr->updatePushSubscription('https://fcm.googleapis.com/fcm/send/hr-email-fail', 'BNcRnejnsCWcu6BCNCiCyiQoXKnAJkOjvgBgzEUrvsSMesTXHsYELfY35xZjFcRp27YWPBMBcIvP1uvxS9Xn1gE', 'tBHItJI5svbpez7KI4CCXg', 'aes128gcm');

    $doc = createEmployeePdfDocument($company->id, $employee->id, $passportType->id, "employee-documents/{$company->id}/{$employee->id}/passport/a.pdf", 'A.pdf');
    $doc->update(['expiry_date' => '2026-06-20']);

    expect(fn () => app(DocumentExpiryAlertService::class)->sendForCompany($company->id))
        ->toThrow(RuntimeException::class);

    Queue::assertPushed(DeliverDocumentComplianceWebPushJob::class, 1);
    expect(Announcement::query()->count())->toBe(0)
        ->and(DocumentExpiryPushAlert::query()->count())->toBe(1)
        ->and(EmployeeDocumentExpiryAlert::query()->count())->toBe(0);
});

test('delivery job final failure records only a generic category and safe logs', function () {
    Log::spy();

    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();
    $hr = User::factory()->create(['email' => 'hr@example.com', 'status' => 'active']);
    grantCompanyPermissions($hr, $company, ['documents.view']);

    $doc = createEmployeePdfDocument($company->id, $employee->id, $passportType->id, "employee-documents/{$company->id}/{$employee->id}/passport/a.pdf", 'A.pdf');
    $doc->update(['expiry_date' => '2026-06-20']);

    $alert = DocumentExpiryPushAlert::query()->create([
        'company_id' => $company->id,
        'employee_document_id' => $doc->id,
        'user_id' => $hr->id,
        'expiry_date_at_alert_time' => '2026-06-20',
        'status' => DocumentExpiryPushAlertStatus::Queued,
        'queued_at' => now(),
    ]);

    $job = new DeliverDocumentComplianceWebPushJob($company->id, $hr->id, [$alert->id]);
    $job->failed(new RuntimeException('provider secret details'));

    expect($alert->fresh()?->status)->toBe(DocumentExpiryPushAlertStatus::Failed)
        ->and($alert->fresh()?->failure_category)->toBe('web_push_exhausted');

    Log::shouldHaveReceived('warning')->withArgs(function (string $message, array $context): bool {
        $encoded = json_encode($context);

        return $message === 'Document compliance web push delivery exhausted retries'
            && ! str_contains($encoded, 'provider secret')
            && ! str_contains($encoded, 'hr@example.com')
            && ! str_contains($encoded, 'fcm.googleapis.com')
            && ($context['failure_category'] ?? null) === 'web_push_exhausted';
    });
});

test('rolled-back transaction does not persist push alert ledger rows', function () {
    Mail::fake();

    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();
    $hr = User::factory()->create(['email' => 'hr@example.com', 'status' => 'active']);
    grantCompanyPermissions($hr, $company, ['documents.view']);
    $hr->updatePushSubscription('https://fcm.googleapis.com/fcm/send/hr-rollback', 'BNcRnejnsCWcu6BCNCiCyiQoXKnAJkOjvgBgzEUrvsSMesTXHsYELfY35xZjFcRp27YWPBMBcIvP1uvxS9Xn1gE', 'tBHItJI5svbpez7KI4CCXg', 'aes128gcm');

    $doc = createEmployeePdfDocument($company->id, $employee->id, $passportType->id, "employee-documents/{$company->id}/{$employee->id}/passport/a.pdf", 'A.pdf');
    $doc->update(['expiry_date' => '2026-06-20']);

    try {
        DB::transaction(function () use ($company): void {
            app(DocumentExpiryAlertService::class)->sendForCompany($company->id);
            throw new RuntimeException('force rollback');
        });
    } catch (RuntimeException) {
        // expected
    }

    expect(DocumentExpiryPushAlert::query()->count())->toBe(0)
        ->and(EmployeeDocumentExpiryAlert::query()->count())->toBe(0);
});
