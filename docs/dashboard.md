# Dashboard

The operational dashboard is the main landing page at `/dashboard`. It provides company-scoped analytics dynamically composed based on the user's existing permissions.

## Access & Permissions

- **Route:** `GET /dashboard` (`dashboard`)
- **Middleware:** `auth`, `verified`
- **Scoping:** Data is strictly scoped to the active company (`current_company_id`).
- **Permission Model:** Accessible to every authenticated user. There is **no** `dashboard.view` permission.
- **Composition:** Data payload and UI sections are dynamically gated based on existing company-team permissions:
  - `personal_summary` & `can`: Always returned (no permission required).
  - `employee_analytics`, `organization_snapshot`, `attendance_analytics`, and deferred trends: Requires `employees.view` (or `attendance.overview.view` for attendance).
  - `document_compliance` & `document_health`: Requires `documents.view`.
  - `crew_summary`: Requires `crew_operations.overview.view`.
  - `payroll_summary`: Requires `payroll.overview.view`.
  - `announcements_summary`: Requires `announcements.view`.

## Architecture

- **Controller:** `App\Http\Controllers\Organization\DashboardController` (Invokable)
- **Composer:** `App\Support\Dashboard\DashboardComposer` — checks permissions before querying analytics engines and composing Inertia props.
- **Analytics Services:**
  - `App\Support\Dashboard\DashboardAnalytics` — core workforce and document metrics.
  - `App\Support\CrewOperations\CrewOperationsDashboardAnalytics` — crew deployment summary.
  - `App\Support\Payroll\PayrollOverviewSummary` — payroll draft/processing periods and last paid summaries.
- **Personal Fallback:** `App\Support\Dashboard\DashboardPersonalSummary` — lightweight greeting when a user holds no module view permissions.
- **Deferred Props:** Heavy workforce trends and distribution breakdowns use `Inertia::defer('secondary')` and are only registered when the user holds `employees.view`.

## Metrics & Modules

### Personal & Capabilities (Universal)
- User name, company name, date greeting.
- Action flags: `employees_create`, `employees_export`, `documents_upload`, `view_audit`.

### Workforce Overview (`employees.view`)
- Total, active, new hires this month, on leave / inactive counts.
- Linked user account status, department counts, branch counts.
- **Deferred:** 6-month workforce trend, department distribution, branch distribution, recent hires list.

### Document Compliance (`documents.view`)
- Total documents, compliance rate, average per employee.
- Expired, expiring within 7/15/30 days, uploaded this month.
- Expiry health pie chart.

### Attendance Today (`attendance.overview.view` / `employees.view`)
- Present today, check-ins today, check-outs today, late today.
- 7-day attendance trend chart and recent attendance records feed.

### Crew Operations (`crew_operations.overview.view`)
- On vessel, in home, needs update alert count, total active crew.

### Payroll Summary (`payroll.overview.view`)
- Draft periods, processing (awaiting approval) periods, last paid period name and total payout.

### Announcements (`announcements.view`)
- Total published announcements and 5 most recent published announcements feed.

## Frontend

- **Entry Point:** `resources/js/pages/dashboard.tsx`
- **Feature Component:** `resources/js/features/dashboard/index.tsx`
- **Types:** `resources/js/features/dashboard/dashboard-types.ts`
- **Polling:** Automatically polls active sections every 60 seconds (`usePoll`).
- **Charts:** Lazy-loaded Recharts components (`AttendanceTrendChart`, `WorkforceTrendChart`, `DistributionBarChart`, `DocumentHealthChart`).
