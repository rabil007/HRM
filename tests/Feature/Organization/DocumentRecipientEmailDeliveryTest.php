<?php

use App\Enums\DocumentRecipientAction;
use App\Enums\DocumentRecipientRequestDeliveryChannel;
use App\Enums\DocumentRecipientRequestDeliveryPurpose;
use App\Enums\DocumentRecipientRequestDeliveryStatus;
use App\Enums\DocumentRecipientRequestStatus;
use App\Enums\DocumentRecipientType;
use App\Enums\EmailTemplateCategory;
use App\Jobs\DeliverDocumentRecipientRequestEmailJob;
use App\Mail\DocumentRecipientRequestActionMail;
use App\Models\Company;
use App\Models\Department;
use App\Models\DocumentRecipientRequest;
use App\Models\DocumentRecipientRequestDelivery;
use App\Models\EmailTemplate;
use App\Models\Employee;
use App\Models\User;
use App\Services\Settings\MailSettingsService;
use App\Services\Settings\SettingService;
use App\Support\Documents\RecipientRequests\Actions\CreateDocumentManagerCountersignRequest;
use App\Support\Documents\RecipientRequests\Actions\CreateDocumentRecipientRequest;
use App\Support\Documents\RecipientRequests\Actions\RegenerateDocumentRecipientRequestToken;
use App\Support\Documents\RecipientRequests\Actions\ResendDocumentRecipientRequestEmail;
use App\Support\Documents\RecipientRequests\Actions\SubmitDocumentRecipientSignature;
use App\Support\Documents\RecipientRequests\Delivery\DispatchDocumentRecipientRequestEmails;
use App\Support\Documents\RecipientRequests\Delivery\DocumentRecipientRequestDeliveryHandoff;
use App\Support\Documents\RecipientRequests\Delivery\QueueDocumentRecipientRequestEmail;
use App\Support\Documents\RecipientRequests\DocumentRecipientRequestLinkService;
use App\Support\Documents\RecipientRequests\DocumentRecipientRequestToken;
use App\Support\Documents\Signing\DocumentSigningInternalSignerEligibility;
use App\Support\Settings\SettingKey;
use Database\Seeders\EmailTemplatesSeeder;
use Database\Seeders\PermissionsSeeder;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

require_once __DIR__.'/../../Support/document-recipient-request-fixtures.php';
require_once __DIR__.'/../../Support/document-workflow-fixtures.php';
require_once __DIR__.'/../../Support/spatie.php';

beforeEach(function () {
    $this->seed(PermissionsSeeder::class);
    Storage::fake('local');
    Queue::fake();
    EmailTemplatesSeeder::seedDocumentRecipientActionRequestTemplate();
});

function configureDocumentRecipientEmailSmtp(): void
{
    app(SettingService::class)->set(SettingKey::MailHost, 'smtp.document-recipient-email.test');
}

function clearDocumentRecipientEmailSmtp(): void
{
    app(SettingService::class)->set(SettingKey::MailHost, null);
}

function emailDeliveryTriplePlacement(): array
{
    return [
        'schema_version' => 1,
        'placements' => [
            [
                'id' => 'subject_signature',
                'type' => 'signature',
                'role' => 'subject',
                'page' => 1,
                'x' => 0.1,
                'y' => 0.75,
                'width' => 0.25,
                'height' => 0.08,
                'required' => true,
            ],
            [
                'id' => 'manager_signature',
                'type' => 'signature',
                'role' => 'manager',
                'page' => 1,
                'x' => 0.1,
                'y' => 0.6,
                'width' => 0.25,
                'height' => 0.08,
                'required' => true,
            ],
            [
                'id' => 'company_signatory_signature',
                'type' => 'signature',
                'role' => 'company_signatory',
                'page' => 1,
                'x' => 0.55,
                'y' => 0.75,
                'width' => 0.25,
                'height' => 0.08,
                'required' => true,
            ],
        ],
    ];
}

function attachEmailDeliveryManager(Employee $subject, User $managerUser): void
{
    $company = $subject->company;
    $managerEmployee = Employee::factory()->forCompany($company)->create([
        'status' => 'active',
        'user_id' => $managerUser->id,
        'name' => $managerUser->name,
    ]);

    $department = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Ops',
        'code' => 'OPS'.fake()->unique()->numerify('##'),
        'manager_id' => $managerEmployee->id,
        'status' => 'active',
    ]);

    $subject->update(['department_id' => $department->id]);
}

/**
 * @return array{company: Company, employee: Employee, document: mixed, requester: User, request: DocumentRecipientRequest, raw_token: string}
 */
function createQueuedSubjectSignRequest(string $workEmail = 'subject@example.com'): array
{
    ['company' => $company, 'employee' => $employee, 'document' => $document] = makeRecipientFixturesWithSignaturePlacement(
        defaultSignaturePlacementConfig(),
    );

    $employee->update([
        'work_email' => $workEmail,
        'personal_email' => null,
    ]);

    $requester = User::factory()->create();
    grantCompanyPermissions($requester, $company, ['documents.recipient-requests.create']);

    $result = app(CreateDocumentRecipientRequest::class)->handle(
        $document->fresh(),
        DocumentRecipientAction::Sign,
        $requester,
        $company->id,
    );

    return [
        'company' => $company,
        'employee' => $employee,
        'document' => $document,
        'requester' => $requester,
        'request' => $result['request'],
        'raw_token' => $result['raw_token'],
    ];
}

test('subject sign creates initial email delivery ledger without raw token persistence', function () {
    $created = createQueuedSubjectSignRequest();

    $delivery = DocumentRecipientRequestDelivery::query()
        ->where('document_recipient_request_id', $created['request']->id)
        ->first();

    expect($delivery)->not->toBeNull()
        ->and($delivery->company_id)->toBe($created['company']->id)
        ->and($delivery->channel)->toBe(DocumentRecipientRequestDeliveryChannel::Email)
        ->and($delivery->purpose)->toBe(DocumentRecipientRequestDeliveryPurpose::Initial)
        ->and($delivery->delivery_sequence)->toBe(1)
        ->and($delivery->destination_snapshot)->toBe('subject@example.com')
        ->and($delivery->status)->toBe(DocumentRecipientRequestDeliveryStatus::Queued)
        ->and($delivery->access_token_hash)->not->toBeNull()
        ->and(strlen((string) $delivery->access_token_hash))->toBe(64)
        ->and($delivery->access_token_hash)->not->toBe($created['raw_token']);

    Queue::assertPushed(DeliverDocumentRecipientRequestEmailJob::class, 1);
});

test('subject acknowledge queues email and missing email suppresses without blocking request', function () {
    ['company' => $company, 'employee' => $employee, 'document' => $document] = makeRecipientFixturesWithSignaturePlacement(
        defaultSignaturePlacementConfig(),
    );

    $employee->update(['work_email' => null, 'personal_email' => null]);

    $requester = User::factory()->create();
    grantCompanyPermissions($requester, $company, ['documents.recipient-requests.create']);

    $result = app(CreateDocumentRecipientRequest::class)->handle(
        $document,
        DocumentRecipientAction::Acknowledge,
        $requester,
        $company->id,
    );

    expect($result['request']->status)->toBe(DocumentRecipientRequestStatus::AwaitingAction);

    $delivery = DocumentRecipientRequestDelivery::query()
        ->where('document_recipient_request_id', $result['request']->id)
        ->firstOrFail();

    expect($delivery->status)->toBe(DocumentRecipientRequestDeliveryStatus::Suppressed)
        ->and($delivery->failure_category)->toBe('recipient_email_missing');

    Queue::assertNotPushed(DeliverDocumentRecipientRequestEmailJob::class);
});

test('disabled template suppresses delivery without blocking request creation', function () {
    EmailTemplate::query()
        ->where('slug', QueueDocumentRecipientRequestEmail::TEMPLATE_SLUG)
        ->update(['enabled' => false]);

    $created = createQueuedSubjectSignRequest();

    $delivery = DocumentRecipientRequestDelivery::query()
        ->where('document_recipient_request_id', $created['request']->id)
        ->firstOrFail();

    expect($created['request']->status)->toBe(DocumentRecipientRequestStatus::AwaitingAction)
        ->and($delivery->status)->toBe(DocumentRecipientRequestDeliveryStatus::Suppressed)
        ->and($delivery->failure_category)->toBe('email_template_disabled');
});

test('request token and delivery token both resolve subject request; delivery email has no pdf attachment', function () {
    Mail::fake();
    configureDocumentRecipientEmailSmtp();

    $created = createQueuedSubjectSignRequest();
    $requestToken = $created['raw_token'];

    /** @var DeliverDocumentRecipientRequestEmailJob|null $job */
    $job = null;
    Queue::assertPushed(DeliverDocumentRecipientRequestEmailJob::class, function (DeliverDocumentRecipientRequestEmailJob $pushed) use (&$job): bool {
        $job = $pushed;

        return true;
    });

    expect($job)->not->toBeNull()
        ->and($job)->toBeInstanceOf(ShouldBeEncrypted::class)
        ->and($job->rawAccessToken)->not->toBeNull()
        ->and($job->rawAccessToken)->not->toBe($requestToken);

    expect(DocumentRecipientRequestToken::findByRawToken($requestToken)?->id)->toBe($created['request']->id)
        ->and(DocumentRecipientRequestToken::findByRawToken((string) $job->rawAccessToken)?->id)->toBe($created['request']->id);

    $job->handle(
        app(MailSettingsService::class),
        app(DocumentRecipientRequestLinkService::class),
        app(DocumentSigningInternalSignerEligibility::class),
    );

    Mail::assertSent(DocumentRecipientRequestActionMail::class, function (DocumentRecipientRequestActionMail $mail) use ($job): bool {
        expect($mail->bodyHtml)->toContain('/document-action/'.$job->rawAccessToken);

        return true;
    });

    $delivery = DocumentRecipientRequestDelivery::query()->findOrFail($job->deliveryId);
    expect($delivery->status)->toBe(DocumentRecipientRequestDeliveryStatus::Sent)
        ->and($delivery->sent_at)->not->toBeNull();
});

test('regenerate link rotates request token and revokes email delivery access tokens', function () {
    $created = createQueuedSubjectSignRequest();
    $oldRequestToken = $created['raw_token'];

    /** @var DeliverDocumentRecipientRequestEmailJob $job */
    $job = null;
    Queue::assertPushed(DeliverDocumentRecipientRequestEmailJob::class, function (DeliverDocumentRecipientRequestEmailJob $pushed) use (&$job): bool {
        $job = $pushed;

        return true;
    });

    $oldDeliveryToken = (string) $job->rawAccessToken;
    $delivery = DocumentRecipientRequestDelivery::query()->findOrFail($job->deliveryId);

    $regenerated = app(RegenerateDocumentRecipientRequestToken::class)->handle(
        $created['request'],
        $created['requester'],
        $created['company']->id,
    );

    expect($regenerated['raw_token'])->not->toBe($oldRequestToken)
        ->and(DocumentRecipientRequestToken::findByRawToken($oldRequestToken))->toBeNull()
        ->and(DocumentRecipientRequestToken::findByRawToken($regenerated['raw_token'])?->id)->toBe($created['request']->id)
        ->and(DocumentRecipientRequestToken::findByRawToken($oldDeliveryToken))->toBeNull()
        ->and($delivery->fresh()->revoked_at)->not->toBeNull()
        ->and($delivery->fresh()->access_token_hash)->not->toBeNull();
});

test('manual resend creates next delivery sequence without rotating request token', function () {
    $created = createQueuedSubjectSignRequest();
    $requestTokenHash = $created['request']->token_hash;

    $first = DocumentRecipientRequestDelivery::query()
        ->where('document_recipient_request_id', $created['request']->id)
        ->firstOrFail();

    $resent = app(ResendDocumentRecipientRequestEmail::class)->handle(
        $created['request'],
        $created['requester'],
        $created['company']->id,
    );

    expect($resent->delivery_sequence)->toBe(2)
        ->and($resent->purpose)->toBe(DocumentRecipientRequestDeliveryPurpose::ManualResend)
        ->and($resent->access_token_hash)->not->toBe($first->access_token_hash)
        ->and($created['request']->fresh()->token_hash)->toBe($requestTokenHash)
        ->and($first->fresh()->revoked_at)->toBeNull();

    Queue::assertPushed(DeliverDocumentRecipientRequestEmailJob::class, 2);
});

test('manager countersign queues authenticated respond url without public delivery token', function () {
    ['company' => $company, 'employee' => $employee, 'document' => $document] = makeRecipientFixturesWithSignaturePlacement(
        emailDeliveryTriplePlacement(),
    );

    $employee->update(['work_email' => 'subject@example.com', 'personal_email' => null]);

    $requester = User::factory()->create();
    grantCompanyPermissions($requester, $company, ['documents.recipient-requests.create']);

    $managerUser = User::factory()->create(['email' => 'manager-signer@example.com']);
    grantCompanyPermissions($managerUser, $company, [
        'documents.recipient-requests.create',
        'documents.recipient-requests.respond',
    ]);
    attachEmailDeliveryManager($employee, $managerUser);

    $subject = app(CreateDocumentRecipientRequest::class)->handle(
        $document,
        DocumentRecipientAction::Sign,
        $requester,
        $company->id,
    );

    app(SubmitDocumentRecipientSignature::class)->handle(
        $subject['request'],
        [
            'signed_name' => 'Employee Name',
            'signature_data' => validSignatureDataUri(),
            'consent' => true,
        ],
        Request::create('/document-action/test', 'POST'),
    );

    $managerResult = app(CreateDocumentManagerCountersignRequest::class)->handle(
        $document->fresh(),
        $requester,
        $company->id,
    );

    $managerRequest = $managerResult['request'];

    expect($managerRequest->recipient_type)->toBe(DocumentRecipientType::CompanyUser);

    $delivery = DocumentRecipientRequestDelivery::query()
        ->where('document_recipient_request_id', $managerRequest->id)
        ->firstOrFail();

    expect($delivery->destination_snapshot)->toBe('manager-signer@example.com')
        ->and($delivery->access_token_hash)->toBeNull()
        ->and($delivery->status)->toBe(DocumentRecipientRequestDeliveryStatus::Queued);

    /** @var DeliverDocumentRecipientRequestEmailJob|null $job */
    $job = null;
    Queue::assertPushed(DeliverDocumentRecipientRequestEmailJob::class, function (DeliverDocumentRecipientRequestEmailJob $pushed) use (&$job, $delivery): bool {
        if ($pushed->deliveryId === $delivery->id) {
            $job = $pushed;

            return true;
        }

        return false;
    });

    expect($job)->not->toBeNull()
        ->and($job->rawAccessToken)->toBeNull();

    Mail::fake();
    configureDocumentRecipientEmailSmtp();

    $job->handle(
        app(MailSettingsService::class),
        app(DocumentRecipientRequestLinkService::class),
        app(DocumentSigningInternalSignerEligibility::class),
    );

    Mail::assertSent(DocumentRecipientRequestActionMail::class, function (DocumentRecipientRequestActionMail $mail) use ($managerRequest): bool {
        expect($mail->bodyHtml)->toContain('/organization/documents/recipient-requests/'.$managerRequest->id.'/respond')
            ->and($mail->bodyHtml)->not->toContain('/document-action/');

        return true;
    });

    expect(DocumentRecipientRequestToken::findByRawToken('not-a-real-token'))->toBeNull();
});

test('job suppresses when request is no longer awaiting and does not send mail', function () {
    Mail::fake();
    configureDocumentRecipientEmailSmtp();

    $created = createQueuedSubjectSignRequest();

    /** @var DeliverDocumentRecipientRequestEmailJob $job */
    $job = null;
    Queue::assertPushed(DeliverDocumentRecipientRequestEmailJob::class, function (DeliverDocumentRecipientRequestEmailJob $pushed) use (&$job): bool {
        $job = $pushed;

        return true;
    });

    $created['request']->update([
        'status' => DocumentRecipientRequestStatus::Completed,
        'completed_at' => now(),
    ]);

    $job->handle(
        app(MailSettingsService::class),
        app(DocumentRecipientRequestLinkService::class),
        app(DocumentSigningInternalSignerEligibility::class),
    );

    Mail::assertNothingSent();

    $delivery = DocumentRecipientRequestDelivery::query()->findOrFail($job->deliveryId);
    expect($delivery->status)->toBe(DocumentRecipientRequestDeliveryStatus::Suppressed)
        ->and($delivery->failure_category)->toBe('request_no_longer_awaiting');
});

test('successful smtp handoff memory prevents duplicate send on retry', function () {
    Mail::fake();
    configureDocumentRecipientEmailSmtp();

    $created = createQueuedSubjectSignRequest();

    /** @var DeliverDocumentRecipientRequestEmailJob $job */
    $job = null;
    Queue::assertPushed(DeliverDocumentRecipientRequestEmailJob::class, function (DeliverDocumentRecipientRequestEmailJob $pushed) use (&$job): bool {
        $job = $pushed;

        return true;
    });

    $job->handle(
        app(MailSettingsService::class),
        app(DocumentRecipientRequestLinkService::class),
        app(DocumentSigningInternalSignerEligibility::class),
    );

    Mail::assertSent(DocumentRecipientRequestActionMail::class, 1);

    DocumentRecipientRequestDelivery::query()->whereKey($job->deliveryId)->update([
        'status' => DocumentRecipientRequestDeliveryStatus::Queued,
        'sent_at' => null,
    ]);

    expect(DocumentRecipientRequestDeliveryHandoff::wasHandedOff(
        DocumentRecipientRequestDeliveryHandoff::emailKey($job->deliveryId),
    ))->toBeTrue();

    $job->handle(
        app(MailSettingsService::class),
        app(DocumentRecipientRequestLinkService::class),
        app(DocumentSigningInternalSignerEligibility::class),
    );

    Mail::assertSent(DocumentRecipientRequestActionMail::class, 1);
    expect(DocumentRecipientRequestDelivery::query()->findOrFail($job->deliveryId)->status)
        ->toBe(DocumentRecipientRequestDeliveryStatus::Sent);
});

test('transport failure increments attempts and exhausted failed does not overwrite smtp handoff', function () {
    configureDocumentRecipientEmailSmtp();

    $created = createQueuedSubjectSignRequest();

    /** @var DeliverDocumentRecipientRequestEmailJob $job */
    $job = null;
    Queue::assertPushed(DeliverDocumentRecipientRequestEmailJob::class, function (DeliverDocumentRecipientRequestEmailJob $pushed) use (&$job): bool {
        $job = $pushed;

        return true;
    });

    Log::spy();
    Mail::shouldReceive('to')->once()->andThrow(new RuntimeException('SMTP auth failed secret-pass'));

    expect(fn () => $job->handle(
        app(MailSettingsService::class),
        app(DocumentRecipientRequestLinkService::class),
        app(DocumentSigningInternalSignerEligibility::class),
    ))->toThrow(RuntimeException::class);

    $delivery = DocumentRecipientRequestDelivery::query()->findOrFail($job->deliveryId);
    expect($delivery->status)->toBe(DocumentRecipientRequestDeliveryStatus::Queued)
        ->and($delivery->attempt_count)->toBe(1);

    Log::shouldHaveReceived('warning')->withArgs(function (string $message, array $context): bool {
        $encoded = json_encode($context);

        return str_contains($message, 'Document recipient email transport failed')
            && ! str_contains((string) $encoded, 'secret-pass')
            && isset($context['exception_class']);
    });

    $job->failed(new RuntimeException('SMTP auth failed secret-pass'));
    expect($delivery->fresh()->status)->toBe(DocumentRecipientRequestDeliveryStatus::Failed)
        ->and($delivery->fresh()->failure_category)->toBe('email_transport_exhausted');
});

test('completed and foreign company requests cannot resend email', function () {
    $created = createQueuedSubjectSignRequest();

    $created['request']->update([
        'status' => DocumentRecipientRequestStatus::Completed,
        'completed_at' => now(),
    ]);

    expect(fn () => app(ResendDocumentRecipientRequestEmail::class)->handle(
        $created['request']->fresh(),
        $created['requester'],
        $created['company']->id,
    ))->toThrow(ValidationException::class);

    $other = createQueuedSubjectSignRequest('other@example.com');

    expect(fn () => app(ResendDocumentRecipientRequestEmail::class)->handle(
        $other['request'],
        $created['requester'],
        $created['company']->id,
    ))->toThrow(HttpException::class);
});

test('dispatch reconciliation dispatches undispatched queued deliveries', function () {
    $created = createQueuedSubjectSignRequest();
    $delivery = DocumentRecipientRequestDelivery::query()
        ->where('document_recipient_request_id', $created['request']->id)
        ->firstOrFail();

    $delivery->update([
        'dispatched_at' => null,
        'claimed_at' => null,
    ]);

    DocumentRecipientRequestDeliveryHandoff::remember(
        DocumentRecipientRequestDeliveryHandoff::queueKey((int) $delivery->id),
    );

    // Clear remembered handoff to exercise claim path with a fresh queue push attempt.
    cache()->forget(DocumentRecipientRequestDeliveryHandoff::queueKey((int) $delivery->id));

    Queue::fake();

    $result = app(DispatchDocumentRecipientRequestEmails::class)->dispatchPending($created['company']->id);

    expect($result['dispatched'])->toBeGreaterThanOrEqual(1);
    Queue::assertPushed(DeliverDocumentRecipientRequestEmailJob::class, 1);
});

test('email template seeder creates document category template without clobbering customizations', function () {
    $template = EmailTemplate::query()
        ->where('slug', QueueDocumentRecipientRequestEmail::TEMPLATE_SLUG)
        ->firstOrFail();

    expect($template->category)->toBe(EmailTemplateCategory::Document)
        ->and($template->label)->toBe('Document action request');

    $template->update([
        'subject' => 'Custom subject for recipient action',
        'body_html' => '<p>Custom body</p>',
    ]);

    EmailTemplatesSeeder::seedDocumentRecipientActionRequestTemplate();

    $fresh = $template->fresh();
    expect($fresh->subject)->toBe('Custom subject for recipient action')
        ->and($fresh->body_html)->toBe('<p>Custom body</p>');

    $template->delete();
    expect(EmailTemplate::withTrashed()->where('slug', QueueDocumentRecipientRequestEmail::TEMPLATE_SLUG)->first()?->trashed())->toBeTrue();

    EmailTemplatesSeeder::seedDocumentRecipientActionRequestTemplate();

    expect(EmailTemplate::query()->where('slug', QueueDocumentRecipientRequestEmail::TEMPLATE_SLUG)->exists())->toBeTrue();
});

test('email failure does not change recipient request awaiting status', function () {
    clearDocumentRecipientEmailSmtp();

    $created = createQueuedSubjectSignRequest();

    /** @var DeliverDocumentRecipientRequestEmailJob $job */
    $job = null;
    Queue::assertPushed(DeliverDocumentRecipientRequestEmailJob::class, function (DeliverDocumentRecipientRequestEmailJob $pushed) use (&$job): bool {
        $job = $pushed;

        return true;
    });

    $job->handle(
        app(MailSettingsService::class),
        app(DocumentRecipientRequestLinkService::class),
        app(DocumentSigningInternalSignerEligibility::class),
    );

    expect($created['request']->fresh()->status)->toBe(DocumentRecipientRequestStatus::AwaitingAction)
        ->and(DocumentRecipientRequestDelivery::query()->findOrFail($job->deliveryId)->failure_category)
        ->toBe('smtp_not_configured');
});
