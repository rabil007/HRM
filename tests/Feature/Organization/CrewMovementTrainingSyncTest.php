<?php

use App\Enums\CrewMovementAction;
use App\Enums\CrewMovementCorrectionStatus;
use App\Enums\CrewPhaseCode;
use App\Enums\CrewPhaseStatus;
use App\Exceptions\CrewMovementException;
use App\Models\Country;
use App\Models\Course;
use App\Models\CrewMovementCorrection;
use App\Models\CrewOperationsSetting;
use App\Models\EmployeeTraining;
use App\Models\User;
use App\Support\CrewMovements\Actions\VoidCrewAssignment;
use App\Support\CrewMovements\Corrections\ApproveCrewMovementCorrection;
use App\Support\CrewMovements\Corrections\RequestCrewMovementCorrection;
use App\Support\CrewMovements\CrewMovementService;
use App\Support\CrewMovements\SyncCrewTrainingToEmployeeTraining;
use Spatie\Activitylog\Models\Activity;

function enableCrewTrainingSync(int $companyId): void
{
    CrewOperationsSetting::query()->updateOrCreate(
        ['company_id' => $companyId],
        [
            'sync_training_to_employee_training' => true,
        ]
    );
}

function disableCrewTrainingSync(int $companyId): void
{
    CrewOperationsSetting::query()->updateOrCreate(
        ['company_id' => $companyId],
        [
            'sync_training_to_employee_training' => false,
        ]
    );
}

function makeActiveCourse(string $name = 'BOSIET with CA-EBS'): Course
{
    return Course::query()->create([
        'name' => $name,
        'is_active' => true,
    ]);
}

test('completing p2b training with sync toggle ON creates employee training record', function () {
    ['company' => $company, 'employee' => $employee, 'user' => $user] = makeCrewAssignmentFixtures();
    enableCrewTrainingSync($company->id);

    $course = makeActiveCourse('BOSIET Induction');
    $service = app(CrewMovementService::class);

    $assignment = $service->createDraft($company->id, $employee->id, [], $user->id);
    $id = $assignment->id;

    $service->perform($company->id, $id, CrewMovementAction::ApproveMobilisation, [
        'occurred_at' => '2026-03-01 08:00:00',
    ], $user->id);

    $service->perform($company->id, $id, CrewMovementAction::RecordArrival, [
        'occurred_at' => '2026-03-02 09:00:00',
        'next_phase' => 'p2a',
    ], $user->id);

    $service->perform($company->id, $id, CrewMovementAction::SendToTraining, [
        'occurred_at' => '2026-03-03 10:00:00',
        'course_id' => $course->id,
        'course' => $course->name,
        'provider' => 'Falck Safety Services',
        'planned_start_at' => '2026-03-03 10:00:00',
        'planned_end_at' => '2026-03-06 17:00:00',
    ], $user->id);

    $trainingPhase = $assignment->fresh()->currentPhase;
    expect($trainingPhase->phase_code)->toBe(CrewPhaseCode::Training)
        ->and($trainingPhase->details['course_id'])->toBe($course->id);

    $service->perform($company->id, $id, CrewMovementAction::CompleteTraining, [
        'occurred_at' => '2026-03-07 14:30:00',
        'next_phase' => 'p2a',
        'sync_training_to_employee_training' => true,
    ], $user->id);

    $synced = EmployeeTraining::query()
        ->where('source_crew_assignment_phase_id', $trainingPhase->id)
        ->first();

    expect($synced)->not->toBeNull()
        ->and($synced->company_id)->toBe($company->id)
        ->and($synced->employee_id)->toBe($employee->id)
        ->and($synced->course_id)->toBe($course->id)
        ->and($synced->issue_date->toDateString())->toBe('2026-03-07')
        ->and($synced->institute_center)->toBe('Falck Safety Services')
        ->and($synced->expiry_date)->toBeNull()
        ->and($synced->country_id)->toBeNull()
        ->and($synced->certificate_path)->toBeNull()
        ->and($synced->isFromCrewOperations())->toBeTrue();

    // Verify Spatie activity log recorded creation
    $activity = Activity::query()
        ->where('subject_type', EmployeeTraining::class)
        ->where('subject_id', $synced->id)
        ->latest('id')
        ->first();

    expect($activity)->not->toBeNull();
});

test('completing p2b training with sync toggle ON and individual skip unchecked does not create employee training', function () {
    ['company' => $company, 'employee' => $employee, 'user' => $user] = makeCrewAssignmentFixtures();
    enableCrewTrainingSync($company->id);

    $course = makeActiveCourse('H2S Safety');
    $service = app(CrewMovementService::class);

    $assignment = $service->createDraft($company->id, $employee->id, [], $user->id);
    $id = $assignment->id;

    $service->perform($company->id, $id, CrewMovementAction::ApproveMobilisation, [
        'occurred_at' => '2026-03-01 08:00:00',
    ], $user->id);

    $service->perform($company->id, $id, CrewMovementAction::RecordArrival, [
        'occurred_at' => '2026-03-02 09:00:00',
        'next_phase' => 'p2a',
    ], $user->id);

    $service->perform($company->id, $id, CrewMovementAction::SendToTraining, [
        'occurred_at' => '2026-03-03 10:00:00',
        'course_id' => $course->id,
        'provider' => 'Enertech Qatar',
    ], $user->id);

    $trainingPhase = $assignment->fresh()->currentPhase;

    // User unchecks individual sync
    $service->perform($company->id, $id, CrewMovementAction::CompleteTraining, [
        'occurred_at' => '2026-03-05 12:00:00',
        'next_phase' => 'p3',
        'sync_training_to_employee_training' => false,
    ], $user->id);

    $synced = EmployeeTraining::query()
        ->where('source_crew_assignment_phase_id', $trainingPhase->id)
        ->first();

    expect($synced)->toBeNull();
});

test('completing p2b training with sync toggle OFF does not create employee training', function () {
    ['company' => $company, 'employee' => $employee, 'user' => $user] = makeCrewAssignmentFixtures();
    disableCrewTrainingSync($company->id);

    $course = makeActiveCourse('First Aid at Sea');
    $service = app(CrewMovementService::class);

    $assignment = $service->createDraft($company->id, $employee->id, [], $user->id);
    $id = $assignment->id;

    $service->perform($company->id, $id, CrewMovementAction::ApproveMobilisation, [
        'occurred_at' => '2026-03-01 08:00:00',
    ], $user->id);

    $service->perform($company->id, $id, CrewMovementAction::RecordArrival, [
        'occurred_at' => '2026-03-02 09:00:00',
        'next_phase' => 'p2a',
    ], $user->id);

    $service->perform($company->id, $id, CrewMovementAction::SendToTraining, [
        'occurred_at' => '2026-03-03 10:00:00',
        'course_id' => $course->id,
    ], $user->id);

    $trainingPhase = $assignment->fresh()->currentPhase;

    $service->perform($company->id, $id, CrewMovementAction::CompleteTraining, [
        'occurred_at' => '2026-03-05 12:00:00',
        'next_phase' => 'p2a',
        'sync_training_to_employee_training' => true,
    ], $user->id);

    $synced = EmployeeTraining::query()
        ->where('source_crew_assignment_phase_id', $trainingPhase->id)
        ->first();

    expect($synced)->toBeNull();
});

test('completing p2b training with sync ON without course throws validation exception', function () {
    ['company' => $company, 'employee' => $employee, 'user' => $user] = makeCrewAssignmentFixtures();
    enableCrewTrainingSync($company->id);

    $service = app(CrewMovementService::class);

    $assignment = $service->createDraft($company->id, $employee->id, [], $user->id);
    $id = $assignment->id;

    $service->perform($company->id, $id, CrewMovementAction::ApproveMobilisation, [
        'occurred_at' => '2026-03-01 08:00:00',
    ], $user->id);

    $service->perform($company->id, $id, CrewMovementAction::RecordArrival, [
        'occurred_at' => '2026-03-02 09:00:00',
        'next_phase' => 'p2a',
    ], $user->id);

    $service->perform($company->id, $id, CrewMovementAction::SendToTraining, [
        'occurred_at' => '2026-03-03 10:00:00',
        // No course_id
    ], $user->id);

    expect(fn () => $service->perform($company->id, $id, CrewMovementAction::CompleteTraining, [
        'occurred_at' => '2026-03-05 12:00:00',
        'next_phase' => 'p2a',
        'sync_training_to_employee_training' => true,
    ], $user->id))->toThrow(CrewMovementException::class, 'Select a Course before adding this training to the employee\'s Training record.');
});

test('planned vs actual completion date maps to actual completion date in company timezone', function () {
    ['company' => $company, 'employee' => $employee, 'user' => $user] = makeCrewAssignmentFixtures();
    $company->update(['timezone' => 'Asia/Dubai']);
    enableCrewTrainingSync($company->id);

    $course = makeActiveCourse('Advanced Fire Fighting');
    $service = app(CrewMovementService::class);

    $assignment = $service->createDraft($company->id, $employee->id, [], $user->id);
    $id = $assignment->id;

    $service->perform($company->id, $id, CrewMovementAction::ApproveMobilisation, [
        'occurred_at' => '2026-03-01 08:00:00',
    ], $user->id);

    $service->perform($company->id, $id, CrewMovementAction::RecordArrival, [
        'occurred_at' => '2026-03-02 09:00:00',
        'next_phase' => 'p2a',
    ], $user->id);

    $service->perform($company->id, $id, CrewMovementAction::SendToTraining, [
        'occurred_at' => '2026-03-03 10:00:00',
        'course_id' => $course->id,
        'planned_start_at' => '2026-03-03 08:00:00',
        'planned_end_at' => '2026-03-05 17:00:00', // Planned end is March 5
    ], $user->id);

    $trainingPhase = $assignment->fresh()->currentPhase;

    // Actual completion occurred on March 8
    $service->perform($company->id, $id, CrewMovementAction::CompleteTraining, [
        'occurred_at' => '2026-03-08 16:00:00',
        'next_phase' => 'p3',
    ], $user->id);

    $synced = EmployeeTraining::query()
        ->where('source_crew_assignment_phase_id', $trainingPhase->id)
        ->first();

    expect($synced)->not->toBeNull()
        ->and($synced->issue_date->toDateString())->toBe('2026-03-08')
        ->and($synced->issue_date->toDateString())->not->toBe('2026-03-05');
});

test('idempotent sync does not duplicate employee training', function () {
    ['company' => $company, 'employee' => $employee, 'user' => $user] = makeCrewAssignmentFixtures();
    enableCrewTrainingSync($company->id);

    $course = makeActiveCourse('Medical First Aid');
    $service = app(CrewMovementService::class);

    $assignment = $service->createDraft($company->id, $employee->id, [], $user->id);
    $id = $assignment->id;

    $service->perform($company->id, $id, CrewMovementAction::ApproveMobilisation, [
        'occurred_at' => '2026-03-01 08:00:00',
    ], $user->id);

    $service->perform($company->id, $id, CrewMovementAction::RecordArrival, [
        'occurred_at' => '2026-03-02 09:00:00',
        'next_phase' => 'p2a',
    ], $user->id);

    $service->perform($company->id, $id, CrewMovementAction::SendToTraining, [
        'occurred_at' => '2026-03-03 10:00:00',
        'course_id' => $course->id,
        'provider' => 'Academy A',
    ], $user->id);

    $trainingPhase = $assignment->fresh()->currentPhase;

    $service->perform($company->id, $id, CrewMovementAction::CompleteTraining, [
        'occurred_at' => '2026-03-05 12:00:00',
        'next_phase' => 'p2a',
    ], $user->id);

    expect(EmployeeTraining::query()->where('source_crew_assignment_phase_id', $trainingPhase->id)->count())->toBe(1);

    // Call syncFromPhase again directly
    $syncer = app(SyncCrewTrainingToEmployeeTraining::class);
    $syncedAgain = $syncer->syncFromPhase($trainingPhase->fresh(), $course->id);

    expect(EmployeeTraining::query()->where('source_crew_assignment_phase_id', $trainingPhase->id)->count())->toBe(1)
        ->and($syncedAgain->id)->toBe(EmployeeTraining::query()->where('source_crew_assignment_phase_id', $trainingPhase->id)->first()->id);
});

test('repeated p2b phases in same assignment create distinct employee trainings', function () {
    ['company' => $company, 'employee' => $employee, 'user' => $user] = makeCrewAssignmentFixtures();
    enableCrewTrainingSync($company->id);

    $course1 = makeActiveCourse('Course 1');
    $course2 = makeActiveCourse('Course 2');
    $service = app(CrewMovementService::class);

    $assignment = $service->createDraft($company->id, $employee->id, [], $user->id);
    $id = $assignment->id;

    $service->perform($company->id, $id, CrewMovementAction::ApproveMobilisation, [
        'occurred_at' => '2026-03-01 08:00:00',
    ], $user->id);

    $service->perform($company->id, $id, CrewMovementAction::RecordArrival, [
        'occurred_at' => '2026-03-02 09:00:00',
        'next_phase' => 'p2a',
    ], $user->id);

    // First training
    $service->perform($company->id, $id, CrewMovementAction::SendToTraining, [
        'occurred_at' => '2026-03-03 10:00:00',
        'course_id' => $course1->id,
        'provider' => 'Provider 1',
    ], $user->id);
    $firstPhase = $assignment->fresh()->currentPhase;

    $service->perform($company->id, $id, CrewMovementAction::CompleteTraining, [
        'occurred_at' => '2026-03-05 12:00:00',
        'next_phase' => 'p2a',
    ], $user->id);

    // Second training
    $service->perform($company->id, $id, CrewMovementAction::SendToTraining, [
        'occurred_at' => '2026-03-06 10:00:00',
        'course_id' => $course2->id,
        'provider' => 'Provider 2',
    ], $user->id);
    $secondPhase = $assignment->fresh()->currentPhase;

    $service->perform($company->id, $id, CrewMovementAction::CompleteTraining, [
        'occurred_at' => '2026-03-08 12:00:00',
        'next_phase' => 'p3',
    ], $user->id);

    $trainings = EmployeeTraining::query()
        ->where('employee_id', $employee->id)
        ->orderBy('id')
        ->get();

    expect($trainings)->toHaveCount(2)
        ->and($trainings[0]->source_crew_assignment_phase_id)->toBe($firstPhase->id)
        ->and($trainings[0]->course_id)->toBe($course1->id)
        ->and($trainings[1]->source_crew_assignment_phase_id)->toBe($secondPhase->id)
        ->and($trainings[1]->course_id)->toBe($course2->id);
});

test('voiding assignment preserves employee training record', function () {
    ['company' => $company, 'employee' => $employee, 'user' => $user] = makeCrewAssignmentFixtures();
    enableCrewTrainingSync($company->id);

    $course = makeActiveCourse('Survival Craft');
    $service = app(CrewMovementService::class);

    $assignment = $service->createDraft($company->id, $employee->id, [], $user->id);
    $id = $assignment->id;

    $service->perform($company->id, $id, CrewMovementAction::ApproveMobilisation, [
        'occurred_at' => '2026-03-01 08:00:00',
    ], $user->id);

    $service->perform($company->id, $id, CrewMovementAction::RecordArrival, [
        'occurred_at' => '2026-03-02 09:00:00',
        'next_phase' => 'p2a',
    ], $user->id);

    $service->perform($company->id, $id, CrewMovementAction::SendToTraining, [
        'occurred_at' => '2026-03-03 10:00:00',
        'course_id' => $course->id,
    ], $user->id);

    $trainingPhase = $assignment->fresh()->currentPhase;

    $service->perform($company->id, $id, CrewMovementAction::CompleteTraining, [
        'occurred_at' => '2026-03-05 12:00:00',
        'next_phase' => 'p2a',
    ], $user->id);

    $trainingId = EmployeeTraining::query()
        ->where('source_crew_assignment_phase_id', $trainingPhase->id)
        ->value('id');

    expect($trainingId)->not->toBeNull();

    // Void the assignment
    $voider = app(VoidCrewAssignment::class);
    $voider->handle($company->id, $assignment->id, $user, 'Created in error');

    // EmployeeTraining must NOT be deleted
    $trainingAfterVoid = EmployeeTraining::query()->find($trainingId);
    expect($trainingAfterVoid)->not->toBeNull();
});

test('approved correction on p2b training updates linked employee training', function () {
    ['company' => $company, 'employee' => $employee, 'user' => $user] = makeCrewAssignmentFixtures();
    enableCrewTrainingSync($company->id);

    $course = makeActiveCourse('Basic Safety');
    $service = app(CrewMovementService::class);

    $assignment = $service->createDraft($company->id, $employee->id, [], $user->id);
    $id = $assignment->id;

    $service->perform($company->id, $id, CrewMovementAction::ApproveMobilisation, [
        'occurred_at' => '2026-03-01 08:00:00',
    ], $user->id);

    $service->perform($company->id, $id, CrewMovementAction::RecordArrival, [
        'occurred_at' => '2026-03-02 09:00:00',
        'next_phase' => 'p2a',
    ], $user->id);

    $service->perform($company->id, $id, CrewMovementAction::SendToTraining, [
        'occurred_at' => '2026-03-03 10:00:00',
        'course_id' => $course->id,
        'provider' => 'Old Provider',
    ], $user->id);

    $trainingPhase = $assignment->fresh()->currentPhase;

    $service->perform($company->id, $id, CrewMovementAction::CompleteTraining, [
        'occurred_at' => '2026-03-05 12:00:00',
        'next_phase' => 'p2a',
    ], $user->id);

    $trainingPhase->refresh();

    $training = EmployeeTraining::query()
        ->where('source_crew_assignment_phase_id', $trainingPhase->id)
        ->firstOrFail();

    expect($training->institute_center)->toBe('Old Provider')
        ->and($training->issue_date->toDateString())->toBe('2026-03-05');

    // Create a correction on provider and actual_end_at
    $approver = User::factory()->create();
    grantCompanyPermissions($approver, $company, [
        'crew_operations.corrections.view',
        'crew_operations.corrections.approve',
    ]);

    $correction = CrewMovementCorrection::query()->create([
        'company_id' => $company->id,
        'crew_assignment_id' => $assignment->id,
        'crew_assignment_phase_id' => $trainingPhase->id,
        'status' => CrewMovementCorrectionStatus::Pending,
        'original_values' => [
            'actual_start_at' => $trainingPhase->actual_start_at->toIso8601String(),
            'actual_end_at' => $trainingPhase->actual_end_at->toIso8601String(),
            'details.provider' => 'Old Provider',
            'details.course' => 'Basic Safety',
            'remarks' => null,
        ],
        'proposed_values' => [
            'actual_end_at' => '2026-03-04 18:00:00',
            'details.provider' => 'Corrected Provider Academy',
        ],
        'reason' => 'Provider name and completion date entered incorrectly',
        'requested_by' => $user->id,
        'requested_at' => now(),
    ]);

    $applier = app(ApproveCrewMovementCorrection::class);
    $applier->handle($correction, $approver, $company->id, 'Approved correction');

    $training->refresh();
    expect($training->institute_center)->toBe('Corrected Provider Academy')
        ->and($training->issue_date->toDateString())->toBe('2026-03-04');
});

test('enabling sync toggle later does not retroactively create employee training for past completed phases', function () {
    ['company' => $company, 'employee' => $employee, 'user' => $user] = makeCrewAssignmentFixtures();
    disableCrewTrainingSync($company->id);

    $course = makeActiveCourse('Past Course');
    $service = app(CrewMovementService::class);

    $assignment = $service->createDraft($company->id, $employee->id, [], $user->id);
    $id = $assignment->id;

    $service->perform($company->id, $id, CrewMovementAction::ApproveMobilisation, [
        'occurred_at' => '2026-03-01 08:00:00',
    ], $user->id);

    $service->perform($company->id, $id, CrewMovementAction::RecordArrival, [
        'occurred_at' => '2026-03-02 09:00:00',
        'next_phase' => 'p2a',
    ], $user->id);

    $service->perform($company->id, $id, CrewMovementAction::SendToTraining, [
        'occurred_at' => '2026-03-03 10:00:00',
        'course_id' => $course->id,
    ], $user->id);

    $trainingPhase = $assignment->fresh()->currentPhase;

    $service->perform($company->id, $id, CrewMovementAction::CompleteTraining, [
        'occurred_at' => '2026-03-05 12:00:00',
        'next_phase' => 'p2a',
    ], $user->id);

    expect(EmployeeTraining::query()->where('employee_id', $employee->id)->count())->toBe(0);

    // Now turn on setting
    enableCrewTrainingSync($company->id);

    // Past completed phase remains unsynced
    expect(EmployeeTraining::query()->where('employee_id', $employee->id)->count())->toBe(0)
        ->and(EmployeeTraining::query()->where('source_crew_assignment_phase_id', $trainingPhase->id)->first())->toBeNull();
});

test('sync action enforces tenant isolation', function () {
    ['company' => $company1, 'employee' => $employee1, 'user' => $user] = makeCrewAssignmentFixtures();
    ['company' => $company2, 'employee' => $employee2] = makeCrewAssignmentFixtures();

    enableCrewTrainingSync($company1->id);
    $course = makeActiveCourse('Isolated Course');

    $syncer = app(SyncCrewTrainingToEmployeeTraining::class);

    // Create a phase for company1, but tamper assignment to point to employee2 (company2)
    $service = app(CrewMovementService::class);
    $assignment = $service->createDraft($company1->id, $employee1->id, [], $user->id);
    $service->perform($company1->id, $assignment->id, CrewMovementAction::ApproveMobilisation, [
        'occurred_at' => '2026-03-01 08:00:00',
    ], $user->id);
    $service->perform($company1->id, $assignment->id, CrewMovementAction::RecordArrival, [
        'occurred_at' => '2026-03-02 09:00:00',
        'next_phase' => 'p2a',
    ], $user->id);
    $service->perform($company1->id, $assignment->id, CrewMovementAction::SendToTraining, [
        'occurred_at' => '2026-03-03 10:00:00',
        'course_id' => $course->id,
    ], $user->id);

    $trainingPhase = $assignment->fresh()->currentPhase;
    $trainingPhase->update([
        'status' => CrewPhaseStatus::Completed,
        'actual_end_at' => now(),
    ]);

    // Alter assignment employee to foreign company employee
    $assignment->update(['employee_id' => $employee2->id]);

    expect(fn () => $syncer->syncFromPhase($trainingPhase->fresh(), $course->id))
        ->toThrow(InvalidArgumentException::class, 'Employee does not belong to the assignment company.');
});

test('re-sync preserves HR-enriched fields on existing EmployeeTraining', function () {
    ['company' => $company, 'employee' => $employee, 'user' => $user] = makeCrewAssignmentFixtures();
    enableCrewTrainingSync($company->id);

    $course = makeActiveCourse('BOSIET Initial');
    $service = app(CrewMovementService::class);

    $assignment = $service->createDraft($company->id, $employee->id, [], $user->id);
    $id = $assignment->id;

    $service->perform($company->id, $id, CrewMovementAction::ApproveMobilisation, [
        'occurred_at' => '2026-03-01 08:00:00',
    ], $user->id);

    $service->perform($company->id, $id, CrewMovementAction::RecordArrival, [
        'occurred_at' => '2026-03-02 09:00:00',
        'next_phase' => 'p2a',
    ], $user->id);

    $service->perform($company->id, $id, CrewMovementAction::SendToTraining, [
        'occurred_at' => '2026-03-03 10:00:00',
        'course_id' => $course->id,
        'provider' => 'Initial Academy',
    ], $user->id);

    $trainingPhase = $assignment->fresh()->currentPhase;

    $service->perform($company->id, $id, CrewMovementAction::CompleteTraining, [
        'occurred_at' => '2026-03-05 12:00:00',
        'next_phase' => 'p2a',
    ], $user->id);

    $synced = EmployeeTraining::query()
        ->where('source_crew_assignment_phase_id', $trainingPhase->id)
        ->firstOrFail();

    $country = Country::query()->firstOrFail();

    // HR enriches the training record with expiry, country, and certificate details
    $synced->update([
        'expiry_date' => '2031-09-12',
        'country_id' => $country->id,
        'certificate_path' => 'employees/1/trainings/cert.pdf',
        'certificate_original_filename' => 'cert.pdf',
        'certificate_mime_type' => 'application/pdf',
        'certificate_size_bytes' => 1048576,
        'current_version' => 1,
    ]);

    // Update phase details to simulate a provider change and re-sync
    $trainingPhase->update([
        'details' => [
            'course_id' => $course->id,
            'course' => $course->name,
            'provider' => 'Updated Academy Provider',
        ],
    ]);

    $syncer = app(SyncCrewTrainingToEmployeeTraining::class);
    $resynced = $syncer->syncFromPhase($trainingPhase->fresh(), $course->id);

    expect($resynced)->not->toBeNull()
        ->and($resynced->id)->toBe($synced->id)
        // Crew-owned fields update
        ->and($resynced->institute_center)->toBe('Updated Academy Provider')
        ->and($resynced->course_id)->toBe($course->id)
        ->and($resynced->issue_date->toDateString())->toBe('2026-03-05')
        // HR-owned fields remain intact and are NEVER reset to null
        ->and($resynced->expiry_date->toDateString())->toBe('2031-09-12')
        ->and($resynced->country_id)->toBe($country->id)
        ->and($resynced->certificate_path)->toBe('employees/1/trainings/cert.pdf')
        ->and($resynced->certificate_original_filename)->toBe('cert.pdf')
        ->and($resynced->certificate_mime_type)->toBe('application/pdf')
        ->and($resynced->certificate_size_bytes)->toBe(1048576)
        ->and($resynced->current_version)->toBe(1);
});

test('active or incomplete p2b phase cannot sync to employee training', function () {
    ['company' => $company, 'employee' => $employee, 'user' => $user] = makeCrewAssignmentFixtures();
    enableCrewTrainingSync($company->id);

    $course = makeActiveCourse('BOSIET');
    $service = app(CrewMovementService::class);

    $assignment = $service->createDraft($company->id, $employee->id, [], $user->id);
    $id = $assignment->id;

    $service->perform($company->id, $id, CrewMovementAction::ApproveMobilisation, [
        'occurred_at' => '2026-03-01 08:00:00',
    ], $user->id);

    $service->perform($company->id, $id, CrewMovementAction::RecordArrival, [
        'occurred_at' => '2026-03-02 09:00:00',
        'next_phase' => 'p2a',
    ], $user->id);

    $service->perform($company->id, $id, CrewMovementAction::SendToTraining, [
        'occurred_at' => '2026-03-03 10:00:00',
        'course_id' => $course->id,
        'provider' => 'Safety Academy',
    ], $user->id);

    $trainingPhase = $assignment->fresh()->currentPhase;
    expect($trainingPhase->phase_code)->toBe(CrewPhaseCode::Training)
        ->and($trainingPhase->status)->toBe(CrewPhaseStatus::Active)
        ->and($trainingPhase->actual_end_at)->toBeNull();

    $syncer = app(SyncCrewTrainingToEmployeeTraining::class);

    // 1. Direct sync on Active P2B phase returns null and creates no training
    $resultActive = $syncer->syncFromPhase($trainingPhase->fresh(), $course->id);
    expect($resultActive)->toBeNull()
        ->and(EmployeeTraining::query()->where('employee_id', $employee->id)->count())->toBe(0);

    // 2. Direct sync on Cancelled P2B phase returns null
    $trainingPhase->update([
        'status' => CrewPhaseStatus::Cancelled,
        'actual_end_at' => '2026-03-05 12:00:00',
    ]);
    $resultCancelled = $syncer->syncFromPhase($trainingPhase->fresh(), $course->id);
    expect($resultCancelled)->toBeNull()
        ->and(EmployeeTraining::query()->where('employee_id', $employee->id)->count())->toBe(0);

    // 3. Completed P2B without actual_end_at returns null
    $trainingPhase->update([
        'status' => CrewPhaseStatus::Completed,
        'actual_end_at' => null,
    ]);
    $resultNoEnd = $syncer->syncFromPhase($trainingPhase->fresh(), $course->id);
    expect($resultNoEnd)->toBeNull()
        ->and(EmployeeTraining::query()->where('employee_id', $employee->id)->count())->toBe(0);

    // 4. Completed P2B with actual_end_at succeeds
    $trainingPhase->update([
        'status' => CrewPhaseStatus::Completed,
        'actual_end_at' => '2026-03-05 12:00:00',
    ]);
    $resultCompleted = $syncer->syncFromPhase($trainingPhase->fresh(), $course->id);
    expect($resultCompleted)->not->toBeNull()
        ->and($resultCompleted->issue_date->toDateString())->toBe('2026-03-05')
        ->and(EmployeeTraining::query()->where('employee_id', $employee->id)->count())->toBe(1);
});

test('sync uses actual completion date and never planned dates or synthetic fallback', function () {
    ['company' => $company, 'employee' => $employee, 'user' => $user] = makeCrewAssignmentFixtures();
    enableCrewTrainingSync($company->id);

    $course = makeActiveCourse('Helicopter Underwater Escape');
    $service = app(CrewMovementService::class);

    $assignment = $service->createDraft($company->id, $employee->id, [], $user->id);
    $id = $assignment->id;

    $service->perform($company->id, $id, CrewMovementAction::ApproveMobilisation, [
        'occurred_at' => '2026-03-01 08:00:00',
    ], $user->id);

    $service->perform($company->id, $id, CrewMovementAction::RecordArrival, [
        'occurred_at' => '2026-03-02 09:00:00',
        'next_phase' => 'p2a',
    ], $user->id);

    // Planned end is March 5th
    $service->perform($company->id, $id, CrewMovementAction::SendToTraining, [
        'occurred_at' => '2026-03-03 10:00:00',
        'course_id' => $course->id,
        'planned_start_at' => '2026-03-03 10:00:00',
        'planned_end_at' => '2026-03-05 17:00:00',
    ], $user->id);

    $trainingPhase = $assignment->fresh()->currentPhase;

    // Actual completion is March 8th (3 days after planned end)
    $service->perform($company->id, $id, CrewMovementAction::CompleteTraining, [
        'occurred_at' => '2026-03-08 16:45:00',
        'next_phase' => 'p2a',
    ], $user->id);

    $synced = EmployeeTraining::query()
        ->where('source_crew_assignment_phase_id', $trainingPhase->id)
        ->firstOrFail();

    expect($synced->issue_date->toDateString())->toBe('2026-03-08')
        ->and($synced->issue_date->toDateString())->not->toBe('2026-03-05')
        ->and($synced->issue_date->toDateString())->not->toBe(now($company->timezone)->toDateString());
});

test('provider and completion date corrections preserve HR-enriched fields', function () {
    ['company' => $company, 'employee' => $employee, 'user' => $user] = makeCrewAssignmentFixtures();
    enableCrewTrainingSync($company->id);

    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.view',
        'crew_operations.assignments.update',
        'crew_operations.movements.perform',
        'crew_operations.corrections.view',
        'crew_operations.corrections.request',
        'crew_operations.corrections.approve',
        'crew_operations.corrections.override',
    ]);

    $course = makeActiveCourse('BOSIET Training');
    $service = app(CrewMovementService::class);

    $assignment = $service->createDraft($company->id, $employee->id, [], $user->id);
    $id = $assignment->id;

    $service->perform($company->id, $id, CrewMovementAction::ApproveMobilisation, [
        'occurred_at' => '2026-03-01 08:00:00',
    ], $user->id);

    $service->perform($company->id, $id, CrewMovementAction::RecordArrival, [
        'occurred_at' => '2026-03-02 09:00:00',
        'next_phase' => 'p2a',
    ], $user->id);

    $service->perform($company->id, $id, CrewMovementAction::SendToTraining, [
        'occurred_at' => '2026-03-03 10:00:00',
        'course_id' => $course->id,
        'provider' => 'Original Academy',
    ], $user->id);

    $trainingPhase = $assignment->fresh()->currentPhase;

    $service->perform($company->id, $id, CrewMovementAction::CompleteTraining, [
        'occurred_at' => '2026-03-05 12:00:00',
        'next_phase' => 'p2a',
    ], $user->id);

    $synced = EmployeeTraining::query()
        ->where('source_crew_assignment_phase_id', $trainingPhase->id)
        ->firstOrFail();

    $country = Country::query()->firstOrFail();

    // HR adds certificate and expiry
    $synced->update([
        'expiry_date' => '2030-05-15',
        'country_id' => $country->id,
        'certificate_path' => 'employees/cert_sgp.pdf',
    ]);

    // Propose correction to provider and completion date (before next phase start)
    $requester = app(RequestCrewMovementCorrection::class);
    $correction = $requester->handle(
        $assignment->fresh(),
        $trainingPhase->fresh(),
        $user,
        [
            'details.provider' => 'Corrected Global Academy',
            'actual_end_at' => '2026-03-04 18:00:00',
        ],
        'Correcting provider name and completion date',
    );

    $approver = app(ApproveCrewMovementCorrection::class);
    $approver->handle($correction, $user, $company->id, 'Approved by manager');

    $synced->refresh();

    // Crew-owned fields updated
    expect($synced->institute_center)->toBe('Corrected Global Academy')
        ->and($synced->issue_date->toDateString())->toBe('2026-03-04')
        // HR-owned fields preserved
        ->and($synced->expiry_date->toDateString())->toBe('2030-05-15')
        ->and($synced->country_id)->toBe($country->id)
        ->and($synced->certificate_path)->toBe('employees/cert_sgp.pdf');
});

test('course correction via structured course_id updates p2b details and employee training course atomically', function () {
    ['company' => $company, 'employee' => $employee, 'user' => $user] = makeCrewAssignmentFixtures();
    enableCrewTrainingSync($company->id);

    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.view',
        'crew_operations.assignments.update',
        'crew_operations.movements.perform',
        'crew_operations.corrections.view',
        'crew_operations.corrections.request',
        'crew_operations.corrections.approve',
        'crew_operations.corrections.override',
    ]);

    $courseA = makeActiveCourse('BOSIET Course A');
    $courseB = makeActiveCourse('FOET Course B');
    $service = app(CrewMovementService::class);

    $assignment = $service->createDraft($company->id, $employee->id, [], $user->id);
    $id = $assignment->id;

    $service->perform($company->id, $id, CrewMovementAction::ApproveMobilisation, [
        'occurred_at' => '2026-03-01 08:00:00',
    ], $user->id);

    $service->perform($company->id, $id, CrewMovementAction::RecordArrival, [
        'occurred_at' => '2026-03-02 09:00:00',
        'next_phase' => 'p2a',
    ], $user->id);

    $service->perform($company->id, $id, CrewMovementAction::SendToTraining, [
        'occurred_at' => '2026-03-03 10:00:00',
        'course_id' => $courseA->id,
        'provider' => 'Training Provider Inc',
    ], $user->id);

    $trainingPhase = $assignment->fresh()->currentPhase;

    $service->perform($company->id, $id, CrewMovementAction::CompleteTraining, [
        'occurred_at' => '2026-03-05 12:00:00',
        'next_phase' => 'p2a',
    ], $user->id);

    $synced = EmployeeTraining::query()
        ->where('source_crew_assignment_phase_id', $trainingPhase->id)
        ->firstOrFail();

    expect($synced->course_id)->toBe($courseA->id);

    // HR enriches record
    $synced->update(['expiry_date' => '2029-03-05']);

    // Propose course correction using structured course_id
    $requester = app(RequestCrewMovementCorrection::class);
    $correction = $requester->handle(
        $assignment->fresh(),
        $trainingPhase->fresh(),
        $user,
        [
            'details.course_id' => $courseB->id,
        ],
        'Course was actually FOET, not BOSIET',
    );

    $approver = app(ApproveCrewMovementCorrection::class);
    $approver->handle($correction, $user, $company->id, 'Course correction approved');

    $trainingPhase->refresh();
    $synced->refresh();

    // P2B details updated atomically
    expect($trainingPhase->details['course_id'])->toBe($courseB->id)
        ->and($trainingPhase->details['course'])->toBe($courseB->name)
        // Linked EmployeeTraining course updated atomically
        ->and($synced->course_id)->toBe($courseB->id)
        // HR-owned expiry date preserved
        ->and($synced->expiry_date->toDateString())->toBe('2029-03-05');
});

test('free text course correction on p2b phase linked to employee training is rejected', function () {
    ['company' => $company, 'employee' => $employee, 'user' => $user] = makeCrewAssignmentFixtures();
    enableCrewTrainingSync($company->id);

    $course = makeActiveCourse('Original BOSIET');
    $service = app(CrewMovementService::class);

    $assignment = $service->createDraft($company->id, $employee->id, [], $user->id);
    $id = $assignment->id;

    $service->perform($company->id, $id, CrewMovementAction::ApproveMobilisation, [
        'occurred_at' => '2026-03-01 08:00:00',
    ], $user->id);

    $service->perform($company->id, $id, CrewMovementAction::RecordArrival, [
        'occurred_at' => '2026-03-02 09:00:00',
        'next_phase' => 'p2a',
    ], $user->id);

    $service->perform($company->id, $id, CrewMovementAction::SendToTraining, [
        'occurred_at' => '2026-03-03 10:00:00',
        'course_id' => $course->id,
    ], $user->id);

    $trainingPhase = $assignment->fresh()->currentPhase;

    $service->perform($company->id, $id, CrewMovementAction::CompleteTraining, [
        'occurred_at' => '2026-03-05 12:00:00',
        'next_phase' => 'p2a',
    ], $user->id);

    expect(EmployeeTraining::query()->where('source_crew_assignment_phase_id', $trainingPhase->id)->exists())->toBeTrue();

    // Attempt to correct details.course using free text without details.course_id
    $requester = app(RequestCrewMovementCorrection::class);

    expect(fn () => $requester->handle(
        $assignment->fresh(),
        $trainingPhase->fresh(),
        $user,
        [
            'details.course' => 'Mismatched Free Text Course',
        ],
        'Trying free-text course change',
    ))->toThrow(CrewMovementException::class, 'Course cannot be changed via free text on a training phase linked to Employee Training.');
});
