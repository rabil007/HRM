# Dashboard Specification & Architecture

The operational dashboard is the main landing page at `/dashboard`. It provides company-scoped analytics dynamically composed based on the user's existing permissions without requiring any synthetic `dashboard.view` permission.

## Access & Permissions

- **Route:** `GET /dashboard` (`dashboard`)
- **Middleware:** `auth`, `verified`
- **Scoping:** Data is strictly scoped to the active company (`current_company_id`).
- **Permission Model:** Accessible to every authenticated user. There is **no** `dashboard.view` permission.
- **Composition:** Data payload and UI sections are dynamically gated based on existing company-team permissions:
  - `personal_summary` & `can`: Always returned (no permission required).
  - `personal_dashboard`: Self-service employee portal (attendance, leave balances, expiring docs, recipient announcements, payslips) linked to the user's employee record.
  - `attention_items`: Permission-aware alert items for items needing action.
  - `employee_analytics` & `organization_snapshot`: Requires `employees.view`.
  - `document_compliance` & `document_health`: Requires `documents.view`.
  - `attendance_analytics`: Requires `attendance.overview.view`.
  - `leave_summary`: Requires `attendance.leave-requests.view`.
  - `contracts_summary`: Requires `contracts.view`.
  - `training_summary`: Requires `training.view`.
  - `bank_accounts_summary`: Requires `bank_accounts.view`.
  - `crew_summary`: Requires `crew_operations.overview.view`.
  - `payroll_summary`: Requires `payroll.overview.view`.
  - `announcements_summary`: Requires `announcements.view`.
  - `audit_summary`: Requires `audit.view`.
  - **Deferred Props:** Heavy workforce trends (`workforce_trends`), department distribution (`employees_by_department`), branch distribution (`employees_by_branch`), and recent hires (`recent_hires`) use `Inertia::defer('secondary')` and are strictly registered when the user holds `employees.view`.

## Architecture & Analytics Services

- **Controller:** `App\Http\Controllers\Organization\DashboardController` (Invokable)
- **Composer:** `App\Support\Dashboard\DashboardComposer` — evaluates user permissions and composes Inertia props.
- **Analytics Service:** `App\Support\Dashboard\DashboardAnalytics`
  - Split into independent, cached methods (`workforceSummary`, `organizationSummary`, `documentSummary`, `attendanceSummary`, `leaveSummary`, `contractsSummary`, `trainingSummary`, `bankAccountsSummary`, `payrollSummary`, `crewSummary`, `announcementsSummary`, `auditSummary`, `attentionCentre`, `personalDashboard`).
  - Implements company & user cache keys: `dashboard.company.{companyId}.user.{userId}.{part}` with automatic invalidation via `DashboardAnalytics::forgetCompany($companyId)`.
- **Metrics Calculation Rules:**
  - **Hiring Metrics:** Driven by official `hire_date` (not record `created_at`).
  - **Headcount Trends:** Baseline active headcount + cumulative monthly hires (`hire_date`) - monthly terminations (`termination_date`).
  - **Attendance Metrics:** Counts distinct `employee_id`s for `present_today`, `late_today`, `absent_today`, `check_ins_today`, `check_outs_today` on today's date, while exposing `attendance_events_today` for total event count.
  - **Document Validity:** Terminology uses `uploaded_document_validity` / "Uploaded Document Validity" rate based on non-expired documents out of total uploaded documents.
  - **Distributions:** `employeesByDepartment` (top 6 + "Other") and `employeesByBranch` (top 4 + "Other") aggregate small values into "Other" so totals match total active workforce.
  - **Active vs historical employees:** Current operational metrics (document compliance, attendance today, on-leave today, bank/training/crew pulse, missing contracts) count **active** employees only. Workforce **trends** remain hire/termination history and are not converted to active-only snapshots. See [Active employee visibility](./architecture/active-employee-visibility.md).

## Frontend Architecture

- **Entry Point:** `resources/js/pages/dashboard.tsx`
- **Feature Modules:** `resources/js/features/dashboard/`
  - `dashboard-content.tsx`: Main dashboard orchestrator component.
  - `dashboard-types.ts`: Comprehensive TypeScript interfaces for all props and section payload data.
  - `components/`: `dashboard-header.tsx`, `quick-actions.tsx`, `attention-center.tsx`, `dashboard-metric-card.tsx`, `dashboard-section.tsx`.
  - `sections/`: `personal-section.tsx`, `workforce-section.tsx`, `attendance-section.tsx`, `compliance-section.tsx`, `leave-section.tsx`, `contracts-section.tsx`, `training-section.tsx`, `bank-section.tsx`, `payroll-section.tsx`, `crew-section.tsx`, `announcements-section.tsx`, `activity-section.tsx`.
  - `charts/`: Recharts lazy-loaded visualization charts (`workforce-trend-chart.tsx`, `distribution-bar-chart.tsx`, `document-health-chart.tsx`, `attendance-trend-chart.tsx`).
- **Polling & Refresh:**
  - Automatically reloads active dashboard sections every 60 seconds (`router.reload`).
  - Displays `"Updated automatically"` status badge with live timestamp and manual refresh control.
