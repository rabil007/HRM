<?php

use App\Jobs\SendCompanyDocumentExpiryAlertJob;
use App\Mail\CompanyDocumentExpiryAlertMail;
use App\Mail\DocumentExpiryAlertMail;
use App\Models\CompanyDocument;
use App\Models\CompanyDocumentExpiryAlert;
use App\Models\CompanyDocumentExpiryNotificationRecipient;
use App\Models\CompanyDocumentExpiryNotificationSetting;
use App\Models\EmailTemplate;
use App\Models\User;
use App\Services\CompanyDocumentExpiryAlertService;
use App\Services\DocumentExpiryAlertService;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;

// ─────────────────────────────────────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────────────────────────────────────

/** Create a CompanyDocument expiry notification setting with recipients. */
function companyDocNotificationSetting(
    int $companyId,
    bool $enabled = true,
    array $toUserIds = [],
    array $ccUserIds = [],
): CompanyDocumentExpiryNotificationSetting {
    $setting = CompanyDocumentExpiryNotificationSetting::query()->create([
        'company_id' => $companyId,
        'enabled' => $enabled,
    ]);

    foreach ($toUserIds as $userId) {
        CompanyDocumentExpiryNotificationRecipient::query()->create([
            'setting_id' => $setting->id,
            'user_id' => $userId,
            'type' => 'to',
        ]);
    }

    foreach ($ccUserIds as $userId) {
        CompanyDocumentExpiryNotificationRecipient::query()->create([
            'setting_id' => $setting->id,
            'user_id' => $userId,
            'type' => 'cc',
        ]);
    }

    return $setting->fresh(['toRecipients', 'ccRecipients']);
}

function attachActiveCompanyMembership(int $companyId, User $user): void
{
    DB::table('company_user')->updateOrInsert(
        ['company_id' => $companyId, 'user_id' => $user->id],
        ['status' => 'active', 'created_at' => now(), 'updated_at' => now()],
    );
}

/** Create a CompanyDocument with an expiry date. */
function expiringCompanyDocument(
    int $companyId,
    int $documentTypeId,
    int $uploaderId,
    string $expiryDate,
    string $title = 'Trade License',
): CompanyDocument {
    Storage::disk('local')->put("company-documents/{$companyId}/test.pdf", minimalPdfBytes());

    return CompanyDocument::query()->create([
        'company_id' => $companyId,
        'document_type_id' => $documentTypeId,
        'title' => $title,
        'document_number' => 'TL-001',
        'expiry_date' => $expiryDate,
        'file_path' => "company-documents/{$companyId}/test.pdf",
        'original_filename' => 'test.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => strlen(minimalPdfBytes()),
        'checksum' => hash('sha256', minimalPdfBytes()),
        'current_version' => 1,
        'uploaded_by' => $uploaderId,
    ]);
}

beforeEach(function () {
    Storage::fake('local');
    config(['documents.expiry_alert_days' => 30]);
    configureDocumentExpiryAlertTemplate();
});

// ─────────────────────────────────────────────────────────────────────────────
// Service behavior
// ─────────────────────────────────────────────────────────────────────────────

test('hasPendingDocuments returns false when notifications are disabled', function () {
    Carbon::setTestNow('2026-09-01');
    $fixtures = makeDocumentFixtures();
    $company = $fixtures['company'];
    $uploader = User::factory()->create(['company_id' => $company->id]);
    attachActiveCompanyMembership($company->id, $uploader);

    companyDocNotificationSetting(
        companyId: $company->id,
        enabled: false,
        toUserIds: [$uploader->id],
    );

    expiringCompanyDocument($company->id, $fixtures['passportType']->id, $uploader->id, '2026-09-20');

    expect(app(CompanyDocumentExpiryAlertService::class)->hasPendingDocuments($company->id))->toBeFalse();
});

test('hasPendingDocuments returns false when no TO recipients are configured', function () {
    Carbon::setTestNow('2026-09-01');
    $fixtures = makeDocumentFixtures();
    $uploader = User::factory()->create(['company_id' => $fixtures['company']->id]);

    // Enabled but no recipients.
    companyDocNotificationSetting(
        companyId: $fixtures['company']->id,
        enabled: true,
        toUserIds: [],
    );

    expiringCompanyDocument($fixtures['company']->id, $fixtures['passportType']->id, $uploader->id, '2026-09-20');

    expect(app(CompanyDocumentExpiryAlertService::class)->hasPendingDocuments($fixtures['company']->id))->toBeFalse();
});

test('hasPendingDocuments returns true when expiring documents and valid recipients exist', function () {
    Carbon::setTestNow('2026-09-01');
    $fixtures = makeDocumentFixtures();
    $uploader = User::factory()->create(['company_id' => $fixtures['company']->id]);
    attachActiveCompanyMembership($fixtures['company']->id, $uploader);

    companyDocNotificationSetting(
        companyId: $fixtures['company']->id,
        enabled: true,
        toUserIds: [$uploader->id],
    );

    expiringCompanyDocument($fixtures['company']->id, $fixtures['passportType']->id, $uploader->id, '2026-09-20');

    expect(app(CompanyDocumentExpiryAlertService::class)->hasPendingDocuments($fixtures['company']->id))->toBeTrue();
});

test('documents outside the alert window do not trigger hasPendingDocuments', function () {
    Carbon::setTestNow('2026-09-01');
    $fixtures = makeDocumentFixtures();
    $uploader = User::factory()->create(['company_id' => $fixtures['company']->id]);
    attachActiveCompanyMembership($fixtures['company']->id, $uploader);

    companyDocNotificationSetting(
        companyId: $fixtures['company']->id,
        enabled: true,
        toUserIds: [$uploader->id],
    );

    // Expiry 60 days away — outside the 30-day window.
    expiringCompanyDocument($fixtures['company']->id, $fixtures['passportType']->id, $uploader->id, '2026-10-31');

    expect(app(CompanyDocumentExpiryAlertService::class)->hasPendingDocuments($fixtures['company']->id))->toBeFalse();
});

test('documents without expiry dates are excluded', function () {
    Carbon::setTestNow('2026-09-01');
    $fixtures = makeDocumentFixtures();
    $uploader = User::factory()->create(['company_id' => $fixtures['company']->id]);
    attachActiveCompanyMembership($fixtures['company']->id, $uploader);

    companyDocNotificationSetting(
        companyId: $fixtures['company']->id,
        enabled: true,
        toUserIds: [$uploader->id],
    );

    // No expiry date.
    CompanyDocument::query()->create([
        'company_id' => $fixtures['company']->id,
        'document_type_id' => $fixtures['passportType']->id,
        'title' => 'No Expiry Doc',
        'file_path' => "company-documents/{$fixtures['company']->id}/noexpiry.pdf",
        'original_filename' => 'noexpiry.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 100,
        'checksum' => 'abc',
        'current_version' => 1,
        'uploaded_by' => $uploader->id,
    ]);

    expect(app(CompanyDocumentExpiryAlertService::class)->hasPendingDocuments($fixtures['company']->id))->toBeFalse();
});

// ─────────────────────────────────────────────────────────────────────────────
// Email delivery
// ─────────────────────────────────────────────────────────────────────────────

test('company document recipients receive the expiry summary email', function () {
    Mail::fake();
    Carbon::setTestNow('2026-09-01');
    $fixtures = makeDocumentFixtures();
    $toUser = User::factory()->create(['company_id' => $fixtures['company']->id, 'email' => 'pro@example.com']);
    $ccUser = User::factory()->create(['company_id' => $fixtures['company']->id, 'email' => 'finance@example.com']);

    DB::table('company_user')->insert([
        ['company_id' => $fixtures['company']->id, 'user_id' => $toUser->id, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
        ['company_id' => $fixtures['company']->id, 'user_id' => $ccUser->id, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
    ]);

    companyDocNotificationSetting(
        companyId: $fixtures['company']->id,
        enabled: true,
        toUserIds: [$toUser->id],
        ccUserIds: [$ccUser->id],
    );

    expiringCompanyDocument($fixtures['company']->id, $fixtures['passportType']->id, $toUser->id, '2026-09-20');

    app(CompanyDocumentExpiryAlertService::class)->sendForCompany($fixtures['company']->id);

    Mail::assertSent(CompanyDocumentExpiryAlertMail::class, function (CompanyDocumentExpiryAlertMail $mail) use ($toUser, $ccUser) {
        return $mail->hasTo($toUser->email)
            && $mail->hasCc($ccUser->email)
            && count($mail->rows) === 1
            && $mail->rows[0]['document_name'] === 'Trade License';
    });
});

test('employee document default recipients do NOT receive company document alerts', function () {
    Mail::fake();
    Carbon::setTestNow('2026-09-01');
    $fixtures = makeDocumentFixtures();
    $companyDocUser = User::factory()->create(['company_id' => $fixtures['company']->id, 'email' => 'pro@example.com']);

    DB::table('company_user')->insert([
        'company_id' => $fixtures['company']->id,
        'user_id' => $companyDocUser->id,
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    companyDocNotificationSetting(
        companyId: $fixtures['company']->id,
        enabled: true,
        toUserIds: [$companyDocUser->id],
    );

    expiringCompanyDocument($fixtures['company']->id, $fixtures['passportType']->id, $companyDocUser->id, '2026-09-20');

    app(CompanyDocumentExpiryAlertService::class)->sendForCompany($fixtures['company']->id);

    // Employee alert template recipients (hr@example.com, manager@example.com) must not receive CompanyDocumentExpiryAlertMail.
    Mail::assertSent(CompanyDocumentExpiryAlertMail::class, fn ($mail) => ! $mail->hasTo('hr@example.com') && ! $mail->hasTo('manager@example.com'));
    Mail::assertNotSent(DocumentExpiryAlertMail::class);
});

test('disabled company document notifications send nothing', function () {
    Mail::fake();
    Carbon::setTestNow('2026-09-01');
    $fixtures = makeDocumentFixtures();
    $user = User::factory()->create(['company_id' => $fixtures['company']->id]);

    DB::table('company_user')->insert([
        'company_id' => $fixtures['company']->id,
        'user_id' => $user->id,
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    companyDocNotificationSetting(
        companyId: $fixtures['company']->id,
        enabled: false,
        toUserIds: [$user->id],
    );

    expiringCompanyDocument($fixtures['company']->id, $fixtures['passportType']->id, $user->id, '2026-09-20');

    app(CompanyDocumentExpiryAlertService::class)->sendForCompany($fixtures['company']->id);

    Mail::assertNothingSent();
});

test('multiple expiring company documents are consolidated into one email', function () {
    Mail::fake();
    Carbon::setTestNow('2026-09-01');
    $fixtures = makeDocumentFixtures();
    $user = User::factory()->create(['company_id' => $fixtures['company']->id]);

    DB::table('company_user')->insert([
        'company_id' => $fixtures['company']->id,
        'user_id' => $user->id,
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    companyDocNotificationSetting(
        companyId: $fixtures['company']->id,
        enabled: true,
        toUserIds: [$user->id],
    );

    expiringCompanyDocument($fixtures['company']->id, $fixtures['passportType']->id, $user->id, '2026-09-15', 'Trade License');
    expiringCompanyDocument($fixtures['company']->id, $fixtures['passportType']->id, $user->id, '2026-09-20', 'Establishment Card');
    expiringCompanyDocument($fixtures['company']->id, $fixtures['passportType']->id, $user->id, '2026-09-25', 'Insurance Policy');

    app(CompanyDocumentExpiryAlertService::class)->sendForCompany($fixtures['company']->id);

    Mail::assertSentCount(1);
    Mail::assertSent(CompanyDocumentExpiryAlertMail::class, fn ($mail) => count($mail->rows) === 3);
});

// ─────────────────────────────────────────────────────────────────────────────
// Deduplication
// ─────────────────────────────────────────────────────────────────────────────

test('same expiry event is not repeatedly sent', function () {
    Mail::fake();
    Carbon::setTestNow('2026-09-01');
    $fixtures = makeDocumentFixtures();
    $user = User::factory()->create(['company_id' => $fixtures['company']->id]);

    DB::table('company_user')->insert([
        'company_id' => $fixtures['company']->id,
        'user_id' => $user->id,
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    companyDocNotificationSetting(
        companyId: $fixtures['company']->id,
        enabled: true,
        toUserIds: [$user->id],
    );

    $doc = expiringCompanyDocument($fixtures['company']->id, $fixtures['passportType']->id, $user->id, '2026-09-25');

    $service = app(CompanyDocumentExpiryAlertService::class);
    $service->sendForCompany($fixtures['company']->id);

    Mail::assertSentCount(1);

    // Second run.
    Carbon::setTestNow('2026-09-10');
    $service->sendForCompany($fixtures['company']->id);

    Mail::assertSentCount(1);
    expect(CompanyDocumentExpiryAlert::query()->count())->toBe(1);
});

test('renewing expiry date allows a future alert', function () {
    Mail::fake();
    Carbon::setTestNow('2026-09-01');
    $fixtures = makeDocumentFixtures();
    $user = User::factory()->create(['company_id' => $fixtures['company']->id]);

    DB::table('company_user')->insert([
        'company_id' => $fixtures['company']->id,
        'user_id' => $user->id,
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    companyDocNotificationSetting(
        companyId: $fixtures['company']->id,
        enabled: true,
        toUserIds: [$user->id],
    );

    $doc = expiringCompanyDocument($fixtures['company']->id, $fixtures['passportType']->id, $user->id, '2026-09-25');

    $service = app(CompanyDocumentExpiryAlertService::class);
    $service->sendForCompany($fixtures['company']->id);

    Mail::assertSentCount(1);

    // Renew with a future expiry date.
    $doc->update(['expiry_date' => '2027-09-25']);

    Carbon::setTestNow('2027-09-01');
    $service->sendForCompany($fixtures['company']->id);

    Mail::assertSentCount(2);
    expect(CompanyDocumentExpiryAlert::query()->count())->toBe(2);
});

// ─────────────────────────────────────────────────────────────────────────────
// Tenant isolation
// ─────────────────────────────────────────────────────────────────────────────

test('company A documents never appear in company B emails', function () {
    Mail::fake();
    Carbon::setTestNow('2026-09-01');

    $fixturesA = makeDocumentFixtures();
    $fixturesB = makeDocumentFixtures();
    $userA = User::factory()->create(['company_id' => $fixturesA['company']->id, 'email' => 'user-a@example.com']);
    $userB = User::factory()->create(['company_id' => $fixturesB['company']->id, 'email' => 'user-b@example.com']);

    DB::table('company_user')->insert([
        ['company_id' => $fixturesA['company']->id, 'user_id' => $userA->id, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
        ['company_id' => $fixturesB['company']->id, 'user_id' => $userB->id, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
    ]);

    companyDocNotificationSetting(
        companyId: $fixturesA['company']->id,
        enabled: true,
        toUserIds: [$userA->id],
    );

    companyDocNotificationSetting(
        companyId: $fixturesB['company']->id,
        enabled: true,
        toUserIds: [$userB->id],
    );

    expiringCompanyDocument($fixturesA['company']->id, $fixturesA['passportType']->id, $userA->id, '2026-09-20', 'Company A Doc');
    expiringCompanyDocument($fixturesB['company']->id, $fixturesB['passportType']->id, $userB->id, '2026-09-20', 'Company B Doc');

    $service = app(CompanyDocumentExpiryAlertService::class);
    $service->sendForCompany($fixturesA['company']->id);

    // Only userA's email should have been sent for company A.
    Mail::assertSent(CompanyDocumentExpiryAlertMail::class, function (CompanyDocumentExpiryAlertMail $mail) {
        return count($mail->rows) === 1
            && $mail->rows[0]['document_name'] === 'Company A Doc';
    });

    // userB should not have received anything yet.
    Mail::assertSentCount(1);

    $service->sendForCompany($fixturesB['company']->id);

    Mail::assertSentCount(2);
    Mail::assertSent(CompanyDocumentExpiryAlertMail::class, function (CompanyDocumentExpiryAlertMail $mail) {
        return count($mail->rows) === 1
            && $mail->rows[0]['document_name'] === 'Company B Doc';
    });
});

// ─────────────────────────────────────────────────────────────────────────────
// Dispatch command
// ─────────────────────────────────────────────────────────────────────────────

test('dispatch command queues company document expiry job when pending documents exist', function () {
    Queue::fake();
    Carbon::setTestNow('2026-09-01');
    $fixtures = makeDocumentFixtures();
    $user = User::factory()->create(['company_id' => $fixtures['company']->id]);

    DB::table('company_user')->insert([
        'company_id' => $fixtures['company']->id,
        'user_id' => $user->id,
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    companyDocNotificationSetting(
        companyId: $fixtures['company']->id,
        enabled: true,
        toUserIds: [$user->id],
    );

    expiringCompanyDocument($fixtures['company']->id, $fixtures['passportType']->id, $user->id, '2026-09-20');

    $this->artisan('documents:dispatch-expiry-alerts', ['--company' => $fixtures['company']->id])
        ->assertSuccessful();

    Queue::assertPushed(SendCompanyDocumentExpiryAlertJob::class, fn ($job) => $job->companyId === $fixtures['company']->id);
});

test('dispatch command does not queue employee job when company doc alert has no pending docs', function () {
    Queue::fake();
    Carbon::setTestNow('2026-09-01');
    $fixtures = makeDocumentFixtures();

    // No notification setting for company docs — hasPendingDocuments returns false.

    $this->artisan('documents:dispatch-expiry-alerts', ['--company' => $fixtures['company']->id])
        ->assertSuccessful();

    Queue::assertNotPushed(SendCompanyDocumentExpiryAlertJob::class);
});

// ─────────────────────────────────────────────────────────────────────────────
// Activity logging
// ─────────────────────────────────────────────────────────────────────────────

test('successful company document expiry alert is logged to activity', function () {
    Mail::fake();
    Carbon::setTestNow('2026-09-01');
    $fixtures = makeDocumentFixtures();
    $user = User::factory()->create(['company_id' => $fixtures['company']->id, 'email' => 'pro@example.com']);

    DB::table('company_user')->insert([
        'company_id' => $fixtures['company']->id,
        'user_id' => $user->id,
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    companyDocNotificationSetting(
        companyId: $fixtures['company']->id,
        enabled: true,
        toUserIds: [$user->id],
    );

    expiringCompanyDocument($fixtures['company']->id, $fixtures['passportType']->id, $user->id, '2026-09-20');

    app(CompanyDocumentExpiryAlertService::class)->sendForCompany($fixtures['company']->id);

    $activity = Activity::query()
        ->where('event', 'company_document_expiry_alert_sent')
        ->latest('id')
        ->first();

    expect($activity)->not->toBeNull()
        ->and($activity->log_name)->toBe('documents')
        ->and($activity->properties->get('document_count'))->toBe(1)
        ->and($activity->company_id)->toBe($fixtures['company']->id);
});

test('failed company document expiry alert is logged to activity', function () {
    $fixtures = makeDocumentFixtures();

    app(CompanyDocumentExpiryAlertService::class)->logFailure(
        $fixtures['company'],
        new RuntimeException('SMTP unavailable'),
    );

    $activity = Activity::query()
        ->where('event', 'company_document_expiry_alert_failed')
        ->latest('id')
        ->first();

    expect($activity)->not->toBeNull()
        ->and($activity->log_name)->toBe('documents');
});

// ─────────────────────────────────────────────────────────────────────────────
// Regression: existing Employee Document alerts unchanged
// ─────────────────────────────────────────────────────────────────────────────

test('existing employee document expiry alerts still use email template TO/CC presets', function () {
    Mail::fake();
    Carbon::setTestNow('2026-06-01');

    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();

    $doc = createEmployeePdfDocument(
        $company->id,
        $employee->id,
        $passportType->id,
        "employee-documents/{$company->id}/{$employee->id}/passport/a.pdf",
        'Passport.pdf',
    );
    $doc->update(['expiry_date' => '2026-06-20']);

    app(DocumentExpiryAlertService::class)->sendForCompany($company->id);

    // Must send to hr@example.com (the template TO preset from configureDocumentExpiryAlertTemplate()).
    Mail::assertSent(DocumentExpiryAlertMail::class, fn ($mail) => $mail->hasTo('hr@example.com'));

    // Must NOT send CompanyDocumentExpiryAlertMail.
    Mail::assertNotSent(CompanyDocumentExpiryAlertMail::class);
});

test('inactive membership after configuration does not receive the email', function () {
    Mail::fake();
    Carbon::setTestNow('2026-09-01');
    $fixtures = makeDocumentFixtures();
    $toUser = User::factory()->create(['company_id' => $fixtures['company']->id, 'email' => 'pro@example.com']);
    attachActiveCompanyMembership($fixtures['company']->id, $toUser);

    companyDocNotificationSetting(
        companyId: $fixtures['company']->id,
        enabled: true,
        toUserIds: [$toUser->id],
    );

    expiringCompanyDocument($fixtures['company']->id, $fixtures['passportType']->id, $toUser->id, '2026-09-20');

    DB::table('company_user')
        ->where('company_id', $fixtures['company']->id)
        ->where('user_id', $toUser->id)
        ->update(['status' => 'inactive']);

    app(CompanyDocumentExpiryAlertService::class)->sendForCompany($fixtures['company']->id);

    Mail::assertNothingSent();
});

test('removed membership after configuration does not receive the email', function () {
    Mail::fake();
    Carbon::setTestNow('2026-09-01');
    $fixtures = makeDocumentFixtures();
    $toUser = User::factory()->create(['company_id' => $fixtures['company']->id, 'email' => 'pro@example.com']);
    attachActiveCompanyMembership($fixtures['company']->id, $toUser);

    companyDocNotificationSetting(
        companyId: $fixtures['company']->id,
        enabled: true,
        toUserIds: [$toUser->id],
    );

    expiringCompanyDocument($fixtures['company']->id, $fixtures['passportType']->id, $toUser->id, '2026-09-20');

    DB::table('company_user')
        ->where('company_id', $fixtures['company']->id)
        ->where('user_id', $toUser->id)
        ->delete();

    app(CompanyDocumentExpiryAlertService::class)->sendForCompany($fixtures['company']->id);

    Mail::assertNothingSent();
});

test('no valid TO recipients means nothing is sent even when CC remains eligible', function () {
    Mail::fake();
    Carbon::setTestNow('2026-09-01');
    $fixtures = makeDocumentFixtures();
    $toUser = User::factory()->create(['company_id' => $fixtures['company']->id, 'email' => 'pro@example.com']);
    $ccUser = User::factory()->create(['company_id' => $fixtures['company']->id, 'email' => 'finance@example.com']);
    attachActiveCompanyMembership($fixtures['company']->id, $toUser);
    attachActiveCompanyMembership($fixtures['company']->id, $ccUser);

    companyDocNotificationSetting(
        companyId: $fixtures['company']->id,
        enabled: true,
        toUserIds: [$toUser->id],
        ccUserIds: [$ccUser->id],
    );

    expiringCompanyDocument($fixtures['company']->id, $fixtures['passportType']->id, $toUser->id, '2026-09-20');

    DB::table('company_user')
        ->where('company_id', $fixtures['company']->id)
        ->where('user_id', $toUser->id)
        ->update(['status' => 'inactive']);

    app(CompanyDocumentExpiryAlertService::class)->sendForCompany($fixtures['company']->id);

    Mail::assertNothingSent();
});

test('multiple TO recipients remain TO and CC recipients remain CC', function () {
    Mail::fake();
    Carbon::setTestNow('2026-09-01');
    $fixtures = makeDocumentFixtures();
    $pro = User::factory()->create(['company_id' => $fixtures['company']->id, 'email' => 'pro@example.com']);
    $gm = User::factory()->create(['company_id' => $fixtures['company']->id, 'email' => 'gm@example.com']);
    $ops = User::factory()->create(['company_id' => $fixtures['company']->id, 'email' => 'ops@example.com']);
    $finance = User::factory()->create(['company_id' => $fixtures['company']->id, 'email' => 'finance@example.com']);

    foreach ([$pro, $gm, $ops, $finance] as $member) {
        attachActiveCompanyMembership($fixtures['company']->id, $member);
    }

    companyDocNotificationSetting(
        companyId: $fixtures['company']->id,
        enabled: true,
        toUserIds: [$pro->id, $gm->id, $ops->id],
        ccUserIds: [$finance->id],
    );

    expiringCompanyDocument($fixtures['company']->id, $fixtures['passportType']->id, $pro->id, '2026-09-20');

    app(CompanyDocumentExpiryAlertService::class)->sendForCompany($fixtures['company']->id);

    Mail::assertSent(CompanyDocumentExpiryAlertMail::class, function (CompanyDocumentExpiryAlertMail $mail) use ($pro, $gm, $ops, $finance) {
        return $mail->hasTo($pro->email)
            && $mail->hasTo($gm->email)
            && $mail->hasTo($ops->email)
            && $mail->hasCc($finance->email)
            && ! $mail->hasCc($pro->email)
            && ! $mail->hasCc($gm->email)
            && ! $mail->hasCc($ops->email)
            && ! $mail->hasTo($finance->email);
    });
});

test('duplicate email between TO and CC is kept only as TO', function () {
    Mail::fake();
    Carbon::setTestNow('2026-09-01');
    $fixtures = makeDocumentFixtures();
    $toUser = User::factory()->create(['company_id' => $fixtures['company']->id, 'email' => 'shared@example.com']);
    attachActiveCompanyMembership($fixtures['company']->id, $toUser);

    companyDocNotificationSetting(
        companyId: $fixtures['company']->id,
        enabled: true,
        toUserIds: [$toUser->id],
        ccUserIds: [$toUser->id],
    );

    expiringCompanyDocument($fixtures['company']->id, $fixtures['passportType']->id, $toUser->id, '2026-09-20');

    app(CompanyDocumentExpiryAlertService::class)->sendForCompany($fixtures['company']->id);

    Mail::assertSent(CompanyDocumentExpiryAlertMail::class, function (CompanyDocumentExpiryAlertMail $mail) use ($toUser) {
        return $mail->hasTo($toUser->email)
            && ! $mail->hasCc($toUser->email);
    });
});

test('inactive TO is skipped while remaining TO and CC still receive the correct roles', function () {
    Mail::fake();
    Carbon::setTestNow('2026-09-01');
    $fixtures = makeDocumentFixtures();
    $pro = User::factory()->create(['company_id' => $fixtures['company']->id, 'email' => 'pro@example.com']);
    $gm = User::factory()->create(['company_id' => $fixtures['company']->id, 'email' => 'gm@example.com']);
    $ops = User::factory()->create(['company_id' => $fixtures['company']->id, 'email' => 'ops@example.com']);
    $finance = User::factory()->create(['company_id' => $fixtures['company']->id, 'email' => 'finance@example.com']);

    foreach ([$pro, $gm, $ops, $finance] as $member) {
        attachActiveCompanyMembership($fixtures['company']->id, $member);
    }

    companyDocNotificationSetting(
        companyId: $fixtures['company']->id,
        enabled: true,
        toUserIds: [$pro->id, $gm->id, $ops->id],
        ccUserIds: [$finance->id],
    );

    expiringCompanyDocument($fixtures['company']->id, $fixtures['passportType']->id, $pro->id, '2026-09-20');

    DB::table('company_user')
        ->where('company_id', $fixtures['company']->id)
        ->where('user_id', $ops->id)
        ->update(['status' => 'inactive']);

    app(CompanyDocumentExpiryAlertService::class)->sendForCompany($fixtures['company']->id);

    Mail::assertSent(CompanyDocumentExpiryAlertMail::class, function (CompanyDocumentExpiryAlertMail $mail) use ($pro, $gm, $ops, $finance) {
        return $mail->hasTo($pro->email)
            && $mail->hasTo($gm->email)
            && ! $mail->hasTo($ops->email)
            && ! $mail->hasCc($ops->email)
            && $mail->hasCc($finance->email);
    });
});

test('disabled company document email template does not stop delivery', function () {
    Mail::fake();
    Carbon::setTestNow('2026-09-01');
    $fixtures = makeDocumentFixtures();
    $toUser = User::factory()->create(['company_id' => $fixtures['company']->id, 'email' => 'pro@example.com']);
    attachActiveCompanyMembership($fixtures['company']->id, $toUser);

    EmailTemplate::query()->updateOrCreate(
        ['slug' => 'company_document_expiry_alert'],
        [
            'label' => 'Company document expiry alert',
            'category' => 'notification',
            'subject' => 'Unused subject',
            'body_html' => 'Unused body',
            'enabled' => false,
            'include_company_footer' => true,
        ],
    );

    companyDocNotificationSetting(
        companyId: $fixtures['company']->id,
        enabled: true,
        toUserIds: [$toUser->id],
    );

    expiringCompanyDocument($fixtures['company']->id, $fixtures['passportType']->id, $toUser->id, '2026-09-20');

    app(CompanyDocumentExpiryAlertService::class)->sendForCompany($fixtures['company']->id);

    Mail::assertSent(CompanyDocumentExpiryAlertMail::class, fn ($mail) => $mail->hasTo($toUser->email));
});

test('company document configuration does not change employee document expiry recipients', function () {
    Mail::fake();
    Carbon::setTestNow('2026-06-01');
    $fixtures = makeDocumentFixtures();
    $companyUser = User::factory()->create(['company_id' => $fixtures['company']->id, 'email' => 'pro@example.com']);
    attachActiveCompanyMembership($fixtures['company']->id, $companyUser);

    companyDocNotificationSetting(
        companyId: $fixtures['company']->id,
        enabled: true,
        toUserIds: [$companyUser->id],
    );

    $doc = createEmployeePdfDocument(
        $fixtures['company']->id,
        $fixtures['employee']->id,
        $fixtures['passportType']->id,
        "employee-documents/{$fixtures['company']->id}/{$fixtures['employee']->id}/passport/b.pdf",
        'Passport.pdf',
    );
    $doc->update(['expiry_date' => '2026-06-20']);

    app(DocumentExpiryAlertService::class)->sendForCompany($fixtures['company']->id);

    Mail::assertSent(DocumentExpiryAlertMail::class, fn ($mail) => $mail->hasTo('hr@example.com') && ! $mail->hasTo($companyUser->email));
    Mail::assertNotSent(CompanyDocumentExpiryAlertMail::class);
});

test('company document expiry job is unique per company', function () {
    Queue::fake();
    $fixtures = makeDocumentFixtures();
    $other = makeDocumentFixtures();

    expect(new SendCompanyDocumentExpiryAlertJob($fixtures['company']->id))->toBeInstanceOf(ShouldBeUnique::class)
        ->and((new SendCompanyDocumentExpiryAlertJob($fixtures['company']->id))->uniqueId())
        ->toBe('company-document-expiry-alert-'.$fixtures['company']->id);

    SendCompanyDocumentExpiryAlertJob::dispatch($fixtures['company']->id);
    SendCompanyDocumentExpiryAlertJob::dispatch($fixtures['company']->id);
    SendCompanyDocumentExpiryAlertJob::dispatch($other['company']->id);

    Queue::assertPushed(SendCompanyDocumentExpiryAlertJob::class, 2);
    Queue::assertPushed(SendCompanyDocumentExpiryAlertJob::class, fn ($job) => $job->companyId === $fixtures['company']->id);
    Queue::assertPushed(SendCompanyDocumentExpiryAlertJob::class, fn ($job) => $job->companyId === $other['company']->id);
});
