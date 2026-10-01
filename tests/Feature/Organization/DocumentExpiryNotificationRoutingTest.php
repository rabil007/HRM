<?php

use App\Models\DocumentExpiryNotificationRule;
use App\Models\DocumentType;
use App\Models\EmailTemplate;
use App\Models\User;
use App\Support\EmployeeDocuments\DocumentExpiryNotification\MigrateLegacyDocumentExpiryAlertRecipients;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;

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
    grantCompanyPermissions($recipient, $company, ['documents.view'], 'routing-recipient-role');

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

test('notification routing index filters rules by document type', function () {
    $user = User::factory()->create();
    ['company' => $company, 'passportType' => $passportType] = makeDocumentFixtures();
    grantCompanyPermissions($user, $company, [
        'documents.notification-routing.view',
        'documents.notification-routing.update',
    ]);

    $medicalType = DocumentType::query()->firstOrCreate(
        ['title' => 'Medical Certificate'],
        ['is_active' => true],
    );

    createDocumentExpiryNotificationRule($company->id, [
        'name' => 'Passport watchers',
        'all_document_types' => false,
        'document_type_ids' => [$passportType->id],
        'to_emails' => ['passport@example.com'],
        'cc_emails' => [],
    ]);

    createDocumentExpiryNotificationRule($company->id, [
        'name' => 'Medical watchers',
        'all_document_types' => false,
        'document_type_ids' => [$medicalType->id],
        'to_emails' => ['medical@example.com'],
        'cc_emails' => [],
    ]);

    createDocumentExpiryNotificationRule($company->id, [
        'name' => 'All types',
        'all_document_types' => true,
        'to_emails' => ['all@example.com'],
        'cc_emails' => [],
    ]);

    $this->actingAs($user)
        ->get(route('organization.documents.configuration.notification-routing', [
            'document_type_id' => $passportType->id,
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/documents/configuration/notification-routing')
            ->where('filtered_by_document_type', true)
            ->where('highlight_document_type_id', $passportType->id)
            ->has('rules', 2)
            ->where('rules.0.name', 'All types')
            ->where('rules.1.name', 'Passport watchers')
        );
});

test('cannot select company members without documents.view as recipients', function () {
    $user = User::factory()->create();
    ['company' => $company] = makeDocumentFixtures();
    grantCompanyPermissions($user, $company, [
        'documents.notification-routing.update',
    ]);

    $memberWithoutDocs = User::factory()->create([
        'email' => 'no-docs@example.com',
        'status' => 'active',
    ]);
    attachActiveCompanyMember($memberWithoutDocs, $company->id);

    $this->actingAs($user)
        ->post(route('organization.documents.configuration.notification-routing.store'), [
            'name' => 'Needs docs permission',
            'enabled' => true,
            'all_document_types' => true,
            'to_user_ids' => [$memberWithoutDocs->id],
            'to_emails' => [],
            'cc_user_ids' => [],
            'cc_emails' => [],
        ])
        ->assertSessionHasErrors('to_user_ids');
});

test('stale configured recipient remains visible and removable on the routing editor', function () {
    $admin = User::factory()->create();
    ['company' => $company] = makeDocumentFixtures();
    grantCompanyPermissions($admin, $company, [
        'documents.notification-routing.view',
        'documents.notification-routing.update',
    ]);

    $rima = User::factory()->create([
        'name' => 'Rima',
        'email' => 'rima@example.com',
        'status' => 'active',
    ]);
    attachActiveCompanyMember($rima, $company->id);
    grantCompanyPermissions($rima, $company, ['documents.view'], 'routing-rima-role');

    $rule = createDocumentExpiryNotificationRule($company->id, [
        'name' => 'Stale recipient rule',
        'to_user_ids' => [$rima->id],
        'to_emails' => [],
        'cc_emails' => [],
    ]);

    app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);
    $rima->syncRoles([]);

    $this->actingAs($admin)
        ->get(route('organization.documents.configuration.notification-routing'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/documents/configuration/notification-routing')
            ->where('rules.0.to.0.user_id', $rima->id)
            ->where('rules.0.to.0.eligible', false)
            ->where('rules.0.to.0.label', 'Rima')
            ->where('company_users', fn ($users) => collect($users)->pluck('id')->doesntContain($rima->id))
        );

    $this->actingAs($admin)
        ->put(route('organization.documents.configuration.notification-routing.update', $rule), [
            'name' => 'Stale recipient rule',
            'enabled' => true,
            'all_document_types' => true,
            'document_type_ids' => [],
            'to_user_ids' => [$rima->id],
            'to_emails' => [],
            'cc_user_ids' => [],
            'cc_emails' => [],
        ])
        ->assertRedirect();

    $this->actingAs($admin)
        ->put(route('organization.documents.configuration.notification-routing.update', $rule), [
            'name' => 'Stale recipient rule',
            'enabled' => true,
            'all_document_types' => true,
            'document_type_ids' => [],
            'to_user_ids' => [],
            'to_emails' => ['fallback@example.com'],
            'cc_user_ids' => [],
            'cc_emails' => [],
        ])
        ->assertRedirect();

    expect($rule->fresh()->toRecipients()->where('user_id', $rima->id)->exists())->toBeFalse();
});

test('inactive configured document type remains visible and removable on the routing editor', function () {
    $admin = User::factory()->create();
    ['company' => $company, 'passportType' => $passportType] = makeDocumentFixtures();
    grantCompanyPermissions($admin, $company, [
        'documents.notification-routing.view',
        'documents.notification-routing.update',
    ]);

    $medical = DocumentType::query()->create([
        'title' => 'Medical Certificate',
        'is_active' => true,
    ]);

    $rule = createDocumentExpiryNotificationRule($company->id, [
        'name' => 'Identity + Medical',
        'all_document_types' => false,
        'document_type_ids' => [$passportType->id, $medical->id],
        'to_emails' => ['hr@example.com'],
        'cc_emails' => [],
    ]);

    $medical->update(['is_active' => false]);

    $this->actingAs($admin)
        ->get(route('organization.documents.configuration.notification-routing'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/documents/configuration/notification-routing')
            ->where('rules.0.document_types', function ($types) use ($medical) {
                $medicalRow = collect($types)->firstWhere('id', $medical->id);

                return $medicalRow !== null
                    && $medicalRow['title'] === 'Medical Certificate'
                    && $medicalRow['is_active'] === false;
            })
            ->where('document_types', fn ($types) => collect($types)->pluck('id')->doesntContain($medical->id))
        );

    $this->actingAs($admin)
        ->put(route('organization.documents.configuration.notification-routing.update', $rule), [
            'name' => 'Identity + Medical',
            'enabled' => true,
            'all_document_types' => false,
            'document_type_ids' => [$passportType->id, $medical->id],
            'to_user_ids' => [],
            'to_emails' => ['hr@example.com'],
            'cc_user_ids' => [],
            'cc_emails' => [],
        ])
        ->assertRedirect();

    $this->actingAs($admin)
        ->put(route('organization.documents.configuration.notification-routing.update', $rule), [
            'name' => 'Identity only',
            'enabled' => true,
            'all_document_types' => false,
            'document_type_ids' => [$passportType->id],
            'to_user_ids' => [],
            'to_emails' => ['hr@example.com'],
            'cc_user_ids' => [],
            'cc_emails' => [],
        ])
        ->assertRedirect();

    expect($rule->fresh()->documentTypes()->pluck('document_types.id')->all())->toBe([$passportType->id]);
});

test('foreign company user id is not exposed as a stale recipient profile', function () {
    $admin = User::factory()->create();
    ['company' => $company] = makeDocumentFixtures();
    $other = makeDocumentFixtures();

    grantCompanyPermissions($admin, $company, [
        'documents.notification-routing.view',
        'documents.notification-routing.update',
    ]);

    $foreignUser = User::factory()->create([
        'name' => 'Secret Foreign',
        'email' => 'secret-foreign@example.com',
        'status' => 'active',
    ]);
    attachActiveCompanyMember($foreignUser, $other['company']->id);
    grantCompanyPermissions($foreignUser, $other['company'], ['documents.view'], 'foreign-role');

    $rule = createDocumentExpiryNotificationRule($company->id, [
        'name' => 'Foreign id rule',
        'to_emails' => ['hr@example.com'],
        'cc_emails' => [],
    ]);

    $rule->toRecipients()->create([
        'company_id' => $company->id,
        'recipient_kind' => 'user',
        'user_id' => $foreignUser->id,
        'email' => null,
        'delivery_type' => 'to',
    ]);

    $this->actingAs($admin)
        ->get(route('organization.documents.configuration.notification-routing'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('rules.0.to', function ($recipients) use ($foreignUser) {
                $stale = collect($recipients)->firstWhere('user_id', $foreignUser->id);

                return $stale !== null
                    && $stale['eligible'] === false
                    && $stale['label'] === 'Unknown user'
                    && $stale['email'] === null
                    && $stale['name'] === null;
            })
        );
});
