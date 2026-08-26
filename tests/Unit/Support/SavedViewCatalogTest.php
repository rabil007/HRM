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

test('employee saved views accept generic completeness missing and present', function () {
    expect(SavedViewCatalog::forApply(SavedViewPage::Employees, [
        'status' => 'active',
        'missing_fields' => 'email,date_of_birth',
    ]))->toBe([
        'status' => 'active',
        'missing_fields' => 'email,date_of_birth',
    ]);

    expect(SavedViewCatalog::normalizeForSave(
        SavedViewPage::Employees,
        ['present_fields' => 'passport_number'],
        1,
    ))->toBe(['present_fields' => 'passport_number']);

    expect(SavedViewCatalog::forApply(SavedViewPage::Employees, [
        'status' => 'all',
        'emirates_id_presence' => 'missing',
    ]))->toBe([
        'status' => 'all',
        'missing_fields' => 'emirates_id',
    ]);
});

test('employee saved views reject unknown completeness concepts and prompts', function () {
    expect(SavedViewCatalog::forApply(SavedViewPage::Employees, [
        'missing_fields' => 'salary',
        'prompt' => 'employees without email',
    ]))->toBe([]);

    expect(fn () => SavedViewCatalog::normalizeForSave(
        SavedViewPage::Employees,
        ['missing_fields' => 'salary'],
        1,
    ))->toThrow(ValidationException::class);

    expect(fn () => SavedViewCatalog::normalizeForSave(
        SavedViewPage::Employees,
        ['prompt' => 'employees without email'],
        1,
    ))->toThrow(ValidationException::class);

    expect(fn () => SavedViewCatalog::normalizeForSave(
        SavedViewPage::Employees,
        ['emirates_id_presence' => '784-1234-1234567-1'],
        1,
    ))->toThrow(ValidationException::class);
});

test('empty and default values are omitted', function () {
    expect(SavedViewCatalog::forApply(SavedViewPage::Documents, [
        'expiry' => 'all',
        'requirement_status' => '',
        'search' => '  ',
    ]))->toBe([])
        ->and(SavedViewCatalog::forApply(SavedViewPage::Documents, [
            'requirement_status' => 'missing',
        ]))->toBe(['requirement_status' => 'missing'])
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
