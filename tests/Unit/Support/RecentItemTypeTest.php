<?php

use App\Enums\RecentItemType;
use App\Models\User;

test('recent item types are a closed catalog with stable prefixes', function () {
    expect(RecentItemType::cases())->toHaveCount(5)
        ->and(RecentItemType::Employee->value)->toBe('employee')
        ->and(RecentItemType::Document->resultId(12))->toBe('document:12')
        ->and(RecentItemType::CrewAssignment->label())->toBe('Crew')
        ->and(RecentItemType::PayrollPeriod->resultPrefix())->toBe('payroll');
});

test('accessibility follows domain view permissions rather than platform access', function () {
    $user = User::factory()->create();

    expect(RecentItemType::Employee->isAccessible($user))->toBeFalse()
        ->and(RecentItemType::tryFrom('App\\Models\\Employee'))->toBeNull();
});
