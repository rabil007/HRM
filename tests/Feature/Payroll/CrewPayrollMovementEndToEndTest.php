<?php

use App\Enums\ContractSalaryStructure;
use App\Enums\CrewAccommodationStatus;
use App\Enums\CrewAssignmentStatus;
use App\Enums\CrewMovementAction;
use App\Enums\CrewPhaseCode;
use App\Enums\CrewTimesheetPayCategory;
use App\Enums\PayrollCategory;
use App\Enums\PayrollPeriodStatus;
use App\Models\CrewAccommodationStay;
use App\Models\CrewAssignment;
use App\Models\CrewTimesheetPreparationLine;
use App\Models\EmployeeContract;
use App\Models\Hotel;
use App\Models\PayrollPeriod;
use App\Models\RoomType;
use App\Support\Contracts\Actions\ApplyContractSalaryRevision;
use App\Support\CrewMovements\CrewAssignmentPresenter;
use App\Support\CrewMovements\CrewMovementService;
use App\Support\Payroll\Actions\SyncContractSalaryComponentsFromContract;
use App\Support\Reports\CrewMovementHistoryPresenter;
use Carbon\Carbon;
use Carbon\CarbonImmutable;

afterEach(function () {
    CarbonImmutable::setTestNow();
    restoreCrewMovementTestClock();
});

function movementPayrollService(): CrewMovementService
{
    return app(CrewMovementService::class);
}

/**
 * @return array{
 *     user: mixed,
 *     company: mixed,
 *     employee: mixed,
 *     rank: mixed,
 *     period: PayrollPeriod,
 *     vessel: mixed,
 *     contract: EmployeeContract
 * }
 */
function makeMovementPayrollFixtures(): array
{
    freezeCrewMovementTestClock();

    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $company->update(['timezone' => 'Asia/Dubai']);
    setMappedCrewTourOfDutyDays($company, $rank, 90);

    $contract = EmployeeContract::factory()->create([
        'employee_id' => $employee->id,
        'company_id' => $company->id,
        'payroll_category' => PayrollCategory::Crew,
        'salary_structure' => ContractSalaryStructure::Daily,
        'status' => 'active',
        'start_date' => '2026-01-01',
        'end_date' => null,
        'basic_salary' => 100,
        'site_allowance' => 30,
        'supplementary_allowance' => 20,
    ]);
    (new SyncContractSalaryComponentsFromContract)->handle($contract);
    app(ApplyContractSalaryRevision::class)->handle(
        $contract->fresh(),
        [
            'basic_salary' => 100,
            'site_allowance' => 30,
            'supplementary_allowance' => 20,
        ],
        '2026-01-01',
        'Movement payroll fixture rates',
    );

    $period = PayrollPeriod::factory()->for($company)->crewOperations()->create([
        'status' => PayrollPeriodStatus::Draft,
        'payroll_category' => PayrollCategory::Crew,
        'start_date' => '2026-09-01',
        'end_date' => '2026-09-30',
        'payment_date' => '2026-09-30',
    ]);

    $vessel = makeCrewMovementVessel('Movement Payroll Vessel', $company);

    return compact('user', 'company', 'employee', 'rank', 'period', 'vessel', 'contract');
}

function preparationPayableDays(int $preparationId, CrewTimesheetPayCategory $category): float
{
    return (float) CrewTimesheetPreparationLine::query()
        ->where('crew_timesheet_preparation_id', $preparationId)
        ->where('pay_category', $category)
        ->sum('days');
}

test('real crew movement happy path produces correct payroll through apply', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-21 12:00:00', 'Asia/Dubai'));

    $fixtures = makeMovementPayrollFixtures();
    ['company' => $company, 'employee' => $employee, 'rank' => $rank, 'user' => $user, 'vessel' => $vessel] = $fixtures;
    $service = movementPayrollService();

    $assignment = $service->createDraft($company->id, $employee->id, [
        'position_id' => $rank->id,
        'vessel_id' => $vessel->id,
    ], $user->id);
    $id = $assignment->id;

    $service->perform($company->id, $id, CrewMovementAction::ApproveMobilisation, [
        'occurred_at' => '2026-09-01 08:00:00',
    ], $user->id);
    $service->perform($company->id, $id, CrewMovementAction::RecordArrival, [
        'occurred_at' => '2026-09-03 08:00:00',
        'next_phase' => 'p1a',
    ], $user->id);
    $service->perform($company->id, $id, CrewMovementAction::JoinVessel, [
        'occurred_at' => '2026-09-07 08:00:00',
        'vessel_id' => $vessel->id,
        'position_id' => $rank->id,
    ], $user->id);
    $service->perform($company->id, $id, CrewMovementAction::ConfirmDisembarkation, [
        'occurred_at' => '2026-09-15 12:00:00',
        'next_phase' => 'p3',
    ], $user->id);
    $assignment = $service->perform($company->id, $id, CrewMovementAction::TravelHome, [
        'occurred_at' => '2026-09-18 14:00:00',
        'completion_intent' => 'close',
    ], $user->id);

    expect($assignment->status)->toBe(CrewAssignmentStatus::Completed)
        ->and($assignment->phases()->count())->toBe(5);

    $fixtures['assignment'] = $assignment->fresh(['phases']);
    ['preparation' => $preparation, 'record' => $record] = runDailyCrewPayrollPipeline($fixtures);

    $standbyDays = preparationPayableDays($preparation->id, CrewTimesheetPayCategory::SignOnStandby);
    $onsiteDays = preparationPayableDays($preparation->id, CrewTimesheetPayCategory::Onsite);
    $signOffDays = preparationPayableDays($preparation->id, CrewTimesheetPayCategory::SignOffStandby);

    expect($standbyDays)->toBe(4.0)
        ->and($onsiteDays)->toBe(9.0)
        ->and($signOffDays)->toBe(3.0)
        ->and($record)->not->toBeNull()
        ->and((float) $record->basic_salary)->toBe(round(($standbyDays + $onsiteDays + $signOffDays) * 100, 2))
        ->and((float) $record->calculation_breakdown['rates']['basic_daily'])->toBe(100.0);

    assertPayrollReconciles($record);
});

test('real transfer vessel action allocates shared day once through payroll', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-21 12:00:00', 'Asia/Dubai'));

    $fixtures = makeMovementPayrollFixtures();
    ['company' => $company, 'employee' => $employee, 'rank' => $rank, 'user' => $user, 'vessel' => $sourceVessel] = $fixtures;
    $destinationVessel = makeCrewMovementVessel('Transfer Destination', $company);
    $service = movementPayrollService();

    $source = $service->createDraft($company->id, $employee->id, [
        'position_id' => $rank->id,
        'vessel_id' => $sourceVessel->id,
    ], $user->id);
    $sourceId = $source->id;

    $service->perform($company->id, $sourceId, CrewMovementAction::ApproveMobilisation, [
        'occurred_at' => '2026-09-01 08:00:00',
    ], $user->id);
    $service->perform($company->id, $sourceId, CrewMovementAction::RecordArrival, [
        'occurred_at' => '2026-09-02 08:00:00',
        'next_phase' => 'p1a',
    ], $user->id);
    $source = $service->perform($company->id, $sourceId, CrewMovementAction::JoinVessel, [
        'occurred_at' => '2026-09-10 08:00:00',
        'vessel_id' => $sourceVessel->id,
        'position_id' => $rank->id,
    ], $user->id);

    $destination = $service->perform(
        $company->id,
        $source->id,
        CrewMovementAction::TransferVessel,
        [
            'occurred_at' => '2026-09-15 12:00:00',
            'vessel_id' => $destinationVessel->id,
            'position_id' => $rank->id,
        ],
        $user->id,
    );

    $source->refresh()->load(['phases', 'currentPhase']);

    expect($source->status)->toBe(CrewAssignmentStatus::Completed)
        ->and($source->currentPhase?->actual_end_at?->toDateTimeString())
        ->toBe($destination->currentPhase?->actual_start_at?->toDateTimeString())
        ->and($destination->previous_assignment_id)->toBe($source->id)
        ->and($destination->currentPhase?->phase_code)->toBe(CrewPhaseCode::OnVessel);

    $fixtures['assignment'] = $source;
    ['preparation' => $preparation, 'record' => $record] = runDailyCrewPayrollPipeline($fixtures);

    $onsiteDays = preparationPayableDays($preparation->id, CrewTimesheetPayCategory::Onsite);

    expect(overlapWarningExists($preparation->id))->toBeFalse()
        ->and($onsiteDays)->toBeGreaterThan(6.0)
        ->and($record)->not->toBeNull()
        ->and((float) $record->gross_salary)->toBeGreaterThan((float) $record->basic_salary);

    assertPayrollReconciles($record);
});

test('real cancel assignment after standby preserves legitimate standby pay through payroll', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-10 12:00:00', 'Asia/Dubai'));

    $fixtures = makeMovementPayrollFixtures();
    ['company' => $company, 'employee' => $employee, 'rank' => $rank, 'user' => $user, 'vessel' => $vessel] = $fixtures;
    $service = movementPayrollService();

    $assignment = $service->createDraft($company->id, $employee->id, [
        'position_id' => $rank->id,
        'vessel_id' => $vessel->id,
    ], $user->id);
    $id = $assignment->id;

    $service->perform($company->id, $id, CrewMovementAction::ApproveMobilisation, [
        'occurred_at' => '2026-09-01 08:00:00',
    ], $user->id);
    $service->perform($company->id, $id, CrewMovementAction::RecordArrival, [
        'occurred_at' => '2026-09-03 08:00:00',
        'next_phase' => 'p1a',
    ], $user->id);

    $assignment = $service->perform($company->id, $id, CrewMovementAction::CancelAssignment, [
        'occurred_at' => '2026-09-06 18:00:00',
        'reason' => 'Vessel deployment cancelled',
    ], $user->id);

    expect($assignment->status)->toBe(CrewAssignmentStatus::Cancelled);

    $fixtures['assignment'] = $assignment->fresh(['phases']);
    ['preparation' => $preparation, 'record' => $record] = runDailyCrewPayrollPipeline($fixtures);

    $standbyDays = preparationPayableDays($preparation->id, CrewTimesheetPayCategory::SignOnStandby);

    expect($standbyDays)->toBe(4.0)
        ->and((float) $record->calculation_breakdown['sign_on_standby_days'])->toBe(4.0)
        ->and((float) $record->basic_salary)->toBe(400.0)
        ->and((float) $record->other_allowances)->toBe(80.0)
        ->and((float) $record->gross_salary)->toBe(480.0)
        ->and((float) $record->net_salary)->toBe(480.0);

    assertPayrollReconciles($record);
});

test('real redeploy closes source assignment and pays combined timeline once', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-21 12:00:00', 'Asia/Dubai'));

    $fixtures = makeMovementPayrollFixtures();
    ['company' => $company, 'employee' => $employee, 'rank' => $rank, 'user' => $user, 'vessel' => $vessel] = $fixtures;
    $service = movementPayrollService();

    $source = $service->createDraft($company->id, $employee->id, [
        'position_id' => $rank->id,
        'vessel_id' => $vessel->id,
    ], $user->id);
    $sourceId = $source->id;

    $service->perform($company->id, $sourceId, CrewMovementAction::ApproveMobilisation, [
        'occurred_at' => '2026-09-01 08:00:00',
    ], $user->id);
    $service->perform($company->id, $sourceId, CrewMovementAction::RecordArrival, [
        'occurred_at' => '2026-09-02 08:00:00',
        'next_phase' => 'p1a',
    ], $user->id);
    $source = $service->perform($company->id, $sourceId, CrewMovementAction::JoinVessel, [
        'occurred_at' => '2026-09-05 08:00:00',
        'vessel_id' => $vessel->id,
        'position_id' => $rank->id,
    ], $user->id);

    $service->perform($company->id, $source->id, CrewMovementAction::ConfirmDisembarkation, [
        'occurred_at' => '2026-09-12 08:00:00',
        'next_phase' => 'p4',
    ], $user->id);

    $destination = $service->perform($company->id, $source->id, CrewMovementAction::Redeploy, [
        'occurred_at' => '2026-09-14 10:00:00',
        'starting_phase' => 'p1a',
        'vessel_id' => $vessel->id,
        'position_id' => $rank->id,
    ], $user->id);

    $source->refresh();

    expect($source->status)->toBe(CrewAssignmentStatus::Completed)
        ->and($destination->previous_assignment_id)->toBe($source->id)
        ->and($destination->source)->toBe('redeployment')
        ->and(CrewAssignment::query()->where('company_id', $company->id)->where('employee_id', $employee->id)->where('status', CrewAssignmentStatus::Active)->count())
        ->toBe(1);

    $fixtures['assignment'] = $source;
    ['preparation' => $preparation, 'record' => $record] = runDailyCrewPayrollPipeline($fixtures);

    expect(overlapWarningExists($preparation->id))->toBeFalse()
        ->and(preparationPayableDays($preparation->id, CrewTimesheetPayCategory::SignOnStandby))->toBeGreaterThan(0)
        ->and(preparationPayableDays($preparation->id, CrewTimesheetPayCategory::Onsite))->toBeGreaterThan(0)
        ->and($record)->not->toBeNull();

    assertPayrollReconciles($record);
});

test('scenario a and b keep assignment history timesheet payroll and hotel days aligned', function () {
    $fixtures = makeMovementPayrollFixtures();
    // Fixtures freeze the shared movement clock; pin company-local as-of for October counts.
    Carbon::setTestNow(Carbon::parse('2026-10-20 12:00:00', 'Asia/Dubai'));
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-20 12:00:00', 'Asia/Dubai'));

    ['company' => $company, 'employee' => $employee, 'rank' => $rank, 'user' => $user, 'vessel' => $vessel] = $fixtures;
    $fixtures['period']->update([
        'status' => PayrollPeriodStatus::Draft,
        'payroll_category' => PayrollCategory::Crew,
        'start_date' => '2026-10-01',
        'end_date' => '2026-10-31',
        'payment_date' => '2026-10-31',
    ]);
    $fixtures['period'] = $fixtures['period']->fresh();

    $hotel = Hotel::factory()->create(['company_id' => $company->id, 'name' => 'Standby Consistency Hotel']);
    $roomType = RoomType::factory()->create([
        'company_id' => $company->id,
        'hotel_id' => $hotel->id,
        'name' => 'Standard',
    ]);
    $service = movementPayrollService();

    $assignment = $service->createDraft($company->id, $employee->id, [
        'position_id' => $rank->id,
        'vessel_id' => $vessel->id,
    ], $user->id);
    $id = $assignment->id;

    $service->perform($company->id, $id, CrewMovementAction::ApproveMobilisation, [
        'occurred_at' => '2026-09-28 08:00:00',
    ], $user->id);

    $service->perform($company->id, $id, CrewMovementAction::RecordArrival, [
        'occurred_at' => '2026-10-01 08:00:00',
        'next_phase' => 'p1a',
        'accommodation_status' => CrewAccommodationStatus::Hotel->value,
        'hotel_id' => $hotel->id,
        'room_type_id' => $roomType->id,
        'check_in_date' => '2026-10-01',
    ], $user->id);
    $service->perform($company->id, $id, CrewMovementAction::JoinVessel, [
        'occurred_at' => '2026-10-05 08:00:00',
        'vessel_id' => $vessel->id,
        'position_id' => $rank->id,
        'check_out_date' => '2026-10-05',
    ], $user->id);

    $service->perform($company->id, $id, CrewMovementAction::ConfirmDisembarkation, [
        'occurred_at' => '2026-10-10 08:00:00',
        'next_phase' => 'p3',
        'accommodation_status' => CrewAccommodationStatus::Hotel->value,
        'hotel_id' => $hotel->id,
        'room_type_id' => $roomType->id,
        'check_in_date' => '2026-10-10',
    ], $user->id);
    $assignment = $service->perform($company->id, $id, CrewMovementAction::TravelHome, [
        'occurred_at' => '2026-10-14 08:00:00',
        'completion_intent' => 'close',
        'check_out_date' => '2026-10-14',
    ], $user->id);

    expect($assignment->status)->toBe(CrewAssignmentStatus::Completed);

    $assignment = $assignment->fresh([
        'company',
        'employee',
        'position',
        'vessel',
        'client',
        'currentPhase',
        'phases',
        'accommodationStays.hotel',
        'accommodationStays.roomType',
        'accommodationStays.startedFromPhase',
    ]);

    $detail = CrewAssignmentPresenter::detail($assignment, $user);
    $history = CrewMovementHistoryPresenter::toArray($assignment);

    $p1aTimeline = collect($detail['phase_timeline'])->firstWhere('phase_code', 'p1a');
    $p3Timeline = collect($detail['phase_timeline'])->firstWhere('phase_code', 'p3');
    $p2Timeline = collect($detail['phase_timeline'])->firstWhere('phase_code', 'p2');

    expect($p1aTimeline['allocated_service_days'])->toBe(4)
        ->and($p3Timeline['allocated_service_days'])->toBe(4)
        ->and($history['join_standby']['total_days'])->toBe(4)
        ->and($history['demob_standby']['total_days'])->toBe(4)
        ->and($history['standby_service_days'])->toBe(8)
        ->and($history['vessel_service_days'])->toBe(6)
        ->and($history['payroll_days']['sign_on_standby']['total_days'])->toBe(4)
        ->and($history['payroll_days']['sign_off_standby']['total_days'])->toBe(4)
        ->and($history['payroll_days']['onsite']['total_days'])->toBe(6)
        ->and($p2Timeline['allocated_service_days'])->toBe(6);

    $stays = CrewAccommodationStay::query()
        ->where('crew_assignment_id', $assignment->id)
        ->where('accommodation_status', CrewAccommodationStatus::Hotel)
        ->orderBy('id')
        ->get();

    expect($stays)->toHaveCount(2)
        ->and($stays[0]->check_in_date?->toDateString())->toBe('2026-10-01')
        ->and($stays[0]->check_out_date?->toDateString())->toBe('2026-10-05')
        ->and($stays[1]->check_in_date?->toDateString())->toBe('2026-10-10')
        ->and($stays[1]->check_out_date?->toDateString())->toBe('2026-10-14');

    $preJoinHotelDays = (int) CarbonImmutable::parse('2026-10-01')->diffInDays(CarbonImmutable::parse('2026-10-05')) + 1;
    $demobHotelDays = (int) CarbonImmutable::parse('2026-10-10')->diffInDays(CarbonImmutable::parse('2026-10-14')) + 1;

    expect($preJoinHotelDays)->toBe(5)
        ->and($demobHotelDays)->toBe(5);

    $fixtures['assignment'] = $assignment;
    ['preparation' => $preparation, 'record' => $record] = runDailyCrewPayrollPipeline($fixtures);

    expect(preparationPayableDays($preparation->id, CrewTimesheetPayCategory::SignOnStandby))->toBe(4.0)
        ->and(preparationPayableDays($preparation->id, CrewTimesheetPayCategory::SignOffStandby))->toBe(4.0)
        ->and(preparationPayableDays($preparation->id, CrewTimesheetPayCategory::Onsite))->toBe(6.0)
        ->and($record)->not->toBeNull()
        ->and((float) $record->calculation_breakdown['sign_on_standby_days'])->toBe(4.0)
        ->and((float) $record->calculation_breakdown['sign_off_standby_days'])->toBe(4.0)
        ->and((float) $record->calculation_breakdown['onsite_days'])->toBe(6.0);

    assertPayrollReconciles($record);
});

test('a to b to c linked vessel transfers do not double-count shared days in history or payroll', function () {
    $fixtures = makeMovementPayrollFixtures();
    Carbon::setTestNow(Carbon::parse('2026-10-20 12:00:00', 'Asia/Dubai'));
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-20 12:00:00', 'Asia/Dubai'));

    ['company' => $company, 'employee' => $employee, 'rank' => $rank, 'user' => $user] = $fixtures;
    $fixtures['period']->update([
        'start_date' => '2026-10-01',
        'end_date' => '2026-10-31',
        'payment_date' => '2026-10-31',
    ]);
    $fixtures['period'] = $fixtures['period']->fresh();

    $vesselA = makeCrewMovementVessel('Chain Vessel A', $company);
    $vesselB = makeCrewMovementVessel('Chain Vessel B', $company);
    $vesselC = makeCrewMovementVessel('Chain Vessel C', $company);
    $service = movementPayrollService();

    $source = $service->createDraft($company->id, $employee->id, [
        'position_id' => $rank->id,
        'vessel_id' => $vesselA->id,
    ], $user->id);
    $sourceId = $source->id;

    $service->perform($company->id, $sourceId, CrewMovementAction::ApproveMobilisation, [
        'occurred_at' => '2026-09-28 08:00:00',
    ], $user->id);
    $service->perform($company->id, $sourceId, CrewMovementAction::RecordArrival, [
        'occurred_at' => '2026-10-01 08:00:00',
        'next_phase' => 'p1a',
        'accommodation_status' => CrewAccommodationStatus::NoAccommodation->value,
    ], $user->id);
    $source = $service->perform($company->id, $sourceId, CrewMovementAction::JoinVessel, [
        'occurred_at' => '2026-10-05 08:00:00',
        'vessel_id' => $vesselA->id,
        'position_id' => $rank->id,
    ], $user->id);

    $mid = $service->perform($company->id, $source->id, CrewMovementAction::TransferVessel, [
        'occurred_at' => '2026-10-10 08:00:00',
        'vessel_id' => $vesselB->id,
        'position_id' => $rank->id,
    ], $user->id);

    $destination = $service->perform($company->id, $mid->id, CrewMovementAction::TransferVessel, [
        'occurred_at' => '2026-10-15 08:00:00',
        'vessel_id' => $vesselC->id,
        'position_id' => $rank->id,
    ], $user->id);

    $source = $source->fresh(['company', 'employee', 'position', 'vessel', 'client', 'currentPhase', 'phases', 'nextAssignments.phases']);
    $mid = $mid->fresh(['company', 'employee', 'position', 'vessel', 'client', 'currentPhase', 'phases', 'previousAssignment.phases', 'nextAssignments.phases']);
    $destination = $destination->fresh(['company', 'employee', 'position', 'vessel', 'client', 'currentPhase', 'phases', 'previousAssignment.phases']);

    $rowA = CrewMovementHistoryPresenter::toArray($source);
    $rowB = CrewMovementHistoryPresenter::toArray($mid);
    $rowC = CrewMovementHistoryPresenter::toArray($destination);

    // Operational reports clamp open phases to company-local today (5–20 Oct).
    expect($rowA['vessel_service_days'] + $rowB['vessel_service_days'] + $rowC['vessel_service_days'])->toBe(16)
        ->and($rowA['vessel_service_days'])->toBe(6)
        ->and($rowB['vessel_service_days'])->toBe(5)
        ->and($rowC['vessel_service_days'])->toBe(5)
        ->and($destination->previous_assignment_id)->toBe($mid->id)
        ->and($mid->previous_assignment_id)->toBe($source->id);

    $fixtures['assignment'] = $source;
    ['preparation' => $preparation] = runDailyCrewPayrollPipeline($fixtures);

    // Automatic timesheet preparation allocates open vessel phases through period end.
    expect(preparationPayableDays($preparation->id, CrewTimesheetPayCategory::Onsite))->toBe(27.0)
        ->and(overlapWarningExists($preparation->id))->toBeFalse();
});
