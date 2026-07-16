<?php

use App\Models\CrewAssignment;
use App\Models\EmployeeDeployment;
use App\Support\CrewMovements\LegacyDeploymentBackfillService;
use Carbon\Carbon;
use Illuminate\Support\Facades\File;

test('backfill command defaults to dry run and supports filters limit and report', function () {
    ['company' => $companyA, 'employee' => $employeeA] = makeCrewAssignmentFixtures();
    ['company' => $companyB, 'employee' => $employeeB] = makeCrewAssignmentFixtures();

    $deploymentA1 = EmployeeDeployment::factory()->forEmployee($employeeA)->create([
        'joined_date' => Carbon::today($companyA->timezone)->subDays(5)->toDateString(),
        'disembarked_date' => null,
    ]);
    EmployeeDeployment::factory()->forEmployee($employeeA)->create([
        'joined_date' => Carbon::today($companyA->timezone)->subDays(40)->toDateString(),
        'disembarked_date' => Carbon::today($companyA->timezone)->subDays(10)->toDateString(),
    ]);
    EmployeeDeployment::factory()->forEmployee($employeeB)->create([
        'joined_date' => Carbon::today($companyB->timezone)->subDays(5)->toDateString(),
        'disembarked_date' => null,
    ]);

    $reportPath = storage_path('app/reports/crew-backfill-test.json');
    File::delete($reportPath);

    $this->artisan('crew-movements:backfill', [
        '--company' => $companyA->id,
        '--limit' => 1,
        '--report' => $reportPath,
    ])
        ->expectsOutputToContain('DRY RUN')
        ->assertSuccessful();

    expect(CrewAssignment::query()->count())->toBe(0)
        ->and(File::exists($reportPath))->toBeTrue();

    $report = json_decode(File::get($reportPath), true);
    expect($report['mode'])->toBe('dry_run')
        ->and($report['summary']['scanned'])->toBe(1)
        ->and($report['rows'])->toHaveCount(1)
        ->and($report['rows'][0]['company_id'])->toBe($companyA->id);

    $this->artisan('crew-movements:backfill', [
        '--deployment' => $deploymentA1->id,
        '--commit' => true,
    ])
        ->expectsOutputToContain('COMMIT MODE')
        ->assertSuccessful();

    expect(CrewAssignment::query()->where('employee_deployment_id', $deploymentA1->id)->count())->toBe(1);

    $this->artisan('crew-movements:backfill', [
        '--deployment' => $deploymentA1->id,
        '--commit' => true,
    ])->assertSuccessful();

    expect(CrewAssignment::query()->where('employee_deployment_id', $deploymentA1->id)->count())->toBe(1);
});

test('backfill command company filter ignores other companies', function () {
    ['company' => $companyA, 'employee' => $employeeA] = makeCrewAssignmentFixtures();
    ['company' => $companyB, 'employee' => $employeeB] = makeCrewAssignmentFixtures();

    EmployeeDeployment::factory()->forEmployee($employeeA)->create([
        'joined_date' => Carbon::today($companyA->timezone)->subDays(5)->toDateString(),
        'disembarked_date' => null,
    ]);
    EmployeeDeployment::factory()->forEmployee($employeeB)->create([
        'joined_date' => Carbon::today($companyB->timezone)->subDays(5)->toDateString(),
        'disembarked_date' => null,
    ]);

    $this->artisan('crew-movements:backfill', [
        '--company' => $companyA->id,
        '--commit' => true,
    ])->assertSuccessful();

    expect(CrewAssignment::query()->where('company_id', $companyA->id)->count())->toBe(1)
        ->and(CrewAssignment::query()->where('company_id', $companyB->id)->count())->toBe(0);
});

test('legacy backfill service is wired for artisan command', function () {
    expect(app(LegacyDeploymentBackfillService::class))->toBeInstanceOf(LegacyDeploymentBackfillService::class);
});
