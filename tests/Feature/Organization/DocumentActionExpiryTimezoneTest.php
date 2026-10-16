<?php

use App\Enums\DocumentRecipientAction;
use App\Models\User;
use App\Services\Settings\SettingService;
use App\Support\Documents\RecipientRequests\Actions\CreateDocumentRecipientRequest;
use App\Support\Settings\CompanyTimezone;
use App\Support\Settings\SettingKey;
use Carbon\Carbon;
use Database\Seeders\PermissionsSeeder;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../../Support/document-recipient-request-fixtures.php';

beforeEach(function () {
    $this->seed(PermissionsSeeder::class);
    Storage::fake('local');
});

test('public document-action exposes company timezone for expiry display', function () {
    ['company' => $company, 'document' => $document] = makeRecipientFixturesWithSignaturePlacement(
        defaultSignaturePlacementConfig(),
    );

    $company->forceFill(['timezone' => 'Asia/Dubai'])->save();

    $requester = User::factory()->create();
    grantCompanyPermissions($requester, $company, ['documents.recipient-requests.create']);

    $created = app(CreateDocumentRecipientRequest::class)->handle(
        $document,
        DocumentRecipientAction::Acknowledge,
        $requester,
        $company->id,
    );

    // UTC instant that is already the next calendar day in Asia/Dubai (UTC+4).
    $expiresAt = Carbon::parse('2026-10-16T22:30:00Z');
    $created['request']->forceFill(['expires_at' => $expiresAt])->save();
    $created['request']->refresh();

    $this->get(route('public.document-action.show', ['token' => $created['raw_token']]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('document-action/index')
            ->where('companyTimezone', 'Asia/Dubai')
            ->where('expiresAt', $created['request']->expires_at?->toIso8601String()));
});

test('public document-action uses platform timezone fallback when company timezone is invalid', function () {
    ['company' => $company, 'document' => $document] = makeRecipientFixturesWithSignaturePlacement(
        defaultSignaturePlacementConfig(),
    );

    app(SettingService::class)->set(SettingKey::Timezone, 'Europe/London');
    $company->forceFill(['timezone' => 'Not/AZone'])->save();

    $requester = User::factory()->create();
    grantCompanyPermissions($requester, $company, ['documents.recipient-requests.create']);

    $created = app(CreateDocumentRecipientRequest::class)->handle(
        $document,
        DocumentRecipientAction::Acknowledge,
        $requester,
        $company->id,
    );

    $expectedTimezone = CompanyTimezone::forCompany($company->fresh());

    expect($expectedTimezone)->toBe('Europe/London');

    $this->get(route('public.document-action.show', ['token' => $created['raw_token']]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('document-action/index')
            ->where('companyTimezone', $expectedTimezone));
});
