<?php

use Illuminate\Support\Facades\Route;

test('retired pre-unified-designer template mutation routes are absent', function () {
    expect(Route::has('organization.documents.templates.create.content'))->toBeFalse()
        ->and(Route::has('organization.documents.templates.preview-draft'))->toBeFalse()
        ->and(Route::has('organization.documents.templates.automation.update'))->toBeFalse()
        ->and(Route::has('organization.documents.templates.versions.placements.save'))->toBeFalse()
        ->and(Route::has('organization.documents.templates.versions.signature-placement.save'))->toBeFalse()
        ->and(Route::has('organization.documents.templates.versions.design.save'))->toBeTrue()
        ->and(Route::has('organization.documents.templates.preview'))->toBeTrue();
});
