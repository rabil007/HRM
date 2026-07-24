<?php

use App\Support\EmployeeDocuments\DocumentExpiry;
use App\Support\EmployeeDocuments\DocumentExpiryStatus;
use Carbon\Carbon;

beforeEach(function () {
    Carbon::setTestNow('2026-05-20');
});

afterEach(function () {
    Carbon::setTestNow();
});

test('resolve returns null when expiry date is missing', function () {
    expect(DocumentExpiry::resolve(null))->toBeNull();
    expect(DocumentExpiry::humanLabel(null))->toBe('No Expiry');
});

test('resolve returns expired for past dates', function () {
    expect(DocumentExpiry::resolve('2026-05-17'))->toBe(DocumentExpiryStatus::Expired);
    expect(DocumentExpiry::remainingDays('2026-05-17'))->toBe(-3);
    expect(DocumentExpiry::humanLabel('2026-05-17'))->toBe('Expired 3 days ago');
});

test('resolve returns expiring tiers within windows', function () {
    expect(DocumentExpiry::resolve('2026-05-27'))->toBe(DocumentExpiryStatus::Expiring7);
    expect(DocumentExpiry::resolve('2026-06-04'))->toBe(DocumentExpiryStatus::Expiring15);
    expect(DocumentExpiry::resolve('2026-06-19'))->toBe(DocumentExpiryStatus::Expiring30);
    expect(DocumentExpiry::resolve('2026-07-01'))->toBe(DocumentExpiryStatus::Valid);
});

test('human label handles today and tomorrow', function () {
    expect(DocumentExpiry::humanLabel('2026-05-20'))->toBe('Expires today');
    expect(DocumentExpiry::humanLabel('2026-05-21'))->toBe('Expires tomorrow');
    expect(DocumentExpiry::humanLabel('2026-05-19'))->toBe('Expired yesterday');
});

test('today falls in the seven day status window while keeping a precise label', function () {
    expect(DocumentExpiry::remainingDays('2026-05-20'))->toBe(0);
    expect(DocumentExpiry::resolve('2026-05-20'))->toBe(DocumentExpiryStatus::Expiring7);
    expect(DocumentExpiry::humanLabel('2026-05-20'))->toBe('Expires today');
});

test('isValidFilter accepts known filters', function () {
    expect(DocumentExpiry::isValidFilter('all'))->toBeTrue();
    expect(DocumentExpiry::isValidFilter('expired'))->toBeTrue();
    expect(DocumentExpiry::isValidFilter('invalid'))->toBeFalse();
});
