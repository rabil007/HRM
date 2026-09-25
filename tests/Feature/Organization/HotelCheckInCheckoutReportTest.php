<?php

use App\Enums\CrewAccommodationStatus;
use App\Enums\CrewAccommodationStayType;
use App\Enums\CrewPhaseCode;
use App\Enums\CrewPhaseStatus;
use App\Models\CrewAccommodationStay;
use App\Models\CrewAssignment;
use App\Models\CrewAssignmentPhase;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Hotel;
use App\Models\RoomType;
use App\Support\Reports\HotelCheckInCheckoutFilters;
use App\Support\Reports\HotelCheckInCheckoutQuery;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

function authorizeHotelCheckInCheckoutReport(): array
{
    $fixtures = makeCrewAssignmentFixtures();
    $fixtures['user']->update(['current_company_id' => $fixtures['company']->id]);
    grantCompanyPermissions($fixtures['user'], $fixtures['company'], [
        'reports.hotel_checkin_checkout.view',
        'reports.hotel_checkin_checkout.export',
    ]);

    return $fixtures;
}

test('hotel check-in & check-out report requires authentication and view permission', function () {
    $this->get(route('organization.reports.hotel-checkin-checkout.index'))
        ->assertRedirect(route('login'));

    ['user' => $user, 'company' => $company] = makeCrewAssignmentFixtures();
    $user->update(['current_company_id' => $company->id]);
    grantCompanyPermissions($user, $company, ['employees.view']);

    $this->actingAs($user)
        ->get(route('organization.reports.hotel-checkin-checkout.index'))
        ->assertForbidden();
});

test('basic listing returns stay with employee, hotel, stay type, dates, assignment, and vessel', function () {
    CarbonImmutable::setTestNow('2026-09-24 10:00:00');
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = authorizeHotelCheckInCheckoutReport();

    $hotel = Hotel::factory()->create(['company_id' => $company->id, 'name' => 'Royal Hotel']);
    $roomType = RoomType::factory()->create(['company_id' => $company->id, 'hotel_id' => $hotel->id, 'name' => 'Deluxe Suite']);

    $assignment = CrewAssignment::factory()
        ->forEmployee($employee)
        ->create([
            'company_id' => $company->id,
            'assignment_no' => 'CA-HOTEL-001',
            'rank_id' => $rank->id,
        ]);

    $phase = CrewAssignmentPhase::factory()->create([
        'company_id' => $company->id,
        'crew_assignment_id' => $assignment->id,
        'phase_code' => CrewPhaseCode::JoinStandby,
        'status' => CrewPhaseStatus::Active,
        'sequence' => 1,
    ]);

    $stay = CrewAccommodationStay::query()->create([
        'company_id' => $company->id,
        'crew_assignment_id' => $assignment->id,
        'hotel_id' => $hotel->id,
        'room_type_id' => $roomType->id,
        'stay_type' => CrewAccommodationStayType::PreJoin,
        'accommodation_status' => CrewAccommodationStatus::Hotel,
        'check_in_date' => '2026-09-20',
        'check_out_date' => '2026-09-26',
        'started_from_phase_id' => $phase->id,
    ]);

    $this->actingAs($user)
        ->get(route('organization.reports.hotel-checkin-checkout.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/reports/hotel-checkin-checkout/index')
            ->has('stays', 1)
            ->where('stays.0.id', $stay->id)
            ->where('stays.0.employee.id', $employee->id)
            ->where('stays.0.employee.name', $employee->name)
            ->where('stays.0.hotel.name', 'Royal Hotel')
            ->where('stays.0.room_type.name', 'Deluxe Suite')
            ->where('stays.0.stay_type', 'pre_join')
            ->where('stays.0.stay_type_label', 'Pre-Join')
            ->where('stays.0.check_in_date', '2026-09-20')
            ->where('stays.0.check_out_date', '2026-09-26')
            ->where('stays.0.stay_status', 'currently_checked_in')
            ->where('stays.0.stay_days', 6)
            ->where('stays.0.assignment.assignment_no', 'CA-HOTEL-001')
            ->where('stays.0.assignment.vessel_name', $assignment->vessel->name)
            ->where('stays.0.starting_checkpoint', 'P2A · Join Standby')
            ->where('summary.total', 1)
            ->where('summary.currently_checked_in', 1)
            ->where('can.export', true));
});

test('open stay with null checkout displays open and currently checked in', function () {
    CarbonImmutable::setTestNow('2026-09-24 10:00:00');
    ['user' => $user, 'company' => $company, 'employee' => $employee] = authorizeHotelCheckInCheckoutReport();

    $hotel = Hotel::factory()->create(['company_id' => $company->id]);
    $assignment = CrewAssignment::factory()->forEmployee($employee)->create(['company_id' => $company->id]);

    $stay = CrewAccommodationStay::query()->create([
        'company_id' => $company->id,
        'crew_assignment_id' => $assignment->id,
        'hotel_id' => $hotel->id,
        'stay_type' => CrewAccommodationStayType::PreJoin,
        'accommodation_status' => CrewAccommodationStatus::Hotel,
        'check_in_date' => '2026-09-20',
        'check_out_date' => null,
    ]);

    $this->actingAs($user)
        ->get(route('organization.reports.hotel-checkin-checkout.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('stays.0.id', $stay->id)
            ->where('stays.0.check_out_date', null)
            ->where('stays.0.is_open', true)
            ->where('stays.0.stay_status', 'currently_checked_in')
            ->where('stays.0.stay_status_label', 'Currently Checked In')
            ->where('stays.0.stay_days', 4));
});

test('operational stay status accurately derives check-in today, checking out today, and upcoming', function () {
    CarbonImmutable::setTestNow('2026-09-24 10:00:00');
    ['user' => $user, 'company' => $company, 'employee' => $employee] = authorizeHotelCheckInCheckoutReport();

    $hotel = Hotel::factory()->create(['company_id' => $company->id]);
    $assignment = CrewAssignment::factory()->forEmployee($employee)->create(['company_id' => $company->id]);

    // Check-in today
    $checkInToday = CrewAccommodationStay::query()->create([
        'company_id' => $company->id,
        'crew_assignment_id' => $assignment->id,
        'hotel_id' => $hotel->id,
        'stay_type' => CrewAccommodationStayType::PreJoin,
        'accommodation_status' => CrewAccommodationStatus::Hotel,
        'check_in_date' => '2026-09-24',
        'check_out_date' => '2026-09-27',
    ]);

    // Checking out today
    $checkOutToday = CrewAccommodationStay::query()->create([
        'company_id' => $company->id,
        'crew_assignment_id' => $assignment->id,
        'hotel_id' => $hotel->id,
        'stay_type' => CrewAccommodationStayType::PostSignoff,
        'accommodation_status' => CrewAccommodationStatus::Hotel,
        'check_in_date' => '2026-09-21',
        'check_out_date' => '2026-09-24',
    ]);

    // Upcoming
    $upcoming = CrewAccommodationStay::query()->create([
        'company_id' => $company->id,
        'crew_assignment_id' => $assignment->id,
        'hotel_id' => $hotel->id,
        'stay_type' => CrewAccommodationStayType::PreJoin,
        'accommodation_status' => CrewAccommodationStatus::Hotel,
        'check_in_date' => '2026-09-28',
        'check_out_date' => '2026-09-30',
    ]);

    // Checked out (in the past)
    $checkedOut = CrewAccommodationStay::query()->create([
        'company_id' => $company->id,
        'crew_assignment_id' => $assignment->id,
        'hotel_id' => $hotel->id,
        'stay_type' => CrewAccommodationStayType::PreJoin,
        'accommodation_status' => CrewAccommodationStatus::Hotel,
        'check_in_date' => '2026-09-10',
        'check_out_date' => '2026-09-15',
    ]);

    $this->actingAs($user)
        ->get(route('organization.reports.hotel-checkin-checkout.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('stays', 4)
            ->where('summary.total', 4)
            ->where('summary.currently_checked_in', 1)
            ->where('summary.check_in_today', 1)
            ->where('summary.checking_out_today', 1)
            ->where('summary.upcoming', 1)
            ->where('summary.checked_out', 1));

    // Test filter stay_status=check_in_today
    $this->actingAs($user)
        ->get(route('organization.reports.hotel-checkin-checkout.index', ['stay_status' => 'check_in_today']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('stays', 1)
            ->where('stays.0.id', $checkInToday->id)
            ->where('stays.0.stay_status', 'check_in_today'));

    // Test filter stay_status=checking_out_today
    $this->actingAs($user)
        ->get(route('organization.reports.hotel-checkin-checkout.index', ['stay_status' => 'checking_out_today']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('stays', 1)
            ->where('stays.0.id', $checkOutToday->id)
            ->where('stays.0.stay_status', 'checking_out_today'));

    // Test filter stay_status=upcoming
    $this->actingAs($user)
        ->get(route('organization.reports.hotel-checkin-checkout.index', ['stay_status' => 'upcoming']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('stays', 1)
            ->where('stays.0.id', $upcoming->id)
            ->where('stays.0.stay_status', 'upcoming'));

    // Test filter stay_status=checked_out
    $this->actingAs($user)
        ->get(route('organization.reports.hotel-checkin-checkout.index', ['stay_status' => 'checked_out']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('stays', 1)
            ->where('stays.0.id', $checkedOut->id)
            ->where('stays.0.stay_status', 'checked_out'));
});

test('stays can be filtered by hotel and stay type', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee] = authorizeHotelCheckInCheckoutReport();

    $hotelA = Hotel::factory()->create(['company_id' => $company->id, 'name' => 'Hotel Alpha']);
    $hotelB = Hotel::factory()->create(['company_id' => $company->id, 'name' => 'Hotel Beta']);

    $assignment = CrewAssignment::factory()->forEmployee($employee)->create(['company_id' => $company->id]);

    $stayPreJoinA = CrewAccommodationStay::query()->create([
        'company_id' => $company->id,
        'crew_assignment_id' => $assignment->id,
        'hotel_id' => $hotelA->id,
        'stay_type' => CrewAccommodationStayType::PreJoin,
        'accommodation_status' => CrewAccommodationStatus::Hotel,
        'check_in_date' => '2026-09-20',
        'check_out_date' => '2026-09-22',
    ]);

    $stayPostSignoffB = CrewAccommodationStay::query()->create([
        'company_id' => $company->id,
        'crew_assignment_id' => $assignment->id,
        'hotel_id' => $hotelB->id,
        'stay_type' => CrewAccommodationStayType::PostSignoff,
        'accommodation_status' => CrewAccommodationStatus::Hotel,
        'check_in_date' => '2026-09-23',
        'check_out_date' => '2026-09-25',
    ]);

    // Filter Hotel A
    $this->actingAs($user)
        ->get(route('organization.reports.hotel-checkin-checkout.index', ['hotel_id' => $hotelA->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('stays', 1)
            ->where('stays.0.id', $stayPreJoinA->id));

    // Filter Stay Type post_signoff
    $this->actingAs($user)
        ->get(route('organization.reports.hotel-checkin-checkout.index', ['stay_type' => 'post_signoff']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('stays', 1)
            ->where('stays.0.id', $stayPostSignoffB->id));
});

test('stays can be filtered by check-in and check-out date ranges', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee] = authorizeHotelCheckInCheckoutReport();
    $hotel = Hotel::factory()->create(['company_id' => $company->id]);
    $assignment = CrewAssignment::factory()->forEmployee($employee)->create(['company_id' => $company->id]);

    $stay1 = CrewAccommodationStay::query()->create([
        'company_id' => $company->id,
        'crew_assignment_id' => $assignment->id,
        'hotel_id' => $hotel->id,
        'stay_type' => CrewAccommodationStayType::PreJoin,
        'accommodation_status' => CrewAccommodationStatus::Hotel,
        'check_in_date' => '2026-09-01',
        'check_out_date' => '2026-09-05',
    ]);

    $stay2 = CrewAccommodationStay::query()->create([
        'company_id' => $company->id,
        'crew_assignment_id' => $assignment->id,
        'hotel_id' => $hotel->id,
        'stay_type' => CrewAccommodationStayType::PostSignoff,
        'accommodation_status' => CrewAccommodationStatus::Hotel,
        'check_in_date' => '2026-09-15',
        'check_out_date' => '2026-09-20',
    ]);

    // Check-in from 2026-09-10
    $this->actingAs($user)
        ->get(route('organization.reports.hotel-checkin-checkout.index', ['check_in_from' => '2026-09-10']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('stays', 1)
            ->where('stays.0.id', $stay2->id));

    // Check-out to 2026-09-10
    $this->actingAs($user)
        ->get(route('organization.reports.hotel-checkin-checkout.index', ['check_out_to' => '2026-09-10']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('stays', 1)
            ->where('stays.0.id', $stay1->id));
});

test('search filters by employee name, employee number, hotel name, and assignment number', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee] = authorizeHotelCheckInCheckoutReport();
    $employee->update(['name' => 'Ahmad Mansoor', 'employee_no' => 'EMP-9901']);

    $hotel = Hotel::factory()->create(['company_id' => $company->id, 'name' => 'Grand Millennium']);
    $assignment = CrewAssignment::factory()->forEmployee($employee)->create([
        'company_id' => $company->id,
        'assignment_no' => 'CA-SEARCH-100',
    ]);

    $stay = CrewAccommodationStay::query()->create([
        'company_id' => $company->id,
        'crew_assignment_id' => $assignment->id,
        'hotel_id' => $hotel->id,
        'stay_type' => CrewAccommodationStayType::PreJoin,
        'accommodation_status' => CrewAccommodationStatus::Hotel,
        'check_in_date' => '2026-09-20',
        'check_out_date' => '2026-09-25',
    ]);

    // Search by employee name
    $this->actingAs($user)
        ->get(route('organization.reports.hotel-checkin-checkout.index', ['search' => 'Mansoor']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('stays', 1)->where('stays.0.id', $stay->id));

    // Search by employee number
    $this->actingAs($user)
        ->get(route('organization.reports.hotel-checkin-checkout.index', ['search' => '9901']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('stays', 1)->where('stays.0.id', $stay->id));

    // Search by hotel name
    $this->actingAs($user)
        ->get(route('organization.reports.hotel-checkin-checkout.index', ['search' => 'Millennium']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('stays', 1)->where('stays.0.id', $stay->id));

    // Search by assignment number
    $this->actingAs($user)
        ->get(route('organization.reports.hotel-checkin-checkout.index', ['search' => 'SEARCH-100']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('stays', 1)->where('stays.0.id', $stay->id));
});

test('employee visibility scope prevents unauthorized employee stays from appearing', function () {
    ['user' => $user, 'company' => $company, 'employee' => $visibleEmployee] = authorizeHotelCheckInCheckoutReport();

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

    $visibleEmployee->update(['department_id' => $deptVisible->id]);

    $hiddenEmployee = Employee::factory()->create([
        'company_id' => $company->id,
        'department_id' => $deptHidden->id,
        'name' => 'Hidden Crew Member',
    ]);

    restrictTestRoleEmployeeVisibility($user, $company, [$deptVisible->id]);

    $hotel = Hotel::factory()->create(['company_id' => $company->id]);

    $visibleAssignment = CrewAssignment::factory()->forEmployee($visibleEmployee)->create(['company_id' => $company->id]);
    $hiddenAssignment = CrewAssignment::factory()->forEmployee($hiddenEmployee)->create(['company_id' => $company->id]);

    $visibleStay = CrewAccommodationStay::query()->create([
        'company_id' => $company->id,
        'crew_assignment_id' => $visibleAssignment->id,
        'hotel_id' => $hotel->id,
        'stay_type' => CrewAccommodationStayType::PreJoin,
        'accommodation_status' => CrewAccommodationStatus::Hotel,
        'check_in_date' => '2026-09-20',
        'check_out_date' => '2026-09-25',
    ]);

    CrewAccommodationStay::query()->create([
        'company_id' => $company->id,
        'crew_assignment_id' => $hiddenAssignment->id,
        'hotel_id' => $hotel->id,
        'stay_type' => CrewAccommodationStayType::PreJoin,
        'accommodation_status' => CrewAccommodationStatus::Hotel,
        'check_in_date' => '2026-09-20',
        'check_out_date' => '2026-09-25',
    ]);

    $this->actingAs($user)
        ->get(route('organization.reports.hotel-checkin-checkout.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('stays', 1)
            ->where('stays.0.id', $visibleStay->id)
            ->where('summary.total', 1));
});

test('tenant isolation ensures Company A user never sees Company B accommodation', function () {
    ['user' => $userA, 'company' => $companyA, 'employee' => $employeeA] = authorizeHotelCheckInCheckoutReport();
    ['company' => $companyB, 'employee' => $employeeB] = makeCrewAssignmentFixtures();

    $hotelA = Hotel::factory()->create(['company_id' => $companyA->id, 'name' => 'Hotel Alpha']);
    $hotelB = Hotel::factory()->create(['company_id' => $companyB->id, 'name' => 'Hotel Bravo']);

    $assignmentA = CrewAssignment::factory()->forEmployee($employeeA)->create(['company_id' => $companyA->id]);
    $assignmentB = CrewAssignment::factory()->forEmployee($employeeB)->create(['company_id' => $companyB->id]);

    $stayA = CrewAccommodationStay::query()->create([
        'company_id' => $companyA->id,
        'crew_assignment_id' => $assignmentA->id,
        'hotel_id' => $hotelA->id,
        'stay_type' => CrewAccommodationStayType::PreJoin,
        'accommodation_status' => CrewAccommodationStatus::Hotel,
        'check_in_date' => '2026-09-20',
        'check_out_date' => '2026-09-25',
    ]);

    CrewAccommodationStay::query()->create([
        'company_id' => $companyB->id,
        'crew_assignment_id' => $assignmentB->id,
        'hotel_id' => $hotelB->id,
        'stay_type' => CrewAccommodationStayType::PreJoin,
        'accommodation_status' => CrewAccommodationStatus::Hotel,
        'check_in_date' => '2026-09-20',
        'check_out_date' => '2026-09-25',
    ]);

    $this->actingAs($userA)
        ->get(route('organization.reports.hotel-checkin-checkout.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('stays', 1)
            ->where('stays.0.id', $stayA->id)
            ->where('summary.total', 1)
            ->where('filter_options.hotels', fn ($hotels) => collect($hotels)->pluck('id')->all() === [$hotelA->id]));
});

test('query relationship loading does not scale with row count (N+1 regression)', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = authorizeHotelCheckInCheckoutReport();
    $hotel = Hotel::factory()->create(['company_id' => $company->id]);
    $roomType = RoomType::factory()->create(['company_id' => $company->id, 'hotel_id' => $hotel->id]);

    // Create 2 stays
    for ($i = 1; $i <= 2; $i++) {
        $assignment = CrewAssignment::factory()->forEmployee($employee)->create([
            'company_id' => $company->id,
            'assignment_no' => "CA-N1-A{$i}",
            'rank_id' => $rank->id,
        ]);
        CrewAccommodationStay::query()->create([
            'company_id' => $company->id,
            'crew_assignment_id' => $assignment->id,
            'hotel_id' => $hotel->id,
            'room_type_id' => $roomType->id,
            'stay_type' => CrewAccommodationStayType::PreJoin,
            'accommodation_status' => CrewAccommodationStatus::Hotel,
            'check_in_date' => '2026-09-20',
            'check_out_date' => '2026-09-25',
        ]);
    }

    $filters = new HotelCheckInCheckoutFilters;
    $query = new HotelCheckInCheckoutQuery($company->id, $filters, 'UTC', $user);

    DB::enableQueryLog();
    DB::flushQueryLog();
    $query->paginate(25);
    $queriesForTwo = count(DB::getQueryLog());

    // Create 10 more stays (total 12)
    for ($i = 3; $i <= 12; $i++) {
        $assignment = CrewAssignment::factory()->forEmployee($employee)->create([
            'company_id' => $company->id,
            'assignment_no' => "CA-N1-B{$i}",
            'rank_id' => $rank->id,
        ]);
        CrewAccommodationStay::query()->create([
            'company_id' => $company->id,
            'crew_assignment_id' => $assignment->id,
            'hotel_id' => $hotel->id,
            'room_type_id' => $roomType->id,
            'stay_type' => CrewAccommodationStayType::PreJoin,
            'accommodation_status' => CrewAccommodationStatus::Hotel,
            'check_in_date' => '2026-09-20',
            'check_out_date' => '2026-09-25',
        ]);
    }

    DB::flushQueryLog();
    $query->paginate(25);
    $queriesForTwelve = count(DB::getQueryLog());

    // The number of queries should remain constant
    expect($queriesForTwelve)->toBe($queriesForTwo);
});

test('no_accommodation records are excluded by default and only included when explicitly filtered', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee] = authorizeHotelCheckInCheckoutReport();

    $hotel = Hotel::factory()->create(['company_id' => $company->id, 'name' => 'Seaside Hotel']);
    $assignment = CrewAssignment::factory()->forEmployee($employee)->create(['company_id' => $company->id]);

    $hotelStay = CrewAccommodationStay::query()->create([
        'company_id' => $company->id,
        'crew_assignment_id' => $assignment->id,
        'hotel_id' => $hotel->id,
        'stay_type' => CrewAccommodationStayType::PreJoin,
        'accommodation_status' => CrewAccommodationStatus::Hotel,
        'check_in_date' => '2026-09-20',
        'check_out_date' => '2026-09-25',
    ]);

    $noAccommodationStay = CrewAccommodationStay::query()->create([
        'company_id' => $company->id,
        'crew_assignment_id' => $assignment->id,
        'hotel_id' => null,
        'room_type_id' => null,
        'stay_type' => CrewAccommodationStayType::PreJoin,
        'accommodation_status' => CrewAccommodationStatus::NoAccommodation,
        'check_in_date' => null,
        'check_out_date' => null,
    ]);

    // Default request without accommodation_status filter returns only hotel stays
    $this->actingAs($user)
        ->get(route('organization.reports.hotel-checkin-checkout.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('stays', 1)
            ->where('stays.0.id', $hotelStay->id)
            ->where('summary.total', 1));

    // Explicit request with accommodation_status=no_accommodation returns no_accommodation stay
    $this->actingAs($user)
        ->get(route('organization.reports.hotel-checkin-checkout.index', [
            'accommodation_status' => CrewAccommodationStatus::NoAccommodation->value,
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('stays', 1)
            ->where('stays.0.id', $noAccommodationStay->id)
            ->where('summary.total', 1));
});
