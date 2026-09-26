<?php

use App\Enums\CrewTimesheetPreparationStatus;
use App\Models\CrewTimesheet;
use App\Models\User;
use App\Support\Payroll\CrewTimeline\Actions\ApplyCrewTimesheetPreparation;
use App\Support\Payroll\CrewTimeline\Actions\ApproveCrewTimesheetPreparation;
use App\Support\Payroll\CrewTimeline\Actions\ReturnCrewTimesheetPreparation;
use App\Support\Payroll\CrewTimeline\Actions\SubmitCrewTimesheetPreparation;
use Illuminate\Validation\ValidationException;

/**
 * Retired Crew Timesheet preparation review / skip HTTP endpoints.
 */
test('retired crew timeline review and skip routes are unavailable', function () {
    $f = setupCrewTimelineHardeningFixtures();

    $this->actingAs($f['user'])
        ->withSession(['current_company_id' => $f['company']->id])
        ->get("/payroll/{$f['period']->id}/crew-timeline/{$f['preparation']->id}")
        ->assertNotFound();

    $this->actingAs($f['user'])
        ->withSession(['current_company_id' => $f['company']->id])
        ->post("/payroll/{$f['period']->id}/crew-timeline/{$f['preparation']->id}/employees/{$f['office']->id}/skip", [
            'reason' => 'Should be unavailable',
        ])
        ->assertNotFound();

    $this->actingAs($f['user'])
        ->withSession(['current_company_id' => $f['company']->id])
        ->delete("/payroll/{$f['period']->id}/crew-timeline/{$f['preparation']->id}/employees/{$f['office']->id}/skip")
        ->assertNotFound();
});

test('restricted user can submit approve return and apply marine-only preparation via Support', function () {
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
        'payroll.crew_timesheets.create',
        'payroll.crew_timesheets.update',
        'payroll.periods.view',
        'payroll.periods.update',
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
        'payroll.crew_timesheets.create',
        'payroll.crew_timesheets.update',
        'payroll.periods.view',
        'payroll.periods.update',
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
