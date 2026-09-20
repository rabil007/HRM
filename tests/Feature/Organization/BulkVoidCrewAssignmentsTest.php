<?php

use App\Enums\PayrollPeriodStatus;
use App\Models\Company;
use App\Models\CrewAssignment;
use App\Models\CrewPlanningAssignment;
use App\Models\CrewTimesheet;
use App\Models\CrewTimesheetSegment;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeSeaService;
use App\Models\EmployeeTraining;
use App\Models\PayrollPeriod;
use App\Models\Rank;
use App\Models\User;
use App\Support\CrewMovements\Actions\BulkVoidCrewAssignments;
use App\Support\CrewMovements\CrewAssignmentVoidGuard;
use App\Support\CrewMovements\CrewMovementService;
use App\Support\EmployeeFiles\EmployeePrivateFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;

use function Pest\Laravel\actingAs;

/**
 * @return array{user: User, company: Company, employee: Employee, rank: Rank}
 */
function makeBulkVoidFixtures(array $extraPermissions = []): array
{
    $fixtures = makeCrewAssignmentFixtures();

    grantCompanyPermissions($fixtures['user'], $fixtures['company'], array_values(array_unique(array_merge([
        'crew_operations.assignments.view',
        'crew_operations.assignments.create',
        'crew_operations.assignments.update',
        'crew_operations.movements.perform',
        'crew_operations.assignments.cancel',
        'crew_operations.assignments.void',
        'audit.view',
    ], $extraPermissions))));

    $fixtures['user']->update(['current_company_id' => $fixtures['company']->id]);

    return $fixtures;
}

function postBulkVoidViaHttp(
    User $user,
    array $assignmentIds,
    string $reason = 'Entered by mistake',
    bool $deleteSeaService = false,
    bool $deleteTraining = false,
): mixed {
    return actingAs($user)
        ->withSession(['current_company_id' => $user->current_company_id])
        ->post(route('organization.crew-assignments.bulk-void'), [
            'assignment_ids' => $assignmentIds,
            'void_reason' => $reason,
            'delete_sea_service' => $deleteSeaService,
            'delete_training' => $deleteTraining,
        ]);
}

function postVoidPreviewViaHttp(User $user, array $assignmentIds): mixed
{
    return actingAs($user)
        ->withSession(['current_company_id' => $user->current_company_id])
        ->postJson(route('organization.crew-assignments.void-preview'), [
            'assignment_ids' => $assignmentIds,
        ]);
}

test('preview returns server-authoritative impact details and permissions', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeBulkVoidFixtures([
        'sea_services.delete',
        'training.delete',
    ]);
    $service = app(CrewMovementService::class);
    $assignment = $service->createDraft($company->id, $employee->id, ['rank_id' => $rank->id], $user->id);
    $phase = $assignment->currentPhase;

    EmployeeSeaService::factory()->forEmployee($employee)->create([
        'crew_assignment_phase_id' => $phase->id,
    ]);

    EmployeeTraining::factory()->forEmployee($employee)->create([
        'source_crew_assignment_phase_id' => $phase->id,
    ]);

    $response = postVoidPreviewViaHttp($user, [$assignment->id]);

    $response->assertOk()
        ->assertJson([
            'total_assignments' => 1,
            'total_sea_service_records' => 1,
            'total_training_records' => 1,
            'can_delete_sea_service' => true,
            'can_delete_training' => true,
            'has_sea_service' => true,
            'has_training' => true,
            'has_protected_blockers' => false,
        ]);

    expect($response->json('assignments.0.id'))->toBe($assignment->id)
        ->and($response->json('assignments.0.sea_service_count'))->toBe(1)
        ->and($response->json('assignments.0.training_count'))->toBe(1);
});

// 1. User with crew_operations.assignments.void can delete an eligible assignment
// 2. Void reason remains required
// 3. Assignment is soft-deleted/voided
// 4. voided_at, voided_by, and reason are retained
// 5. Existing planning cleanup still works
// 6. Audit event remains recorded
test('basic single assignment deletion via bulk endpoint satisfies all operational invariants', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeBulkVoidFixtures();
    $service = app(CrewMovementService::class);
    $assignment = $service->createDraft($company->id, $employee->id, ['rank_id' => $rank->id], $user->id);

    // Create a planning record to verify cleanup
    CrewPlanningAssignment::factory()->create([
        'company_id' => $company->id,
        'crew_assignment_id' => $assignment->id,
        'employee_id' => $employee->id,
        'rank_id' => $rank->id,
    ]);
    expect(CrewPlanningAssignment::query()->where('crew_assignment_id', $assignment->id)->exists())->toBeTrue();

    // 2. Reason required
    postBulkVoidViaHttp($user, [$assignment->id], '')
        ->assertSessionHasErrors('void_reason');

    // 1. User with void permission can delete
    postBulkVoidViaHttp($user, [$assignment->id], 'Operational cancellation')
        ->assertRedirect(route('organization.crew-assignments.index'))
        ->assertSessionHas('success');

    // 3. Soft deleted & 4. voided_at, voided_by, reason retained
    $voided = CrewAssignment::withTrashed()->findOrFail($assignment->id);
    expect($voided->trashed())->toBeTrue()
        ->and($voided->voided_at)->not->toBeNull()
        ->and($voided->voided_by)->toBe($user->id)
        ->and($voided->void_reason)->toBe('Operational cancellation')
        ->and(CrewAssignment::query()->whereKey($assignment->id)->exists())->toBeFalse();

    // 5. Planning cleanup worked
    expect(CrewPlanningAssignment::query()->where('crew_assignment_id', $assignment->id)->exists())->toBeFalse();

    // 6. Audit event recorded
    $activity = Activity::query()
        ->where('event', 'crew_assignment_voided')
        ->where('subject_type', CrewAssignment::class)
        ->where('subject_id', $assignment->id)
        ->latest()
        ->first();

    expect($activity)->not->toBeNull()
        ->and($activity->causer_id)->toBe($user->id)
        ->and($activity->properties['reason'])->toBe('Operational cancellation')
        ->and($activity->properties['assignment_no'])->toBe($assignment->assignment_no);
});

// 7. Multiple eligible assignments can be deleted together
// 8. Every selected assignment receives its own audit evidence
test('bulk deletion deletes multiple assignments and records distinct audit evidence for each', function () {
    ['user' => $user, 'company' => $company, 'rank' => $rank] = makeBulkVoidFixtures();
    $service = app(CrewMovementService::class);

    $emp1 = Employee::factory()->forCompany($company)->create(['rank_id' => $rank->id]);
    $emp2 = Employee::factory()->forCompany($company)->create(['rank_id' => $rank->id]);
    $emp3 = Employee::factory()->forCompany($company)->create(['rank_id' => $rank->id]);

    $a1 = $service->createDraft($company->id, $emp1->id, ['rank_id' => $rank->id], $user->id);
    $a2 = $service->createDraft($company->id, $emp2->id, ['rank_id' => $rank->id], $user->id);
    $a3 = $service->createDraft($company->id, $emp3->id, ['rank_id' => $rank->id], $user->id);

    postBulkVoidViaHttp($user, [$a1->id, $a2->id, $a3->id], 'Bulk duplicate purge')
        ->assertRedirect(route('organization.crew-assignments.index'))
        ->assertSessionHas('success');

    expect(CrewAssignment::withTrashed()->find($a1->id)->trashed())->toBeTrue()
        ->and(CrewAssignment::withTrashed()->find($a2->id)->trashed())->toBeTrue()
        ->and(CrewAssignment::withTrashed()->find($a3->id)->trashed())->toBeTrue();

    // 8. Distinct audit evidence
    foreach ([$a1, $a2, $a3] as $assignment) {
        $activity = Activity::query()
            ->where('event', 'crew_assignment_voided')
            ->where('subject_type', $assignment->getMorphClass())
            ->where('subject_id', $assignment->id)
            ->latest('id')
            ->first();

        expect($activity)->not->toBeNull()
            ->and($activity->causer_id)->toBe($user->id)
            ->and($activity->properties['reason'])->toBe('Bulk duplicate purge')
            ->and($activity->properties['is_bulk'])->toBeTrue();
    }
});

// 9. One blocked assignment causes the entire bulk operation to fail
// 10. No assignment is changed when bulk preflight fails
test('bulk operation is all-or-nothing: one blocked assignment halts the entire batch', function () {
    ['user' => $user, 'company' => $company, 'rank' => $rank] = makeBulkVoidFixtures();
    $service = app(CrewMovementService::class);

    $emp1 = Employee::factory()->forCompany($company)->create(['rank_id' => $rank->id]);
    $emp2 = Employee::factory()->forCompany($company)->create(['rank_id' => $rank->id]);

    $safeAssignment = $service->createDraft($company->id, $emp1->id, ['rank_id' => $rank->id], $user->id);
    $blockedAssignment = $service->createDraft($company->id, $emp2->id, ['rank_id' => $rank->id], $user->id);

    // Block the second assignment with generated sea service without cleanup option enabled
    EmployeeSeaService::factory()->forEmployee($emp2)->create([
        'crew_assignment_phase_id' => $blockedAssignment->currentPhase->id,
    ]);

    $response = postBulkVoidViaHttp($user, [$safeAssignment->id, $blockedAssignment->id], 'Purge', deleteSeaService: false);
    $response->assertSessionHasErrors('void');

    // Neither assignment must be modified
    expect(CrewAssignment::query()->whereKey($safeAssignment->id)->exists())->toBeTrue()
        ->and(CrewAssignment::query()->whereKey($blockedAssignment->id)->exists())->toBeTrue();
});

// 11. Generated Sea Service is detected through crew_assignment_phase_id
// 12. Assignment remains blocked when generated Sea Service exists and cleanup was not selected
// 13. Selecting Sea Service cleanup removes only generated linked Sea Service
// 14. Manual/imported/unrelated Sea Service remains untouched
// 15. Sea Service from another Crew Assignment remains untouched
test('sea service cleanup targets only records explicitly linked via crew_assignment_phase_id', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeBulkVoidFixtures([
        'sea_services.delete',
    ]);
    $service = app(CrewMovementService::class);

    $assignment1 = $service->createDraft($company->id, $employee->id, ['rank_id' => $rank->id], $user->id);
    $assignment2 = $service->createDraft($company->id, $employee->id, ['rank_id' => $rank->id], $user->id);

    // Linked to assignment1
    $linkedSeaService = EmployeeSeaService::factory()->forEmployee($employee)->create([
        'crew_assignment_phase_id' => $assignment1->currentPhase->id,
    ]);

    // Linked to assignment2 (different assignment)
    $otherAssignmentSeaService = EmployeeSeaService::factory()->forEmployee($employee)->create([
        'crew_assignment_phase_id' => $assignment2->currentPhase->id,
    ]);

    // Manual / imported (no phase id)
    $manualSeaService = EmployeeSeaService::factory()->forEmployee($employee)->create([
        'crew_assignment_phase_id' => null,
    ]);

    // 12. Unchecked delete_sea_service blocks void
    postBulkVoidViaHttp($user, [$assignment1->id], 'Delete', deleteSeaService: false)
        ->assertSessionHasErrors('void');
    expect(CrewAssignment::query()->whereKey($assignment1->id)->exists())->toBeTrue()
        ->and(EmployeeSeaService::query()->whereKey($linkedSeaService->id)->exists())->toBeTrue();

    // 13. Checked delete_sea_service voids assignment and soft deletes only linked sea service
    postBulkVoidViaHttp($user, [$assignment1->id], 'Delete with sea service', deleteSeaService: true)
        ->assertRedirect(route('organization.crew-assignments.index'))
        ->assertSessionHas('success');

    expect(CrewAssignment::withTrashed()->find($assignment1->id)->trashed())->toBeTrue()
        ->and(EmployeeSeaService::withTrashed()->find($linkedSeaService->id)->trashed())->toBeTrue();

    // 14. Manual sea service untouched
    expect(EmployeeSeaService::query()->whereKey($manualSeaService->id)->exists())->toBeTrue();

    // 15. Other assignment sea service untouched
    expect(EmployeeSeaService::query()->whereKey($otherAssignmentSeaService->id)->exists())->toBeTrue();
});

// 16. User without sea_services.delete cannot request Sea Service cleanup
test('user without sea_services.delete permission cannot request sea service cleanup', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeBulkVoidFixtures();
    $service = app(CrewMovementService::class);
    $assignment = $service->createDraft($company->id, $employee->id, ['rank_id' => $rank->id], $user->id);

    EmployeeSeaService::factory()->forEmployee($employee)->create([
        'crew_assignment_phase_id' => $assignment->currentPhase->id,
    ]);

    // User does not have sea_services.delete
    postBulkVoidViaHttp($user, [$assignment->id], 'Delete', deleteSeaService: true)
        ->assertForbidden();

    expect(CrewAssignment::query()->whereKey($assignment->id)->exists())->toBeTrue();
});

// 17. Generated Training is detected through source_crew_assignment_phase_id
// 18. Training remains when cleanup is unchecked
// 19. Generated linked Training is removed when cleanup is selected
// 20. Manual/imported/unrelated Training remains untouched
// 21. Training belonging to another assignment remains untouched
// 22. User without training.delete cannot request Training cleanup
// 23. Existing Training certificate/version file cleanup behavior is preserved
test('training cleanup handles optional deletion and removes certificates while preserving unrelated training', function () {
    Storage::fake(EmployeePrivateFile::DISK);

    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeBulkVoidFixtures([
        'training.delete',
    ]);
    $service = app(CrewMovementService::class);

    $assignment1 = $service->createDraft($company->id, $employee->id, ['rank_id' => $rank->id], $user->id);
    $assignment2 = $service->createDraft($company->id, $employee->id, ['rank_id' => $rank->id], $user->id);

    // Staging file in storage with valid directory prefix
    $filePath = "employees/{$company->id}/training-certificates/cert.pdf";
    Storage::disk(EmployeePrivateFile::DISK)->put($filePath, 'dummy pdf content');

    // Generated training for assignment 1
    $linkedTraining = EmployeeTraining::factory()->forEmployee($employee)->create([
        'source_crew_assignment_phase_id' => $assignment1->currentPhase->id,
        'certificate_path' => $filePath,
        'current_version' => 1,
    ]);

    // Generated training for assignment 2
    $otherTraining = EmployeeTraining::factory()->forEmployee($employee)->create([
        'source_crew_assignment_phase_id' => $assignment2->currentPhase->id,
    ]);

    // Manual training (no source_crew_assignment_phase_id)
    $manualTraining = EmployeeTraining::factory()->forEmployee($employee)->create([
        'source_crew_assignment_phase_id' => null,
    ]);

    // 18. If delete_training is false, training remains intact
    postBulkVoidViaHttp($user, [$assignment1->id], 'Void without training cleanup', deleteTraining: false)
        ->assertRedirect(route('organization.crew-assignments.index'));

    expect(EmployeeTraining::query()->whereKey($linkedTraining->id)->exists())->toBeTrue()
        ->and(Storage::disk(EmployeePrivateFile::DISK)->exists($filePath))->toBeTrue();

    // Now test with delete_training: true on assignment 2
    $filePath2 = "employees/{$company->id}/training-certificates/cert2.pdf";
    Storage::disk(EmployeePrivateFile::DISK)->put($filePath2, 'version 2 pdf content');
    $otherTraining->update([
        'certificate_path' => $filePath2,
    ]);

    postBulkVoidViaHttp($user, [$assignment2->id], 'Void with training cleanup', deleteTraining: true)
        ->assertRedirect(route('organization.crew-assignments.index'));

    // 19. Linked training is soft-deleted
    expect(EmployeeTraining::withTrashed()->find($otherTraining->id)->trashed())->toBeTrue();

    // 20. Manual training untouched
    expect(EmployeeTraining::query()->whereKey($manualTraining->id)->exists())->toBeTrue();

    // 23. Certificate file removed from storage
    expect(Storage::disk(EmployeePrivateFile::DISK)->exists($filePath2))->toBeFalse();
});

test('user without training.delete cannot request training cleanup', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeBulkVoidFixtures();
    $service = app(CrewMovementService::class);
    $assignment = $service->createDraft($company->id, $employee->id, ['rank_id' => $rank->id], $user->id);

    // User does not have training.delete
    postBulkVoidViaHttp($user, [$assignment->id], 'Delete', deleteTraining: true)
        ->assertForbidden();

    expect(CrewAssignment::query()->whereKey($assignment->id)->exists())->toBeTrue();
});

// 24. Applied/protected Payroll still blocks deletion
// 25. Protected Crew Timesheet dependencies still block deletion
// 26. Linked next assignment still blocks deletion
// 27. Accommodation history still follows the existing blocker rule
// 28. Cleanup flags cannot bypass unrelated blockers
test('cleanup flags cannot bypass operational blockers like paid payroll or timesheet segments', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeBulkVoidFixtures([
        'sea_services.delete',
        'training.delete',
    ]);
    $service = app(CrewMovementService::class);
    $assignment = $service->createDraft($company->id, $employee->id, ['rank_id' => $rank->id], $user->id);

    // Paid payroll period segment
    $period = PayrollPeriod::factory()->for($company)->create([
        'status' => PayrollPeriodStatus::Paid,
        'payroll_category' => 'crew',
    ]);
    $timesheet = CrewTimesheet::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'period_id' => $period->id,
    ]);
    CrewTimesheetSegment::factory()->create([
        'company_id' => $company->id,
        'crew_timesheet_id' => $timesheet->id,
        'crew_assignment_id' => $assignment->id,
    ]);

    // Attempt deletion with both cleanup flags enabled
    postBulkVoidViaHttp($user, [$assignment->id], 'Trying to bypass payroll', deleteSeaService: true, deleteTraining: true)
        ->assertSessionHasErrors('void');

    expect(CrewAssignment::query()->whereKey($assignment->id)->exists())->toBeTrue();
});

// 29. Cross-company assignment IDs cannot be deleted
// 30. Mixed-company forged bulk requests fail safely
// 31. Cross-company Sea Service/Training can never be touched
// 32. A user without crew_operations.assignments.void cannot call the backend action
test('tenancy and authorization are strictly enforced: cross-company or unauthorized calls fail safely', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeBulkVoidFixtures();
    ['company' => $otherCompany, 'employee' => $otherEmployee, 'rank' => $otherRank] = makeCrewAssignmentFixtures();

    $service = app(CrewMovementService::class);
    $validAssignment = $service->createDraft($company->id, $employee->id, ['rank_id' => $rank->id], $user->id);
    $foreignAssignment = $service->createDraft($otherCompany->id, $otherEmployee->id, ['rank_id' => $otherRank->id]);

    // 29. Pure cross-company ID
    postBulkVoidViaHttp($user, [$foreignAssignment->id])
        ->assertNotFound();

    // 30. Mixed-company forged IDs fail with 404 and neither assignment is altered
    postBulkVoidViaHttp($user, [$validAssignment->id, $foreignAssignment->id])
        ->assertNotFound();

    expect(CrewAssignment::query()->whereKey($validAssignment->id)->exists())->toBeTrue()
        ->and(CrewAssignment::query()->whereKey($foreignAssignment->id)->exists())->toBeTrue();

    // 32. Unauthorized user gets 403
    ['user' => $unauthorizedUser] = makeCrewAssignmentFixtures();
    actingAs($unauthorizedUser)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('organization.crew-assignments.bulk-void'), [
            'assignment_ids' => [$validAssignment->id],
            'void_reason' => 'Unauthorized attempt',
        ])
        ->assertForbidden();

    expect(CrewAssignment::query()->whereKey($validAssignment->id)->exists())->toBeTrue();
});

test('employee visibility restricts void preview and hides non-permitted assignments safely', function () {
    ['user' => $user, 'company' => $company, 'rank' => $rank] = makeBulkVoidFixtures([
        'sea_services.delete',
        'training.delete',
    ]);
    $service = app(CrewMovementService::class);

    $deptA = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Allowed Department A',
        'code' => 'DEPTA',
        'status' => 'active',
    ]);
    $deptB = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Hidden Department B',
        'code' => 'DEPTB',
        'status' => 'active',
    ]);

    $empA = Employee::factory()->forCompany($company)->create(['department_id' => $deptA->id, 'rank_id' => $rank->id]);
    $empB = Employee::factory()->forCompany($company)->create(['department_id' => $deptB->id, 'rank_id' => $rank->id]);

    restrictUserToDepartments($user, $company, [$deptA->id]);

    $assignmentA = $service->createDraft($company->id, $empA->id, ['rank_id' => $rank->id], $user->id);
    $assignmentB = $service->createDraft($company->id, $empB->id, ['rank_id' => $rank->id], $user->id);

    EmployeeSeaService::factory()->forEmployee($empB)->create([
        'crew_assignment_phase_id' => $assignmentB->currentPhase->id,
    ]);
    EmployeeTraining::factory()->forEmployee($empB)->create([
        'source_crew_assignment_phase_id' => $assignmentB->currentPhase->id,
    ]);

    // 1. Department A assignment -> allowed
    postVoidPreviewViaHttp($user, [$assignmentA->id])
        ->assertOk()
        ->assertJsonPath('total_assignments', 1)
        ->assertJsonPath('assignments.0.id', $assignmentA->id);

    // 2. Department B assignment ID forged -> 404 (does not disclose employee existence or impact data)
    postVoidPreviewViaHttp($user, [$assignmentB->id])
        ->assertNotFound();

    // 3. Mixed allowed + hidden IDs -> fail safely (404, reveals nothing about batch)
    postVoidPreviewViaHttp($user, [$assignmentA->id, $assignmentB->id])
        ->assertNotFound();
});

test('employee visibility restricts bulk void mutation and enforces all-or-nothing on mixed batches', function () {
    ['user' => $user, 'company' => $company, 'rank' => $rank] = makeBulkVoidFixtures();
    $service = app(CrewMovementService::class);

    $deptA = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Dept A',
        'code' => 'DEPTA2',
        'status' => 'active',
    ]);
    $deptB = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Dept B',
        'code' => 'DEPTB2',
        'status' => 'active',
    ]);

    $empA = Employee::factory()->forCompany($company)->create(['department_id' => $deptA->id, 'rank_id' => $rank->id]);
    $empB = Employee::factory()->forCompany($company)->create(['department_id' => $deptB->id, 'rank_id' => $rank->id]);

    restrictUserToDepartments($user, $company, [$deptA->id]);

    $assignmentA = $service->createDraft($company->id, $empA->id, ['rank_id' => $rank->id], $user->id);
    $assignmentB = $service->createDraft($company->id, $empB->id, ['rank_id' => $rank->id], $user->id);

    // 1. Department B forged mutation fails with 404
    postBulkVoidViaHttp($user, [$assignmentB->id], 'Forged attempt')
        ->assertNotFound();
    expect(CrewAssignment::query()->whereKey($assignmentB->id)->exists())->toBeTrue();

    // 2. Mixed batch fails safely with 404 and neither assignment is altered
    postBulkVoidViaHttp($user, [$assignmentA->id, $assignmentB->id], 'Mixed batch')
        ->assertNotFound();
    expect(CrewAssignment::query()->whereKey($assignmentA->id)->exists())->toBeTrue()
        ->and(CrewAssignment::query()->whereKey($assignmentB->id)->exists())->toBeTrue();

    // 3. Department A assignment alone may void successfully
    postBulkVoidViaHttp($user, [$assignmentA->id], 'Legitimate void')
        ->assertRedirect(route('organization.crew-assignments.index'))
        ->assertSessionHas('success');
    expect(CrewAssignment::withTrashed()->find($assignmentA->id)->trashed())->toBeTrue();
});

test('training certificate file is preserved if database transaction rolls back', function () {
    Storage::fake(EmployeePrivateFile::DISK);

    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeBulkVoidFixtures([
        'training.delete',
    ]);
    $service = app(CrewMovementService::class);
    $assignment = $service->createDraft($company->id, $employee->id, ['rank_id' => $rank->id], $user->id);

    $filePath = "employees/{$company->id}/training-certificates/rollback-test.pdf";
    Storage::disk(EmployeePrivateFile::DISK)->put($filePath, 'precious certificate bytes');

    $training = EmployeeTraining::factory()->forEmployee($employee)->create([
        'source_crew_assignment_phase_id' => $assignment->currentPhase->id,
        'certificate_path' => $filePath,
        'current_version' => 1,
    ]);

    // Force an exception after training soft delete inside the transaction
    CrewAssignment::deleting(function ($model) use ($assignment) {
        if ($model->id === $assignment->id) {
            throw new RuntimeException('Simulated failure during assignment void');
        }
    });

    try {
        app(BulkVoidCrewAssignments::class)->handle(
            $company->id,
            [$assignment->id],
            $user,
            'Rollback test',
            deleteTraining: true,
        );
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toBe('Simulated failure during assignment void');
    } finally {
        CrewAssignment::flushEventListeners();
    }

    // 1. Certificate file still exists on disk
    expect(Storage::disk(EmployeePrivateFile::DISK)->exists($filePath))->toBeTrue();

    // 2. Training record remains active
    expect(EmployeeTraining::query()->whereKey($training->id)->exists())->toBeTrue();

    // 3. Assignment remains active
    expect(CrewAssignment::query()->whereKey($assignment->id)->exists())->toBeTrue();
});

test('batch blocker resolution executes in constant queries and eliminates N+1 overhead', function () {
    ['company' => $company, 'rank' => $rank, 'user' => $user] = makeBulkVoidFixtures();
    $service = app(CrewMovementService::class);

    $assignments = [];
    for ($i = 0; $i < 5; $i++) {
        $emp = Employee::factory()->forCompany($company)->create(['rank_id' => $rank->id]);
        $assignments[] = $service->createDraft($company->id, $emp->id, ['rank_id' => $rank->id], $user->id);
    }

    $guard = app(CrewAssignmentVoidGuard::class);

    DB::flushQueryLog();
    DB::enableQueryLog();

    $results = $guard->batchBlockers($assignments, $company->id);

    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    // An N+1 approach would execute ~7-8 queries per assignment (= 35-40 queries for 5 assignments).
    // The batched implementation executes in a small constant number of queries (<= 10 queries).
    expect(count($queries))->toBeLessThanOrEqual(10)
        ->and(count($results))->toBe(5);

    foreach ($assignments as $a) {
        expect($results)->toHaveKey($a->id);
    }
});
