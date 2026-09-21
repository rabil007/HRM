<?php

use App\Enums\CrewTimesheetPreparationStatus;
use App\Models\CrewTimesheet;
use App\Models\CrewTimesheetPreparationSkip;
use App\Models\User;
use App\Support\Payroll\CrewTimeline\Actions\ApplyCrewTimesheetPreparation;
use App\Support\Payroll\CrewTimeline\Actions\ApproveCrewTimesheetPreparation;
use App\Support\Payroll\CrewTimeline\Actions\ReturnCrewTimesheetPreparation;
use App\Support\Payroll\CrewTimeline\Actions\SubmitCrewTimesheetPreparation;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

test('restricted user cannot view hidden employees on preparation review page', function () {
    $f = setupCrewTimelineHardeningFixtures();

    $response = $this->actingAs($f['user'])
        ->withSession(['current_company_id' => $f['company']->id])
        ->get(route('payroll.crew-timeline.show', [$f['period'], $f['preparation']]));

    $response->assertOk();
    $response->assertInertia(function (Assert $page) use ($f) {
        $page->component('payroll/crew-timeline/show');

        $employeeIds = collect($page->toArray()['props']['employees'])->pluck('employee_id')->all();
        expect($employeeIds)->toContain($f['marine']->id)
            ->and($employeeIds)->not->toContain($f['office']->id);

        $deptTree = $page->toArray()['props']['department_tree'];
        $deptIds = collect($deptTree)->pluck('id')->filter()->all();
        expect($deptIds)->toContain($f['marineDept']->id)
            ->and($deptIds)->not->toContain($f['officeDept']->id);
    });
});

test('restricted user cannot skip hidden employee in preparation', function () {
    $f = setupCrewTimelineHardeningFixtures();

    $this->actingAs($f['user'])
        ->withSession(['current_company_id' => $f['company']->id])
        ->post(route('payroll.crew-timeline.employee-skip', [$f['period'], $f['preparation'], $f['office']]), [
            'reason' => 'Should be forbidden or not found',
        ])
        ->assertNotFound();
});

test('restricted user cannot restore hidden employee in preparation', function () {
    $f = setupCrewTimelineHardeningFixtures();

    // Create skip for office employee first (as owner/unrestricted)
    CrewTimesheetPreparationSkip::query()->create([
        'company_id' => $f['company']->id,
        'payroll_period_id' => $f['period']->id,
        'crew_timesheet_preparation_id' => $f['preparation']->id,
        'employee_id' => $f['office']->id,
        'skipped_by' => $f['user']->id,
        'skipped_at' => now(),
        'reason' => 'Pre-existing skip',
    ]);

    $this->actingAs($f['user'])
        ->withSession(['current_company_id' => $f['company']->id])
        ->delete(route('payroll.crew-timeline.employee-skip.restore', [$f['period'], $f['preparation'], $f['office']]))
        ->assertNotFound();
});

test('restricted user can submit approve return and apply marine-only preparation', function () {
    $f = setupMarineOnlyCrewTimelineFixtures();

    app(SubmitCrewTimesheetPreparation::class)->handle(
        $f['period'],
        $f['preparation'],
        $f['user'],
        (int) $f['company']->id,
    );

    expect($f['preparation']->fresh()->status)->toBe(CrewTimesheetPreparationStatus::Submitted);

    app(ReturnCrewTimesheetPreparation::class)->handle(
        $f['period'],
        $f['preparation']->fresh(),
        $f['user'],
        (int) $f['company']->id,
        'Needs revision',
    );

    expect($f['preparation']->fresh()->status)->toBe(CrewTimesheetPreparationStatus::Returned);

    $f['preparation']->refresh()->update(['status' => CrewTimesheetPreparationStatus::Submitted]);

    app(ApproveCrewTimesheetPreparation::class)->handle(
        $f['period'],
        $f['preparation']->fresh(),
        $f['user'],
        (int) $f['company']->id,
    );

    expect($f['preparation']->fresh()->status)->toBe(CrewTimesheetPreparationStatus::Approved);

    grantApplyPermissions($f['user'], $f['company']);

    app(ApplyCrewTimesheetPreparation::class)->handle(
        $f['period'],
        $f['preparation']->fresh(),
        $f['user'],
        (int) $f['company']->id,
    );

    expect($f['preparation']->fresh()->status)->toBe(CrewTimesheetPreparationStatus::Applied);
});

test('restricted user cannot submit approve return or apply mixed-scope preparation', function () {
    $f = setupCrewTimelineHardeningFixtures();

    $unrestrictedActor = User::factory()->create();
    grantCompanyPermissions($unrestrictedActor, $f['company'], [
        'payroll.crew_timesheets.view',
        'payroll.crew_timesheets.prepare',
        'payroll.crew_timesheets.submit',
        'payroll.crew_timesheets.approve',
        'payroll.crew_timesheets.return',
        'payroll.crew_timesheets.skip_timeline',
    ], 'unrestricted-role');

    expect(fn () => app(SubmitCrewTimesheetPreparation::class)->handle(
        $f['period'],
        $f['preparation'],
        $f['user'],
        (int) $f['company']->id,
    ))->toThrow(ValidationException::class, 'authorized employee scope');

    app(SubmitCrewTimesheetPreparation::class)->handle(
        $f['period'],
        $f['preparation'],
        $unrestrictedActor,
        (int) $f['company']->id,
    );

    expect(fn () => app(ApproveCrewTimesheetPreparation::class)->handle(
        $f['period'],
        $f['preparation']->fresh(),
        $f['user'],
        (int) $f['company']->id,
    ))->toThrow(ValidationException::class, 'authorized employee scope');

    expect(fn () => app(ReturnCrewTimesheetPreparation::class)->handle(
        $f['period'],
        $f['preparation']->fresh(),
        $f['user'],
        (int) $f['company']->id,
        'Needs revision',
    ))->toThrow(ValidationException::class, 'authorized employee scope');

    $f['preparation']->refresh()->update(['status' => CrewTimesheetPreparationStatus::Approved]);
    grantApplyPermissions($f['user'], $f['company']);

    expect(fn () => app(ApplyCrewTimesheetPreparation::class)->handle(
        $f['period'],
        $f['preparation']->fresh(),
        $f['user'],
        (int) $f['company']->id,
    ))->toThrow(ValidationException::class, 'authorized employee scope');
});

test('unrestricted user can apply mixed-scope preparation', function () {
    $f = setupCrewTimelineHardeningFixtures();

    $unrestrictedActor = User::factory()->create();
    grantCompanyPermissions($unrestrictedActor, $f['company'], [
        'payroll.crew_timesheets.view',
        'payroll.crew_timesheets.prepare',
        'payroll.crew_timesheets.submit',
        'payroll.crew_timesheets.approve',
        'payroll.crew_timesheets.return',
        'payroll.crew_timesheets.skip_timeline',
        'payroll.crew_timesheets.apply_approved',
        'payroll.crew_timesheets.create',
        'payroll.crew_timesheets.update',
    ], 'unrestricted-role');

    app(SubmitCrewTimesheetPreparation::class)->handle(
        $f['period'],
        $f['preparation'],
        $unrestrictedActor,
        (int) $f['company']->id,
    );

    app(ApproveCrewTimesheetPreparation::class)->handle(
        $f['period'],
        $f['preparation']->fresh(),
        $unrestrictedActor,
        (int) $f['company']->id,
    );

    app(ApplyCrewTimesheetPreparation::class)->handle(
        $f['period'],
        $f['preparation']->fresh(),
        $unrestrictedActor,
        (int) $f['company']->id,
    );

    expect($f['preparation']->fresh()->status)->toBe(CrewTimesheetPreparationStatus::Applied)
        ->and(CrewTimesheet::query()->where('period_id', $f['period']->id)->where('employee_id', $f['marine']->id)->exists())->toBeTrue()
        ->and(CrewTimesheet::query()->where('period_id', $f['period']->id)->where('employee_id', $f['office']->id)->exists())->toBeTrue();
});

test('denied apply does not mutate hidden or visible employee timesheets', function () {
    $f = setupCrewTimelineHardeningFixtures();
    $f['preparation']->refresh()->update(['status' => CrewTimesheetPreparationStatus::Approved]);
    grantApplyPermissions($f['user'], $f['company']);

    $visibleTimesheetCount = CrewTimesheet::query()->count();
    $officeTimesheetCount = CrewTimesheet::query()->where('employee_id', $f['office']->id)->count();

    expect(fn () => app(ApplyCrewTimesheetPreparation::class)->handle(
        $f['period'],
        $f['preparation']->fresh(),
        $f['user'],
        (int) $f['company']->id,
    ))->toThrow(ValidationException::class);

    expect($f['preparation']->fresh()->status)->toBe(CrewTimesheetPreparationStatus::Approved)
        ->and(CrewTimesheet::query()->count())->toBe($visibleTimesheetCount)
        ->and(CrewTimesheet::query()->where('employee_id', $f['office']->id)->count())->toBe($officeTimesheetCount);
});
