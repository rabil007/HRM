<?php

use Illuminate\Support\Facades\Schema;

test('recruitment salary fields migration can roll back and re-run', function () {
    $path = 'database/migrations/2026_10_05_160000_add_salary_fields_to_recruitment_requirement_lines_table.php';

    expect(Schema::hasColumn('recruitment_requirement_lines', 'salary_min'))->toBeTrue()
        ->and(Schema::hasColumn('recruitment_requirement_lines', 'salary_max'))->toBeTrue()
        ->and(Schema::hasColumn('recruitment_requirement_lines', 'salary_currency_code'))->toBeTrue();

    $this->artisan('migrate:rollback', [
        '--path' => $path,
        '--force' => true,
    ])->assertSuccessful();

    expect(Schema::hasColumn('recruitment_requirement_lines', 'salary_min'))->toBeFalse()
        ->and(Schema::hasColumn('recruitment_requirement_lines', 'salary_max'))->toBeFalse()
        ->and(Schema::hasColumn('recruitment_requirement_lines', 'salary_currency_code'))->toBeFalse();

    $this->artisan('migrate', [
        '--path' => $path,
        '--force' => true,
    ])->assertSuccessful();

    expect(Schema::hasColumn('recruitment_requirement_lines', 'salary_min'))->toBeTrue()
        ->and(Schema::hasColumn('recruitment_requirement_lines', 'salary_max'))->toBeTrue()
        ->and(Schema::hasColumn('recruitment_requirement_lines', 'salary_currency_code'))->toBeTrue();
});
