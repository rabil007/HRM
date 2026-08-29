<?php

use App\Enums\DocumentRecipientAction;
use App\Enums\DocumentRecipientRequestDeliveryChannel;
use App\Enums\DocumentRecipientRequestDeliveryPurpose;
use App\Enums\DocumentRecipientRequestDeliveryStatus;
use App\Enums\DocumentRecipientRequestEventType;
use App\Enums\DocumentRecipientRequestStatus;
use App\Enums\DocumentSigningFlowStatus;
use App\Jobs\DeliverDocumentRecipientRequestEmailJob;
use App\Models\Company;
use App\Models\DocumentInstanceVersion;
use App\Models\DocumentRecipientAutomationSetting;
use App\Models\DocumentRecipientRequest;
use App\Models\DocumentRecipientRequestDelivery;
use App\Models\DocumentRecipientRequestEvent;
use App\Models\EmailTemplate;
use App\Models\User;
use App\Support\Documents\RecipientRequests\Actions\CreateDocumentRecipientRequest;
use App\Support\Documents\RecipientRequests\Actions\ExpireDocumentRecipientRequest;
use App\Support\Documents\RecipientRequests\Actions\SubmitDocumentRecipientAcknowledgement;
use App\Support\Documents\RecipientRequests\Actions\SubmitDocumentRecipientSignature;
use App\Support\Documents\RecipientRequests\Automation\DocumentRecipientAutomationPolicy;
use App\Support\Documents\RecipientRequests\Automation\ReconcileDocumentRecipientRequests;
use App\Support\Documents\RecipientRequests\DocumentRecipientRequestToken;
use App\Support\Documents\Signing\Actions\StartDocumentSigningFlow;
use App\Support\Documents\Signing\Actions\StoreDocumentSigningPreset;
use App\Support\Documents\Signing\DocumentSigningFlowPresenter;
use Database\Seeders\EmailTemplatesSeeder;
use Database\Seeders\PermissionsSeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/../../Support/document-recipient-request-fixtures.php';
require_once __DIR__.'/../../Support/document-workflow-fixtures.php';
require_once __DIR__.'/../../Support/document-fixtures.php';
require_once __DIR__.'/../../Support/spatie.php';

beforeEach(function () {
    $this->seed(PermissionsSeeder::class);
    Storage::fake('local');
    Queue::fake();
    EmailTemplatesSeeder::seedDocumentRecipientActionRequestTemplate();
    EmailTemplatesSeeder::seedDocumentRecipientActionReminderTemplate();
});

function enableRecipientReminders(Company $company, array $days = [7, 3, 1]): DocumentRecipientAutomationSetting
{
    return DocumentRecipientAutomationSetting::query()->updateOrCreate(
        ['company_id' => $company->id],
        [
            'reminders_enabled' => true,
            'reminder_days_before_expiry' => $days,
        ],
    );
}

function createSubjectSignRequest(Company $company, $document, User $requester): array
{
    if ($document->employee !== null) {
        $document->employee->update([
            'work_email' => $document->employee->work_email ?: 'subject-recipient@example.test',
            'personal_email' => $document->employee->personal_email,
        ]);
    }

    return app(CreateDocumentRecipientRequest::class)->handle(
        $document->fresh(['employee']),
        DocumentRecipientAction::Sign,
        $requester,
        $company->id,
    );
}

test('default automation policy has reminders disabled', function () {
    $company = makeDocumentFixtures()['company'];

    $resolved = app(DocumentRecipientAutomationPolicy::class)->resolveForCompany($company->id);

    expect($resolved['reminders_enabled'])->toBeFalse()
        ->and($resolved['reminder_days_before_expiry'])->toBe([7, 3, 1]);
});

test('authorized user can enable reminder settings and store normalized days', function () {
    $company = makeDocumentFixtures()['company'];
    $user = User::factory()->create();
    grantCompanyPermissions($user, $company, [
        'documents.recipient-automation.view',
        'documents.recipient-automation.update',
        'documents.recipient-requests.view',
    ]);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->put(route('organization.documents.recipient-automation-settings.update'), [
            'reminders_enabled' => true,
            'reminder_days_before_expiry' => [1, 7, 3],
        ])
        ->assertRedirect();

    $settings = DocumentRecipientAutomationSetting::query()->where('company_id', $company->id)->first();

    expect($settings)->not->toBeNull()
        ->and($settings->reminders_enabled)->toBeTrue()
        ->and($settings->reminder_days_before_expiry)->toBe([7, 3, 1]);
});

test('reminder day validation rejects invalid offsets', function (array $days) {
    $company = makeDocumentFixtures()['company'];
    $user = User::factory()->create();
    grantCompanyPermissions($user, $company, [
        'documents.recipient-automation.update',
    ]);

    $response = $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->from(route('organization.documents.requests', ['tab' => 'recipient']))
        ->put(route('organization.documents.recipient-automation-settings.update'), [
            'reminders_enabled' => true,
            'reminder_days_before_expiry' => $days,
        ]);

    $response->assertSessionHasErrors();
    $bag = session('errors');
    $keys = $bag instanceof ViewErrorBag
        ? $bag->getBag('default')->keys()
        : [];
    expect(collect($keys)->contains(fn (string $key) => str_starts_with($key, 'reminder_days_before_expiry')))->toBeTrue();
})->with([
    'zero' => [[0, 1]],
    'fourteen' => [[14, 3]],
    'over thirteen' => [[15]],
    'too many' => [[1, 2, 3, 4, 5, 6]],
    'duplicates' => [[3, 3]],
]);

test('recipient automation update requires permission and is company scoped', function () {
    $companyA = makeDocumentFixtures()['company'];
    $companyB = makeDocumentFixtures()['company'];
    $user = User::factory()->create();
    grantCompanyPermissions($user, $companyA, [
        'documents.recipient-automation.update',
    ]);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $companyA->id])
        ->put(route('organization.documents.recipient-automation-settings.update'), [
            'reminders_enabled' => true,
            'reminder_days_before_expiry' => [7, 3, 1],
        ])
        ->assertRedirect();

    expect(DocumentRecipientAutomationSetting::query()->where('company_id', $companyA->id)->exists())->toBeTrue()
        ->and(DocumentRecipientAutomationSetting::query()->where('company_id', $companyB->id)->exists())->toBeFalse();

    $outsider = User::factory()->create();
    grantCompanyPermissions($outsider, $companyA, ['documents.recipient-requests.view']);

    $this->actingAs($outsider)
        ->withSession(['current_company_id' => $companyA->id])
        ->put(route('organization.documents.recipient-automation-settings.update'), [
            'reminders_enabled' => true,
            'reminder_days_before_expiry' => [5, 1],
        ])
        ->assertForbidden();
});

test('new requests snapshot reminder policy and later settings changes do not rewrite them', function () {
    ['company' => $company, 'document' => $document] = makeRecipientFixturesWithSignaturePlacement(
        defaultSignaturePlacementConfig(),
    );
    enableRecipientReminders($company, [7, 3, 1]);
    $requester = User::factory()->create();

    $first = createSubjectSignRequest($company, $document, $requester);
    $requestA = $first['request'];

    expect($requestA->reminder_policy_snapshot)->toMatchArray([
        'schema_version' => 1,
        'enabled' => true,
        'days_before_expiry' => [7, 3, 1],
    ]);

    enableRecipientReminders($company, [5, 1]);
    $requestA->refresh();

    expect($requestA->reminder_policy_snapshot['days_before_expiry'])->toBe([7, 3, 1]);

    $other = makeRecipientFixturesWithSignaturePlacement(defaultSignaturePlacementConfig());
    enableRecipientReminders($other['company'], [5, 1]);
    $requestB = createSubjectSignRequest($other['company'], $other['document'], $requester)['request'];

    expect($requestB->reminder_policy_snapshot['days_before_expiry'])->toBe([5, 1]);
});

test('null reminder snapshot never queues automatic reminders', function () {
    ['company' => $company, 'document' => $document] = makeRecipientFixturesWithSignaturePlacement(
        defaultSignaturePlacementConfig(),
    );
    $requester = User::factory()->create();
    $created = createSubjectSignRequest($company, $document, $requester);
    $request = $created['request'];

    $request->forceFill([
        'reminder_policy_snapshot' => null,
        'expires_at' => now()->addHours(12),
    ])->save();

    DocumentRecipientRequestDelivery::query()
        ->where('document_recipient_request_id', $request->id)
        ->delete();

    app(ReconcileDocumentRecipientRequests::class)->handle($company->id);

    expect(
        DocumentRecipientRequestDelivery::query()
            ->where('document_recipient_request_id', $request->id)
            ->where('purpose', DocumentRecipientRequestDeliveryPurpose::Reminder)
            ->count(),
    )->toBe(0);
});

test('seven day reminder is created once when due and not before', function () {
    Carbon::setTestNow('2026-09-01 10:30:00');

    ['company' => $company, 'document' => $document] = makeRecipientFixturesWithSignaturePlacement(
        defaultSignaturePlacementConfig(),
    );
    enableRecipientReminders($company, [7, 3, 1]);
    $requester = User::factory()->create();
    $created = createSubjectSignRequest($company, $document, $requester);
    $request = $created['request'];

    $request->forceFill([
        'expires_at' => Carbon::parse('2026-09-12 10:30:00'),
        'reminder_policy_snapshot' => [
            'schema_version' => 1,
            'enabled' => true,
            'days_before_expiry' => [7, 3, 1],
        ],
    ])->save();

    DocumentRecipientRequestDelivery::query()
        ->where('document_recipient_request_id', $request->id)
        ->where('purpose', DocumentRecipientRequestDeliveryPurpose::Reminder)
        ->delete();

    Carbon::setTestNow('2026-09-04 10:30:00');
    app(ReconcileDocumentRecipientRequests::class)->handle($company->id);

    expect(
        DocumentRecipientRequestDelivery::query()
            ->where('document_recipient_request_id', $request->id)
            ->where('purpose', DocumentRecipientRequestDeliveryPurpose::Reminder)
            ->count(),
    )->toBe(0);

    Carbon::setTestNow('2026-09-05 10:30:00');
    app(ReconcileDocumentRecipientRequests::class)->handle($company->id);
    app(ReconcileDocumentRecipientRequests::class)->handle($company->id);

    $reminders = DocumentRecipientRequestDelivery::query()
        ->where('document_recipient_request_id', $request->id)
        ->where('purpose', DocumentRecipientRequestDeliveryPurpose::Reminder)
        ->get();

    expect($reminders)->toHaveCount(1)
        ->and($reminders->first()->automation_key)->toBe('reminder:7d')
        ->and($reminders->first()->scheduled_for?->equalTo(Carbon::parse('2026-09-05 10:30:00')))->toBeTrue()
        ->and($reminders->first()->status)->toBe(DocumentRecipientRequestDeliveryStatus::Queued)
        ->and($reminders->first()->access_token_hash)->not->toBeNull()
        ->and($reminders->first()->access_token_hash)->not->toBe($request->token_hash);

    Carbon::setTestNow();
});

test('missed reminder windows suppress older slots and queue only the nearest', function () {
    Carbon::setTestNow('2026-09-12 00:00:00');

    ['company' => $company, 'document' => $document] = makeRecipientFixturesWithSignaturePlacement(
        defaultSignaturePlacementConfig(),
    );
    enableRecipientReminders($company, [7, 3, 1]);
    $requester = User::factory()->create();
    $created = createSubjectSignRequest($company, $document, $requester);
    $request = $created['request'];

    $request->forceFill([
        'expires_at' => Carbon::parse('2026-09-12 12:00:00'),
        'reminder_policy_snapshot' => [
            'schema_version' => 1,
            'enabled' => true,
            'days_before_expiry' => [7, 3, 1],
        ],
    ])->save();

    DocumentRecipientRequestDelivery::query()
        ->where('document_recipient_request_id', $request->id)
        ->where('purpose', DocumentRecipientRequestDeliveryPurpose::Reminder)
        ->delete();

    app(ReconcileDocumentRecipientRequests::class)->handle($company->id);
    app(ReconcileDocumentRecipientRequests::class)->handle($company->id);

    $byKey = DocumentRecipientRequestDelivery::query()
        ->where('document_recipient_request_id', $request->id)
        ->where('purpose', DocumentRecipientRequestDeliveryPurpose::Reminder)
        ->get()
        ->keyBy('automation_key');

    expect($byKey)->toHaveCount(3)
        ->and($byKey['reminder:7d']->status)->toBe(DocumentRecipientRequestDeliveryStatus::Suppressed)
        ->and($byKey['reminder:7d']->failure_category)->toBe('reminder_window_missed')
        ->and($byKey['reminder:3d']->status)->toBe(DocumentRecipientRequestDeliveryStatus::Suppressed)
        ->and($byKey['reminder:3d']->failure_category)->toBe('reminder_window_missed')
        ->and($byKey['reminder:1d']->status)->toBe(DocumentRecipientRequestDeliveryStatus::Queued)
        ->and($byKey['reminder:1d']->failure_category)->toBeNull();

    Carbon::setTestNow();
});

test('scheduler expires awaiting requests idempotently and cleans deliveries', function () {
    Carbon::setTestNow('2026-09-12 11:00:00');

    ['company' => $company, 'document' => $document] = makeRecipientFixturesWithSignaturePlacement(
        defaultSignaturePlacementConfig(),
    );
    $requester = User::factory()->create();
    $created = createSubjectSignRequest($company, $document, $requester);
    $request = $created['request'];

    $queued = DocumentRecipientRequestDelivery::query()
        ->where('document_recipient_request_id', $request->id)
        ->where('purpose', DocumentRecipientRequestDeliveryPurpose::Initial)
        ->first();

    expect($queued)->not->toBeNull();

    $queued->forceFill([
        'status' => DocumentRecipientRequestDeliveryStatus::Queued,
        'failure_category' => null,
        'failed_at' => null,
        'destination_snapshot' => 'subject-recipient@example.test',
        'access_token_hash' => DocumentRecipientRequestToken::hash(DocumentRecipientRequestToken::generate()),
        'revoked_at' => null,
    ])->save();

    $sentReminder = DocumentRecipientRequestDelivery::query()->create([
        'company_id' => $company->id,
        'document_recipient_request_id' => $request->id,
        'channel' => DocumentRecipientRequestDeliveryChannel::Email,
        'purpose' => DocumentRecipientRequestDeliveryPurpose::Reminder,
        'automation_key' => 'reminder:1d',
        'scheduled_for' => now()->subDay(),
        'delivery_sequence' => ((int) $queued->delivery_sequence) + 1,
        'destination_snapshot' => 'employee@example.test',
        'template_slug' => 'document_recipient_action_reminder',
        'access_token_hash' => DocumentRecipientRequestToken::hash(DocumentRecipientRequestToken::generate()),
        'status' => DocumentRecipientRequestDeliveryStatus::Sent,
        'sent_at' => now()->subHour(),
    ]);

    $request->forceFill(['expires_at' => now()->subMinute()])->save();

    $this->artisan('documents:reconcile-recipient-requests', ['--company' => $company->id])
        ->assertSuccessful();
    $this->artisan('documents:reconcile-recipient-requests', ['--company' => $company->id])
        ->assertSuccessful();

    $request->refresh();
    $queued->refresh();
    $sentReminder->refresh();

    expect($request->status)->toBe(DocumentRecipientRequestStatus::Expired)
        ->and(DocumentRecipientRequestEvent::query()
            ->where('document_recipient_request_id', $request->id)
            ->where('event', DocumentRecipientRequestEventType::RequestExpired)
            ->count())->toBe(1)
        ->and($queued->status)->toBe(DocumentRecipientRequestDeliveryStatus::Suppressed)
        ->and($queued->failure_category)->toBe('request_expired')
        ->and($queued->revoked_at)->not->toBeNull()
        ->and($sentReminder->status)->toBe(DocumentRecipientRequestDeliveryStatus::Sent)
        ->and($sentReminder->revoked_at)->not->toBeNull();

    Carbon::setTestNow();
});

test('terminal request statuses are never expired by reconciliation', function (DocumentRecipientRequestStatus $status) {
    ['company' => $company, 'document' => $document] = makeRecipientFixturesWithSignaturePlacement(
        defaultSignaturePlacementConfig(),
    );
    $requester = User::factory()->create();
    $created = createSubjectSignRequest($company, $document, $requester);
    $request = $created['request'];

    $request->forceFill([
        'status' => $status,
        'expires_at' => now()->subDay(),
        'completed_at' => $status === DocumentRecipientRequestStatus::Completed ? now() : null,
        'cancelled_at' => $status === DocumentRecipientRequestStatus::Cancelled ? now() : null,
    ])->save();

    app(ExpireDocumentRecipientRequest::class)->handle($request);

    expect($request->fresh()->status)->toBe($status);
})->with([
    'completed' => DocumentRecipientRequestStatus::Completed,
    'cancelled' => DocumentRecipientRequestStatus::Cancelled,
    'superseded' => DocumentRecipientRequestStatus::Superseded,
]);

test('expiring a flow-linked request blocks the signing flow after commit', function () {
    $fixtures = makeRecipientFixturesWithSignaturePlacement(defaultSignaturePlacementConfig());
    $company = $fixtures['company'];

    $hr = User::factory()->create();
    grantCompanyPermissions($hr, $company, [
        'documents.recipient-requests.create',
        'documents.signing-presets.create',
    ]);

    $preset = app(StoreDocumentSigningPreset::class)->handle(
        $hr,
        $company->id,
        'Subject only',
        null,
        [
            ['recipient_role' => 'subject'],
        ],
    );

    $started = app(StartDocumentSigningFlow::class)->handle(
        $fixtures['document'],
        $hr,
        $company->id,
        $preset->id,
    );

    $flow = $started['flow'];
    $request = $started['request'];

    $request->forceFill(['expires_at' => now()->subMinute()])->save();

    app(ReconcileDocumentRecipientRequests::class)->handle($company->id);

    $flow->refresh();
    $request->refresh();
    $presented = app(DocumentSigningFlowPresenter::class)->forDocumentShow($flow);

    expect($request->status)->toBe(DocumentRecipientRequestStatus::Expired)
        ->and($flow->status)->toBe(DocumentSigningFlowStatus::Blocked)
        ->and($presented['can_retry'])->toBeFalse();
});

test('locked signature submit rechecks expiry against the database', function () {
    ['company' => $company, 'document' => $document, 'instance' => $instance] = makeRecipientFixturesWithSignaturePlacement(
        defaultSignaturePlacementConfig(),
    );
    $requester = User::factory()->create();
    $created = createSubjectSignRequest($company, $document, $requester);
    $stale = $created['request'];

    expect($stale->expires_at->isFuture())->toBeTrue();

    DocumentRecipientRequest::query()->whereKey($stale->id)->update([
        'expires_at' => now()->subMinute(),
    ]);

    $versionsBefore = DocumentInstanceVersion::query()
        ->where('document_instance_id', $instance->id)
        ->count();

    expect(fn () => app(SubmitDocumentRecipientSignature::class)->handle(
        $stale,
        [
            'signed_name' => 'Employee Name',
            'signature_data' => validSignatureDataUri(),
            'consent' => true,
        ],
        Request::create('/document-action/test', 'POST'),
    ))->toThrow(ValidationException::class);

    expect($stale->fresh()->status)->toBe(DocumentRecipientRequestStatus::Expired)
        ->and(DocumentInstanceVersion::query()->where('document_instance_id', $instance->id)->count())
        ->toBe($versionsBefore)
        ->and($stale->fresh()->result_document_instance_version_id)->toBeNull();
});

test('locked acknowledgement submit rechecks expiry against the database', function () {
    ['company' => $company, 'document' => $document] = makeRecipientFixturesWithSignaturePlacement();
    $requester = User::factory()->create();
    $created = app(CreateDocumentRecipientRequest::class)->handle(
        $document,
        DocumentRecipientAction::Acknowledge,
        $requester,
        $company->id,
    );
    $stale = $created['request'];

    DocumentRecipientRequest::query()->whereKey($stale->id)->update([
        'expires_at' => now()->subMinute(),
    ]);

    expect(fn () => app(SubmitDocumentRecipientAcknowledgement::class)->handle(
        $stale,
        [
            'name' => 'Employee Name',
            'acknowledgement' => true,
        ],
        Request::create('/document-action/test', 'POST'),
    ))->toThrow(ValidationException::class);

    expect($stale->fresh()->status)->toBe(DocumentRecipientRequestStatus::Expired)
        ->and($stale->fresh()->completed_at)->toBeNull();
});

test('reminder is suppressed when reminder template is disabled and request stays awaiting', function () {
    Carbon::setTestNow('2026-09-05 10:30:00');

    ['company' => $company, 'document' => $document] = makeRecipientFixturesWithSignaturePlacement(
        defaultSignaturePlacementConfig(),
    );
    enableRecipientReminders($company, [7]);
    $requester = User::factory()->create();
    $created = createSubjectSignRequest($company, $document, $requester);
    $request = $created['request'];

    EmailTemplate::query()
        ->where('slug', 'document_recipient_action_reminder')
        ->update(['enabled' => false]);

    $request->forceFill([
        'expires_at' => Carbon::parse('2026-09-12 10:30:00'),
        'reminder_policy_snapshot' => [
            'schema_version' => 1,
            'enabled' => true,
            'days_before_expiry' => [7],
        ],
    ])->save();

    DocumentRecipientRequestDelivery::query()
        ->where('document_recipient_request_id', $request->id)
        ->where('purpose', DocumentRecipientRequestDeliveryPurpose::Reminder)
        ->delete();

    app(ReconcileDocumentRecipientRequests::class)->handle($company->id);

    $reminder = DocumentRecipientRequestDelivery::query()
        ->where('document_recipient_request_id', $request->id)
        ->where('automation_key', 'reminder:7d')
        ->first();

    expect($reminder)->not->toBeNull()
        ->and($reminder->status)->toBe(DocumentRecipientRequestDeliveryStatus::Suppressed)
        ->and($reminder->failure_category)->toBe('email_template_disabled')
        ->and($request->fresh()->status)->toBe(DocumentRecipientRequestStatus::AwaitingAction);

    Carbon::setTestNow();
});

test('subject reminder token resolves the same request without mutating the request token', function () {
    Carbon::setTestNow('2026-09-05 10:30:00');

    ['company' => $company, 'document' => $document] = makeRecipientFixturesWithSignaturePlacement(
        defaultSignaturePlacementConfig(),
    );
    enableRecipientReminders($company, [7]);
    $requester = User::factory()->create();
    $created = createSubjectSignRequest($company, $document, $requester);
    $request = $created['request'];
    $originalHash = $request->token_hash;
    $rawRequestToken = $created['raw_token'];

    $initial = DocumentRecipientRequestDelivery::query()
        ->where('document_recipient_request_id', $request->id)
        ->where('purpose', DocumentRecipientRequestDeliveryPurpose::Initial)
        ->first();

    $request->forceFill([
        'expires_at' => Carbon::parse('2026-09-12 10:30:00'),
        'reminder_policy_snapshot' => [
            'schema_version' => 1,
            'enabled' => true,
            'days_before_expiry' => [7],
        ],
    ])->save();

    app(ReconcileDocumentRecipientRequests::class)->handle($company->id);

    $reminder = DocumentRecipientRequestDelivery::query()
        ->where('document_recipient_request_id', $request->id)
        ->where('automation_key', 'reminder:7d')
        ->first();

    expect($reminder)->not->toBeNull()
        ->and($reminder->access_token_hash)->not->toBe($originalHash)
        ->and($reminder->access_token_hash)->not->toBe($initial?->access_token_hash)
        ->and($request->fresh()->token_hash)->toBe($originalHash)
        ->and(DocumentRecipientRequestToken::findByRawToken($rawRequestToken)?->is($request->fresh()))->toBeTrue();

    $job = new DeliverDocumentRecipientRequestEmailJob(
        (int) $reminder->id,
        (int) $company->id,
        'not-persisted-raw-token',
    );

    expect($reminder->fresh()->getAttributes())->not->toHaveKey('raw_access_token')
        ->and(array_key_exists('access_token', $reminder->getAttributes()))->toBeFalse();

    unset($job);
    Carbon::setTestNow();
});

test('disabled reminders snapshot enabled false for new requests', function () {
    ['company' => $company, 'document' => $document] = makeRecipientFixturesWithSignaturePlacement(
        defaultSignaturePlacementConfig(),
    );
    $requester = User::factory()->create();
    $request = createSubjectSignRequest($company, $document, $requester)['request'];

    expect($request->reminder_policy_snapshot)->toMatchArray([
        'enabled' => false,
        'days_before_expiry' => [],
    ]);
});
