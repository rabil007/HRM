<?php

use App\Enums\CrewAccommodationStatus;
use App\Enums\CrewAccommodationStayType;
use App\Enums\CrewPhaseCode;
use App\Enums\CrewPhaseStatus;
use App\Exports\HotelCheckInCheckoutExport;
use App\Models\CrewAccommodationStay;
use App\Models\CrewAssignment;
use App\Models\CrewAssignmentPhase;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Hotel;
use App\Models\RoomType;
use App\Support\Reports\HotelCheckInCheckoutFilters;
use App\Support\Reports\HotelCheckInCheckoutQuery;
use App\Support\Settings\CompanyTimezone;
use Carbon\Carbon;
use Maatwebsite\Excel\Facades\Excel;

function makeHotelCheckInCheckoutExportFixtures(): array
{
    $fixtures = makeCrewAssignmentFixtures();
    $fixtures['user']->update(['current_company_id' => $fixtures['company']->id]);
    grantCompanyPermissions($fixtures['user'], $fixtures['company'], [
        'reports.hotel_checkin_checkout.view',
        'reports.hotel_checkin_checkout.export',
    ]);

    $hotel = Hotel::factory()->create(['company_id' => $fixtures['company']->id, 'name' => 'Grand Ocean Hotel']);
    $roomType = RoomType::factory()->create(['company_id' => $fixtures['company']->id, 'hotel_id' => $hotel->id, 'name' => 'Deluxe Suite']);

    $assignment = CrewAssignment::factory()
        ->forEmployee($fixtures['employee'])
        ->create([
            'company_id' => $fixtures['company']->id,
            'assignment_no' => 'CA-EXPORT-001',
        ]);

    $phase = CrewAssignmentPhase::query()->create([
        'company_id' => $fixtures['company']->id,
        'crew_assignment_id' => $assignment->id,
        'phase_code' => CrewPhaseCode::JoinStandby,
        'status' => CrewPhaseStatus::Active,
        'sequence' => 1,
    ]);

    $stay = CrewAccommodationStay::query()->create([
        'company_id' => $fixtures['company']->id,
        'crew_assignment_id' => $assignment->id,
        'hotel_id' => $hotel->id,
        'room_type_id' => $roomType->id,
        'stay_type' => CrewAccommodationStayType::PreJoin,
        'accommodation_status' => CrewAccommodationStatus::Hotel,
        'check_in_date' => '2026-09-20',
        'check_out_date' => '2026-09-25',
        'started_from_phase_id' => $phase->id,
    ]);

    $fixtures['hotel'] = $hotel;
    $fixtures['roomType'] = $roomType;
    $fixtures['assignment'] = $assignment;
    $fixtures['phase'] = $phase;
    $fixtures['stay'] = $stay;

    return $fixtures;
}

test('hotel check-in & check-out export requires export permission', function () {
    ['user' => $user, 'company' => $company] = makeCrewAssignmentFixtures();
    $user->update(['current_company_id' => $company->id]);
    grantCompanyPermissions($user, $company, ['reports.hotel_checkin_checkout.view']);

    $this->actingAs($user)
        ->get(route('organization.reports.hotel-checkin-checkout.export'))
        ->assertForbidden();
});

test('hotel check-in & check-out exports excel and csv with active filters', function () {
    Excel::fake();
    Carbon::setTestNow('2026-09-24 10:00:00');
    $fixtures = makeHotelCheckInCheckoutExportFixtures();
    $user = $fixtures['user'];
    $company = $fixtures['company'];

    $hotelOther = Hotel::factory()->create(['company_id' => $company->id, 'name' => 'Other Hotel']);
    $stayOther = CrewAccommodationStay::query()->create([
        'company_id' => $company->id,
        'crew_assignment_id' => $fixtures['assignment']->id,
        'hotel_id' => $hotelOther->id,
        'stay_type' => CrewAccommodationStayType::PostSignoff,
        'accommodation_status' => CrewAccommodationStatus::Hotel,
        'check_in_date' => '2026-09-26',
        'check_out_date' => '2026-09-30',
    ]);

    // XLSX download with hotel filter
    $this->actingAs($user)
        ->get(route('organization.reports.hotel-checkin-checkout.export', [
            'format' => 'xlsx',
            'hotel_id' => $fixtures['hotel']->id,
        ]))
        ->assertOk();

    Excel::assertDownloaded(
        'hotel-checkin-checkout-report-2026-09-24.xlsx',
        fn (HotelCheckInCheckoutExport $export): bool => $export->query()->count() === 1
            && (int) $export->query()->first()?->id === (int) $fixtures['stay']->id,
    );

    // CSV download with stay_type filter
    $this->actingAs($user)
        ->get(route('organization.reports.hotel-checkin-checkout.export', [
            'format' => 'csv',
            'stay_type' => CrewAccommodationStayType::PostSignoff->value,
        ]))
        ->assertOk();

    Excel::assertDownloaded(
        'hotel-checkin-checkout-report-2026-09-24.csv',
        fn (HotelCheckInCheckoutExport $export): bool => $export->query()->count() === 1
            && (int) $export->query()->first()?->id === (int) $stayOther->id,
    );
});

test('export headings and mapped row match authoritative stay and assignment values', function () {
    Carbon::setTestNow('2026-09-24 10:00:00');
    $fixtures = makeHotelCheckInCheckoutExportFixtures();
    $company = $fixtures['company'];
    $stay = $fixtures['stay'];
    $employee = $fixtures['employee'];
    $assignment = $fixtures['assignment'];
    $hotel = $fixtures['hotel'];
    $roomType = $fixtures['roomType'];

    $timezone = CompanyTimezone::forCompanyId($company->id);
    $query = new HotelCheckInCheckoutQuery($company->id, new HotelCheckInCheckoutFilters, $timezone);
    $export = HotelCheckInCheckoutExport::forQuery($query->exportQuery(), $timezone);

    expect($export->headings())->toBe([
        'Stay Record ID',
        'Employee No.',
        'Employee Name',
        'Rank',
        'Hotel',
        'Room Type',
        'Stay Type',
        'Accommodation Status',
        'Check-In',
        'Check-Out',
        'Stay Status',
        'Stay Days',
        'Assignment No.',
        'Assignment Status',
        'Vessel',
        'Client',
        'Starting Checkpoint',
        'Current Crew Phase',
        'Assignment Record ID',
    ]);

    $eagerStay = $query->exportQuery()->whereKey($stay->id)->firstOrFail();
    $mapped = $export->map($eagerStay);

    expect($mapped[0])->toBe((int) $stay->id)
        ->and($mapped[1])->toBe($employee->employee_no)
        ->and($mapped[2])->toBe($employee->name)
        ->and($mapped[3])->toBe($fixtures['rank']->name)
        ->and($mapped[4])->toBe($hotel->name)
        ->and($mapped[5])->toBe($roomType->name)
        ->and($mapped[6])->toBe('Pre-Join')
        ->and($mapped[7])->toBe('Hotel')
        ->and($mapped[8])->toBe('2026-09-20')
        ->and($mapped[9])->toBe('2026-09-25')
        ->and($mapped[10])->toBe('Currently Checked In')
        ->and($mapped[11])->toBe(5)
        ->and($mapped[12])->toBe($assignment->assignment_no)
        ->and($mapped[13])->toBe($assignment->status->label())
        ->and($mapped[14])->toBe($assignment->vessel->name)
        ->and($mapped[15])->toBe($assignment->client->name)
        ->and($mapped[16])->toBe('P2A · Join Standby')
        ->and($mapped[18])->toBe((int) $assignment->id);
});

test('export respects employee visibility scoping', function () {
    Excel::fake();
    Carbon::setTestNow('2026-09-24 10:00:00');
    $fixtures = makeHotelCheckInCheckoutExportFixtures();
    $user = $fixtures['user'];
    $company = $fixtures['company'];

    $deptVisible = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Visible Operations',
        'code' => 'VIS',
        'status' => 'active',
        'include_in_attendance_leave' => true,
    ]);
    $deptHidden = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Hidden Operations',
        'code' => 'HID',
        'status' => 'active',
        'include_in_attendance_leave' => true,
    ]);

    $fixtures['employee']->update(['department_id' => $deptVisible->id]);

    $hiddenEmployee = Employee::factory()->create([
        'company_id' => $company->id,
        'department_id' => $deptHidden->id,
        'name' => 'Hidden Employee',
    ]);
    $hiddenAssignment = CrewAssignment::factory()->forEmployee($hiddenEmployee)->create(['company_id' => $company->id]);
    $hiddenStay = CrewAccommodationStay::query()->create([
        'company_id' => $company->id,
        'crew_assignment_id' => $hiddenAssignment->id,
        'hotel_id' => $fixtures['hotel']->id,
        'stay_type' => CrewAccommodationStayType::PreJoin,
        'accommodation_status' => CrewAccommodationStatus::Hotel,
        'check_in_date' => '2026-09-20',
        'check_out_date' => '2026-09-25',
    ]);

    restrictTestRoleEmployeeVisibility($user, $company, [$deptVisible->id]);

    $this->actingAs($user)
        ->get(route('organization.reports.hotel-checkin-checkout.export', ['format' => 'xlsx']))
        ->assertOk();

    Excel::assertDownloaded(
        'hotel-checkin-checkout-report-2026-09-24.xlsx',
        function (HotelCheckInCheckoutExport $export) use ($fixtures, $hiddenStay): bool {
            $ids = $export->query()->pluck('crew_accommodation_stays.id')->all();

            return in_array($fixtures['stay']->id, $ids, true)
                && ! in_array($hiddenStay->id, $ids, true);
        },
    );
});

test('export enforces tenant company isolation', function () {
    Excel::fake();
    Carbon::setTestNow('2026-09-24 10:00:00');
    $fixturesA = makeHotelCheckInCheckoutExportFixtures();
    $fixturesB = makeHotelCheckInCheckoutExportFixtures();

    $this->actingAs($fixturesA['user'])
        ->get(route('organization.reports.hotel-checkin-checkout.export', ['format' => 'xlsx']))
        ->assertOk();

    Excel::assertDownloaded(
        'hotel-checkin-checkout-report-2026-09-24.xlsx',
        function (HotelCheckInCheckoutExport $export) use ($fixturesA, $fixturesB): bool {
            $ids = $export->query()->pluck('crew_accommodation_stays.id')->all();

            return in_array($fixturesA['stay']->id, $ids, true)
                && ! in_array($fixturesB['stay']->id, $ids, true);
        },
    );
});
