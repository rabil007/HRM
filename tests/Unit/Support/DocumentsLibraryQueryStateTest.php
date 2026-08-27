<?php

use App\Support\Documents\DocumentsLibraryQueryState;
use Illuminate\Http\Request;

test('keeps supported library filters and drops unknown parameters including company id', function () {
    $request = Request::create('/organization/documents', 'GET', [
        'search' => ' visa ',
        'expiry' => 'expired',
        'requirement_status' => 'missing',
        'department_id' => '12',
        'page' => '3',
        'company_id' => '99',
        'foo' => 'bar',
    ]);

    $state = DocumentsLibraryQueryState::fromRequest($request);

    expect($state->hasBrowseState())->toBeTrue()
        ->and($state->toQuery())->toBe([
            'search' => 'visa',
            'expiry' => 'expired',
            'requirement_status' => 'missing',
            'department_id' => '12',
            'page' => '3',
        ]);
});

test('invalid filters sanitize to defaults and are not browse state', function () {
    $request = Request::create('/organization/documents', 'GET', [
        'expiry' => 'bogus',
        'requirement_status' => 'nope',
        'department_id' => 'abc',
        'page' => '1',
        'search' => '  ',
        'company_id' => '1',
    ]);

    $state = DocumentsLibraryQueryState::fromRequest($request);

    expect($state->hasBrowseState())->toBeFalse()
        ->and($state->toQuery())->toBe([]);
});

test('default expiry and first page are omitted from the query', function () {
    $request = Request::create('/organization/documents', 'GET', [
        'expiry' => 'all',
        'page' => '1',
    ]);

    expect(DocumentsLibraryQueryState::fromRequest($request)->toQuery())->toBe([]);
});
