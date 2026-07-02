<?php

use App\Models\PayrollPeriod;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * @return list<string>
 */
function payrollPeriodsIndexNames(): array
{
    return collect(Schema::getIndexes('payroll_periods'))
        ->pluck('name')
        ->values()
        ->all();
}

test('payroll periods unique constraint can be dropped when company foreign key depends on it', function () {
    ['company' => $company] = makeDocumentFixtures();

    PayrollPeriod::factory()->for($company)->create();

    $indexNames = payrollPeriodsIndexNames();

    if (in_array('payroll_periods_company_id_index', $indexNames, true)) {
        Schema::table('payroll_periods', function (Blueprint $table) {
            $table->dropIndex(['company_id']);
        });
    }

    if (! in_array('payroll_periods_company_start_category_unique', $indexNames, true)) {
        Schema::table('payroll_periods', function (Blueprint $table) {
            $table->unique(
                ['company_id', 'start_date', 'payroll_category'],
                'payroll_periods_company_start_category_unique',
            );
        });
    }

    expect(payrollPeriodsIndexNames())->toContain('payroll_periods_company_start_category_unique');

    DB::table('migrations')
        ->where('migration', '2026_06_29_165737_drop_unique_constraint_from_payroll_periods_table')
        ->delete();

    Artisan::call('migrate', [
        '--force' => true,
        '--path' => 'database/migrations/2026_06_29_165737_drop_unique_constraint_from_payroll_periods_table.php',
    ]);

    expect(payrollPeriodsIndexNames())->not->toContain('payroll_periods_company_start_category_unique')
        ->and(payrollPeriodsIndexNames())->toContain('payroll_periods_company_id_index');
});
