<?php

use App\Jobs\FetchHikvisionAccessEventsJob;
use App\Jobs\ProcessHikvisionWebhookEventJob;
use App\Models\Company;
use App\Models\Employee;
use App\Models\HikvisionAccessEvent;
use App\Models\HikvisionDevice;
use App\Models\HikvisionPerson;
use App\Models\HikvisionPersonGroup;
use App\Models\HikvisionSetting;
use App\Models\User;
use App\Support\Hikvision\HikvisionWebhookSignature;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

function additionalHikvisionTestCompany(Company $company, string $slug = 'hikvision-other-company'): Company
{
    return Company::query()->create([
        'name' => 'Hikvision Other Company '.$slug,
        'slug' => $slug,
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $company->country_id,
        'currency_id' => $company->currency_id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);
}

test('company a cannot view company b hikvision settings', function () {
    $user = User::factory()->create();
    $companyA = setupCompanyWithSettingsPermissions($user, ['settings.integrations.hikvision.view']);
    $companyB = additionalHikvisionTestCompany($companyA, 'hikvision-b-view');

    configuredHikvisionSettings($companyA->id)->update(['api_host' => 'https://a.example.test']);
    configuredHikvisionSettings($companyB->id)->update(['api_host' => 'https://b.example.test']);

    $this->actingAs($user)
        ->get(route('integrations.hikvision.edit'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('settings.api_host', 'https://a.example.test'));
});

test('company a update does not change company b hikvision settings', function () {
    $user = User::factory()->create();
    $companyA = setupCompanyWithSettingsPermissions($user, [
        'settings.integrations.hikvision.view',
        'settings.integrations.hikvision.update',
    ]);
    $companyB = additionalHikvisionTestCompany($companyA, 'hikvision-b-update');

    configuredHikvisionSettings($companyB->id)->update(['api_host' => 'https://b.example.test']);

    $this->actingAs($user)->get(route('integrations.hikvision.edit'));

    $this->actingAs($user)->put(route('application.hikvision.update'), [
        '_token' => csrf_token(),
        'api_host' => 'https://a-updated.example.test',
        'api_key' => 'key-a',
        'api_secret' => 'secret-a',
        'enabled' => true,
    ])->assertRedirect();

    expect(HikvisionSetting::forCompany($companyA->id)->api_host)->toBe('https://a-updated.example.test')
        ->and(HikvisionSetting::forCompany($companyB->id)->api_host)->toBe('https://b.example.test');
});

test('company switching loads the selected company hikvision configuration', function () {
    $user = User::factory()->create();
    $companyA = setupCompanyWithSettingsPermissions($user, ['settings.integrations.hikvision.view']);
    $companyB = additionalHikvisionTestCompany($companyA, 'hikvision-switch');

    grantCompanyPermissions($user, $companyB, ['settings.integrations.hikvision.view']);
    configuredHikvisionSettings($companyA->id)->update(['api_host' => 'https://a.example.test']);
    configuredHikvisionSettings($companyB->id)->update(['api_host' => 'https://b.example.test']);

    $this->actingAs($user)
        ->post(route('organization.companies.switch'), ['company_id' => $companyB->id])
        ->assertRedirect();

    $this->actingAs($user)
        ->get(route('integrations.hikvision.edit'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('settings.api_host', 'https://b.example.test'));
});

test('company without stored credentials receives disabled settings state even when config credentials are set', function () {
    $user = User::factory()->create();
    setupCompanyWithSettingsPermissions($user, ['settings.integrations.hikvision.view']);
    config()->set('hikvision.api_host', 'https://env.example.test');
    config()->set('hikvision.api_key', 'env-api-key');
    config()->set('hikvision.api_secret', 'env-api-secret');

    $this->actingAs($user)
        ->get(route('integrations.hikvision.edit'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('settings.enabled', false)
            ->where('settings.is_configured', false)
            ->where('settings.api_host', '')
            ->where('settings.api_key', '')
            ->where('settings.api_secret', '')
            ->where('settings.has_api_key', false)
            ->where('settings.has_api_secret', false));
});

test('hikvision credentials stay in hikvision_settings not companies', function () {
    $company = setupCompanyWithSettingsPermissions(User::factory()->create(), []);
    configuredHikvisionSettings($company->id);

    expect(Schema::hasColumn('companies', 'api_key'))->toBeFalse()
        ->and(Schema::hasColumn('hikvision_settings', 'api_key'))->toBeTrue()
        ->and(Schema::hasColumn('hikvision_settings', 'company_id'))->toBeTrue()
        ->and(DB::table('hikvision_settings')->where('company_id', $company->id)->exists())->toBeTrue();
});

test('forCompany does not create hikvision settings records', function () {
    $company = setupCompanyWithSettingsPermissions(User::factory()->create(), []);

    HikvisionSetting::forCompany($company->id);
    HikvisionSetting::forCompany($company->id);

    expect(HikvisionSetting::query()->where('company_id', $company->id)->count())->toBe(0);
});

test('same remote identifiers are permitted in separate companies', function () {
    $companyA = setupCompanyWithSettingsPermissions(User::factory()->create(), []);
    $companyB = additionalHikvisionTestCompany($companyA, 'hikvision-shared-ids');

    HikvisionPerson::upsertFromApi($companyA->id, ['personInfo' => ['personId' => 'shared-person']]);
    HikvisionPerson::upsertFromApi($companyB->id, ['personInfo' => ['personId' => 'shared-person']]);
    HikvisionDevice::upsertFromApi($companyA->id, ['serialNo' => 'shared-device', 'id' => 'shared-device-id']);
    HikvisionDevice::upsertFromApi($companyB->id, ['serialNo' => 'shared-device', 'id' => 'shared-device-id']);
    HikvisionPersonGroup::upsertFromApi($companyA->id, ['groupId' => 'shared-group', 'groupName' => 'Shared']);
    HikvisionPersonGroup::upsertFromApi($companyB->id, ['groupId' => 'shared-group', 'groupName' => 'Shared']);
    HikvisionAccessEvent::query()->create([
        'company_id' => $companyA->id,
        'system_id' => 'shared-event',
        'msg_type' => 'webhook/event',
        'occurrence_time' => now(),
        'event_source' => HikvisionAccessEvent::EVENT_SOURCE_WEBHOOK,
        'transaction_source' => HikvisionAccessEvent::TRANSACTION_DEVICE,
    ]);
    HikvisionAccessEvent::query()->create([
        'company_id' => $companyB->id,
        'system_id' => 'shared-event',
        'msg_type' => 'webhook/event',
        'occurrence_time' => now(),
        'event_source' => HikvisionAccessEvent::EVENT_SOURCE_WEBHOOK,
        'transaction_source' => HikvisionAccessEvent::TRANSACTION_DEVICE,
    ]);

    expect(HikvisionPerson::query()->where('person_id', 'shared-person')->count())->toBe(2)
        ->and(HikvisionDevice::query()->where('serial_no', 'shared-device')->count())->toBe(2)
        ->and(HikvisionPersonGroup::query()->where('group_id', 'shared-group')->count())->toBe(2)
        ->and(HikvisionAccessEvent::query()->where('system_id', 'shared-event')->count())->toBe(2);
});

test('persons devices groups and events indexes are company scoped', function () {
    $user = User::factory()->create();
    $companyA = setupCompanyWithSettingsPermissions($user, [
        'hikvision.persons.view',
        'hikvision.events.view',
        'hikvision.devices.view',
        'settings.integrations.hikvision.view',
    ]);
    $companyB = additionalHikvisionTestCompany($companyA, 'hikvision-scoped-lists');

    HikvisionPerson::query()->create(['company_id' => $companyB->id, 'person_id' => 'b-person', 'full_name' => 'B Person']);
    HikvisionAccessEvent::query()->create([
        'company_id' => $companyB->id,
        'system_id' => 'b-event',
        'msg_type' => 'webhook/event',
        'occurrence_time' => now(),
        'person_name' => 'B Person',
        'event_source' => HikvisionAccessEvent::EVENT_SOURCE_WEBHOOK,
        'transaction_source' => HikvisionAccessEvent::TRANSACTION_DEVICE,
    ]);
    HikvisionDevice::query()->create([
        'company_id' => $companyB->id,
        'hikvision_id' => 'b-device',
        'serial_no' => 'b-serial',
        'name' => 'B Device',
        'category' => 'accessControllerDevice',
    ]);

    $this->actingAs($user)
        ->get(route('hikvision.persons.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('persons', 0));

    $this->actingAs($user)
        ->get(route('hikvision.access-events.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('events', 0));

    $this->actingAs($user)
        ->get(route('integrations.hikvision.edit'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('devices.items', 0));
});

test('cross company employee to person linking is rejected', function () {
    $user = User::factory()->create();
    $companyA = setupCompanyWithSettingsPermissions($user, [
        'hikvision.persons.view',
        'hikvision.persons.link',
    ]);
    $companyB = additionalHikvisionTestCompany($companyA, 'hikvision-cross-link');

    $person = HikvisionPerson::query()->create([
        'company_id' => $companyA->id,
        'person_id' => 'link-person',
        'full_name' => 'Link Person',
    ]);
    $otherEmployee = Employee::factory()->forCompany($companyB)->create();

    $this->actingAs($user)
        ->put(route('hikvision.persons.employee.link', $person), [
            'employee_id' => $otherEmployee->id,
        ])
        ->assertSessionHasErrors('employee_id');

    expect($otherEmployee->fresh()->hikvision_person_id)->toBeNull();
});

test('cross company person mutation returns not found', function () {
    $user = User::factory()->create();
    $companyA = setupCompanyWithSettingsPermissions($user, ['hikvision.persons.delete']);
    $companyB = additionalHikvisionTestCompany($companyA, 'hikvision-cross-person');

    $foreignPerson = HikvisionPerson::query()->create([
        'company_id' => $companyB->id,
        'person_id' => 'foreign-person',
        'full_name' => 'Foreign',
    ]);

    expect((int) $foreignPerson->company_id)->toBe($companyB->id)
        ->and((int) $foreignPerson->company_id)->not->toBe($companyA->id);

    $response = $this->actingAs($user)
        ->from('/hikvision/persons')
        ->delete(route('hikvision.persons.destroy', $foreignPerson));

    expect($response->status())->toBe(404)
        ->and(HikvisionPerson::query()->whereKey($foreignPerson->id)->exists())->toBeTrue();
});

test('scheduled fetch job uses settings company and isolates fetch state', function () {
    Queue::fake();

    $companyA = setupCompanyWithSettingsPermissions(User::factory()->create(), []);
    $companyB = additionalHikvisionTestCompany($companyA, 'hikvision-fetch-state');

    $settingsA = configuredHikvisionSettings($companyA->id);
    $settingsB = configuredHikvisionSettings($companyB->id);

    $settingsA->beginEventsFetch();
    FetchHikvisionAccessEventsJob::dispatch($settingsA->id);

    expect($settingsA->fresh()->events_fetch_status)->toBe(HikvisionSetting::EVENTS_FETCH_QUEUED)
        ->and($settingsB->fresh()->events_fetch_status)->not->toBe(HikvisionSetting::EVENTS_FETCH_QUEUED);

    Queue::assertPushed(
        FetchHikvisionAccessEventsJob::class,
        fn (FetchHikvisionAccessEventsJob $job): bool => $job->hikvisionSettingId === $settingsA->id,
    );
});

test('webhook ownership comes from public integration id and ignores payload company_id', function () {
    Queue::fake();

    $companyA = setupCompanyWithSettingsPermissions(User::factory()->create(), []);
    $companyB = additionalHikvisionTestCompany($companyA, 'hikvision-webhook-owner');
    $settingsA = configuredHikvisionSettings($companyA->id);
    $settingsA->update([
        'webhook_verify_token' => 'abc12345',
        'webhook_enabled' => true,
    ]);
    configuredHikvisionSettings($companyB->id);

    $payload = [
        'company_id' => $companyB->id,
        'personInfo' => [
            'personId' => 'webhook-person',
            'personName' => 'Webhook Person',
        ],
        'occurTime' => '2026-06-05T09:15:00+04:00',
        'attendanceStatus' => 'checkIn',
    ];

    $timestamp = (string) time();
    $batchId = 'tenant-batch';
    $signature = HikvisionWebhookSignature::generate('abc12345', $timestamp, $batchId);

    $this->postJson(route('webhooks.hikvision', $settingsA->public_id), $payload, [
        'X-Hook-Batch-Id' => $batchId,
        'X-Hook-Timestamp' => $timestamp,
        'X-Hook-Signature' => $signature,
    ])->assertNoContent();

    Queue::assertPushed(
        ProcessHikvisionWebhookEventJob::class,
        fn (ProcessHikvisionWebhookEventJob $job): bool => $job->hikvisionSettingId === $settingsA->id
            && ($job->payload['company_id'] ?? null) === $companyB->id,
    );

    (new ProcessHikvisionWebhookEventJob($payload, $settingsA->id))->handle();

    $event = HikvisionAccessEvent::query()->where('person_hikvision_id', 'webhook-person')->first();

    expect($event)->not->toBeNull()
        ->and((int) $event->company_id)->toBe($companyA->id);
});

test('invalid webhook public id returns not found without leaking tenant data', function () {
    $this->postJson(route('webhooks.hikvision', '00000000-0000-0000-0000-000000000000'), [
        'personName' => 'Nobody',
    ])->assertNotFound()
        ->assertDontSee('Acme')
        ->assertDontSee('company');
});

test('secrets are masked and blank updates preserve stored values', function () {
    $user = User::factory()->create();
    $company = setupCompanyWithSettingsPermissions($user, [
        'settings.integrations.hikvision.view',
        'settings.integrations.hikvision.update',
    ]);

    configuredHikvisionSettings($company->id);

    $this->actingAs($user)
        ->get(route('integrations.hikvision.edit'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('settings.api_key', '')
            ->where('settings.api_secret', '')
            ->where('settings.has_api_key', true)
            ->where('settings.has_api_secret', true));

    $this->actingAs($user)->get(route('integrations.hikvision.edit'));

    $this->actingAs($user)->put(route('application.hikvision.update'), [
        '_token' => csrf_token(),
        'api_host' => 'https://isgp.hikcentralconnect.com',
        'api_key' => '',
        'api_secret' => '',
        'enabled' => true,
    ])->assertRedirect();

    $settings = HikvisionSetting::forCompany($company->id);

    expect($settings->api_key)->toBe('test-api-key')
        ->and($settings->api_secret)->toBe('test-api-secret');
});

test('relinking an employee does not change historical event ownership', function () {
    $companyA = setupCompanyWithSettingsPermissions(User::factory()->create(), []);
    $companyB = additionalHikvisionTestCompany($companyA, 'hikvision-relink-history');

    $person = HikvisionPerson::query()->create([
        'company_id' => $companyA->id,
        'person_id' => 'history-person',
        'full_name' => 'History Person',
    ]);
    $employee = Employee::factory()->forCompany($companyA)->create([
        'hikvision_person_id' => $person->id,
    ]);
    $event = HikvisionAccessEvent::query()->create([
        'company_id' => $companyA->id,
        'hikvision_person_id' => $person->id,
        'system_id' => 'history-event',
        'msg_type' => 'webhook/event',
        'occurrence_time' => now(),
        'person_hikvision_id' => 'history-person',
        'event_source' => HikvisionAccessEvent::EVENT_SOURCE_WEBHOOK,
        'transaction_source' => HikvisionAccessEvent::TRANSACTION_DEVICE,
    ]);

    $employee->update([
        'company_id' => $companyB->id,
        'hikvision_person_id' => null,
    ]);

    expect($event->fresh()->company_id)->toBe($companyA->id);
});

test('ambiguous unlinked persons are not assigned by matching employee name', function () {
    $company = setupCompanyWithSettingsPermissions(User::factory()->create(), []);

    $person = HikvisionPerson::withoutEvents(fn () => HikvisionPerson::query()->create([
        'company_id' => null,
        'person_id' => 'orphan-person',
        'full_name' => 'Same Name',
    ]));
    Employee::factory()->forCompany($company)->create([
        'name' => 'Same Name',
    ]);

    expect($person->fresh()->company_id)->toBeNull();
});

test('users without hikvision permissions receive forbidden', function () {
    $user = User::factory()->create();
    setupCompanyWithSettingsPermissions($user, ['settings.application.view']);

    $this->actingAs($user)
        ->get(route('hikvision.persons.index'))
        ->assertForbidden();

    $this->actingAs($user)
        ->get(route('hikvision.access-events.index'))
        ->assertForbidden();

    $this->actingAs($user)
        ->put(route('application.hikvision.update'), [
            'api_host' => 'https://example.test',
            'enabled' => true,
        ])
        ->assertForbidden();
});

test('webhook settings without company ownership return not found', function () {
    $settings = HikvisionSetting::withoutEvents(fn () => HikvisionSetting::query()->create([
        'company_id' => null,
        'public_id' => '00000000-0000-0000-0000-000000000001',
        'webhook_verify_token' => 'expected-token',
        'webhook_enabled' => true,
    ]));

    $this->postJson(route('webhooks.hikvision', $settings->public_id), [], [
        'X-HCC-Webhook-Token' => 'expected-token',
    ])->assertNotFound();
});

test('fetch job fails safely when settings have no company ownership', function () {
    $settings = HikvisionSetting::withoutEvents(fn () => HikvisionSetting::query()->create([
        'company_id' => null,
        'public_id' => '00000000-0000-0000-0000-000000000002',
    ]));

    (new FetchHikvisionAccessEventsJob($settings->id))->handle();

    expect($settings->fresh()->events_fetch_status)->toBe(HikvisionSetting::EVENTS_FETCH_FAILED)
        ->and($settings->fresh()->events_fetch_message)->toBe('Hikvision settings have no company ownership.');
});

test('webhook job exits safely when settings have no company ownership', function () {
    $settings = HikvisionSetting::withoutEvents(fn () => HikvisionSetting::query()->create([
        'company_id' => null,
        'public_id' => '00000000-0000-0000-0000-000000000003',
    ]));

    (new ProcessHikvisionWebhookEventJob([], $settings->id))->handle();

    expect(HikvisionAccessEvent::query()->count())->toBe(0);
});

test('deleted settings remain deleted after viewing the integration page', function () {
    $user = User::factory()->create();
    $company = setupCompanyWithSettingsPermissions($user, ['settings.integrations.hikvision.view']);
    $settings = configuredHikvisionSettings($company->id);
    $settings->delete();

    $this->actingAs($user)
        ->get(route('integrations.hikvision.edit'))
        ->assertOk();

    expect(HikvisionSetting::withTrashed()->find($settings->id)?->trashed())->toBeTrue()
        ->and(HikvisionSetting::forCompany($company->id)->exists)->toBeFalse();
});

test('viewing integration settings does not create a settings record', function () {
    $user = User::factory()->create();
    $company = setupCompanyWithSettingsPermissions($user, ['settings.integrations.hikvision.view']);

    expect(HikvisionSetting::query()->where('company_id', $company->id)->count())->toBe(0);

    $this->actingAs($user)
        ->get(route('integrations.hikvision.edit'))
        ->assertOk();

    expect(HikvisionSetting::query()->where('company_id', $company->id)->count())->toBe(0);
});

test('hikvision api upserts reject invalid company ids', function () {
    expect(fn () => HikvisionPerson::upsertFromApi(0, []))->toThrow(InvalidArgumentException::class)
        ->and(fn () => HikvisionDevice::upsertFromApi(-1, []))->toThrow(InvalidArgumentException::class)
        ->and(fn () => HikvisionPersonGroup::upsertFromApi(0, []))->toThrow(InvalidArgumentException::class);
});

test('webhook manager cannot restore deleted hikvision settings', function () {
    Http::fake();

    $user = User::factory()->create();
    $company = setupCompanyWithSettingsPermissions($user, [
        'settings.integrations.hikvision.view',
        'hikvision.webhook.manage',
    ]);
    $settings = configuredHikvisionSettings($company->id);
    $oldPublicId = $settings->public_id;
    $oldApiKey = $settings->api_key;
    $settings->delete();

    $this->actingAs($user)
        ->from(route('integrations.hikvision.edit'))
        ->post(route('integrations.hikvision.webhook.register'))
        ->assertRedirect()
        ->assertSessionHasErrors('webhook');

    expect(HikvisionSetting::withTrashed()->find($settings->id)?->trashed())->toBeTrue()
        ->and(HikvisionSetting::query()->where('company_id', $company->id)->exists())->toBeFalse()
        ->and(HikvisionSetting::withTrashed()->find($settings->id)?->public_id)->toBe($oldPublicId)
        ->and(HikvisionSetting::withTrashed()->find($settings->id)?->api_key)->toBe($oldApiKey);

    Http::assertNothingSent();
});

test('updating after deletion does not reuse old credentials and creates company owned settings', function () {
    $user = User::factory()->create();
    $company = setupCompanyWithSettingsPermissions($user, [
        'settings.integrations.hikvision.view',
        'settings.integrations.hikvision.update',
    ]);
    $settings = configuredHikvisionSettings($company->id);
    $deletedId = $settings->id;
    $oldApiKey = $settings->api_key;
    $oldPublicId = $settings->public_id;
    $settings->update([
        'webhook_verify_token' => 'oldtoken1',
        'webhook_callback_url' => 'https://old.example/hook',
        'events_fetch_schedule_enabled' => true,
    ]);
    $settings->delete();

    $this->actingAs($user)->put(route('integrations.hikvision.update'), [
        'api_host' => 'https://new.example.test',
        'api_key' => 'brand-new-key',
        'api_secret' => 'brand-new-secret',
        'enabled' => true,
    ])->assertRedirect();

    $fresh = HikvisionSetting::query()->where('company_id', $company->id)->first();

    expect($fresh)->not->toBeNull()
        ->and($fresh->company_id)->toBe($company->id)
        ->and($fresh->id)->not->toBe($deletedId)
        ->and($fresh->api_key)->toBe('brand-new-key')
        ->and($fresh->api_key)->not->toBe($oldApiKey)
        ->and($fresh->api_secret)->toBe('brand-new-secret')
        ->and($fresh->public_id)->not->toBe($oldPublicId)
        ->and($fresh->webhook_verify_token)->toBeNull()
        ->and($fresh->webhook_callback_url)->toBeNull()
        ->and($fresh->events_fetch_schedule_enabled)->toBeFalse()
        ->and(HikvisionSetting::withTrashed()->find($deletedId))->toBeNull();
});

test('blank credential update after deletion does not revive deleted secrets', function () {
    $user = User::factory()->create();
    $company = setupCompanyWithSettingsPermissions($user, [
        'settings.integrations.hikvision.view',
        'settings.integrations.hikvision.update',
    ]);
    $settings = configuredHikvisionSettings($company->id);
    $settings->delete();

    $this->actingAs($user)->put(route('integrations.hikvision.update'), [
        'api_host' => 'https://new.example.test',
        'api_key' => '',
        'api_secret' => '',
        'enabled' => true,
    ])->assertRedirect();

    $fresh = HikvisionSetting::query()->where('company_id', $company->id)->first();

    expect($fresh)->not->toBeNull()
        ->and($fresh->api_key)->toBeNull()
        ->and($fresh->api_secret)->toBeNull()
        ->and($fresh->isConfigured())->toBeFalse()
        ->and($fresh->toSettingsPageArray()['has_api_key'])->toBeFalse()
        ->and($fresh->toSettingsPageArray()['has_api_secret'])->toBeFalse();
});

test('cross company deleted settings cannot be restored or accessed', function () {
    Http::fake();

    $user = User::factory()->create();
    $companyA = setupCompanyWithSettingsPermissions($user, [
        'settings.integrations.hikvision.view',
        'settings.integrations.hikvision.update',
        'hikvision.webhook.manage',
    ]);
    $companyB = additionalHikvisionTestCompany($companyA, 'hikvision-deleted-cross');
    $settingsB = configuredHikvisionSettings($companyB->id);
    $settingsB->delete();

    $this->actingAs($user)
        ->from(route('integrations.hikvision.edit'))
        ->post(route('integrations.hikvision.webhook.register'))
        ->assertRedirect()
        ->assertSessionHasErrors('webhook');

    expect(HikvisionSetting::withTrashed()->find($settingsB->id)?->trashed())->toBeTrue()
        ->and(HikvisionSetting::forCompany($companyA->id)->exists)->toBeFalse()
        ->and(HikvisionSetting::forCompany($companyB->id)->exists)->toBeFalse();

    Http::assertNothingSent();

    $this->actingAs($user)
        ->get(route('integrations.hikvision.edit'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('settings.is_configured', false)
            ->where('settings.has_api_key', false));
});
