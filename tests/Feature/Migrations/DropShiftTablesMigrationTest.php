<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

test('shift tables are not present after migrations', function () {
    expect(Schema::hasTable('shifts'))->toBeFalse()
        ->and(Schema::hasTable('employee_shifts'))->toBeFalse()
        ->and(Schema::hasTable('attendance_records'))->toBeTrue()
        ->and(Schema::hasColumn('attendance_records', 'shift_id'))->toBeFalse();
});
