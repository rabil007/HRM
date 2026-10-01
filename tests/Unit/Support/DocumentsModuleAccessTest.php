<?php

use App\Models\User;
use App\Support\Documents\DocumentsModuleAccess;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;

test('overview and library require documents view', function () {
    $user = User::factory()->create();
    ['company' => $company] = makeDocumentFixtures();

    expect(DocumentsModuleAccess::canViewOverview($user))->toBeFalse()
        ->and(DocumentsModuleAccess::canViewLibrary($user))->toBeFalse();

    grantCompanyPermissions($user, $company, ['documents.view']);

    expect(DocumentsModuleAccess::canViewOverview($user))->toBeTrue()
        ->and(DocumentsModuleAccess::canViewLibrary($user))->toBeTrue()
        ->and(DocumentsModuleAccess::canViewGenerate($user))->toBeFalse()
        ->and(DocumentsModuleAccess::canViewTemplates($user))->toBeFalse()
        ->and(DocumentsModuleAccess::canViewConfiguration($user))->toBeFalse();
});

test('generate requests and activity require separate permissions', function () {
    $user = User::factory()->create();
    ['company' => $company] = makeDocumentFixtures();

    grantCompanyPermissions($user, $company, ['documents.view']);

    expect(DocumentsModuleAccess::canViewGenerate($user))->toBeFalse()
        ->and(DocumentsModuleAccess::canViewRequests($user))->toBeFalse()
        ->and(DocumentsModuleAccess::canViewActivity($user))->toBeFalse();

    grantCompanyPermissions($user, $company, ['bulk_documents.view']);

    expect(DocumentsModuleAccess::canViewGenerate($user))->toBeTrue()
        ->and(DocumentsModuleAccess::canViewRequests($user))->toBeFalse()
        ->and(DocumentsModuleAccess::canViewActivity($user))->toBeTrue()
        ->and(DocumentsModuleAccess::canViewOverview($user))->toBeFalse()
        ->and(DocumentsModuleAccess::canViewTemplates($user))->toBeTrue();
});

test('requests access follows current document request permissions', function () {
    $user = User::factory()->create();
    ['company' => $company] = makeDocumentFixtures();

    grantCompanyPermissions($user, $company, ['documents.requests.view']);
    expect(DocumentsModuleAccess::canViewRequests($user))->toBeTrue()
        ->and(DocumentsModuleAccess::canViewGenerate($user))->toBeFalse();

    $responder = User::factory()->create();
    grantCompanyPermissions($responder, $company, ['documents.recipient-requests.respond']);
    expect(DocumentsModuleAccess::canViewRequests($responder))->toBeTrue();
});

test('templates is visible for bulk and custom template view, but NOT for document-types-only', function (array $permissions, bool $platform, bool $expectedTemplates, bool $expectedConfig) {
    $user = User::factory()->create();
    ['company' => $company] = makeDocumentFixtures();

    if ($permissions !== []) {
        grantCompanyPermissions($user, $company, $permissions);
    }

    if ($platform) {
        grantPlatformAccess($user);
    }

    expect(DocumentsModuleAccess::canViewTemplates($user))->toBe($expectedTemplates)
        ->and(DocumentsModuleAccess::canViewConfiguration($user))->toBe($expectedConfig);
})->with([
    'documents view only' => [['documents.view'], false, false, false],
    'bulk documents view' => [['bulk_documents.view'], false, true, false],
    'document types view' => [['settings.master-data.document-types.view'], false, false, true],
    'templates view' => [['documents.templates.view'], false, true, false],
    'platform view' => [[], true, false, false],
]);

test('bulk generate does not imply bulk view', function () {
    $user = User::factory()->create();
    ['company' => $company] = makeDocumentFixtures();

    grantCompanyPermissions($user, $company, ['bulk_documents.generate']);

    expect(DocumentsModuleAccess::canViewGenerate($user))->toBeFalse();
});

test('document-types-only user can enter the module and open configuration but not templates', function () {
    $user = User::factory()->create();
    ['company' => $company] = makeDocumentFixtures();

    grantCompanyPermissions($user, $company, ['settings.master-data.document-types.view']);

    expect(DocumentsModuleAccess::canEnter($user))->toBeTrue()
        ->and(DocumentsModuleAccess::canViewConfiguration($user))->toBeTrue()
        ->and(DocumentsModuleAccess::canViewDocumentTypes($user))->toBeTrue()
        ->and(DocumentsModuleAccess::canViewTemplates($user))->toBeFalse()
        ->and(DocumentsModuleAccess::canViewOverview($user))->toBeFalse()
        ->and(DocumentsModuleAccess::canViewGenerate($user))->toBeFalse();
});

test('documents view alone does not grant templates access', function () {
    $user = User::factory()->create();
    ['company' => $company] = makeDocumentFixtures();

    grantCompanyPermissions($user, $company, ['documents.view']);

    expect(DocumentsModuleAccess::canViewTemplates($user))->toBeFalse()
        ->and(DocumentsModuleAccess::canEnter($user))->toBeTrue();
});

test('resolve bulk view uses the canonical activity query and safely falls back', function () {
    $activity = Request::create('/organization/documents/generate', 'GET', ['view' => 'activity']);
    $activityRoute = new Route(['GET'], 'organization/documents/generate', fn () => 'ok');
    $activityRoute->bind($activity);
    $activity->setRouteResolver(fn () => $activityRoute);

    $legacy = Request::create('/organization/documents/generate', 'GET', ['view' => 'history']);
    $legacyRoute = new Route(['GET'], 'organization/documents/generate', fn () => 'ok');
    $legacyRoute->bind($legacy);
    $legacy->setRouteResolver(fn () => $legacyRoute);

    $invalid = Request::create('/organization/documents/generate', 'GET', ['view' => 'signatures']);
    $invalidRoute = new Route(['GET'], 'organization/documents/generate', fn () => 'ok');
    $invalidRoute->bind($invalid);
    $invalid->setRouteResolver(fn () => $invalidRoute);

    expect(DocumentsModuleAccess::resolveBulkView($activity))->toBe('history')
        ->and(DocumentsModuleAccess::resolveBulkView($legacy))->toBe('history')
        ->and(DocumentsModuleAccess::resolveBulkView($invalid))->toBe('roster');
});
