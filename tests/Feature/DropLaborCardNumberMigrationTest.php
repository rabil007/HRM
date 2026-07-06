<?php

use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Models\EmployeeProfileTemplate;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * @return Migration
 */
function runDropLaborCardNumberMigration(): object
{
    /** @var Migration $migration */
    $migration = require database_path('migrations/2026_07_06_150744_drop_labor_card_number_from_employees_table.php');

    return $migration;
}

function restoreLaborCardNumberColumnForMigrationTest(): void
{
    if (! Schema::hasColumn('employees', 'labor_card_number')) {
        Schema::table('employees', function (Blueprint $table): void {
            $table->string('labor_card_number', 100)->nullable()->after('passport_number');
        });
    }
}

test('drop labor card number migration copies value to latest active contract', function () {
    ['company' => $company] = makePayrollFixtures();

    restoreLaborCardNumberColumnForMigrationTest();

    $employee = Employee::factory()->forCompany($company)->create();

    DB::table('employees')
        ->where('id', $employee->id)
        ->update(['labor_card_number' => 'CARD-123']);

    $contract = EmployeeContract::factory()->create([
        'employee_id' => $employee->id,
        'company_id' => $company->id,
        'status' => 'active',
        'labor_contract_id' => null,
    ]);

    runDropLaborCardNumberMigration()->up();

    expect($contract->fresh()->labor_contract_id)->toBe('CARD-123')
        ->and(Schema::hasColumn('employees', 'labor_card_number'))->toBeFalse();
});

test('drop labor card number migration does not overwrite existing contract labor_contract_id', function () {
    ['company' => $company] = makePayrollFixtures();

    restoreLaborCardNumberColumnForMigrationTest();

    $employee = Employee::factory()->forCompany($company)->create();

    DB::table('employees')
        ->where('id', $employee->id)
        ->update(['labor_card_number' => 'CARD-999']);

    $contract = EmployeeContract::factory()->create([
        'employee_id' => $employee->id,
        'company_id' => $company->id,
        'status' => 'active',
        'labor_contract_id' => 'EXISTING-111',
    ]);

    runDropLaborCardNumberMigration()->up();

    expect($contract->fresh()->labor_contract_id)->toBe('EXISTING-111');
});

test('drop labor card number migration skips employees without an active contract', function () {
    ['company' => $company] = makePayrollFixtures();

    restoreLaborCardNumberColumnForMigrationTest();

    $employee = Employee::factory()->forCompany($company)->create();

    EmployeeContract::query()->where('employee_id', $employee->id)->delete();

    DB::table('employees')
        ->where('id', $employee->id)
        ->update(['labor_card_number' => 'ORPHAN-CARD']);

    runDropLaborCardNumberMigration()->up();

    expect(EmployeeContract::query()->where('employee_id', $employee->id)->count())->toBe(0)
        ->and(Schema::hasColumn('employees', 'labor_card_number'))->toBeFalse();
});

test('drop labor card number migration removes field from employee profile templates', function () {
    ['company' => $company] = makePayrollFixtures();

    restoreLaborCardNumberColumnForMigrationTest();

    $template = EmployeeProfileTemplate::query()->create([
        'company_id' => $company->id,
        'name' => 'Legacy template',
        'configuration_json' => [
            'version' => 1,
            'tabs' => ['personal' => ['visible' => true]],
            'fields' => [
                'employees' => [
                    'labor_card_number' => ['visible' => true, 'required' => false],
                    'name' => ['visible' => true, 'required' => true],
                ],
            ],
        ],
    ]);

    runDropLaborCardNumberMigration()->up();

    $configuration = $template->fresh()->configuration_json;

    expect($configuration['fields']['employees'] ?? [])
        ->not->toHaveKey('labor_card_number')
        ->toHaveKey('name');
});
