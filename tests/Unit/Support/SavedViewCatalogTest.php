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

test('employee saved views accept emirates id presence missing and present', function () {
    expect(SavedViewCatalog::forApply(SavedViewPage::Employees, [
        'status' => 'active',
        'emirates_id_presence' => 'missing',
    ]))->toBe([
        'status' => 'active',
        'emirates_id_presence' => 'missing',
    ]);

    expect(SavedViewCatalog::normalizeForSave(
        SavedViewPage::Employees,
        ['emirates_id_presence' => 'present'],
        1,
    ))->toBe(['emirates_id_presence' => 'present']);
});

test('employee saved views reject arbitrary emirates id values', function () {
    expect(SavedViewCatalog::forApply(SavedViewPage::Employees, [
        'emirates_id_presence' => '784-1234-1234567-1',
    ]))->toBe([]);

    expect(fn () => SavedViewCatalog::normalizeForSave(
        SavedViewPage::Employees,
        ['emirates_id_presence' => '784-1234-1234567-1'],
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
