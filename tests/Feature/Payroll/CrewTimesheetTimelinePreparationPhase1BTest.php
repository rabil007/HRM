<?php

use App\Enums\ContractSalaryStructure;
use App\Enums\CrewAssignmentStatus;
use App\Enums\CrewPhaseCode;
use App\Enums\CrewPhaseStatus;
use App\Enums\CrewTimelineWarningCode;
use App\Enums\CrewTimesheetPayCategory;
use App\Enums\CrewTimesheetPreparationStatus;
use App\Enums\PayrollCategory;
use App\Enums\PayrollPeriodStatus;
use App\Models\CrewAssignment;
use App\Models\CrewAssignmentPhase;
use App\Models\CrewMovementCorrection;
use App\Models\CrewTimesheet;
use App\Models\CrewTimesheetPreparation;
use App\Models\CrewTimesheetPreparationLine;
use App\Models\EmployeeContract;
use App\Models\PayrollPeriod;
use App\Support\Payroll\CrewTimeline\PrepareCrewTimesheetTimeline;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

test('users without prepare permission cannot prepare crew timeline', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    $this->actingAs($fixtures['user']);
    grantCompanyPermissions($fixtures['user'], $fixtures['company'], ['payroll.periods.view']);

    $this->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('payroll.crew-timeline.prepare', $fixtures['period']))
        ->assertForbidden();
});

test('prepare rejects non draft payroll periods', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    $this->actingAs($fixtures['user']);
    grantCompanyPermissions($fixtures['user'], $fixtures['company'], ['payroll.crew_timesheets.prepare']);

    $fixtures['period']->update(['status' => PayrollPeriodStatus::Processing]);

    $this->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('payroll.crew-timeline.prepare', $fixtures['period']))
        ->assertSessionHasErrors('payroll_period_id');
});

test('prepare rejects cutoff date outside the pay period', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    $this->actingAs($fixtures['user']);
    grantCompanyPermissions($fixtures['user'], $fixtures['company'], ['payroll.crew_timesheets.prepare']);

    $this->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('payroll.crew-timeline.prepare', $fixtures['period']), [
            'cutoff_date' => '2026-08-15',
        ])
        ->assertSessionHasErrors('cutoff_date');
});

test('prepare creates draft timeline for normal p2a p3 p4 flow', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    $this->actingAs($fixtures['user']);
    grantCompanyPermissions($fixtures['user'], $fixtures['company'], ['payroll.crew_timesheets.prepare']);

    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::JoinStandby, 1, '2026-07-01 08:00:00', '2026-07-03 18:00:00');
    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::ReadyToJoin, 2, '2026-07-04 08:00:00', '2026-07-05 18:00:00');
    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::OnVessel, 3, '2026-07-06 08:00:00', '2026-07-20 18:00:00');

    $response = $this->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('payroll.crew-timeline.prepare', $fixtures['period']))
        ->assertSessionHas('success');

    $preparation = CrewTimesheetPreparation::query()
        ->where('payroll_period_id', $fixtures['period']->id)
        ->firstOrFail();

    $response->assertRedirect(route('payroll.crew-timeline.show', [
        $fixtures['period'],
        $preparation,
    ]));

    expect($preparation->status)->toBe(CrewTimesheetPreparationStatus::Draft)
        ->and($preparation->version)->toBe(1)
        ->and($preparation->prepared_by)->toBe($fixtures['user']->id)
        ->and($preparation->source_hash)->not->toBeEmpty();

    $payable = CrewTimesheetPreparationLine::query()
        ->where('crew_timesheet_preparation_id', $preparation->id)
        ->whereIn('pay_category', [
            CrewTimesheetPayCategory::SignOnStandby->value,
            CrewTimesheetPayCategory::Onsite->value,
        ])
        ->where('days', '>', 0)
        ->get();

    expect($payable->contains(fn ($line) => $line->pay_category === CrewTimesheetPayCategory::SignOnStandby))->toBeTrue()
        ->and($payable->contains(fn ($line) => $line->pay_category === CrewTimesheetPayCategory::Onsite))->toBeTrue()
        ->and(CrewTimesheet::query()->count())->toBe(0);
});

test('prepare creates sign off standby after p4 disembarkation', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    grantCompanyPermissions($fixtures['user'], $fixtures['company'], ['payroll.crew_timesheets.prepare']);

    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::OnVessel, 1, '2026-07-01 08:00:00', '2026-07-10 18:00:00');
    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::DemobStandby, 2, '2026-07-10 18:00:00', '2026-07-15 18:00:00');

    $preparation = app(PrepareCrewTimesheetTimeline::class)->handle(
        $fixtures['period'],
        (int) $fixtures['company']->id,
        (int) $fixtures['user']->id,
    );

    $onsite = CrewTimesheetPreparationLine::query()
        ->where('crew_timesheet_preparation_id', $preparation->id)
        ->where('pay_category', CrewTimesheetPayCategory::Onsite)
        ->where('days', '>', 0)
        ->firstOrFail();

    $signOff = CrewTimesheetPreparationLine::query()
        ->where('crew_timesheet_preparation_id', $preparation->id)
        ->where('pay_category', CrewTimesheetPayCategory::SignOffStandby)
        ->where('days', '>', 0)
        ->firstOrFail();

    expect($onsite->to_date->toDateString())->toBe('2026-07-10')
        ->and($signOff->from_date->toDateString())->toBe('2026-07-11');
});

test('prepare clips cross month phases to the payroll period', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-25 12:00:00', 'Asia/Dubai'));

    $fixtures = makeDailyCrewTimelineFixtures();

    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::OnVessel, 1, '2026-07-10 08:00:00', '2026-08-20 18:00:00');

    $preparation = app(PrepareCrewTimesheetTimeline::class)->handle(
        $fixtures['period'],
        (int) $fixtures['company']->id,
        (int) $fixtures['user']->id,
    );

    $onsite = CrewTimesheetPreparationLine::query()
        ->where('crew_timesheet_preparation_id', $preparation->id)
        ->where('pay_category', CrewTimesheetPayCategory::Onsite)
        ->where('days', '>', 0)
        ->firstOrFail();

    expect($onsite->from_date->toDateString())->toBe('2026-07-10')
        ->and($onsite->to_date->toDateString())->toBe('2026-07-31');

    CarbonImmutable::setTestNow();
});

test('prepare uses effective end for active p4 with null end date', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-15 12:00:00', 'Asia/Dubai'));

    $fixtures = makeDailyCrewTimelineFixtures();

    addTimelinePhase(
        $fixtures['assignment'],
        CrewPhaseCode::OnVessel,
        1,
        '2026-07-01 08:00:00',
        null,
        CrewPhaseStatus::Active,
    );

    $preparation = app(PrepareCrewTimesheetTimeline::class)->handle(
        $fixtures['period'],
        (int) $fixtures['company']->id,
        (int) $fixtures['user']->id,
        CarbonImmutable::parse('2026-07-12'),
    );

    $onsite = CrewTimesheetPreparationLine::query()
        ->where('crew_timesheet_preparation_id', $preparation->id)
        ->where('pay_category', CrewTimesheetPayCategory::Onsite)
        ->where('days', '>', 0)
        ->firstOrFail();

    expect($onsite->to_date->toDateString())->toBe('2026-07-12');

    CarbonImmutable::setTestNow();
});

test('prepare excludes future payable days', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-10 12:00:00', 'Asia/Dubai'));

    $fixtures = makeDailyCrewTimelineFixtures();

    addTimelinePhase(
        $fixtures['assignment'],
        CrewPhaseCode::OnVessel,
        1,
        '2026-07-01 08:00:00',
        '2026-07-31 18:00:00',
    );

    $preparation = app(PrepareCrewTimesheetTimeline::class)->handle(
        $fixtures['period'],
        (int) $fixtures['company']->id,
        (int) $fixtures['user']->id,
    );

    $onsite = CrewTimesheetPreparationLine::query()
        ->where('crew_timesheet_preparation_id', $preparation->id)
        ->where('pay_category', CrewTimesheetPayCategory::Onsite)
        ->where('days', '>', 0)
        ->firstOrFail();

    expect($onsite->to_date->toDateString())->toBe('2026-07-10');

    CarbonImmutable::setTestNow();
});

test('prepare keeps repeated phases as separate lines', function () {
    $fixtures = makeDailyCrewTimelineFixtures();

    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::OnVessel, 1, '2026-07-01 08:00:00', '2026-07-05 18:00:00');
    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::JoinStandby, 2, '2026-07-06 08:00:00', '2026-07-08 18:00:00');
    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::OnVessel, 3, '2026-07-09 08:00:00', '2026-07-15 18:00:00');

    $preparation = app(PrepareCrewTimesheetTimeline::class)->handle(
        $fixtures['period'],
        (int) $fixtures['company']->id,
        (int) $fixtures['user']->id,
    );

    $onsiteLines = CrewTimesheetPreparationLine::query()
        ->where('crew_timesheet_preparation_id', $preparation->id)
        ->where('pay_category', CrewTimesheetPayCategory::Onsite)
        ->where('days', '>', 0)
        ->get();

    expect($onsiteLines)->toHaveCount(2);
});

test('prepare supports multiple assignments in one period', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    $secondEmployee = createCrewEmployeeWithContract($fixtures['company'], 'CRW-TL-002', 100, 50, 25);

    $secondAssignment = CrewAssignment::query()->create([
        'company_id' => $fixtures['company']->id,
        'assignment_no' => 'CA-TL-'.fake()->unique()->numerify('######'),
        'employee_id' => $secondEmployee->id,
        'rank_id' => $fixtures['rank']->id,
        'vessel_id' => $fixtures['vessel']->id,
        'status' => CrewAssignmentStatus::Active,
        'source' => 'manual',
    ]);

    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::OnVessel, 1, '2026-07-01 08:00:00', '2026-07-10 18:00:00');
    addTimelinePhase($secondAssignment, CrewPhaseCode::OnVessel, 1, '2026-07-05 08:00:00', '2026-07-20 18:00:00');

    $preparation = app(PrepareCrewTimesheetTimeline::class)->handle(
        $fixtures['period'],
        (int) $fixtures['company']->id,
        (int) $fixtures['user']->id,
    );

    $employeeIds = CrewTimesheetPreparationLine::query()
        ->where('crew_timesheet_preparation_id', $preparation->id)
        ->where('days', '>', 0)
        ->pluck('employee_id')
        ->unique()
        ->sort()
        ->values()
        ->all();

    expect($employeeIds)->toBe([
        $fixtures['employee']->id,
        $secondEmployee->id,
    ]);
});

test('handover transition date does not create overlap warning and onsite wins', function () {
    $fixtures = makeDailyCrewTimelineFixtures();

    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::ReadyToJoin, 1, '2026-07-01 08:00:00', '2026-07-06 18:00:00');
    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::OnVessel, 2, '2026-07-06 08:00:00', '2026-07-20 18:00:00');

    $preparation = app(PrepareCrewTimesheetTimeline::class)->handle(
        $fixtures['period'],
        (int) $fixtures['company']->id,
        (int) $fixtures['user']->id,
    );

    $julySixth = CrewTimesheetPreparationLine::query()
        ->where('crew_timesheet_preparation_id', $preparation->id)
        ->where('days', '>', 0)
        ->whereDate('from_date', '<=', '2026-07-06')
        ->whereDate('to_date', '>=', '2026-07-06')
        ->get();

    expect($julySixth->contains(fn ($line) => $line->pay_category === CrewTimesheetPayCategory::Onsite))->toBeTrue()
        ->and(
            CrewTimesheetPreparationLine::query()
                ->where('crew_timesheet_preparation_id', $preparation->id)
                ->where('warning_code', CrewTimelineWarningCode::OverlappingPhases->value)
                ->exists()
        )->toBeFalse();
});

test('genuine multi-day overlap creates blocking overlap warning and onsite wins', function () {
    $fixtures = makeDailyCrewTimelineFixtures();

    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::ReadyToJoin, 1, '2026-07-01 08:00:00', '2026-07-10 18:00:00');
    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::OnVessel, 2, '2026-07-06 08:00:00', '2026-07-20 18:00:00');

    $preparation = app(PrepareCrewTimesheetTimeline::class)->handle(
        $fixtures['period'],
        (int) $fixtures['company']->id,
        (int) $fixtures['user']->id,
    );

    $julyEighth = CrewTimesheetPreparationLine::query()
        ->where('crew_timesheet_preparation_id', $preparation->id)
        ->where('days', '>', 0)
        ->whereDate('from_date', '<=', '2026-07-08')
        ->whereDate('to_date', '>=', '2026-07-08')
        ->get();

    expect($julyEighth->contains(fn ($line) => $line->pay_category === CrewTimesheetPayCategory::Onsite))->toBeTrue()
        ->and(
            CrewTimesheetPreparationLine::query()
                ->where('crew_timesheet_preparation_id', $preparation->id)
                ->where('warning_code', CrewTimelineWarningCode::OverlappingPhases->value)
                ->exists()
        )->toBeTrue();
});

test('prepare ignores planned dates', function () {
    $fixtures = makeDailyCrewTimelineFixtures();

    CrewAssignmentPhase::query()->create([
        'company_id' => $fixtures['company']->id,
        'crew_assignment_id' => $fixtures['assignment']->id,
        'phase_code' => CrewPhaseCode::OnVessel,
        'sequence' => 1,
        'status' => CrewPhaseStatus::Planned,
        'planned_start_at' => CarbonImmutable::parse('2026-07-01 08:00:00', 'Asia/Dubai'),
        'planned_end_at' => CarbonImmutable::parse('2026-07-20 18:00:00', 'Asia/Dubai'),
        'actual_start_at' => null,
        'actual_end_at' => null,
    ]);

    $preparation = app(PrepareCrewTimesheetTimeline::class)->handle(
        $fixtures['period'],
        (int) $fixtures['company']->id,
        (int) $fixtures['user']->id,
    );

    expect(
        CrewTimesheetPreparationLine::query()
            ->where('crew_timesheet_preparation_id', $preparation->id)
            ->where('days', '>', 0)
            ->count()
    )->toBe(0);
});

test('monthly crew employees are skipped with warning', function () {
    $fixtures = makeDailyCrewTimelineFixtures();

    EmployeeContract::query()
        ->where('employee_id', $fixtures['employee']->id)
        ->update(['salary_structure' => ContractSalaryStructure::Monthly]);

    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::OnVessel, 1, '2026-07-01 08:00:00', '2026-07-10 18:00:00');

    $preparation = app(PrepareCrewTimesheetTimeline::class)->handle(
        $fixtures['period'],
        (int) $fixtures['company']->id,
        (int) $fixtures['user']->id,
    );

    expect(
        CrewTimesheetPreparationLine::query()
            ->where('crew_timesheet_preparation_id', $preparation->id)
            ->where('days', '>', 0)
            ->count()
    )->toBe(0)
        ->and(
            CrewTimesheetPreparationLine::query()
                ->where('crew_timesheet_preparation_id', $preparation->id)
                ->where('warning_code', CrewTimelineWarningCode::MonthlyContractNotSupported->value)
                ->exists()
        )->toBeTrue();
});

test('missing active crew contract creates warning and no payable lines', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();

    $period = PayrollPeriod::factory()->for($company)->crewOperations()->create([
        'status' => PayrollPeriodStatus::Draft,
        'payroll_category' => PayrollCategory::Crew,
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-31',
    ]);

    $assignment = CrewAssignment::query()->create([
        'company_id' => $company->id,
        'assignment_no' => 'CA-TL-'.fake()->unique()->numerify('######'),
        'employee_id' => $employee->id,
        'rank_id' => $rank->id,
        'vessel_id' => makeCrewMovementVessel('No Contract Vessel')->id,
        'status' => CrewAssignmentStatus::Active,
        'source' => 'manual',
    ]);

    addTimelinePhase($assignment, CrewPhaseCode::OnVessel, 1, '2026-07-01 08:00:00', '2026-07-10 18:00:00');

    $preparation = app(PrepareCrewTimesheetTimeline::class)->handle(
        $period,
        (int) $company->id,
        (int) $user->id,
    );

    expect(
        CrewTimesheetPreparationLine::query()
            ->where('crew_timesheet_preparation_id', $preparation->id)
            ->where('warning_code', CrewTimelineWarningCode::NoActiveCrewContract->value)
            ->exists()
    )->toBeTrue()
        ->and(
            CrewTimesheetPreparationLine::query()
                ->where('crew_timesheet_preparation_id', $preparation->id)
                ->where('days', '>', 0)
                ->count()
        )->toBe(0);
});

test('pending movement correction creates warning', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    $phase = addTimelinePhase($fixtures['assignment'], CrewPhaseCode::OnVessel, 1, '2026-07-01 08:00:00', '2026-07-10 18:00:00');

    CrewMovementCorrection::factory()
        ->forAssignment($fixtures['assignment'], $phase)
        ->pending()
        ->create([
            'company_id' => $fixtures['company']->id,
            'requested_by' => $fixtures['user']->id,
        ]);

    $preparation = app(PrepareCrewTimesheetTimeline::class)->handle(
        $fixtures['period'],
        (int) $fixtures['company']->id,
        (int) $fixtures['user']->id,
    );

    expect(
        CrewTimesheetPreparationLine::query()
            ->where('crew_timesheet_preparation_id', $preparation->id)
            ->where('warning_code', CrewTimelineWarningCode::PendingMovementCorrection->value)
            ->exists()
    )->toBeTrue();
});

test('prepare increments version and preserves previous versions', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::OnVessel, 1, '2026-07-01 08:00:00', '2026-07-10 18:00:00');

    $first = app(PrepareCrewTimesheetTimeline::class)->handle(
        $fixtures['period'],
        (int) $fixtures['company']->id,
        (int) $fixtures['user']->id,
    );

    $second = app(PrepareCrewTimesheetTimeline::class)->handle(
        $fixtures['period'],
        (int) $fixtures['company']->id,
        (int) $fixtures['user']->id,
    );

    expect($first->version)->toBe(1)
        ->and($second->version)->toBe(2)
        ->and(CrewTimesheetPreparation::query()->where('payroll_period_id', $fixtures['period']->id)->count())->toBe(2)
        ->and($first->fresh()->exists)->toBeTrue()
        ->and($first->source_hash)->toBe($second->source_hash);
});

test('prepare is company isolated', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    ['company' => $otherCompany] = makePayrollFixtures();

    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::OnVessel, 1, '2026-07-01 08:00:00', '2026-07-10 18:00:00');

    $otherPeriod = PayrollPeriod::factory()->for($otherCompany)->crewOperations()->create([
        'status' => PayrollPeriodStatus::Draft,
        'payroll_category' => PayrollCategory::Crew,
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-31',
    ]);

    expect(fn () => app(PrepareCrewTimesheetTimeline::class)->handle(
        $fixtures['period'],
        (int) $otherCompany->id,
        (int) $fixtures['user']->id,
    ))->toThrow(HttpException::class);

    $preparation = app(PrepareCrewTimesheetTimeline::class)->handle(
        $otherPeriod,
        (int) $otherCompany->id,
        (int) $fixtures['user']->id,
    );

    expect(
        CrewTimesheetPreparationLine::query()
            ->where('crew_timesheet_preparation_id', $preparation->id)
            ->where('days', '>', 0)
            ->count()
    )->toBe(0);
});

test('prepare rolls back when line insert fails', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::OnVessel, 1, '2026-07-01 08:00:00', '2026-07-10 18:00:00');

    DB::listen(function ($query): void {
        if (
            str_contains(strtolower($query->sql), 'insert into')
            && str_contains($query->sql, 'crew_timesheet_preparation_lines')
        ) {
            throw new RuntimeException('forced line failure');
        }
    });

    try {
        app(PrepareCrewTimesheetTimeline::class)->handle(
            $fixtures['period'],
            (int) $fixtures['company']->id,
            (int) $fixtures['user']->id,
        );
        expect(false)->toBeTrue();
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('forced line failure');
    }

    expect(CrewTimesheetPreparation::query()->where('payroll_period_id', $fixtures['period']->id)->count())->toBe(0);
});
