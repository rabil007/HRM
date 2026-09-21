<?php

use App\Enums\HistoricalCrewImportBatchStatus;
use App\Enums\HistoricalCrewImportRowStatus;
use App\Models\CrewAssignment;
use App\Models\Employee;
use App\Models\HistoricalCrewImportBatch;
use App\Models\HistoricalCrewImportRow;
use App\Support\CrewMovements\Historical\HistoricalCrewAssignmentData;
use App\Support\CrewMovements\Historical\HistoricalCrewAssignmentService;
use App\Support\CrewMovements\Historical\HistoricalCrewImportBatchNumberGenerator;
use App\Support\CrewMovements\Historical\HistoricalCrewImportExecutionService;
use App\Support\CrewMovements\Historical\HistoricalCrewImportPreviewService;
use App\Support\CrewMovements\Historical\HistoricalCrewImportResultExporter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

test('historical import schema has intended foreign keys and unique indexes', function () {
    expect(Schema::hasTable('historical_crew_import_batches'))->toBeTrue()
        ->and(Schema::hasTable('historical_crew_import_rows'))->toBeTrue()
        ->and(Schema::hasColumn('crew_assignments', 'historical_import_batch_id'))->toBeTrue()
        ->and(Schema::hasColumn('historical_crew_import_batches', 'workbook_hash'))->toBeTrue()
        ->and(Schema::hasColumn('historical_crew_import_batches', 'last_progress_at'))->toBeTrue();

    $rowForeignColumns = collect(Schema::getForeignKeys('historical_crew_import_rows'))
        ->map(fn (array $fk): string => implode(',', $fk['columns'] ?? []))
        ->all();

    expect($rowForeignColumns)->toContain('historical_crew_import_batch_id')
        ->and($rowForeignColumns)->toContain('crew_assignment_id');

    $assignmentForeignColumns = collect(Schema::getForeignKeys('crew_assignments'))
        ->map(fn (array $fk): string => implode(',', $fk['columns'] ?? []))
        ->all();

    expect($assignmentForeignColumns)->toContain('historical_import_batch_id');

    $rowIndexes = collect(Schema::getIndexes('historical_crew_import_rows'));
    $hasBatchRowUnique = $rowIndexes->contains(function (array $index): bool {
        return ($index['unique'] ?? false)
            && ($index['columns'] ?? []) === ['historical_crew_import_batch_id', 'row_number'];
    });
    $hasBatchStatusIndex = $rowIndexes->contains(function (array $index): bool {
        return ($index['columns'] ?? []) === ['historical_crew_import_batch_id', 'status'];
    });

    expect($hasBatchRowUnique)->toBeTrue()
        ->and($hasBatchStatusIndex)->toBeTrue();

    $batchIndexes = collect(Schema::getIndexes('historical_crew_import_batches'));
    $hasCompanyBatchUnique = $batchIndexes->contains(function (array $index): bool {
        return ($index['unique'] ?? false)
            && ($index['columns'] ?? []) === ['company_id', 'batch_no'];
    });
    $hasCompanyIdemUnique = $batchIndexes->contains(function (array $index): bool {
        return ($index['unique'] ?? false)
            && ($index['columns'] ?? []) === ['company_id', 'idempotency_key'];
    });

    expect($hasCompanyBatchUnique)->toBeTrue()
        ->and($hasCompanyIdemUnique)->toBeTrue();
});

test('repair migration is idempotent when schema already complete', function () {
    $migration = require database_path('migrations/2026_09_21_124150_repair_historical_crew_import_schema.php');
    $migration->up();
    $migration->up();

    expect(Schema::hasTable('historical_crew_import_rows'))->toBeTrue();
});

test('interrupted importing batch resumes without duplicating assignments', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $employee->update(['employee_no' => '3119']);
    Employee::factory()->forCompany($company)->create(['employee_no' => '3220', 'status' => 'active']);
    $vessel = makeCrewMovementVessel('Resume Vessel', $company);

    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.create_historical',
    ]);
    $user->update(['current_company_id' => $company->id]);

    $rows = [
        [
            'employee_no' => '3119',
            'vessel' => $vessel->name,
            'rank' => $rank->name,
            'vessel_join_date' => '2024-01-01',
            'disembark_date' => '2024-03-01',
        ],
        [
            'employee_no' => '3220',
            'vessel' => $vessel->name,
            'rank' => $rank->name,
            'vessel_join_date' => '2024-04-01',
            'disembark_date' => '2024-06-01',
        ],
    ];

    $key = historicalImportIdempotencyKey('resume');
    $file = makeHistoricalCrewImportFile($rows);
    $hash = hash_file('sha256', $file->getRealPath());

    $batch = HistoricalCrewImportBatch::query()->create([
        'company_id' => $company->id,
        'batch_no' => 'HI-000099',
        'created_by' => $user->id,
        'original_filename' => 'historical-crew-import.xlsx',
        'status' => HistoricalCrewImportBatchStatus::Importing,
        'total_rows' => 2,
        'ready_rows' => 2,
        'warning_rows' => 0,
        'blocked_rows' => 0,
        'imported_rows' => 1,
        'failed_rows' => 0,
        'skipped_rows' => 0,
        'idempotency_key' => $key,
        'workbook_hash' => $hash,
        'started_at' => now()->subHour(),
        'last_progress_at' => now()->subMinutes(20),
        'summary' => [],
    ]);

    $firstAssignment = app(HistoricalCrewAssignmentService::class)->create(
        data: HistoricalCrewAssignmentData::fromArray(
            data: [
                'employee_id' => $employee->id,
                'vessel_id' => $vessel->id,
                'rank_id' => $rank->id,
                'joined_vessel_at' => '2024-01-01',
                'disembarked_at' => '2024-03-01',
            ],
            companyId: $company->id,
            timezone: 'Asia/Dubai',
            source: HistoricalCrewAssignmentData::SOURCE_IMPORT,
        ),
        actorId: $user->id,
        importBatchId: (int) $batch->id,
    );

    HistoricalCrewImportRow::query()->create([
        'historical_crew_import_batch_id' => $batch->id,
        'row_number' => 2,
        'employee_no' => '3119',
        'status' => HistoricalCrewImportRowStatus::Imported,
        'crew_assignment_id' => $firstAssignment->id,
        'assignment_no' => $firstAssignment->assignment_no,
        'warnings' => [],
        'errors' => [],
    ]);

    $resumeFile = new UploadedFile(
        $file->getRealPath(),
        'historical-crew-import.xlsx',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        null,
        true,
    );

    $response = $this->actingAs($user)
        ->postJson(route('organization.crew-assignments.historical.import.execute'), [
            'file' => $resumeFile,
            'idempotency_key' => $key,
            'confirmed' => '1',
        ]);

    $response->assertOk()
        ->assertJsonPath('imported_rows', 2)
        ->assertJsonPath('resumed', true)
        ->assertJsonPath('status', HistoricalCrewImportBatchStatus::Completed->value);

    expect(CrewAssignment::query()->where('source', HistoricalCrewAssignmentData::SOURCE_IMPORT)->count())->toBe(2)
        ->and(HistoricalCrewImportRow::query()->where('historical_crew_import_batch_id', $batch->id)->count())->toBe(2);
});

test('active importing batch rejects concurrent retry until stale', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $employee->update(['employee_no' => '3119']);
    $vessel = makeCrewMovementVessel('Active Import Vessel', $company);

    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.create_historical',
    ]);
    $user->update(['current_company_id' => $company->id]);

    $row = [
        'employee_no' => '3119',
        'vessel' => $vessel->name,
        'rank' => $rank->name,
        'vessel_join_date' => '2024-01-01',
        'disembark_date' => '2024-03-01',
    ];
    $key = historicalImportIdempotencyKey('active');
    $file = makeHistoricalCrewImportFile([$row]);

    HistoricalCrewImportBatch::query()->create([
        'company_id' => $company->id,
        'batch_no' => 'HI-000100',
        'created_by' => $user->id,
        'original_filename' => 'historical-crew-import.xlsx',
        'status' => HistoricalCrewImportBatchStatus::Importing,
        'total_rows' => 1,
        'ready_rows' => 1,
        'warning_rows' => 0,
        'blocked_rows' => 0,
        'imported_rows' => 0,
        'failed_rows' => 0,
        'skipped_rows' => 0,
        'idempotency_key' => $key,
        'workbook_hash' => hash_file('sha256', $file->getRealPath()),
        'started_at' => now(),
        'last_progress_at' => now(),
        'summary' => [],
    ]);

    $retryFile = new UploadedFile(
        $file->getRealPath(),
        'historical-crew-import.xlsx',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        null,
        true,
    );

    $this->actingAs($user)
        ->postJson(route('organization.crew-assignments.historical.import.execute'), [
            'file' => $retryFile,
            'idempotency_key' => $key,
            'confirmed' => '1',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['file']);
});

test('different workbook with same idempotency key is rejected', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $employee->update(['employee_no' => '3119']);
    $vessel = makeCrewMovementVessel('Hash Vessel', $company);

    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.create_historical',
    ]);
    $user->update(['current_company_id' => $company->id]);

    $key = historicalImportIdempotencyKey('hash');

    HistoricalCrewImportBatch::query()->create([
        'company_id' => $company->id,
        'batch_no' => 'HI-000101',
        'created_by' => $user->id,
        'original_filename' => 'historical-crew-import.xlsx',
        'status' => HistoricalCrewImportBatchStatus::Importing,
        'total_rows' => 1,
        'ready_rows' => 1,
        'warning_rows' => 0,
        'blocked_rows' => 0,
        'imported_rows' => 0,
        'failed_rows' => 0,
        'skipped_rows' => 0,
        'idempotency_key' => $key,
        'workbook_hash' => str_repeat('a', 64),
        'started_at' => now()->subHour(),
        'last_progress_at' => now()->subMinutes(20),
        'summary' => [],
    ]);

    $this->actingAs($user)
        ->postJson(route('organization.crew-assignments.historical.import.execute'), [
            'file' => makeHistoricalCrewImportFile([
                [
                    'employee_no' => '3119',
                    'vessel' => $vessel->name,
                    'rank' => $rank->name,
                    'vessel_join_date' => '2024-01-01',
                    'disembark_date' => '2024-03-01',
                ],
            ]),
            'idempotency_key' => $key,
            'confirmed' => '1',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['file']);
});

test('row persist failure rolls back assignment creation', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $employee->update(['employee_no' => '3119']);
    $vessel = makeCrewMovementVessel('Atomic Vessel', $company);

    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.create_historical',
    ]);
    $user->update(['current_company_id' => $company->id]);

    $service = new class(app(HistoricalCrewImportPreviewService::class), app(HistoricalCrewAssignmentService::class), app(HistoricalCrewImportBatchNumberGenerator::class), app(HistoricalCrewImportResultExporter::class)) extends HistoricalCrewImportExecutionService
    {
        public bool $failNextPersist = true;

        protected function writeRowResult(
            HistoricalCrewImportBatch $batch,
            array $row,
            HistoricalCrewImportRowStatus $status,
            ?int $assignmentId,
            array $warnings,
            array $errors,
            ?string $assignmentNo,
            ?HistoricalCrewImportRow $existing,
        ): void {
            if (
                $this->failNextPersist
                && in_array($status, [
                    HistoricalCrewImportRowStatus::Imported,
                    HistoricalCrewImportRowStatus::ImportedWithWarnings,
                ], true)
            ) {
                $this->failNextPersist = false;

                throw new RuntimeException('Forced row-result persistence failure.');
            }

            parent::writeRowResult(
                $batch,
                $row,
                $status,
                $assignmentId,
                $warnings,
                $errors,
                $assignmentNo,
                $existing,
            );
        }
    };

    $before = CrewAssignment::query()->count();

    $service->import(
        (int) $company->id,
        makeHistoricalCrewImportFile([
            [
                'employee_no' => '3119',
                'vessel' => $vessel->name,
                'rank' => $rank->name,
                'vessel_join_date' => '2024-01-01',
                'disembark_date' => '2024-03-01',
            ],
        ]),
        $user,
        historicalImportIdempotencyKey('atomic'),
    );

    expect(CrewAssignment::query()->count())->toBe($before)
        ->and(HistoricalCrewImportRow::query()->where('status', HistoricalCrewImportRowStatus::Failed)->count())->toBe(1);
});

test('concurrent idempotency collision reloads existing batch instead of raw sql error', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $employee->update(['employee_no' => '3119']);
    $vessel = makeCrewMovementVessel('Race Key Vessel', $company);

    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.create_historical',
    ]);
    $user->update(['current_company_id' => $company->id]);

    $key = historicalImportIdempotencyKey('race-key');
    $row = [
        'employee_no' => '3119',
        'vessel' => $vessel->name,
        'rank' => $rank->name,
        'vessel_join_date' => '2024-01-01',
        'disembark_date' => '2024-03-01',
    ];

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

test('batch number generator retries without clock fallback', function () {
    ['company' => $company] = makeCrewAssignmentFixtures();

    HistoricalCrewImportBatch::query()->create([
        'company_id' => $company->id,
        'batch_no' => 'HI-000007',
        'created_by' => null,
        'original_filename' => 'x.xlsx',
        'status' => HistoricalCrewImportBatchStatus::Completed,
        'total_rows' => 0,
        'ready_rows' => 0,
        'warning_rows' => 0,
        'blocked_rows' => 0,
        'imported_rows' => 0,
        'failed_rows' => 0,
        'skipped_rows' => 0,
        'idempotency_key' => 'batch-no-seed-'.Str::lower(Str::random(12)),
        'workbook_hash' => str_repeat('b', 64),
        'started_at' => now(),
        'completed_at' => now(),
        'summary' => [],
    ]);

    $next = app(HistoricalCrewImportBatchNumberGenerator::class)->next((int) $company->id);

    expect($next)->toBe('HI-000008');
});
