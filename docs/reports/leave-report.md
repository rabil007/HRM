# Leave Report

Leave Report is a read-only **historical** HR reporting view for company leave request history.

- **My Leave** remains the operational workflow for submitting and tracking your own leave.
- **Leave Approvals** is an action queue for the current required pending step assigned to you. It is not a historical browser, and `view_all` does not turn it into an everyone list.
- Leave Report reports and exports historical leave requests, approval progress, and reassignment history without changing balances, approval logic, or notifications.

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

The report query, summary cards, filter employee/department/leave-type options, department tree counts, and export all use `EmployeeVisibilityScope` and `AttendanceLeaveDepartmentScope` (`Department.include_in_attendance_leave`). Users restricted to selected departments cannot discover employees outside that scope through rows, counts, filters, or exports. Departments excluded from Attendance & Leave never appear. Soft-deleted leave types remain filterable when visible historical rows reference them.

The department tree only includes departments the user is allowed to access. Unauthorized department names are not shown with zero counts — those nodes are omitted entirely. Allowed child departments may appear as root nodes when their parent department is not visible.

Department tree counts include inactive and terminated employees when they have valid non-deleted leave history. Soft-deleted leave requests do not contribute to filter options or department tree counts.

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

Summary day totals use the same filtered, visibility-scoped request set as the table. Rejected and cancelled requests do not contribute.

- **Total Leave Days** — approved leave days plus pending leave days
- **Approved Leave Days** — days on approved requests
- **Pending Leave Days** — days on pending requests
- **Annual Leave Days** — approved, pending, and total days for leave types whose reporting category is `annual`
- **Sick Leave Days** — approved, pending, and total days for leave types whose reporting category is `sick`

Annual and Sick totals use `LeaveType.category`. They do not use the editable leave type name, code, or `payroll_treatment`.

The leave-period filter selects overlapping requests and **clips** counted days to the dates inside that period, using the same inclusive day calculation as leave requests. Submitted and decided date filters select requests and do not clip their duration. When no leave period is set, the stored request day total is used.

## Approval history

Leave Approvals is only the current action queue. Leave Report is the historical source for approval progress.

Each row includes structured `approval_progress`, the required approval chain, and reassignment history. FYI / non-required steps are excluded from progress. Pending and waiting steps do not receive an invented action time. Dates use the company timezone.

The Approval column opens the chain:

- step label, approver, status, and action time when the step was acted
- reassignment from/to names, who reassigned, and when

The internal reassignment reason is included only when the viewer has `audit.view`. It is never sent to other viewers. Private approval comments, the leave request reason, and attachments are not part of this report.

## Privacy

The default table and export do **not** include leave reason, attachments, or private approval comments.

## Export

Excel (`.xlsx`) and CSV exports use the same query, filters, employee visibility, tenancy, and ordering as the on-screen report. Exports include all matching records, not only the current page.

Export columns:

- Employee No, Employee Name, Department, Leave Type, Leave Category, Leave From, Leave To, Total Days, Status, Submitted At, Decided At, Decided By, Approval Progress, Current / Waiting Approver, Approval Chain, Reassignment Summary

The approval chain is flattened in sequence, for example `1. Department Manager — Mohamed — Approved — 20 Sep 2026 10:30`. The reassignment summary uses snapshot names, for example `Step 2: Rima → Sara on 21 Sep 2026`. Export does not include private approval comments, the leave reason, attachments, or the internal reassignment reason.

Filenames: `leave-report-YYYY-MM-DD.xlsx` and `leave-report-YYYY-MM-DD.csv`.
