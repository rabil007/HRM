<?php

use App\Enums\CrewPhaseCode;
use App\Enums\CrewTimelineWarningCode;
use App\Enums\CrewTimesheetPayCategory;
use App\Enums\CrewTimesheetPreparationStatus;
use App\Enums\PayrollPeriodStatus;
use App\Models\Company;
use App\Models\CrewTimesheet;
use App\Models\CrewTimesheetPreparation;
use App\Models\CrewTimesheetPreparationLine;
use App\Models\PayrollPeriod;
use App\Models\User;
use App\Support\Payroll\CrewTimeline\Actions\ApproveCrewTimesheetPreparation;
use App\Support\Payroll\CrewTimeline\PrepareCrewTimesheetTimeline;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

function prepareFreshTimeline(array $fixtures, ?User $preparedBy = null): CrewTimesheetPreparation
{
    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::JoinStandby, 1, '2026-07-01 08:00:00', '2026-07-03 18:00:00');
    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::OnVessel, 2, '2026-07-04 08:00:00', '2026-07-20 18:00:00');

    return app(PrepareCrewTimesheetTimeline::class)->handle(
        $fixtures['period'],
        (int) $fixtures['company']->id,
        (int) ($preparedBy ?? $fixtures['user'])->id,
    );
}

function grantTimelinePermissions(User $user, Company $company, array $extra = []): void
{
    grantCompanyPermissions($user, $company, array_values(array_unique(array_merge([
        'payroll.crew_timesheets.view',
        'payroll.crew_timesheets.prepare',
        'payroll.crew_timesheets.submit',
        'payroll.crew_timesheets.approve',
        'payroll.crew_timesheets.return',
    ], $extra))));
}

test('authorized user can view preparation review page', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    grantTimelinePermissions($fixtures['user'], $fixtures['company']);
    $preparation = prepareFreshTimeline($fixtures);

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->get(route('payroll.crew-timeline.show', [$fixtures['period'], $preparation]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('payroll/crew-timeline/show')
            ->where('preparation.id', $preparation->id)
            ->where('preparation.version', 1)
            ->where('preparation.status', 'draft')
            ->where('preparation.is_fresh', true)
            ->where('summary.total_employees', 1)
            ->has('employees.0.lines'));
});

test('unauthorized user cannot view preparation', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    $preparation = prepareFreshTimeline($fixtures);

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->get(route('payroll.crew-timeline.show', [$fixtures['period'], $preparation]))
        ->assertForbidden();
});

test('cross company preparation returns 404', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    grantTimelinePermissions($fixtures['user'], $fixtures['company']);
    $preparation = prepareFreshTimeline($fixtures);

    $other = makeDailyCrewTimelineFixtures();
    grantTimelinePermissions($fixtures['user'], $other['company']);

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $other['company']->id])
        ->get(route('payroll.crew-timeline.show', [$other['period'], $preparation]))
        ->assertNotFound();
});

test('preparation belonging to another payroll period returns 404', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    grantTimelinePermissions($fixtures['user'], $fixtures['company']);
    $preparation = prepareFreshTimeline($fixtures);

    $otherPeriod = PayrollPeriod::factory()->for($fixtures['company'])->create([
        'status' => PayrollPeriodStatus::Draft,
        'payroll_category' => $fixtures['period']->payroll_category,
        'start_date' => '2026-08-01',
        'end_date' => '2026-08-31',
        'payment_date' => '2026-08-31',
    ]);

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->get(route('payroll.crew-timeline.show', [$otherPeriod, $preparation]))
        ->assertNotFound();
});

test('employee totals exclude warning only rows', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    grantTimelinePermissions($fixtures['user'], $fixtures['company']);
    $preparation = prepareFreshTimeline($fixtures);

    CrewTimesheetPreparationLine::factory()->forPreparation($preparation)->create([
        'employee_id' => $fixtures['employee']->id,
        'crew_assignment_id' => $fixtures['assignment']->id,
        'pay_category' => CrewTimesheetPayCategory::Excluded,
        'days' => 0,
        'warning_code' => CrewTimelineWarningCode::TimelineGap->value,
        'from_date' => '2026-07-21',
        'to_date' => '2026-07-21',
    ]);

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->get(route('payroll.crew-timeline.show', [$fixtures['period'], $preparation]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('employees.0.informational_warning_count', 1)
            ->where('employees.0.total_payable_days', fn ($days) => (float) $days > 0));
});

test('stale state is reported on review page', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    grantTimelinePermissions($fixtures['user'], $fixtures['company']);
    $preparation = prepareFreshTimeline($fixtures);

    $fixtures['assignment']->phases()
        ->where('phase_code', CrewPhaseCode::OnVessel)
        ->firstOrFail()
        ->update(['actual_end_at' => '2026-07-15 18:00:00']);

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->get(route('payroll.crew-timeline.show', [$fixtures['period'], $preparation]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('preparation.is_stale', true)
            ->where('preparation.is_fresh', false));
});

test('latest fresh draft can be submitted', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    grantTimelinePermissions($fixtures['user'], $fixtures['company']);
    $preparation = prepareFreshTimeline($fixtures);

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('payroll.crew-timeline.submit', [$fixtures['period'], $preparation]))
        ->assertRedirect(route('payroll.crew-timeline.show', [$fixtures['period'], $preparation]));

    $preparation->refresh();

    expect($preparation->status)->toBe(CrewTimesheetPreparationStatus::Submitted)
        ->and($preparation->submitted_by)->toBe($fixtures['user']->id)
        ->and($preparation->submitted_at)->not->toBeNull()
        ->and(CrewTimesheet::query()->count())->toBe(0);

    expect(Activity::query()->where('event', 'updated')->orWhere('description', 'Crew timesheet preparation submitted')->exists())->toBeTrue();
});

test('older draft version cannot be submitted', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    grantTimelinePermissions($fixtures['user'], $fixtures['company']);
    $v1 = prepareFreshTimeline($fixtures);
    $v2 = app(PrepareCrewTimesheetTimeline::class)->handle(
        $fixtures['period'],
        (int) $fixtures['company']->id,
        (int) $fixtures['user']->id,
    );

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('payroll.crew-timeline.submit', [$fixtures['period'], $v1]))
        ->assertSessionHasErrors('preparation');

    expect($v1->fresh()->status)->toBe(CrewTimesheetPreparationStatus::Draft)
        ->and($v2->fresh()->status)->toBe(CrewTimesheetPreparationStatus::Draft);
});

test('stale preparation cannot be submitted', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    grantTimelinePermissions($fixtures['user'], $fixtures['company']);
    $preparation = prepareFreshTimeline($fixtures);
    $fixtures['assignment']->phases()
        ->where('phase_code', CrewPhaseCode::OnVessel)
        ->firstOrFail()
        ->update(['actual_end_at' => '2026-07-15 18:00:00']);

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('payroll.crew-timeline.submit', [$fixtures['period'], $preparation]))
        ->assertSessionHasErrors('preparation');

    expect($preparation->fresh()->status)->toBe(CrewTimesheetPreparationStatus::Draft)
        ->and($preparation->fresh()->source_hash)->toBe($preparation->source_hash);
});

test('blocking warnings prevent submission', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    grantTimelinePermissions($fixtures['user'], $fixtures['company']);
    $preparation = prepareFreshTimeline($fixtures);

    CrewTimesheetPreparationLine::factory()->forPreparation($preparation)->create([
        'employee_id' => $fixtures['employee']->id,
        'crew_assignment_id' => $fixtures['assignment']->id,
        'pay_category' => CrewTimesheetPayCategory::Excluded,
        'days' => 0,
        'warning_code' => CrewTimelineWarningCode::OverlappingPhases->value,
        'from_date' => '2026-07-10',
        'to_date' => '2026-07-10',
    ]);

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('payroll.crew-timeline.submit', [$fixtures['period'], $preparation]))
        ->assertSessionHasErrors('preparation');
});

test('informational warnings allow submission', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    grantTimelinePermissions($fixtures['user'], $fixtures['company']);
    $preparation = prepareFreshTimeline($fixtures);

    CrewTimesheetPreparationLine::factory()->forPreparation($preparation)->create([
        'employee_id' => $fixtures['employee']->id,
        'crew_assignment_id' => $fixtures['assignment']->id,
        'pay_category' => CrewTimesheetPayCategory::Excluded,
        'days' => 0,
        'warning_code' => CrewTimelineWarningCode::TravelInExcluded->value,
        'from_date' => '2026-07-01',
        'to_date' => '2026-07-01',
    ]);

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('payroll.crew-timeline.submit', [$fixtures['period'], $preparation]))
        ->assertRedirect();

    expect($preparation->fresh()->status)->toBe(CrewTimesheetPreparationStatus::Submitted);
});

test('non draft preparation cannot be submitted', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    grantTimelinePermissions($fixtures['user'], $fixtures['company']);
    $preparation = prepareFreshTimeline($fixtures);
    $preparation->update([
        'status' => CrewTimesheetPreparationStatus::Returned,
        'returned_by' => $fixtures['user']->id,
        'returned_at' => now(),
        'decision_notes' => 'fix dates',
    ]);

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('payroll.crew-timeline.submit', [$fixtures['period'], $preparation]))
        ->assertSessionHasErrors('preparation');
});

test('non draft payroll period prevents submission', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    grantTimelinePermissions($fixtures['user'], $fixtures['company']);
    $preparation = prepareFreshTimeline($fixtures);
    $fixtures['period']->update(['status' => PayrollPeriodStatus::Processing]);

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('payroll.crew-timeline.submit', [$fixtures['period'], $preparation]))
        ->assertSessionHasErrors('payroll_period_id');
});

test('duplicate submitted preparation is prevented', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    grantTimelinePermissions($fixtures['user'], $fixtures['company']);
    $first = prepareFreshTimeline($fixtures);
    $first->update([
        'status' => CrewTimesheetPreparationStatus::Submitted,
        'submitted_by' => $fixtures['user']->id,
        'submitted_at' => now(),
    ]);

    $second = app(PrepareCrewTimesheetTimeline::class)->handle(
        $fixtures['period'],
        (int) $fixtures['company']->id,
        (int) $fixtures['user']->id,
    );

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('payroll.crew-timeline.submit', [$fixtures['period'], $second]))
        ->assertSessionHasErrors('preparation');
});

test('submit permission denial', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    grantCompanyPermissions($fixtures['user'], $fixtures['company'], ['payroll.crew_timesheets.view']);
    $preparation = prepareFreshTimeline($fixtures);

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('payroll.crew-timeline.submit', [$fixtures['period'], $preparation]))
        ->assertForbidden();
});

test('submitted preparation can be returned with notes', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    $approver = User::factory()->create();
    grantTimelinePermissions($fixtures['user'], $fixtures['company']);
    grantTimelinePermissions($approver, $fixtures['company']);
    $preparation = prepareFreshTimeline($fixtures);
    $preparation->update([
        'status' => CrewTimesheetPreparationStatus::Submitted,
        'submitted_by' => $fixtures['user']->id,
        'submitted_at' => now(),
    ]);

    $this->actingAs($approver)
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('payroll.crew-timeline.return', [$fixtures['period'], $preparation]), [
            'decision_notes' => 'Please correct demob standby dates.',
        ])
        ->assertRedirect();

    $preparation->refresh();

    expect($preparation->status)->toBe(CrewTimesheetPreparationStatus::Returned)
        ->and($preparation->returned_by)->toBe($approver->id)
        ->and($preparation->returned_at)->not->toBeNull()
        ->and($preparation->decision_notes)->toBe('Please correct demob standby dates.')
        ->and(CrewTimesheet::query()->count())->toBe(0);
});

test('return notes are required', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    grantTimelinePermissions($fixtures['user'], $fixtures['company']);
    $preparation = prepareFreshTimeline($fixtures);
    $preparation->update([
        'status' => CrewTimesheetPreparationStatus::Submitted,
        'submitted_by' => $fixtures['user']->id,
        'submitted_at' => now(),
    ]);

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('payroll.crew-timeline.return', [$fixtures['period'], $preparation]), [
            'decision_notes' => '',
        ])
        ->assertSessionHasErrors('decision_notes');
});

test('draft approved and returned preparations cannot be returned', function (CrewTimesheetPreparationStatus $status) {
    $fixtures = makeDailyCrewTimelineFixtures();
    grantTimelinePermissions($fixtures['user'], $fixtures['company']);
    $preparation = prepareFreshTimeline($fixtures);
    $preparation->update(['status' => $status]);

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('payroll.crew-timeline.return', [$fixtures['period'], $preparation]), [
            'decision_notes' => 'Not allowed',
        ])
        ->assertSessionHasErrors('preparation');
})->with([
    CrewTimesheetPreparationStatus::Draft,
    CrewTimesheetPreparationStatus::Approved,
    CrewTimesheetPreparationStatus::Returned,
]);

test('fresh submitted preparation can be approved by different user', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    $approver = User::factory()->create();
    grantTimelinePermissions($fixtures['user'], $fixtures['company']);
    grantTimelinePermissions($approver, $fixtures['company']);
    $preparation = prepareFreshTimeline($fixtures);
    $preparation->update([
        'status' => CrewTimesheetPreparationStatus::Submitted,
        'submitted_by' => $fixtures['user']->id,
        'submitted_at' => now(),
    ]);

    $this->actingAs($approver)
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('payroll.crew-timeline.approve', [$fixtures['period'], $preparation]), [
            'decision_notes' => 'Looks good',
        ])
        ->assertRedirect();

    $preparation->refresh();

    expect($preparation->status)->toBe(CrewTimesheetPreparationStatus::Approved)
        ->and($preparation->approved_by)->toBe($approver->id)
        ->and($preparation->approved_at)->not->toBeNull()
        ->and($preparation->decision_notes)->toBe('Looks good')
        ->and(CrewTimesheet::query()->count())->toBe(0);
});

test('prepared by cannot approve', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    grantTimelinePermissions($fixtures['user'], $fixtures['company']);
    $preparation = prepareFreshTimeline($fixtures);
    $preparation->update([
        'status' => CrewTimesheetPreparationStatus::Submitted,
        'submitted_by' => User::factory()->create()->id,
        'submitted_at' => now(),
    ]);

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('payroll.crew-timeline.approve', [$fixtures['period'], $preparation]))
        ->assertSessionHasErrors('preparation');
});

test('submitted by cannot approve', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    $preparer = User::factory()->create();
    $submitter = User::factory()->create();
    grantTimelinePermissions($preparer, $fixtures['company']);
    grantTimelinePermissions($submitter, $fixtures['company']);
    $preparation = prepareFreshTimeline($fixtures, $preparer);
    $preparation->update([
        'status' => CrewTimesheetPreparationStatus::Submitted,
        'submitted_by' => $submitter->id,
        'submitted_at' => now(),
    ]);

    $this->actingAs($submitter)
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('payroll.crew-timeline.approve', [$fixtures['period'], $preparation]))
        ->assertSessionHasErrors('preparation');
});

test('stale preparation cannot be approved', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    $approver = User::factory()->create();
    grantTimelinePermissions($fixtures['user'], $fixtures['company']);
    grantTimelinePermissions($approver, $fixtures['company']);
    $preparation = prepareFreshTimeline($fixtures);
    $preparation->update([
        'status' => CrewTimesheetPreparationStatus::Submitted,
        'submitted_by' => $fixtures['user']->id,
        'submitted_at' => now(),
    ]);
    $fixtures['assignment']->phases()
        ->where('phase_code', CrewPhaseCode::OnVessel)
        ->firstOrFail()
        ->update(['actual_end_at' => '2026-07-15 18:00:00']);

    $this->actingAs($approver)
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('payroll.crew-timeline.approve', [$fixtures['period'], $preparation]))
        ->assertSessionHasErrors('preparation');
});

test('blocking warnings prevent approval', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    $approver = User::factory()->create();
    grantTimelinePermissions($fixtures['user'], $fixtures['company']);
    grantTimelinePermissions($approver, $fixtures['company']);
    $preparation = prepareFreshTimeline($fixtures);
    $preparation->update([
        'status' => CrewTimesheetPreparationStatus::Submitted,
        'submitted_by' => $fixtures['user']->id,
        'submitted_at' => now(),
    ]);
    CrewTimesheetPreparationLine::factory()->forPreparation($preparation)->create([
        'employee_id' => $fixtures['employee']->id,
        'crew_assignment_id' => $fixtures['assignment']->id,
        'pay_category' => CrewTimesheetPayCategory::Excluded,
        'days' => 0,
        'warning_code' => CrewTimelineWarningCode::NoActiveCrewContract->value,
        'from_date' => '2026-07-01',
        'to_date' => '2026-07-01',
    ]);

    $this->actingAs($approver)
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('payroll.crew-timeline.approve', [$fixtures['period'], $preparation]))
        ->assertSessionHasErrors('preparation');
});

test('previous approved version becomes superseded', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    $approver = User::factory()->create();
    grantTimelinePermissions($fixtures['user'], $fixtures['company']);
    grantTimelinePermissions($approver, $fixtures['company']);

    $previous = prepareFreshTimeline($fixtures);
    $previous->update([
        'status' => CrewTimesheetPreparationStatus::Approved,
        'approved_by' => $approver->id,
        'approved_at' => now(),
    ]);

    $current = app(PrepareCrewTimesheetTimeline::class)->handle(
        $fixtures['period'],
        (int) $fixtures['company']->id,
        (int) $fixtures['user']->id,
    );
    $current->update([
        'status' => CrewTimesheetPreparationStatus::Submitted,
        'submitted_by' => $fixtures['user']->id,
        'submitted_at' => now(),
    ]);

    $this->actingAs($approver)
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('payroll.crew-timeline.approve', [$fixtures['period'], $current]))
        ->assertRedirect();

    expect($previous->fresh()->status)->toBe(CrewTimesheetPreparationStatus::Superseded)
        ->and($current->fresh()->status)->toBe(CrewTimesheetPreparationStatus::Approved)
        ->and(CrewTimesheetPreparation::query()
            ->where('payroll_period_id', $fixtures['period']->id)
            ->where('status', CrewTimesheetPreparationStatus::Approved)
            ->count())->toBe(1);
});

test('applied version prevents replacement approval', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    $approver = User::factory()->create();
    grantTimelinePermissions($fixtures['user'], $fixtures['company']);
    grantTimelinePermissions($approver, $fixtures['company']);

    $applied = prepareFreshTimeline($fixtures);
    $applied->update([
        'status' => CrewTimesheetPreparationStatus::Applied,
        'applied_by' => $approver->id,
        'applied_at' => now(),
    ]);

    $current = app(PrepareCrewTimesheetTimeline::class)->handle(
        $fixtures['period'],
        (int) $fixtures['company']->id,
        (int) $fixtures['user']->id,
    );
    $current->update([
        'status' => CrewTimesheetPreparationStatus::Submitted,
        'submitted_by' => $fixtures['user']->id,
        'submitted_at' => now(),
    ]);

    $this->actingAs($approver)
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('payroll.crew-timeline.approve', [$fixtures['period'], $current]))
        ->assertSessionHasErrors('preparation');

    expect($applied->fresh()->status)->toBe(CrewTimesheetPreparationStatus::Applied)
        ->and($current->fresh()->status)->toBe(CrewTimesheetPreparationStatus::Submitted);
});

test('approve permission denial', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    grantCompanyPermissions($fixtures['user'], $fixtures['company'], ['payroll.crew_timesheets.view']);
    $preparation = prepareFreshTimeline($fixtures);
    $preparation->update([
        'status' => CrewTimesheetPreparationStatus::Submitted,
        'submitted_by' => User::factory()->create()->id,
        'submitted_at' => now(),
    ]);

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('payroll.crew-timeline.approve', [$fixtures['period'], $preparation]))
        ->assertForbidden();
});

test('simultaneous submissions cannot create two submitted versions', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    grantTimelinePermissions($fixtures['user'], $fixtures['company']);
    $first = prepareFreshTimeline($fixtures);
    $second = app(PrepareCrewTimesheetTimeline::class)->handle(
        $fixtures['period'],
        (int) $fixtures['company']->id,
        (int) $fixtures['user']->id,
    );

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('payroll.crew-timeline.submit', [$fixtures['period'], $second]))
        ->assertRedirect();

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('payroll.crew-timeline.submit', [$fixtures['period'], $first]))
        ->assertSessionHasErrors('preparation');

    expect(CrewTimesheetPreparation::query()
        ->where('payroll_period_id', $fixtures['period']->id)
        ->where('status', CrewTimesheetPreparationStatus::Submitted)
        ->count())->toBe(1);
});

test('simultaneous approvals cannot leave two approved versions', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    $approver = User::factory()->create();
    grantTimelinePermissions($fixtures['user'], $fixtures['company']);
    grantTimelinePermissions($approver, $fixtures['company']);

    $first = prepareFreshTimeline($fixtures);
    $first->update([
        'status' => CrewTimesheetPreparationStatus::Approved,
        'approved_by' => $approver->id,
        'approved_at' => now(),
    ]);

    $second = app(PrepareCrewTimesheetTimeline::class)->handle(
        $fixtures['period'],
        (int) $fixtures['company']->id,
        (int) $fixtures['user']->id,
    );
    $second->update([
        'status' => CrewTimesheetPreparationStatus::Submitted,
        'submitted_by' => $fixtures['user']->id,
        'submitted_at' => now(),
    ]);

    DB::transaction(function () use ($fixtures, $approver, $second): void {
        app(ApproveCrewTimesheetPreparation::class)->handle(
            $fixtures['period'],
            $second,
            $approver,
            (int) $fixtures['company']->id,
        );
    });

    expect(CrewTimesheetPreparation::query()
        ->where('payroll_period_id', $fixtures['period']->id)
        ->where('status', CrewTimesheetPreparationStatus::Approved)
        ->count())->toBe(1)
        ->and($first->fresh()->status)->toBe(CrewTimesheetPreparationStatus::Superseded);
});

test('prepare redirects to review page', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    grantTimelinePermissions($fixtures['user'], $fixtures['company']);
    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::OnVessel, 1, '2026-07-01 08:00:00', '2026-07-10 18:00:00');

    $response = $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('payroll.crew-timeline.prepare', $fixtures['period']));

    $preparation = CrewTimesheetPreparation::query()
        ->where('payroll_period_id', $fixtures['period']->id)
        ->firstOrFail();

    $response->assertRedirect(route('payroll.crew-timeline.show', [$fixtures['period'], $preparation]));
});
