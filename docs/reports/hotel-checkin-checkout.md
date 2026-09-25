# Hotel Check-In & Check-Out Report

Hotel Check-In & Check-Out is the operational report for crew hotel accommodation across Crew Assignments.

- **Crew Assignments** manages crew movement lifecycles and linked accommodation periods.
- **Hotel Check-In & Check-Out Report** displays and exports authoritative hotel stays, occupancy status, and stay duration.

## Source of Truth

Each row represents one `CrewAccommodationStay` record where `accommodation_status = 'hotel'`.

- This is a stay-oriented report, **not** an assignment-oriented report (`1 stay = 1 row`). An employee with multiple stays (e.g. Pre-Join stay and Post-Sign-Off stay) appears as separate report rows.
- The report focuses strictly on actual hotel accommodation. Explicit `no_accommodation` records are excluded by default.
- Hotel stays are never inferred from assignment phases; only authoritative `CrewAccommodationStay` rows are shown.

## Route and Navigation

- **URL**: `/organization/reports/hotel-checkin-checkout`
- **Named Routes**:
  - `organization.reports.hotel-checkin-checkout.index`
  - `organization.reports.hotel-checkin-checkout.export`
- **Sidebar / Navigation**: Under **Crew Operations** as "Hotel Stays" with the `Hotel` icon (links to the full "Hotel Check-In & Check-Out" report).

## Authorization & Permissions

Access is enforced exclusively on the backend:

- `reports.hotel_checkin_checkout.view` (Definition ID: 324) — required to view the report page and search/filter.
- `reports.hotel_checkin_checkout.export` (Definition ID: 323) — required to export data to Excel or CSV.
- Granted by default to roles holding `crew_operations.assignments.view` via migration `2026_09_24_190000_add_hotel_checkin_checkout_report_permissions.php`.

## Multi-Tenancy & Employee Visibility

- **Company Scoping**: Every query is strictly isolated by `company_id = current_company_id`. Related entities (`Hotel`, `RoomType`, `CrewAssignment`, `Employee`) must belong to the active company.
- **Employee Visibility**: `EmployeeVisibilityScope::whereHas($query, $user, $companyId, 'assignment.employee')` is applied across the report index, summary card counts, and exports. Users can never view or export stays for crew members outside their allowed department scope.

## Operational Stay Status Semantics

The report derives a truthful operational stay status based on company-local calendar boundaries (`today` evaluated in `CompanyTimezone`):

| Status | Code | Condition |
|---|---|---|
| Checked Out | `checked_out` | `check_out_date < today` |
| Checking Out Today | `checking_out_today` | `check_out_date = today` |
| Upcoming | `upcoming` | `check_in_date > today` |
| Check-In Today | `check_in_today` | `check_in_date = today` and (`check_out_date IS NULL` or `check_out_date >= today`) |
| Currently Checked In | `currently_checked_in` | `check_in_date <= today` and (`check_out_date IS NULL` or `check_out_date > today`) |

*Note*: Because `CrewAccommodationStay` does not persist an expected checkout date, no speculative "Overdue Checkout" status is fabricated for open stays. Stays with `check_out_date = null` are displayed as **Open** and evaluated as currently checked in once the check-in date is reached.

## Stay Days Calculation

- Uses the authoritative `CrewAccommodationService::calculateStayDays($checkIn, $checkOut, $timezone)` logic.
- Completed stays calculate days between check-in and check-out.
- Open stays calculate days from check-in through company-local today.

## Filters & Summary Cards

### Summary Cards

1. **Total Records** (active filter scope)
2. **Currently Checked In**
3. **Check-In Today**
4. **Checking Out Today**
5. **Upcoming Check-Ins**
6. **Checked Out**

Clicking any summary card toggles the corresponding `stay_status` filter.

### Filters

- **Search**: Matches employee name, employee number, hotel name, room type name, assignment number, or vessel name.
- **Hotel**: Active company hotels.
- **Room Type**: Active room types (scoped to selected hotel if chosen).
- **Stay Type**: `pre_join` ("Pre-Join") or `post_signoff` ("Post-Sign-Off").
- **Stay Status**: `currently_checked_in`, `check_in_today`, `checking_out_today`, `upcoming`, `checked_out`.
- **Check-In Dates**: Check-In From and Check-In To.
- **Check-Out Dates**: Check-Out From and Check-Out To.
- **Vessel**: Company vessels.
- **Rank**: Active ranks.
- **Client**: Company clients.

## Sorting Whitelist

Default sort: `check_in` DESC, `id` DESC.

Whitelisted sort fields:
- `check_in`: Check-in date
- `check_out`: Check-out date
- `hotel`: Hotel name (correlated subquery)
- `employee`: Employee name (correlated subquery)
- `vessel`: Vessel name (correlated subquery)
- `stay_days`: Stay duration expression (MySQL `DATEDIFF`, SQLite `julianday`)

## Export

Excel (`.xlsx`) and CSV (`.csv`) export formats via `HotelCheckInCheckoutExport` stream results with identical filters, permissions, and tenant/visibility scoping.

Export columns:
1. Stay Record ID
2. Employee No.
3. Employee Name
4. Rank
5. Hotel
6. Room Type
7. Stay Type
8. Accommodation Status
9. Check-In
10. Check-Out
11. Stay Status
12. Stay Days
13. Assignment No.
14. Assignment Status
15. Vessel
16. Client
17. Starting Checkpoint
18. Current Crew Phase
19. Assignment Record ID
