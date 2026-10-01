<?php

use App\Models\DocumentExpiryNotificationRule;
use App\Models\EmailTemplate;
use App\Models\User;
use App\Support\EmployeeDocuments\DocumentExpiryNotification\MigrateLegacyDocumentExpiryAlertRecipients;
use Inertia\Testing\AssertableInertia as Assert;

test('authorized user can view notification routing page', function () {
    $user = User::factory()->create();
    ['company' => $company] = makeDocumentFixtures();
    grantCompanyPermissions($user, $company, [
        'documents.notification-routing.view',
        'documents.notification-routing.update',
    ]);

    createDocumentExpiryNotificationRule($company->id, [
        'name' => 'HR Identity',
        'to_emails' => ['hr@example.com'],
        'cc_emails' => [],
    ]);

    $this->actingAs($user)
        ->get(route('organization.documents.configuration.notification-routing'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/documents/configuration/notification-routing')
            ->has('rules', 1)
            ->where('rules.0.name', 'HR Identity')
            ->where('can.update', true)
        );
});

test('unauthorized user cannot view or manage notification routing', function () {
    $user = User::factory()->create();
    ['company' => $company] = makeDocumentFixtures();
    grantCompanyPermissions($user, $company, ['documents.view']);

    $this->actingAs($user)
        ->get(route('organization.documents.configuration.notification-routing'))
        ->assertForbidden();

    $this->actingAs($user)
        ->post(route('organization.documents.configuration.notification-routing.store'), [
            'name' => 'Blocked',
            'enabled' => true,
            'all_document_types' => true,
            'to_emails' => ['hr@example.com'],
        ])
        ->assertForbidden();
});

test('authorized user can create a routing rule with users and manual emails', function () {
    $user = User::factory()->create();
    ['company' => $company, 'passportType' => $passportType] = makeDocumentFixtures();
    grantCompanyPermissions($user, $company, [
        'documents.notification-routing.view',
        'documents.notification-routing.update',
    ]);

    $recipient = User::factory()->create([
        'name' => 'Rima',
        'email' => 'rima@example.com',
        'status' => 'active',
    ]);
    attachActiveCompanyMember($recipient, $company->id);

    $this->actingAs($user)
        ->post(route('organization.documents.configuration.notification-routing.store'), [
            'name' => 'HR Identity Documents',
            'enabled' => true,
            'all_document_types' => false,
            'document_type_ids' => [$passportType->id],
            'to_user_ids' => [$recipient->id],
            'to_emails' => ['compliance@example.com'],
            'cc_user_ids' => [],
            'cc_emails' => ['management@example.com'],
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    $rule = DocumentExpiryNotificationRule::query()->where('company_id', $company->id)->first();

    expect($rule)->not->toBeNull()
        ->and($rule->name)->toBe('HR Identity Documents')
        ->and($rule->all_document_types)->toBeFalse()
        ->and($rule->documentTypes()->pluck('document_types.id')->all())->toBe([$passportType->id])
        ->and($rule->toRecipients()->count())->toBe(2)
        ->and($rule->ccRecipients()->count())->toBe(1);
});

test('cannot select users from another company as recipients', function () {
    $user = User::factory()->create();
    ['company' => $company] = makeDocumentFixtures();
    grantCompanyPermissions($user, $company, [
        'documents.notification-routing.update',
    ]);

    ['company' => $otherCompany] = makeDocumentFixtures();
    $foreignUser = User::factory()->create(['status' => 'active']);
    attachActiveCompanyMember($foreignUser, $otherCompany->id);

    $this->actingAs($user)
        ->post(route('organization.documents.configuration.notification-routing.store'), [
            'name' => 'Cross company',
            'enabled' => true,
            'all_document_types' => true,
            'to_user_ids' => [$foreignUser->id],
            'to_emails' => [],
            'cc_user_ids' => [],
            'cc_emails' => [],
        ])
        ->assertSessionHasErrors('to_user_ids');
});

test('enabled rule requires at least one TO recipient', function () {
    $user = User::factory()->create();
    ['company' => $company] = makeDocumentFixtures();
    grantCompanyPermissions($user, $company, [
        'documents.notification-routing.update',
    ]);

    $this->actingAs($user)
        ->post(route('organization.documents.configuration.notification-routing.store'), [
            'name' => 'No recipients',
            'enabled' => true,
            'all_document_types' => true,
            'to_user_ids' => [],
            'to_emails' => [],
            'cc_emails' => ['cc@example.com'],
        ])
        ->assertSessionHasErrors('to_user_ids');
});

test('document type detail includes matching expiry notification rules', function () {
    $user = User::factory()->create();
    ['company' => $company, 'passportType' => $passportType] = makeDocumentFixtures();
    grantCompanyPermissions($user, $company, [
        'settings.master-data.document-types.view',
        'documents.notification-routing.view',
    ]);

    createDocumentExpiryNotificationRule($company->id, [
        'name' => 'Passport watchers',
        'all_document_types' => false,
        'document_type_ids' => [$passportType->id],
        'to_emails' => ['hr@example.com'],
        'cc_emails' => [],
    ]);

    $this->actingAs($user)
        ->get(route('organization.documents.configuration.show', $passportType))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/documents/configuration/document-type-show')
            ->where('document_type.expiry_notification_rules_count', 1)
            ->where('document_type.expiry_notification_rules.0.name', 'Passport watchers')
            ->where('can.view_notification_routing', true)
        );
});

test('legacy template TO/CC presets migrate into company routing rules once', function () {
    configureDocumentExpiryAlertTemplate([
        'to_preset' => 'legacy-to@example.com, also-to@example.com',
        'cc_preset' => 'legacy-cc@example.com, legacy-to@example.com',
    ]);

    ['company' => $company] = makeDocumentFixtures();

    $created = app(MigrateLegacyDocumentExpiryAlertRecipients::class)->handle();

    expect($created)->toBeGreaterThan(0);

    $rule = DocumentExpiryNotificationRule::query()
        ->where('company_id', $company->id)
        ->where('name', 'Employee Documents (migrated)')
        ->first();

    expect($rule)->not->toBeNull()
        ->and($rule->all_document_types)->toBeTrue()
        ->and($rule->toRecipients()->pluck('email')->sort()->values()->all())
        ->toBe(['also-to@example.com', 'legacy-to@example.com'])
        ->and($rule->ccRecipients()->pluck('email')->all())
        ->toBe(['legacy-cc@example.com']);

    $template = EmailTemplate::query()->where('slug', 'document_expiry_alert')->first();

    expect($template?->to_preset)->toBeNull()
        ->and($template?->cc_preset)->toBeNull();

    $secondPass = app(MigrateLegacyDocumentExpiryAlertRecipients::class)->handle();

    expect($secondPass)->toBe(0)
        ->and(DocumentExpiryNotificationRule::query()->where('company_id', $company->id)->count())->toBe(1);
});
