<?php

use App\Enums\CrewAssignmentStatus;
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
use App\Support\CrewMovements\Historical\HistoricalCrewAssignmentData;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Activitylog\Models\Activity;

test('ready rows import as completed historical_import with batch linkage and sea service', function () {
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
        ->and($assignment->status)->toBe(CrewAssignmentStatus::Completed)
        ->and($assignment->historical_import_batch_id)->toBe($response->json('id'))
        ->and($assignment->phases)->toHaveCount(1)
        ->and($assignment->phases->first()->phase_code)->toBe(CrewPhaseCode::OnVessel);

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
    ]);
    $hiddenDept = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Hidden Marine',
        'code' => 'HH'.Str::upper(Str::random(3)),
        'status' => 'active',
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

    // Assignment half-open: 01–10 and 10–20 do not overlap.
    $this->actingAs($user)
        ->postJson(route('organization.crew-assignments.historical.import.validate'), [
            'file' => makeHistoricalCrewImportFile([
                [
                    'employee_no' => '3119',
                    'vessel' => $vessel->name,
                    'rank' => $rank->name,
                    'vessel_join_date' => '2024-01-01',
                    'disembark_date' => '2024-01-10',
                ],
                [
                    'employee_no' => '3119',
                    'vessel' => $vessel->name,
                    'rank' => $rank->name,
                    'vessel_join_date' => '2024-01-10',
                    'disembark_date' => '2024-01-20',
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
                ],
                [
                    'employee_no' => '3119',
                    'vessel' => $vessel->name,
                    'rank' => $rank->name,
                    'vessel_join_date' => '2024-01-11',
                    'disembark_date' => '2024-01-20',
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
