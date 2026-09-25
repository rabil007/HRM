<?php

use App\Enums\CrewPhaseCode;
use App\Enums\CrewTimesheetPayCategory;
use App\Enums\CrewTimesheetPreparationStatus;
use App\Enums\CrewTimesheetSource;
use App\Enums\PayrollCategory;
use App\Enums\PayrollPeriodStatus;
use App\Models\CrewTimesheet;
use App\Models\CrewTimesheetSegment;
use App\Models\PayrollPeriod;
use App\Models\Permission;
use App\Models\User;
use App\Support\Authorization\ApplicationPermissionRegistry;
use Database\Seeders\PermissionsSeeder;
use Illuminate\Support\Facades\Artisan;
use Inertia\Testing\AssertableInertia as Assert;

test('retired crew timesheet approval permissions are not in the catalog or roles ui', function () {
    Artisan::call('db:seed', ['--class' => PermissionsSeeder::class]);

    $retired = [
        'payroll.crew_timesheets.submit',
        'payroll.crew_timesheets.approve',
        'payroll.crew_timesheets.return',
        'payroll.crew_timesheets.apply_approved',
        'payroll.crew_timesheets.skip_timeline',
    ];

    foreach ($retired as $name) {
        expect(ApplicationPermissionRegistry::find($name))->toBeNull()
            ->and(Permission::query()->where('guard_name', 'web')->where('name', $name)->exists())->toBeFalse();
    }

    $modulePermissions = Permission::query()
        ->where('guard_name', 'web')
        ->where('name', 'like', 'payroll.crew_timesheets.%')
        ->orderBy('name')
        ->pluck('name')
        ->all();

    expect($modulePermissions)->toBe([
        'payroll.crew_timesheets.clear',
        'payroll.crew_timesheets.create',
        'payroll.crew_timesheets.import',
        'payroll.crew_timesheets.prepare',
        'payroll.crew_timesheets.update',
        'payroll.crew_timesheets.view',
    ]);
});

test('retired crew timesheet approval routes are unavailable', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    ['user' => $user, 'company' => $company, 'period' => $period] = $fixtures;

    grantCompanyPermissions($user, $company, [
        'payroll.crew_timesheets.view',
        'payroll.crew_timesheets.prepare',
        'payroll.crew_timesheets.update',
    ]);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->get("/payroll/{$period->id}/crew-timeline/1")
        ->assertNotFound();

    foreach ([
        "/payroll/{$period->id}/crew-timeline/1/submit",
        "/payroll/{$period->id}/crew-timeline/1/approve",
        "/payroll/{$period->id}/crew-timeline/1/return",
        "/payroll/{$period->id}/crew-timeline/1/apply",
    ] as $path) {
        $this->actingAs($user)
            ->withSession(['current_company_id' => $company->id])
            ->post($path)
            ->assertNotFound();
    }
});

test('crew draft period show page exposes editable crew timesheet workspace without approval actions', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    ['user' => $user, 'company' => $company, 'period' => $period, 'assignment' => $assignment] = $fixtures;

    addTimelinePhase($assignment, CrewPhaseCode::OnVessel, 1, '2026-07-01 08:00:00', '2026-07-15 18:00:00');

    grantCompanyPermissions($user, $company, [
        'payroll.periods.view',
        'payroll.crew_timesheets.view',
        'payroll.crew_timesheets.create',
        'payroll.crew_timesheets.update',
        'payroll.crew_timesheets.prepare',
    ]);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->get("/payroll/{$period->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('payroll/show')
            ->where('period.payroll_category', PayrollCategory::Crew->value)
            ->where('period.status', PayrollPeriodStatus::Draft->value)
            ->where('permissions.prepare_timeline', true)
            ->where('permissions.update', true)
            ->where('crew_timeline_preparation', null)
            ->missing('permissions.submit_timesheet')
            ->missing('permissions.approve_timesheet')
            ->missing('permissions.return_timesheet')
            ->missing('permissions.apply_approved')
        );
});

test('office payroll does not expose crew timesheet prepare workflow', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    ['user' => $user, 'company' => $company] = $fixtures;

    $officePeriod = PayrollPeriod::query()->create([
        'company_id' => $company->id,
        'name' => 'July 2026 - Office',
        'payroll_category' => PayrollCategory::Office,
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-31',
        'status' => PayrollPeriodStatus::Draft,
        'created_by' => $user->id,
    ]);

    grantCompanyPermissions($user, $company, [
        'payroll.periods.view',
        'payroll.crew_timesheets.view',
        'payroll.crew_timesheets.prepare',
    ]);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->get("/payroll/{$officePeriod->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('payroll/show')
            ->where('period.payroll_category', PayrollCategory::Office->value)
            ->where('period.uses_crew_operations_timesheets', false)
            ->where('crew_timeline_preparation', null)
        );
});

test('prepare from crew assignments populates timesheets and redirects to payroll show', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'period' => $period, 'assignment' => $assignment] = $fixtures;

    addTimelinePhase($assignment, CrewPhaseCode::OnVessel, 1, '2026-07-01 08:00:00', '2026-07-20 18:00:00');

    grantCompanyPermissions($user, $company, [
        'payroll.periods.view',
        'payroll.crew_timesheets.prepare',
        'payroll.crew_timesheets.view',
        'payroll.crew_timesheets.update',
    ]);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post("/payroll/{$period->id}/crew-timeline/prepare")
        ->assertRedirect(route('payroll.show', $period));

    $timesheet = CrewTimesheet::query()
        ->where('company_id', $company->id)
        ->where('period_id', $period->id)
        ->where('employee_id', $employee->id)
        ->with('segments')
        ->first();

    expect($timesheet)->not->toBeNull()
        ->and($timesheet->source)->toBe(CrewTimesheetSource::CrewOperations)
        ->and($timesheet->isOperationallyLocked())->toBeFalse()
        ->and($timesheet->segments)->not->toBeEmpty();

    $preparation = $timesheet->preparation;
    expect($preparation)->not->toBeNull()
        ->and($preparation->status)->toBe(CrewTimesheetPreparationStatus::Applied);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->get(route('payroll.show', $period))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('payroll/show')
            ->where('permissions.prepare_timeline', true)
            ->where('crew_timeline_preparation.status', CrewTimesheetPreparationStatus::Applied->value)
            ->where('crew_timeline_preparation.linked_timesheet_count', fn ($count) => (int) $count >= 1)
            ->missing('permissions.submit_timesheet')
            ->missing('permissions.approve_timesheet')
            ->missing('permissions.return_timesheet')
        );
});

test('authorized user can edit onsite standby overtime and remarks without mutating crew assignment', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'period' => $period, 'assignment' => $assignment] = $fixtures;

    $phase = addTimelinePhase($assignment, CrewPhaseCode::OnVessel, 1, '2026-07-01 08:00:00', '2026-07-26 18:00:00');

    grantCompanyPermissions($user, $company, [
        'payroll.crew_timesheets.prepare',
        'payroll.crew_timesheets.update',
        'payroll.crew_timesheets.create',
        'payroll.crew_timesheets.view',
    ]);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post("/payroll/{$period->id}/crew-timeline/prepare")
        ->assertRedirect();

    $timesheet = CrewTimesheet::query()
        ->where('company_id', $company->id)
        ->where('period_id', $period->id)
        ->where('employee_id', $employee->id)
        ->firstOrFail();

    $phaseBefore = $phase->fresh();
    $assignmentBefore = $assignment->fresh();

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->put("/payroll/{$period->id}/timesheets/{$timesheet->id}/segments", [
            'segments' => [
                [
                    'pay_category' => CrewTimesheetPayCategory::SignOnStandby->value,
                    'from_date' => '2026-07-01',
                    'to_date' => '2026-07-03',
                    'remarks' => null,
                ],
                [
                    'pay_category' => CrewTimesheetPayCategory::Onsite->value,
                    'from_date' => '2026-07-04',
                    'to_date' => '2026-07-25',
                    'remarks' => null,
                ],
                [
                    'pay_category' => CrewTimesheetPayCategory::SignOffStandby->value,
                    'from_date' => '2026-07-26',
                    'to_date' => '2026-07-28',
                    'remarks' => null,
                ],
            ],
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->patch("/payroll/{$period->id}/timesheets/{$timesheet->id}/financials", [
            'overtime_hours' => 12.5,
            'remarks' => 'Payroll correction through 25th',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $fresh = $timesheet->fresh(['segments']);
    expect((float) $fresh->overtime_hours)->toBe(12.5)
        ->and($fresh->remarks)->toBe('Payroll correction through 25th')
        ->and($fresh->segments)->toHaveCount(3)
        ->and($phase->fresh()->actual_start_at?->toIso8601String())->toBe($phaseBefore->actual_start_at?->toIso8601String())
        ->and($phase->fresh()->actual_end_at?->toIso8601String())->toBe($phaseBefore->actual_end_at?->toIso8601String())
        ->and($assignment->fresh()->only(['started_at', 'closed_at', 'status', 'current_phase_id']))
        ->toBe($assignmentBefore->only(['started_at', 'closed_at', 'status', 'current_phase_id']));
});

test('unauthorized user cannot update crew timesheet segments', function () {
    $fixtures = makeDailyCrewTimelineFixtures(withWorkflowPermissions: false);
    ['company' => $company, 'employee' => $employee, 'period' => $period] = $fixtures;

    $timesheet = CrewTimesheet::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'period_id' => $period->id,
        'source' => CrewTimesheetSource::Manual,
    ]);

    $viewer = User::factory()->create();
    grantCompanyPermissions($viewer, $company, ['payroll.crew_timesheets.view']);

    $this->actingAs($viewer)
        ->withSession(['current_company_id' => $company->id])
        ->put("/payroll/{$period->id}/timesheets/{$timesheet->id}/segments", [
            'segments' => [
                [
                    'pay_category' => CrewTimesheetPayCategory::Onsite->value,
                    'from_date' => '2026-07-01',
                    'to_date' => '2026-07-10',
                    'days' => 10,
                ],
            ],
        ])
        ->assertForbidden();
});

test('cross-company timesheet ids are rejected', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    ['user' => $user, 'company' => $company, 'period' => $period] = $fixtures;

    $other = makeDailyCrewTimelineFixtures();
    $otherTimesheet = CrewTimesheet::factory()->create([
        'company_id' => $other['company']->id,
        'employee_id' => $other['employee']->id,
        'period_id' => $other['period']->id,
        'source' => CrewTimesheetSource::Manual,
    ]);

    grantCompanyPermissions($user, $company, [
        'payroll.crew_timesheets.update',
        'payroll.crew_timesheets.create',
    ]);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->put("/payroll/{$period->id}/timesheets/{$otherTimesheet->id}/segments", [
            'segments' => [
                [
                    'pay_category' => CrewTimesheetPayCategory::Onsite->value,
                    'from_date' => '2026-07-01',
                    'to_date' => '2026-07-05',
                    'days' => 5,
                ],
            ],
        ])
        ->assertNotFound();
});

test('approved payroll periods cannot be operationally edited', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'period' => $period] = $fixtures;

    $period->update(['status' => PayrollPeriodStatus::Approved]);

    $timesheet = CrewTimesheet::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'period_id' => $period->id,
        'source' => CrewTimesheetSource::Manual,
        'onsite_from' => '2026-07-01',
        'onsite_to' => '2026-07-10',
        'onsite_days' => 10,
    ]);

    CrewTimesheetSegment::query()->create([
        'company_id' => $company->id,
        'crew_timesheet_id' => $timesheet->id,
        'sequence' => 1,
        'pay_category' => CrewTimesheetPayCategory::Onsite,
        'from_date' => '2026-07-01',
        'to_date' => '2026-07-10',
        'days' => 10,
        'source' => CrewTimesheetSource::Manual,
    ]);

    grantCompanyPermissions($user, $company, [
        'payroll.crew_timesheets.update',
        'payroll.crew_timesheets.create',
    ]);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->put("/payroll/{$period->id}/timesheets/{$timesheet->id}/segments", [
            'segments' => [
                [
                    'pay_category' => CrewTimesheetPayCategory::Onsite->value,
                    'from_date' => '2026-07-01',
                    'to_date' => '2026-07-05',
                    'days' => 5,
                ],
            ],
        ])
        ->assertSessionHasErrors('period_id');
});

test('payroll generation does not require crew timesheet submit approve or apply', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'period' => $period, 'assignment' => $assignment] = $fixtures;

    addTimelinePhase($assignment, CrewPhaseCode::OnVessel, 1, '2026-07-01 08:00:00', '2026-07-20 18:00:00');

    grantCompanyPermissions($user, $company, [
        'payroll.crew_timesheets.prepare',
        'payroll.crew_timesheets.view',
        'payroll.crew_timesheets.update',
        'payroll.periods.update',
        'payroll.periods.view',
    ]);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post("/payroll/{$period->id}/crew-timeline/prepare")
        ->assertRedirect();

    expect(
        CrewTimesheet::query()
            ->where('company_id', $company->id)
            ->where('period_id', $period->id)
            ->where('employee_id', $employee->id)
            ->exists()
    )->toBeTrue();

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post("/payroll/{$period->id}/generate", [
            'excluded_employee_ids' => [],
        ])
        ->assertRedirect();

    expect($period->fresh()->status)->toBe(PayrollPeriodStatus::Processing);
});
