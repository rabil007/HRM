<?php

use Illuminate\Support\Facades\Schema;

test('recruitment approval fields migration can roll back and re-run', function () {
    $path = 'database/migrations/2026_10_01_180001_add_recruitment_requirement_approval_fields.php';

    expect(Schema::hasColumn('recruitment_requirements', 'submitted_at'))->toBeTrue()
        ->and(Schema::hasColumn('recruitment_requirements', 'approved_by'))->toBeTrue()
        ->and(Schema::hasColumn('recruitment_requirements', 'return_reason'))->toBeTrue();

    $this->artisan('migrate:rollback', [
        '--path' => $path,
        '--force' => true,
    ])->assertSuccessful();

    expect(Schema::hasColumn('recruitment_requirements', 'submitted_at'))->toBeFalse()
        ->and(Schema::hasColumn('recruitment_requirements', 'approved_by'))->toBeFalse()
        ->and(Schema::hasColumn('recruitment_requirements', 'return_reason'))->toBeFalse();

    $this->artisan('migrate', [
        '--path' => $path,
        '--force' => true,
    ])->assertSuccessful();

    expect(Schema::hasColumn('recruitment_requirements', 'submitted_at'))->toBeTrue()
        ->and(Schema::hasColumn('recruitment_requirements', 'approved_by'))->toBeTrue()
        ->and(Schema::hasColumn('recruitment_requirements', 'returned_by'))->toBeTrue()
        ->and(Schema::hasColumn('recruitment_requirements', 'return_reason'))->toBeTrue();
});
