# Leave Report

Leave Report is a read-only HR reporting view for company leave request history.

- **My Leave** and **Leave Approvals** remain the operational workflows for submitting and deciding leave.
- Leave Report reports and exports historical leave requests without changing balances, approval logic, or notifications.

## Source of truth

Each row represents one `LeaveRequest` in the active company. Soft-deleted or administratively deleted requests are excluded from the normal report query.

Department values come from the employee's **current** organizational assignment. They are not snapshotted at the time the leave was taken.

## Permissions

- `reports.leave.view` — view the report, filters, summary cards, and paginated table within the user's employee visibility scope.
- `reports.leave.export` — export the same filtered dataset to Excel or CSV.

Both routes enforce their permission independently. Company scoping is always applied.

## Employee visibility

The report query, summary cards, filter employee/department options, and export all use the same employee visibility rules as the rest of OMS-HRM (`EmployeeVisibilityScope`). Users restricted to selected departments cannot discover employees outside that scope through rows, counts, filters, or exports.

## Filters

| Filter | Behavior |
| --- | --- |
| Search | Employee name or employee number |
| Leave period | **Date overlap** — `start_date <= leave_to` and `end_date >= leave_from` |
| Employee | Exact employee |
| Leave type | Exact leave type |
| Status | `pending`, `approved`, `rejected`, `cancelled` |
| Department | Employee's current department |
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

Filenames: `leave-report-YYYY-MM-DD.xlsx` and `leave-report-YYYY-MM-DD.csv`.
