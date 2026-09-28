<?php

use App\Enums\CrewAccommodationStayType;
use App\Enums\CrewAssignmentStatus;
use App\Enums\CrewPhaseCode;
use App\Models\Company;
use App\Models\CrewAccommodationStay;
use App\Models\Employee;
use App\Models\Rank;
use App\Models\User;
use App\Models\Vessel;
use App\Support\CrewMovements\CrewAssignmentStatusResolver;
use App\Support\CrewMovements\CrewMovementAttentionQuery;
use App\Support\CrewMovements\CurrentCrewHomeQuery;
use App\Support\CrewMovements\CurrentCrewVesselQuery;
use App\Support\CrewMovements\CurrentOnboardCrewQuery;
use App\Support\CrewMovements\Historical\HistoricalCrewAssignmentData;
use App\Support\CrewMovements\Historical\HistoricalCrewAssignmentService;
use App\Support\Reports\HotelCheckInCheckoutFilters;
use App\Support\Reports\HotelCheckInCheckoutQuery;
use App\Support\Settings\CompanyTimezone;
use Carbon\CarbonImmutable;

/**
 * @return array{user: User, company: Company, employee: Employee, rank: Rank, vessel: Vessel}
 */
function makePastCrewOperationalFixtures(string $vesselName): array
{
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel($vesselName, $company);

    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.view',
        'crew_operations.assignments.create_historical',
    ]);
    $user->update(['current_company_id' => $company->id]);

    return compact('user', 'company', 'employee', 'rank', 'vessel');
}

test('open P4 past crew bootstrap appears in current onboard and vessel manning scope', function () {
    $fixtures = makePastCrewOperationalFixtures('Past Onboard Vessel');

    $data = HistoricalCrewAssignmentData::fromArray([
        'employee_id' => $fixtures['employee']->id,
        'vessel_id' => $fixtures['vessel']->id,
        'rank_id' => $fixtures['rank']->id,
        'onsite_from' => '2024-08-01',
    ], (int) $fixtures['company']->id, CompanyTimezone::forCompanyId((int) $fixtures['company']->id));

    $assignment = app(HistoricalCrewAssignmentService::class)->create($data, (int) $fixtures['user']->id);

    expect($assignment->status)->toBe(CrewAssignmentStatus::Active)
        ->and($assignment->currentPhase?->phase_code)->toBe(CrewPhaseCode::OnVessel);

    expect(CurrentOnboardCrewQuery::assignments((int) $fixtures['company']->id)->whereKey($assignment->id)->exists())->toBeTrue();

    $page = CurrentCrewVesselQuery::paginate((int) $fixtures['company']->id, []);
    $vesselRow = collect($page->items())->firstWhere('id', $fixtures['vessel']->id);

    expect($vesselRow)->not->toBeNull()
        ->and(collect($vesselRow['crew'])->pluck('employee.id')->all())->toContain($fixtures['employee']->id);

    $summary = CrewMovementAttentionQuery::summaryCounts((int) $fixtures['company']->id);
    expect($summary['crew_on_site'])->toBeGreaterThanOrEqual(1);
});

test('open P2A past crew bootstrap establishes join standby without creating stay or appearing in pre-join hotel view', function () {
    $fixtures = makePastCrewOperationalFixtures('Past PreJoin Vessel');
    $timezone = CompanyTimezone::forCompanyId((int) $fixtures['company']->id);

    $data = HistoricalCrewAssignmentData::fromArray([
        'employee_id' => $fixtures['employee']->id,
        'vessel_id' => $fixtures['vessel']->id,
        'rank_id' => $fixtures['rank']->id,
        'sign_on_standby_from' => '2024-09-20',
    ], (int) $fixtures['company']->id, $timezone);

    $assignment = app(HistoricalCrewAssignmentService::class)->create($data, (int) $fixtures['user']->id);

    expect($assignment->status)->toBe(CrewAssignmentStatus::Active)
        ->and($assignment->currentPhase?->phase_code)->toBe(CrewPhaseCode::JoinStandby)
        ->and(CrewAccommodationStay::query()->where('crew_assignment_id', $assignment->id)->count())->toBe(0);

    $hotelQuery = new HotelCheckInCheckoutQuery(
        companyId: (int) $fixtures['company']->id,
        filters: new HotelCheckInCheckoutFilters(stayType: CrewAccommodationStayType::PreJoin->value),
        timezone: $timezone,
    );

    $stays = collect($hotelQuery->paginate()->items());
    expect($stays->pluck('assignment.employee_id')->all())->not->toContain($fixtures['employee']->id);
});

test('open P5 past crew bootstrap establishes demob standby without creating stay or appearing in post-signoff hotel view', function () {
    $fixtures = makePastCrewOperationalFixtures('Past PostSignoff Vessel');
    $timezone = CompanyTimezone::forCompanyId((int) $fixtures['company']->id);

    $data = HistoricalCrewAssignmentData::fromArray([
        'employee_id' => $fixtures['employee']->id,
        'vessel_id' => $fixtures['vessel']->id,
        'rank_id' => $fixtures['rank']->id,
        'onsite_from' => '2024-08-01',
        'onsite_to' => '2024-09-10',
        'sign_off_standby_from' => '2024-09-10',
    ], (int) $fixtures['company']->id, $timezone);

    $assignment = app(HistoricalCrewAssignmentService::class)->create($data, (int) $fixtures['user']->id);

    expect($assignment->status)->toBe(CrewAssignmentStatus::Active)
        ->and($assignment->currentPhase?->phase_code)->toBe(CrewPhaseCode::DemobStandby)
        ->and(CrewAccommodationStay::query()->where('crew_assignment_id', $assignment->id)->count())->toBe(0);

    $hotelQuery = new HotelCheckInCheckoutQuery(
        companyId: (int) $fixtures['company']->id,
        filters: new HotelCheckInCheckoutFilters(stayType: CrewAccommodationStayType::PostSignoff->value),
        timezone: $timezone,
    );

    $stays = collect($hotelQuery->paginate()->items());
    expect($stays->pluck('assignment.employee_id')->all())->not->toContain($fixtures['employee']->id);
});

test('completed past crew home bootstrap feeds in-home status and home query days', function () {
    $fixtures = makePastCrewOperationalFixtures('Past Home Vessel');
    $timezone = CompanyTimezone::forCompanyId((int) $fixtures['company']->id);
    $homeDate = CarbonImmutable::now($timezone)->subDays(5)->toDateString();

    $data = HistoricalCrewAssignmentData::fromArray([
        'employee_id' => $fixtures['employee']->id,
        'vessel_id' => $fixtures['vessel']->id,
        'rank_id' => $fixtures['rank']->id,
        'onsite_from' => CarbonImmutable::now($timezone)->subDays(40)->toDateString(),
        'onsite_to' => CarbonImmutable::now($timezone)->subDays(10)->toDateString(),
        'sign_off_standby_from' => CarbonImmutable::now($timezone)->subDays(10)->toDateString(),
        'sign_off_standby_to' => $homeDate,
        'home_available_from' => $homeDate,
    ], (int) $fixtures['company']->id, $timezone);

    $assignment = app(HistoricalCrewAssignmentService::class)->create($data, (int) $fixtures['user']->id);

    expect($assignment->status)->toBe(CrewAssignmentStatus::Completed)
        ->and($assignment->closed_at?->timezone($timezone)->toDateString())->toBe($homeDate);

    $resolved = app(CrewAssignmentStatusResolver::class)->forEmployee(
        $fixtures['employee']->fresh(),
        CarbonImmutable::now($timezone)->startOfDay(),
    );

    expect($resolved['status'])->toBe('in_home')
        ->and($resolved['in_home_days'])->toBe(5);

    $homePage = CurrentCrewHomeQuery::paginate((int) $fixtures['company']->id, []);
    $homeRow = collect($homePage->items())->first(
        fn (array $item): bool => (int) $item['employee']->id === (int) $fixtures['employee']->id,
    );

    expect($homeRow)->not->toBeNull()
        ->and($homeRow['days_at_home'])->toBe(5);
});
