<?php

use App\Models\CrewTimesheet;
use App\Models\PayrollPeriod;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

$migrationPath = 'database/migrations/2026_07_21_100000_replace_legacy_standby_fields_on_crew_timesheets.php';

test('migration adds unpaid leave days and drops legacy standby columns', function () {
    expect(Schema::hasColumn('crew_timesheets', 'unpaid_leave_days'))->toBeTrue()
        ->and(Schema::hasColumn('crew_timesheets', 'standby_from'))->toBeFalse()
        ->and(Schema::hasColumn('crew_timesheets', 'standby_to'))->toBeFalse()
        ->and(Schema::hasColumn('crew_timesheets', 'standby_days'))->toBeFalse();
});

test('migration rollback restores legacy standby columns and drops unpaid leave days', function () use ($migrationPath) {
    Artisan::call('migrate:rollback', [
        '--path' => $migrationPath,
        '--force' => true,
    ]);

    expect(Schema::hasColumn('crew_timesheets', 'standby_from'))->toBeTrue()
        ->and(Schema::hasColumn('crew_timesheets', 'standby_to'))->toBeTrue()
        ->and(Schema::hasColumn('crew_timesheets', 'standby_days'))->toBeTrue()
        ->and(Schema::hasColumn('crew_timesheets', 'unpaid_leave_days'))->toBeFalse();

    Artisan::call('migrate', [
        '--path' => $migrationPath,
        '--force' => true,
    ]);

    expect(Schema::hasColumn('crew_timesheets', 'unpaid_leave_days'))->toBeTrue()
        ->and(Schema::hasColumn('crew_timesheets', 'standby_days'))->toBeFalse();
});

test('crew timesheet factory produces split operational and unpaid leave fields', function () {
    ['company' => $company, 'employee' => $employee] = makeCrewAssignmentFixtures();
    $dailyPeriod = PayrollPeriod::factory()->for($company)->create();
    $monthlyPeriod = PayrollPeriod::factory()->for($company)->create();

    $daily = CrewTimesheet::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'period_id' => $dailyPeriod->id,
        'sign_on_standby_days' => 2,
        'onsite_days' => 10,
        'sign_off_standby_days' => 1,
    ]);

    $monthly = CrewTimesheet::factory()->monthly()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'period_id' => $monthlyPeriod->id,
        'unpaid_leave_days' => 3,
    ]);

    expect((float) $daily->sign_on_standby_days)->toBe(2.0)
        ->and((float) $daily->onsite_days)->toBe(10.0)
        ->and((float) $daily->sign_off_standby_days)->toBe(1.0)
        ->and((float) $monthly->unpaid_leave_days)->toBe(3.0);
});
