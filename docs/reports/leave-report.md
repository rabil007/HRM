# Leave Report

Leave Report is a read-only **historical** HR reporting view for company leave request history.

- **My Leave** and **Leave Approvals** remain the operational workflows for submitting and deciding leave.
- Leave Report reports and exports historical leave requests without changing balances, approval logic, or notifications.

## Source of truth

Each row represents one `LeaveRequest` in the active company. Soft-deleted or administratively deleted requests are excluded from the normal report query.

Department values come from the employee's **current** organizational assignment. They are not snapshotted at the time the leave was taken.

## Historical filters

Because Leave Report is historical:

- **Employees** who are now inactive, terminated, or otherwise not active may still appear in the report and in the Employee filter when they have non-deleted leave history in the company.
- **Leave Types** that are now inactive may still appear in the report and in the Leave Type filter when they are referenced by existing non-deleted leave requests.
- Unused inactive leave types and employees without any leave history are not listed in filter options.

The department filter is **department-only** (positions are not selectable in this report).

## Permissions

- `reports.leave.view` — view the report, filters, summary cards, and paginated table within the user's employee visibility scope.
- `reports.leave.export` — export the same filtered dataset to Excel or CSV.

Both routes enforce their permission independently. Company scoping is always applied.

## Employee visibility

The report query, summary cards, filter employee/department/leave-type options, department tree counts, and export all use the same employee visibility rules as the rest of OMS-HRM (`EmployeeVisibilityScope`). Users restricted to selected departments cannot discover employees outside that scope through rows, counts, filters, or exports.

The authenticated user is always required for report queries; there is no unscoped read path for normal HTTP access.

## Filters

| Filter | Behavior |
| --- | --- |
| Search | Employee name or employee number |
| Leave period | **Date overlap** — `start_date <= leave_to` and `end_date >= leave_from` |
| Department | Employee's current department (department tree only) |
| Leave type | Exact leave type (includes historically used inactive types) |
| Employee | Exact employee (includes employees with leave history, regardless of current active status) |
| Status | `pending`, `approved`, `rejected`, `cancelled` |
| Submitted | `created_at` date range |
| Decided | `decided_at` date range |

Default sort: `start_date desc`.

## Summary cards

Summary counts are calculated from the same filtered, visibility-scoped query as the table:

- **Total Requests** — matching leave requests
- **Approved** — matching requests with status `approved`
- **Pending** — matching requests with status `pending`
- **Approved Leave Days** — sum of `total_days` for approved matching requests
- **Employees Taking Leave** — distinct employees with approved matching requests

## Privacy

The default table and export do **not** include leave reason, attachments, or private approval comments.

## Export

Excel (`.xlsx`) and CSV exports use the same query, filters, employee visibility, tenancy, and ordering as the on-screen report. Exports include all matching records, not only the current page.

Export columns:

- Employee No, Employee Name, Department, Leave Type, Leave From, Leave To, Total Days, Status, Submitted At, Decided At, Decided By

Filenames: `leave-report-YYYY-MM-DD.xlsx` and `leave-report-YYYY-MM-DD.csv`.
