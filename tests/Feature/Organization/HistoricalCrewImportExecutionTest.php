<?php

use App\Enums\CrewAssignmentStatus;
use App\Enums\CrewMovementAction;
use App\Enums\CrewPhaseCode;
use App\Enums\HistoricalCrewImportBatchStatus;
use App\Enums\HistoricalCrewImportRowStatus;
use App\Models\CrewAccommodationStay;
use App\Models\CrewAssignment;
use App\Models\CrewOperationalAlert;
use App\Models\CrewPlanningAssignment;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeSeaService;
use App\Models\HistoricalCrewImportBatch;
use App\Models\HistoricalCrewImportRow;
use App\Support\CrewMovements\CrewMovementService;
use App\Support\CrewMovements\Historical\HistoricalCrewAssignmentData;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Activitylog\Models\Activity;

test('ready rows import as active historical_import with batch linkage and sea service when ending at P5', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $employee->update(['employee_no' => '3119', 'name' => 'Ranjan Rai']);
    $vessel = makeCrewMovementVessel('OMS Import Vessel', $company);

    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.create_historical',
    ]);
    $user->update(['current_company_id' => $company->id]);

    $file = makeHistoricalCrewImportFile([
        [
            'employee_no' => '3119',
            'vessel' => $vessel->name,
            'rank' => $rank->name,
            'vessel_join_date' => '2024-01-15',
            'disembark_date' => '2024-07-20',
            'remarks' => 'Phase 3 import',
        ],
    ]);

    $key = historicalImportIdempotencyKey();

    $response = $this->actingAs($user)
        ->postJson(route('organization.crew-assignments.historical.import.execute'), [
            'file' => $file,
            'idempotency_key' => $key,
            'confirmed' => '1',
        ]);

    $response->assertOk()
        ->assertJsonPath('imported_rows', 1)
        ->assertJsonPath('blocked_rows', 0)
        ->assertJsonPath('failed_rows', 0)
        ->assertJsonPath('status', HistoricalCrewImportBatchStatus::Completed->value);

    $assignment = CrewAssignment::query()
        ->where('company_id', $company->id)
        ->where('employee_id', $employee->id)
        ->where('source', HistoricalCrewAssignmentData::SOURCE_IMPORT)
        ->first();

    expect($assignment)->not->toBeNull()
        ->and($assignment->status)->toBe(CrewAssignmentStatus::Active)
        ->and($assignment->closed_at)->toBeNull()
        ->and($assignment->historical_import_batch_id)->toBe($response->json('id'))
        ->and($assignment->phases)->toHaveCount(2)
        ->and($assignment->phases->sortBy('sequence')->values()->pluck('phase_code')->all())->toBe([
            CrewPhaseCode::OnVessel,
            CrewPhaseCode::DemobStandby,
        ]);

    $batch = HistoricalCrewImportBatch::query()->findOrFail($response->json('id'));
    expect($batch->company_id)->toBe($company->id)
        ->and($batch->created_by)->toBe($user->id)
        ->and($batch->original_filename)->toContain('historical-crew-import');

    $row = HistoricalCrewImportRow::query()->where('historical_crew_import_batch_id', $batch->id)->first();
    expect($row)->not->toBeNull()
        ->and($row->status)->toBe(HistoricalCrewImportRowStatus::Imported)
        ->and($row->crew_assignment_id)->toBe($assignment->id)
        ->and($row->assignment_no)->toBe($assignment->assignment_no);

    expect(EmployeeSeaService::query()->where('employee_id', $employee->id)->count())->toBe(1);

    $activity = Activity::query()
        ->where('event', 'historical_crew_import_completed')
        ->where('subject_type', HistoricalCrewImportBatch::class)
        ->where('subject_id', $batch->id)
        ->latest('id')
        ->first();

    expect($activity)->not->toBeNull()
        ->and((int) $activity->company_id)->toBe($company->id);
});

test('ready rows with home import as completed historical assignment', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $employee->update(['employee_no' => '3119']);
    $vessel = makeCrewMovementVessel('OMS Completed Import Vessel', $company);

    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.create_historical',
    ]);
    $user->update(['current_company_id' => $company->id]);

    $response = $this->actingAs($user)
        ->postJson(route('organization.crew-assignments.historical.import.execute'), [
            'file' => makeHistoricalCrewImportFile([
                [
                    'employee_no' => '3119',
                    'vessel' => $vessel->name,
                    'rank' => $rank->name,
                    'vessel_join_date' => '2024-01-15',
                    'disembark_date' => '2024-07-20',
                    'travel_home_date' => '2024-07-22',
                ],
            ]),
            'idempotency_key' => historicalImportIdempotencyKey('home'),
            'confirmed' => '1',
        ])
        ->assertOk();

    $assignment = CrewAssignment::query()
        ->where('employee_id', $employee->id)
        ->where('source', HistoricalCrewAssignmentData::SOURCE_IMPORT)
        ->firstOrFail();

    expect($assignment->status)->toBe(CrewAssignmentStatus::Completed)
        ->and($assignment->phases->pluck('phase_code')->all())->toContain(CrewPhaseCode::HomeRedeploy)
        ->and(EmployeeSeaService::query()->where('employee_id', $employee->id)->count())->toBe(1);
});

test('partial import skips blocked rows and imports ready plus warning rows', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $employee->update(['employee_no' => '3119', 'status' => 'terminated']);
    $vessel = makeCrewMovementVessel('Partial Vessel', $company);

    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.create_historical',
    ]);
    $user->update(['current_company_id' => $company->id]);

    $file = makeHistoricalCrewImportFile([
        [
            'employee_no' => '3119',
            'vessel' => $vessel->name,
            'rank' => $rank->name,
            'vessel_join_date' => '2024-01-15',
            'disembark_date' => '2024-07-20',
            'travel_home_date' => '2024-07-23',
        ],
        [
            'employee_no' => 'MISSING-999',
            'vessel' => $vessel->name,
            'rank' => $rank->name,
            'vessel_join_date' => '2023-01-01',
            'disembark_date' => '2023-06-01',
        ],
    ]);

    $response = $this->actingAs($user)
        ->postJson(route('organization.crew-assignments.historical.import.execute'), [
            'file' => $file,
            'idempotency_key' => historicalImportIdempotencyKey(),
            'confirmed' => '1',
        ]);

    $response->assertOk()
        ->assertJsonPath('imported_rows', 1)
        ->assertJsonPath('skipped_rows', 1)
        ->assertJsonPath('status', HistoricalCrewImportBatchStatus::CompletedWithErrors->value);

    expect(CrewAssignment::query()->where('source', HistoricalCrewAssignmentData::SOURCE_IMPORT)->count())->toBe(1);

    $statuses = HistoricalCrewImportRow::query()
        ->where('historical_crew_import_batch_id', $response->json('id'))
        ->pluck('status')
        ->map(fn ($s) => $s->value)
        ->all();

    expect($statuses)->toContain(HistoricalCrewImportRowStatus::ImportedWithWarnings->value)
        ->and($statuses)->toContain(HistoricalCrewImportRowStatus::Skipped->value);
});

test('idempotent double submit returns same batch without duplicating assignments', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $employee->update(['employee_no' => '3119']);
    $vessel = makeCrewMovementVessel('Idem Vessel', $company);

    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.create_historical',
    ]);
    $user->update(['current_company_id' => $company->id]);

    $row = [
        'employee_no' => '3119',
        'vessel' => $vessel->name,
        'rank' => $rank->name,
        'vessel_join_date' => '2024-02-01',
        'disembark_date' => '2024-08-01',
    ];
    $key = historicalImportIdempotencyKey('idem');

    $first = $this->actingAs($user)
        ->postJson(route('organization.crew-assignments.historical.import.execute'), [
            'file' => makeHistoricalCrewImportFile([$row]),
            'idempotency_key' => $key,
            'confirmed' => '1',
        ])
        ->assertOk();

    $second = $this->actingAs($user)
        ->postJson(route('organization.crew-assignments.historical.import.execute'), [
            'file' => makeHistoricalCrewImportFile([$row]),
            'idempotency_key' => $key,
            'confirmed' => '1',
        ])
        ->assertOk();

    expect($second->json('id'))->toBe($first->json('id'))
        ->and(CrewAssignment::query()->where('source', HistoricalCrewAssignmentData::SOURCE_IMPORT)->count())->toBe(1);
});

test('reimporting same historical interval is blocked by domain overlap', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $employee->update(['employee_no' => '3119']);
    $vessel = makeCrewMovementVessel('Reimport Vessel', $company);

    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.create_historical',
    ]);
    $user->update(['current_company_id' => $company->id]);

    $row = [
        'employee_no' => '3119',
        'vessel' => $vessel->name,
        'rank' => $rank->name,
        'vessel_join_date' => '2024-03-01',
        'disembark_date' => '2024-09-01',
    ];

    $this->actingAs($user)
        ->postJson(route('organization.crew-assignments.historical.import.execute'), [
            'file' => makeHistoricalCrewImportFile([$row]),
            'idempotency_key' => historicalImportIdempotencyKey('a'),
            'confirmed' => '1',
        ])
        ->assertOk()
        ->assertJsonPath('imported_rows', 1);

    $this->actingAs($user)
        ->postJson(route('organization.crew-assignments.historical.import.execute'), [
            'file' => makeHistoricalCrewImportFile([$row]),
            'idempotency_key' => historicalImportIdempotencyKey('b'),
            'confirmed' => '1',
        ])
        ->assertUnprocessable();

    expect(CrewAssignment::query()->where('source', HistoricalCrewAssignmentData::SOURCE_IMPORT)->count())->toBe(1);
});

test('final import revalidation blocks row when overlapping assignment appears after preview', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $employee->update(['employee_no' => '3119']);
    $vessel = makeCrewMovementVessel('Race Vessel', $company);

    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.create_historical',
    ]);
    $user->update(['current_company_id' => $company->id]);

    $file = makeHistoricalCrewImportFile([
        [
            'employee_no' => '3119',
            'vessel' => $vessel->name,
            'rank' => $rank->name,
            'vessel_join_date' => '2024-04-01',
            'disembark_date' => '2024-10-01',
        ],
    ]);

    $this->actingAs($user)
        ->postJson(route('organization.crew-assignments.historical.import.validate'), [
            'file' => $file,
        ])
        ->assertOk()
        ->assertJsonPath('summary.ready', 1);

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.historical.store'), [
            'employee_id' => $employee->id,
            'vessel_id' => $vessel->id,
            'rank_id' => $rank->id,
            'joined_vessel_at' => '2024-04-01',
            'disembarked_at' => '2024-10-01',
        ])
        ->assertRedirect();

    $response = $this->actingAs($user)
        ->postJson(route('organization.crew-assignments.historical.import.execute'), [
            'file' => makeHistoricalCrewImportFile([
                [
                    'employee_no' => '3119',
                    'vessel' => $vessel->name,
                    'rank' => $rank->name,
                    'vessel_join_date' => '2024-04-01',
                    'disembark_date' => '2024-10-01',
                ],
            ]),
            'idempotency_key' => historicalImportIdempotencyKey('race'),
            'confirmed' => '1',
        ]);

    $response->assertUnprocessable();

    expect(CrewAssignment::query()->where('source', HistoricalCrewAssignmentData::SOURCE_IMPORT)->count())->toBe(0);
});

test('hidden employee cannot be imported and remains indistinguishable from missing', function () {
    ['user' => $user, 'company' => $company, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $visibleDept = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Visible Office',
        'code' => 'HV'.Str::upper(Str::random(3)),
        'status' => 'active',
        'include_in_attendance_leave' => true,
    ]);
    $hiddenDept = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Hidden Marine',
        'code' => 'HH'.Str::upper(Str::random(3)),
        'status' => 'active',
        'include_in_attendance_leave' => true,
    ]);
    Employee::factory()->forCompany($company)->create([
        'employee_no' => 'HID-55',
        'department_id' => $hiddenDept->id,
        'status' => 'active',
    ]);
    $vessel = makeCrewMovementVessel('Hidden Import Vessel', $company);

    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.create_historical',
    ]);
    restrictUserToDepartments($user, $company, [$visibleDept->id]);
    $user->update(['current_company_id' => $company->id]);

    $this->actingAs($user)
        ->postJson(route('organization.crew-assignments.historical.import.execute'), [
            'file' => makeHistoricalCrewImportFile([
                [
                    'employee_no' => 'HID-55',
                    'vessel' => $vessel->name,
                    'rank' => $rank->name,
                    'vessel_join_date' => '2024-01-15',
                    'disembark_date' => '2024-07-20',
                ],
            ]),
            'idempotency_key' => historicalImportIdempotencyKey('hid'),
            'confirmed' => '1',
        ])
        ->assertUnprocessable();

    expect(CrewAssignment::query()->count())->toBe(0);
});

test('historical import does not mutate active assignment planning stays alerts or payroll tables', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $employee->update(['employee_no' => '3119']);
    $vessel = makeCrewMovementVessel('Isolation Vessel', $company);
    $active = makeActiveOnVesselAssignment($company, $employee, $rank, $vessel);

    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.create_historical',
    ]);
    $user->update(['current_company_id' => $company->id]);

    $beforePhaseId = $active->current_phase_id;
    $beforePlanning = CrewPlanningAssignment::query()->count();
    $beforeStays = CrewAccommodationStay::query()->count();
    $beforeAlerts = class_exists(CrewOperationalAlert::class) ? CrewOperationalAlert::query()->count() : 0;

    $this->actingAs($user)
        ->postJson(route('organization.crew-assignments.historical.import.execute'), [
            'file' => makeHistoricalCrewImportFile([
                [
                    'employee_no' => '3119',
                    'vessel' => $vessel->name,
                    'rank' => $rank->name,
                    'vessel_join_date' => '2024-01-15',
                    'disembark_date' => '2024-07-20',
                    'travel_home_date' => '2024-07-20',
                ],
            ]),
            'idempotency_key' => historicalImportIdempotencyKey('iso'),
            'confirmed' => '1',
        ])
        ->assertOk()
        ->assertJsonPath('imported_rows', 1);

    $active->refresh();
    expect($active->status)->toBe(CrewAssignmentStatus::Active)
        ->and($active->current_phase_id)->toBe($beforePhaseId)
        ->and(CrewPlanningAssignment::query()->count())->toBe($beforePlanning)
        ->and(CrewAccommodationStay::query()->count())->toBe($beforeStays);

    if (class_exists(CrewOperationalAlert::class)) {
        expect(CrewOperationalAlert::query()->count())->toBe($beforeAlerts);
    }
});

test('importing older history before an active assignment updates previous linkage chronologically', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $employee->update(['employee_no' => '3119']);
    $vessel = makeCrewMovementVessel('Chrono Vessel', $company);
    $active = makeActiveOnVesselAssignment($company, $employee, $rank, $vessel, [
        'previous_assignment_id' => null,
    ]);

    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.create_historical',
    ]);
    $user->update(['current_company_id' => $company->id]);

    $this->actingAs($user)
        ->postJson(route('organization.crew-assignments.historical.import.execute'), [
            'file' => makeHistoricalCrewImportFile([
                [
                    'employee_no' => '3119',
                    'vessel' => $vessel->name,
                    'rank' => $rank->name,
                    'vessel_join_date' => '2024-01-15',
                    'disembark_date' => '2024-07-20',
                    'travel_home_date' => '2024-07-20',
                ],
            ]),
            'idempotency_key' => historicalImportIdempotencyKey('chrono'),
            'confirmed' => '1',
        ])
        ->assertOk();

    $historical = CrewAssignment::query()
        ->where('source', HistoricalCrewAssignmentData::SOURCE_IMPORT)
        ->firstOrFail();

    $active->refresh();
    expect($active->previous_assignment_id)->toBe($historical->id);
});

test('one failed or blocked employee does not prevent other successful imports', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $employee->update(['employee_no' => '3119']);
    $other = Employee::factory()->forCompany($company)->create([
        'employee_no' => '3220',
        'status' => 'active',
    ]);
    $vessel = makeCrewMovementVessel('Write Fail Vessel', $company);

    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.create_historical',
    ]);
    $user->update(['current_company_id' => $company->id]);

    $fileRows = [
        [
            'employee_no' => '3220',
            'vessel' => $vessel->name,
            'rank' => $rank->name,
            'vessel_join_date' => '2023-01-01',
            'disembark_date' => '2023-06-01',
        ],
        [
            'employee_no' => '3119',
            'vessel' => $vessel->name,
            'rank' => $rank->name,
            'vessel_join_date' => '2024-01-01',
            'disembark_date' => '2024-06-30',
        ],
    ];

    $this->actingAs($user)
        ->postJson(route('organization.crew-assignments.historical.import.validate'), [
            'file' => makeHistoricalCrewImportFile($fileRows),
        ])
        ->assertOk()
        ->assertJsonPath('summary.ready', 2);

    $employee->delete();

    $response = $this->actingAs($user)
        ->postJson(route('organization.crew-assignments.historical.import.execute'), [
            'file' => makeHistoricalCrewImportFile($fileRows),
            'idempotency_key' => historicalImportIdempotencyKey('write-fail'),
            'confirmed' => '1',
        ]);

    $response->assertOk();
    expect($response->json('imported_rows'))->toBeGreaterThanOrEqual(1)
        ->and(CrewAssignment::query()->where('employee_id', $other->id)->where('source', HistoricalCrewAssignmentData::SOURCE_IMPORT)->exists())->toBeTrue()
        ->and(CrewAssignment::query()->where('employee_id', $employee->id)->where('source', HistoricalCrewAssignmentData::SOURCE_IMPORT)->exists())->toBeFalse();
});

test('batch detail and result workbook download work for company actor', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $employee->update(['employee_no' => '3119']);
    $vessel = makeCrewMovementVessel('Report Vessel', $company);

    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.create_historical',
    ]);
    $user->update(['current_company_id' => $company->id]);

    $file = makeHistoricalCrewImportFile([
        [
            'employee_no' => '3119',
            'vessel' => $vessel->name,
            'rank' => $rank->name,
            'vessel_join_date' => '2024-01-15',
            'disembark_date' => '2024-07-20',
        ],
        [
            'employee_no' => 'NOPE',
            'vessel' => $vessel->name,
            'rank' => $rank->name,
            'vessel_join_date' => '2023-01-01',
            'disembark_date' => '2023-02-01',
            'remarks' => '=CMD()',
        ],
    ]);

    $import = $this->actingAs($user)
        ->postJson(route('organization.crew-assignments.historical.import.execute'), [
            'file' => $file,
            'idempotency_key' => historicalImportIdempotencyKey('report'),
            'confirmed' => '1',
        ])
        ->assertOk();

    $batchId = $import->json('id');

    $this->actingAs($user)
        ->getJson(route('organization.crew-assignments.historical.import.batches.show', $batchId))
        ->assertOk()
        ->assertJsonPath('id', $batchId)
        ->assertJsonPath('imported_rows', 1);

    $download = $this->actingAs($user)
        ->get(route('organization.crew-assignments.historical.import.batches.result', $batchId));

    $download->assertOk();
    expect($download->headers->get('content-disposition'))->toContain('Historical_Import_');

    $tempPath = tempnam(sys_get_temp_dir(), 'hist-result-').'.xlsx';
    file_put_contents($tempPath, $download->streamedContent());
    $sheet = IOFactory::load($tempPath)->getActiveSheet()->toArray();
    $flat = collect($sheet)->flatten()->map(fn ($v) => (string) $v)->implode(' ');
    expect($flat)->toContain('3119')
        ->and($flat)->not->toContain('hidden department');
    @unlink($tempPath);
});

test('result workbook reports Training End as last movement when inferred state is Join Standby', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $employee->update(['employee_no' => 'TRAIN01', 'status' => 'active']);
    $vessel = makeCrewMovementVessel('Training End Result Vessel', $company);

    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.create_historical',
    ]);
    $user->update(['current_company_id' => $company->id]);

    $file = makeHistoricalCrewImportFile([
        [
            'employee_no' => 'TRAIN01',
            'vessel' => $vessel->name,
            'rank' => $rank->name,
            'join_standby_date' => '2024-09-03',
            'training_start_date' => '2024-09-05',
            'training_end_date' => '2024-09-10',
        ],
    ]);

    $import = $this->actingAs($user)
        ->postJson(route('organization.crew-assignments.historical.import.execute'), [
            'file' => $file,
            'idempotency_key' => historicalImportIdempotencyKey('training-end-result'),
            'confirmed' => '1',
        ])
        ->assertOk();

    $assignment = CrewAssignment::query()->where('employee_id', $employee->id)->firstOrFail();
    expect($assignment->status)->toBe(CrewAssignmentStatus::Active)
        ->and($assignment->currentPhase?->phase_code)->toBe(CrewPhaseCode::JoinStandby);

    $download = $this->actingAs($user)
        ->get(route('organization.crew-assignments.historical.import.batches.result', $import->json('id')));

    $download->assertOk();
    $tempPath = tempnam(sys_get_temp_dir(), 'hist-training-result-').'.xlsx';
    file_put_contents($tempPath, $download->streamedContent());
    $rows = IOFactory::load($tempPath)->getActiveSheet()->toArray();
    @unlink($tempPath);

    $header = $rows[0];
    $data = $rows[1];
    $lastMovementIndex = array_search('Last Movement', $header, true);
    $inferredIndex = array_search('Inferred State', $header, true);

    expect($lastMovementIndex)->not->toBeFalse()
        ->and($inferredIndex)->not->toBeFalse()
        ->and((string) $data[$lastMovementIndex])->toContain('Training End')
        ->and((string) $data[$lastMovementIndex])->toContain('10 Sep')
        ->and((string) $data[$lastMovementIndex])->not->toContain('Join Standby —')
        ->and((string) $data[$inferredIndex])->toBe('Join Standby');
});

test('result workbook reports Disembarked as last movement for Active P5 endings', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $employee->update(['employee_no' => 'P5RES01', 'status' => 'active']);
    $vessel = makeCrewMovementVessel('P5 Result Vessel', $company);

    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.create_historical',
    ]);
    $user->update(['current_company_id' => $company->id]);

    $file = makeHistoricalCrewImportFile([
        [
            'employee_no' => 'P5RES01',
            'vessel' => $vessel->name,
            'rank' => $rank->name,
            'vessel_join_date' => '2024-01-15',
            'disembark_date' => '2024-07-20',
        ],
    ]);

    $import = $this->actingAs($user)
        ->postJson(route('organization.crew-assignments.historical.import.execute'), [
            'file' => $file,
            'idempotency_key' => historicalImportIdempotencyKey('p5-result'),
            'confirmed' => '1',
        ])
        ->assertOk();

    $download = $this->actingAs($user)
        ->get(route('organization.crew-assignments.historical.import.batches.result', $import->json('id')));
    $path = tempnam(sys_get_temp_dir(), 'hist-p5-result-').'.xlsx';
    file_put_contents($path, $download->streamedContent());
    $rows = IOFactory::load($path)->getActiveSheet()->toArray();
    @unlink($path);

    $header = $rows[0];
    $data = $rows[1];
    $lastIdx = array_search('Last Movement', $header, true);
    $inferredIdx = array_search('Inferred State', $header, true);

    expect((string) $data[$lastIdx])->toContain('Disembarked')
        ->and((string) $data[$inferredIdx])->toBe('Demobilisation Standby');
});

test('result workbook reports Home / Redeployment as last movement for Completed P6 endings', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $employee->update(['employee_no' => 'P6RES01', 'status' => 'active']);
    $vessel = makeCrewMovementVessel('P6 Result Vessel', $company);

    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.create_historical',
    ]);
    $user->update(['current_company_id' => $company->id]);

    $file = makeHistoricalCrewImportFile([
        [
            'employee_no' => 'P6RES01',
            'vessel' => $vessel->name,
            'rank' => $rank->name,
            'vessel_join_date' => '2023-01-15',
            'disembark_date' => '2023-07-20',
            'travel_home_date' => '2023-07-23',
        ],
    ]);

    $import = $this->actingAs($user)
        ->postJson(route('organization.crew-assignments.historical.import.execute'), [
            'file' => $file,
            'idempotency_key' => historicalImportIdempotencyKey('p6-result'),
            'confirmed' => '1',
        ])
        ->assertOk();

    $download = $this->actingAs($user)
        ->get(route('organization.crew-assignments.historical.import.batches.result', $import->json('id')));
    $path = tempnam(sys_get_temp_dir(), 'hist-p6-result-').'.xlsx';
    file_put_contents($path, $download->streamedContent());
    $rows = IOFactory::load($path)->getActiveSheet()->toArray();
    @unlink($path);

    $header = $rows[0];
    $data = $rows[1];
    $lastIdx = array_search('Last Movement', $header, true);
    $inferredIdx = array_search('Inferred State', $header, true);

    expect((string) $data[$lastIdx])->toContain('Home / Redeployment')
        ->and((string) $data[$inferredIdx])->toBe('Home / Redeployment');
});

test('result workbook stays On Vessel after later live disembarkation', function () {
    Carbon::setTestNow('2025-03-01 10:00:00');

    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $employee->update(['employee_no' => 'AUDITP4', 'status' => 'active']);
    $vessel = makeCrewMovementVessel('Audit Stable P4 Vessel', $company);

    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.create_historical',
    ]);
    $user->update(['current_company_id' => $company->id]);

    $import = $this->actingAs($user)
        ->postJson(route('organization.crew-assignments.historical.import.execute'), [
            'file' => makeHistoricalCrewImportFile([
                [
                    'employee_no' => 'AUDITP4',
                    'vessel' => $vessel->name,
                    'rank' => $rank->name,
                    'vessel_join_date' => '2024-01-15',
                ],
            ]),
            'idempotency_key' => historicalImportIdempotencyKey('audit-p4-stable'),
            'confirmed' => '1',
        ])
        ->assertOk();

    $batchId = $import->json('id');
    $assignment = CrewAssignment::query()->where('employee_id', $employee->id)->firstOrFail();
    expect($assignment->currentPhase?->phase_code)->toBe(CrewPhaseCode::OnVessel);

    Carbon::setTestNow('2025-03-01 12:00:00');

    app(CrewMovementService::class)->perform(
        $company->id,
        $assignment->id,
        CrewMovementAction::ConfirmDisembarkation,
        [
            'occurred_at' => '2025-03-01 12:00:00',
            'next_phase' => CrewPhaseCode::DemobStandby->value,
        ],
        $user->id,
    );

    Carbon::setTestNow('2025-03-01 15:00:00');

    app(CrewMovementService::class)->perform(
        $company->id,
        $assignment->id,
        CrewMovementAction::TravelHome,
        [
            'occurred_at' => '2025-03-01 15:00:00',
        ],
        $user->id,
    );

    $assignment->refresh()->load('currentPhase');
    expect($assignment->currentPhase?->phase_code)->toBe(CrewPhaseCode::HomeRedeploy)
        ->and($assignment->status)->toBe(CrewAssignmentStatus::Completed);

    $download = $this->actingAs($user)
        ->get(route('organization.crew-assignments.historical.import.batches.result', $batchId));
    $path = tempnam(sys_get_temp_dir(), 'hist-audit-p4-').'.xlsx';
    file_put_contents($path, $download->streamedContent());
    $rows = IOFactory::load($path)->getActiveSheet()->toArray();
    @unlink($path);

    $header = $rows[0];
    $data = $rows[1];
    $lastIdx = array_search('Last Movement', $header, true);
    $inferredIdx = array_search('Inferred State', $header, true);

    expect((string) $data[$lastIdx])->toContain('On Vessel')
        ->and((string) $data[$inferredIdx])->toBe('On Vessel');

    Carbon::setTestNow();
});

test('result workbook stays Training End / Join Standby after later live join vessel', function () {
    Carbon::setTestNow('2025-04-01 10:00:00');

    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $employee->update(['employee_no' => 'AUDITTEND', 'status' => 'active']);
    $vessel = makeCrewMovementVessel('Audit Stable Training End Vessel', $company);

    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.create_historical',
    ]);
    $user->update(['current_company_id' => $company->id]);

    $import = $this->actingAs($user)
        ->postJson(route('organization.crew-assignments.historical.import.execute'), [
            'file' => makeHistoricalCrewImportFile([
                [
                    'employee_no' => 'AUDITTEND',
                    'vessel' => $vessel->name,
                    'rank' => $rank->name,
                    'training_start_date' => '2024-09-05',
                    'training_end_date' => '2024-09-10',
                ],
            ]),
            'idempotency_key' => historicalImportIdempotencyKey('audit-training-end-stable'),
            'confirmed' => '1',
        ])
        ->assertOk();

    $batchId = $import->json('id');
    $assignment = CrewAssignment::query()->where('employee_id', $employee->id)->firstOrFail();
    expect($assignment->currentPhase?->phase_code)->toBe(CrewPhaseCode::JoinStandby);

    Carbon::setTestNow('2025-04-01 12:00:00');

    app(CrewMovementService::class)->perform(
        $company->id,
        $assignment->id,
        CrewMovementAction::JoinVessel,
        [
            'occurred_at' => '2025-04-01 12:00:00',
            'vessel_id' => $vessel->id,
            'rank_id' => $rank->id,
        ],
        $user->id,
    );

    $assignment->refresh()->load('currentPhase');
    expect($assignment->currentPhase?->phase_code)->toBe(CrewPhaseCode::OnVessel);

    $download = $this->actingAs($user)
        ->get(route('organization.crew-assignments.historical.import.batches.result', $batchId));
    $path = tempnam(sys_get_temp_dir(), 'hist-audit-tend-').'.xlsx';
    file_put_contents($path, $download->streamedContent());
    $rows = IOFactory::load($path)->getActiveSheet()->toArray();
    @unlink($path);

    $header = $rows[0];
    $data = $rows[1];
    $lastIdx = array_search('Last Movement', $header, true);
    $inferredIdx = array_search('Inferred State', $header, true);

    expect((string) $data[$lastIdx])->toContain('Training End')
        ->and((string) $data[$lastIdx])->toContain('10 Sep')
        ->and((string) $data[$inferredIdx])->toBe('Join Standby');

    Carbon::setTestNow();
});

test('cross company employee and vessel remain blocked on import', function () {
    ['user' => $user, 'company' => $company, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $other = makeHistoricalImportOtherCompany();
    $foreignEmployee = Employee::factory()->forCompany($other)->create([
        'employee_no' => 'X-FOREIGN',
        'status' => 'active',
    ]);
    $foreignVessel = makeCrewMovementVessel('Foreign Vessel '.uniqid(), $other);
    $localVessel = makeCrewMovementVessel('Local Vessel', $company);

    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.create_historical',
    ]);
    $user->update(['current_company_id' => $company->id]);

    $this->actingAs($user)
        ->postJson(route('organization.crew-assignments.historical.import.execute'), [
            'file' => makeHistoricalCrewImportFile([
                [
                    'employee_no' => $foreignEmployee->employee_no,
                    'vessel' => $localVessel->name,
                    'rank' => $rank->name,
                    'vessel_join_date' => '2024-01-15',
                    'disembark_date' => '2024-07-20',
                ],
                [
                    'employee_no' => 'LOCAL-MISSING',
                    'vessel' => $foreignVessel->name,
                    'rank' => $rank->name,
                    'vessel_join_date' => '2024-01-15',
                    'disembark_date' => '2024-07-20',
                ],
            ]),
            'idempotency_key' => historicalImportIdempotencyKey('xco'),
            'confirmed' => '1',
        ])
        ->assertUnprocessable();

    expect(CrewAssignment::query()->where('company_id', $company->id)->count())->toBe(0);
});

test('company_id injection from client is ignored for batches', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $employee->update(['employee_no' => '3119']);
    $other = makeHistoricalImportOtherCompany();
    $vessel = makeCrewMovementVessel('Tenant Vessel', $company);

    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.create_historical',
    ]);
    $user->update(['current_company_id' => $company->id]);

    $response = $this->actingAs($user)
        ->postJson(route('organization.crew-assignments.historical.import.execute'), [
            'file' => makeHistoricalCrewImportFile([
                [
                    'employee_no' => '3119',
                    'vessel' => $vessel->name,
                    'rank' => $rank->name,
                    'vessel_join_date' => '2024-01-15',
                    'disembark_date' => '2024-07-20',
                ],
            ]),
            'idempotency_key' => historicalImportIdempotencyKey('tenant'),
            'confirmed' => '1',
            'company_id' => $other->id,
        ])
        ->assertOk();

    expect((int) $response->json('id'))->toBeGreaterThan(0);
    $batch = HistoricalCrewImportBatch::query()->findOrFail($response->json('id'));
    expect($batch->company_id)->toBe($company->id)
        ->and($batch->company_id)->not->toBe($other->id);
});

test('adjacent half-open workbook intervals match manual assignment overlap; sea service uses next calendar day', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $employee->update(['employee_no' => '3119']);
    $vessel = makeCrewMovementVessel('Adjacent Vessel', $company);

    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.create_historical',
    ]);
    $user->update(['current_company_id' => $company->id]);

    // Assignment half-open: 01–10 and 10–20 do not overlap when both are completed.
    $this->actingAs($user)
        ->postJson(route('organization.crew-assignments.historical.import.validate'), [
            'file' => makeHistoricalCrewImportFile([
                [
                    'employee_no' => '3119',
                    'vessel' => $vessel->name,
                    'rank' => $rank->name,
                    'vessel_join_date' => '2024-01-01',
                    'disembark_date' => '2024-01-10',
                    'travel_home_date' => '2024-01-10',
                ],
                [
                    'employee_no' => '3119',
                    'vessel' => $vessel->name,
                    'rank' => $rank->name,
                    'vessel_join_date' => '2024-01-10',
                    'disembark_date' => '2024-01-20',
                    'travel_home_date' => '2024-01-20',
                ],
            ]),
        ])
        ->assertOk()
        ->assertJsonPath('summary.ready', 2)
        ->assertJsonPath('summary.blocked', 0);

    // Sea Service inclusive days: import adjacent calendar days (10 then 11) to avoid shared-day conflict.
    $this->actingAs($user)
        ->postJson(route('organization.crew-assignments.historical.import.execute'), [
            'file' => makeHistoricalCrewImportFile([
                [
                    'employee_no' => '3119',
                    'vessel' => $vessel->name,
                    'rank' => $rank->name,
                    'vessel_join_date' => '2024-01-01',
                    'disembark_date' => '2024-01-10',
                    'travel_home_date' => '2024-01-10',
                ],
                [
                    'employee_no' => '3119',
                    'vessel' => $vessel->name,
                    'rank' => $rank->name,
                    'vessel_join_date' => '2024-01-11',
                    'disembark_date' => '2024-01-20',
                    'travel_home_date' => '2024-01-20',
                ],
            ]),
            'idempotency_key' => historicalImportIdempotencyKey('adj'),
            'confirmed' => '1',
        ])
        ->assertOk()
        ->assertJsonPath('imported_rows', 2);
});

test('hundreds of rows validate without exploding query count', function () {
    ['user' => $user, 'company' => $company, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Perf Vessel', $company);

    $employees = [];
    for ($i = 0; $i < 40; $i++) {
        $employees[] = Employee::factory()->forCompany($company)->create([
            'employee_no' => 'PERF-'.str_pad((string) $i, 3, '0', STR_PAD_LEFT),
            'status' => 'active',
        ]);
    }

    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.create_historical',
    ]);
    $user->update(['current_company_id' => $company->id]);

    $rows = [];
    foreach ($employees as $index => $employee) {
        for ($j = 0; $j < 5; $j++) {
            $year = 2015 + $j;
            $rows[] = [
                'employee_no' => $employee->employee_no,
                'vessel' => $vessel->name,
                'rank' => $rank->name,
                'vessel_join_date' => "{$year}-01-01",
                'disembark_date' => "{$year}-06-01",
                'travel_home_date' => "{$year}-06-01",
            ];
        }
    }

    expect(count($rows))->toBe(200);

    DB::flushQueryLog();
    DB::enableQueryLog();

    $response = $this->actingAs($user)
        ->postJson(route('organization.crew-assignments.historical.import.validate'), [
            'file' => makeHistoricalCrewImportFile($rows),
        ]);

    $queryCount = count(DB::getQueryLog());
    DB::disableQueryLog();

    $response->assertOk()
        ->assertJsonPath('summary.total', 200)
        ->assertJsonPath('summary.ready', 200);

    expect($queryCount)->toBeLessThan(80);
});
