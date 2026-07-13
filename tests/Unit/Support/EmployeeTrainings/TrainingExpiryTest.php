<?php

use App\Support\EmployeeTrainings\TrainingExpiry;
use App\Support\EmployeeTrainings\TrainingExpiryStatus;
use Carbon\Carbon;

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-07-13'));
});

afterEach(function () {
    Carbon::setTestNow();
});

test('training expiry resolve returns null when expiry date is missing', function () {
    expect(TrainingExpiry::resolve(null))->toBeNull();
});

test('training expiry resolve returns expired when date is in the past', function () {
    expect(TrainingExpiry::resolve('2026-07-12'))->toBe(TrainingExpiryStatus::Expired);
});

test('training expiry resolve returns expiring buckets within window', function () {
    expect(TrainingExpiry::resolve('2026-07-13'))->toBe(TrainingExpiryStatus::Expiring7);
    expect(TrainingExpiry::resolve('2026-07-20'))->toBe(TrainingExpiryStatus::Expiring7);
    expect(TrainingExpiry::resolve('2026-07-28'))->toBe(TrainingExpiryStatus::Expiring15);
    expect(TrainingExpiry::resolve('2026-08-12'))->toBe(TrainingExpiryStatus::Expiring30);
    expect(TrainingExpiry::resolve('2026-09-01'))->toBe(TrainingExpiryStatus::Valid);
});

test('training expiry isValidFilter accepts known filters', function () {
    expect(TrainingExpiry::isValidFilter('all'))->toBeTrue();
    expect(TrainingExpiry::isValidFilter('expired'))->toBeTrue();
    expect(TrainingExpiry::isValidFilter('expiring_7'))->toBeTrue();
    expect(TrainingExpiry::isValidFilter('expiring_15'))->toBeTrue();
    expect(TrainingExpiry::isValidFilter('expiring_30'))->toBeTrue();
    expect(TrainingExpiry::isValidFilter('valid'))->toBeFalse();
    expect(TrainingExpiry::isValidFilter('bogus'))->toBeFalse();
});

test('training expiry remaining days and human label', function () {
    expect(TrainingExpiry::remainingDays('2026-07-15'))->toBe(2);
    expect(TrainingExpiry::humanLabel('2026-07-15'))->toBe('Expires in 2 days');
    expect(TrainingExpiry::humanLabel(null))->toBe('No Expiry');
    expect(TrainingExpiry::humanLabel('2026-07-12'))->toBe('Expired yesterday');
});
