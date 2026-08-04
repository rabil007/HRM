<?php

use App\Enums\CrewPhaseCode;
use App\Enums\CrewPhaseStatus;
use App\Models\Company;
use App\Models\Country;
use App\Models\Course;
use App\Models\CrewAssignment;
use App\Models\CrewAssignmentPhase;
use App\Models\Currency;
use App\Models\Employee;
use App\Models\EmployeeTraining;
use App\Models\User;
use App\Support\Activity\ActivityChangePresenter;
use App\Support\Activity\RecentActivityQuery;
use Spatie\Activitylog\Models\Activity;

function makeActivityPresentationFixtures(): array
{
    $user = User::factory()->create();

    $country = Country::query()->create([
        'code' => 'APL',
        'name' => 'Activity Present Land',
        'dial_code' => '+971',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'APL',
        'name' => 'Activity Present Currency',
        'symbol' => 'A$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Activity Present Co',
        'slug' => 'activity-present-co-'.uniqid(),
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $user->forceFill(['company_id' => $company->id])->save();

    $employee = Employee::factory()->forCompany($company)->create([
        'name' => 'Ali Hassan',
        'employee_no' => 'EMP-9001',
        'status' => 'active',
    ]);

    return compact('user', 'company', 'employee', 'country');
}

test('recent activity resolves foreign key ids to human readable labels', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee] = makeActivityPresentationFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['audit.view', 'trainings.view']);

    $course = Course::query()->create([
        'name' => 'STCW Basic Safety',
        'is_active' => true,
    ]);

    $training = EmployeeTraining::factory()->forEmployee($employee)->create([
        'course_id' => $course->id,
        'institute_center' => 'Marine Academy',
    ]);

    $items = RecentActivityQuery::for(
        $user,
        $company->id,
        EmployeeTraining::class,
        $training->id,
    );

    expect($items)->not->toBeEmpty();

    $created = collect($items)->firstWhere('event', 'created');

    expect($created)->not->toBeNull()
        ->and(data_get($created, 'new_values.course_id'))->toBe('STCW Basic Safety')
        ->and(data_get($created, 'new_values.employee_id'))->toBe('Ali Hassan (EMP-9001)');
});

test('activity change presenter resolves old and new course names on update', function () {
    ['company' => $company, 'employee' => $employee] = makeActivityPresentationFixtures();

    $oldCourse = Course::query()->create([
        'name' => 'Basic Firefighting',
        'is_active' => true,
    ]);
    $newCourse = Course::query()->create([
        'name' => 'Advanced Firefighting',
        'is_active' => true,
    ]);

    $training = EmployeeTraining::factory()->forEmployee($employee)->create([
        'course_id' => $oldCourse->id,
    ]);

    $training->update(['course_id' => $newCourse->id]);

    $log = Activity::query()
        ->where('subject_type', EmployeeTraining::class)
        ->where('subject_id', $training->id)
        ->where('event', 'updated')
        ->latest('id')
        ->first();

    expect($log)->not->toBeNull();

    $presented = ActivityChangePresenter::presentLogs(collect([$log]), $company->id)
        ->map(fn (Activity $activity): array => ActivityChangePresenter::toRecentActivityArray($activity))
        ->first();

    expect(data_get($presented, 'old_values.course_id'))->toBe('Basic Firefighting')
        ->and(data_get($presented, 'new_values.course_id'))->toBe('Advanced Firefighting');
});

test('activity logs page shows resolved labels instead of raw ids', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee] = makeActivityPresentationFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['audit.view']);

    $course = Course::query()->create([
        'name' => 'Medical First Aid',
        'is_active' => true,
    ]);

    EmployeeTraining::factory()->forEmployee($employee)->create([
        'course_id' => $course->id,
    ]);

    $this->get(route('organization.activity-logs', [
        'date_from' => now()->toDateString(),
        'date_to' => now()->toDateString(),
    ]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('organization/activity-logs')
            ->has('logs', fn ($logs) => $logs
                ->where('0.new_values.course_id', 'Medical First Aid')
                ->etc()
            )
        );
});

test('crew assignment recent activity resolves phase vessel and assignment ids to labels', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'audit.view',
        'crew_operations.assignments.view',
    ]);

    $sourceVessel = makeCrewMovementVessel('Source Vessel');
    $destinationVessel = makeCrewMovementVessel('Destination Vessel');

    $previous = CrewAssignment::factory()->forEmployee($employee)->create([
        'rank_id' => $rank->id,
        'vessel_id' => $sourceVessel->id,
        'assignment_no' => 'CA-TEST-PREV',
        'status' => 'completed',
        'created_by' => $user->id,
        'updated_by' => $user->id,
    ]);

    $assignment = CrewAssignment::factory()->forEmployee($employee)->create([
        'rank_id' => $rank->id,
        'vessel_id' => $destinationVessel->id,
        'assignment_no' => 'CA-TEST-DEST',
        'status' => 'active',
        'previous_assignment_id' => $previous->id,
        'created_by' => $user->id,
        'updated_by' => $user->id,
    ]);

    $phase = CrewAssignmentPhase::factory()->forAssignment($assignment)->create([
        'phase_code' => CrewPhaseCode::OnVessel,
        'status' => CrewPhaseStatus::Active,
        'sequence' => 1,
        'actual_start_at' => now(),
        'started_by' => $user->id,
    ]);

    $assignment->update(['current_phase_id' => $phase->id]);

    activity()
        ->performedOn($assignment)
        ->causedBy($user)
        ->withProperties([
            'event' => 'crew_vessel_transferred',
            'company_id' => $company->id,
            'employee_id' => $employee->id,
            'source_assignment_id' => $previous->id,
            'destination_assignment_id' => $assignment->id,
            'source_vessel_id' => $sourceVessel->id,
            'destination_vessel_id' => $destinationVessel->id,
            'occurred_at' => now()->toDateTimeString(),
        ])
        ->tap(function ($activity) use ($company): void {
            $activity->company_id = $company->id;
        })
        ->log('Crew vessel transferred');

    $this->withSession(['current_company_id' => $company->id])
        ->get(route('organization.crew-assignments.show', $assignment))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('organization/crew/show')
            ->has('recent_activity')
            ->where('recent_activity.0.description', 'Crew vessel transferred')
            ->where('recent_activity.0.new_values.employee_id', $employee->name.' ('.$employee->employee_no.')')
            ->where('recent_activity.0.new_values.source_assignment_id', 'CA-TEST-PREV')
            ->where('recent_activity.0.new_values.destination_assignment_id', 'CA-TEST-DEST')
            ->where('recent_activity.0.new_values.source_vessel_id', $sourceVessel->name)
            ->where('recent_activity.0.new_values.destination_vessel_id', $destinationVessel->name)
            ->where('recent_activity.1.new_values.current_phase_id', 'P4 On Vessel')
            ->where('recent_activity.2.new_values.previous_assignment_id', 'CA-TEST-PREV')
            ->where('recent_activity.2.new_values.vessel_id', $destinationVessel->name)
        );
});
