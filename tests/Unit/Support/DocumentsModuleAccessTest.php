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

test('generate requests and activity require bulk documents view', function () {
    $user = User::factory()->create();
    ['company' => $company] = makeDocumentFixtures();

    grantCompanyPermissions($user, $company, ['documents.view']);

    expect(DocumentsModuleAccess::canViewGenerate($user))->toBeFalse()
        ->and(DocumentsModuleAccess::canViewRequests($user))->toBeFalse()
        ->and(DocumentsModuleAccess::canViewActivity($user))->toBeFalse();

    grantCompanyPermissions($user, $company, ['bulk_documents.view']);

    expect(DocumentsModuleAccess::canViewGenerate($user))->toBeTrue()
        ->and(DocumentsModuleAccess::canViewRequests($user))->toBeTrue()
        ->and(DocumentsModuleAccess::canViewActivity($user))->toBeTrue()
        ->and(DocumentsModuleAccess::canViewOverview($user))->toBeFalse()
        ->and(DocumentsModuleAccess::canViewTemplates($user))->toBeTrue();
});

test('templates is visible for any exposed bridge resource', function (array $permissions, bool $platform, bool $expected) {
    $user = User::factory()->create();
    ['company' => $company] = makeDocumentFixtures();

    if ($permissions !== []) {
        grantCompanyPermissions($user, $company, $permissions);
    }

    if ($platform) {
        grantPlatformAccess($user);
    }

    expect(DocumentsModuleAccess::canViewTemplates($user))->toBe($expected)
        ->and(DocumentsModuleAccess::canViewConfiguration($user))->toBe(
            $permissions === ['settings.master-data.document-types.view'],
        );
})->with([
    'documents view only' => [['documents.view'], false, false],
    'bulk documents view' => [['bulk_documents.view'], false, true],
    'document types view' => [['settings.master-data.document-types.view'], false, true],
    'platform view' => [[], true, true],
]);

test('bulk generate does not imply bulk view', function () {
    $user = User::factory()->create();
    ['company' => $company] = makeDocumentFixtures();

    grantCompanyPermissions($user, $company, ['bulk_documents.generate']);

    expect(DocumentsModuleAccess::canViewGenerate($user))->toBeFalse();
});

test('resolve bulk view prefers the module route default over the query string', function () {
    $request = Request::create('/organization/documents/generate', 'GET', ['view' => 'signatures']);
    $route = new Route(['GET'], 'organization/documents/generate', fn () => 'ok');
    $route->defaults('module_view', 'roster');
    $route->bind($request);
    $request->setRouteResolver(fn () => $route);

    expect(DocumentsModuleAccess::resolveBulkView($request))->toBe('roster');
});

test('resolve bulk view falls back to the legacy query string', function () {
    $request = Request::create('/organization/documents/bulk', 'GET', ['view' => 'history']);
    $route = new Route(['GET'], 'organization/documents/bulk', fn () => 'ok');
    $route->bind($request);
    $request->setRouteResolver(fn () => $route);

    expect(DocumentsModuleAccess::resolveBulkView($request))->toBe('history');
});
