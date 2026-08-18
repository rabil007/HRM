<?php

use App\Enums\SavedViewPage;
use App\Models\User;
use App\Support\SavedViews\SavedViewCatalog;
use Illuminate\Validation\ValidationException;

test('page keys are a closed catalog', function () {
    expect(SavedViewPage::cases())->toHaveCount(5)
        ->and(SavedViewPage::tryFrom('branches'))->toBeNull()
        ->and(SavedViewPage::Employees->routeName())->toBe('organization.employees');
});

test('unknown keys are rejected on save and stripped on apply', function () {
    expect(SavedViewCatalog::forApply(SavedViewPage::Employees, [
        'status' => 'active',
        'sort' => 'salary',
        'company_id' => '1',
    ]))->toBe(['status' => 'active']);

    expect(fn () => SavedViewCatalog::normalizeForSave(
        SavedViewPage::Employees,
        ['status' => 'active', 'sort' => 'salary'],
        1,
    ))->toThrow(ValidationException::class);
});

test('empty and default values are omitted', function () {
    expect(SavedViewCatalog::forApply(SavedViewPage::Documents, [
        'expiry' => 'all',
        'search' => '  ',
    ]))->toBe([])
        ->and(SavedViewCatalog::forApply(SavedViewPage::Leave, [
            'status' => 'pending',
            'scope' => 'my',
        ]))->toBe(['status' => 'pending']);
});

test('accessibility follows list view permissions rather than platform access', function () {
    $user = User::factory()->create();

    expect(SavedViewPage::Employees->userCanAccess($user))->toBeFalse()
        ->and(SavedViewPage::Payroll->userCanAccess($user))->toBeFalse()
        ->and(SavedViewCatalog::userCanAccess($user, 'employees'))->toBeFalse();
});
