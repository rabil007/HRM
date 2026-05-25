# Dashboard

The dashboard is the post-login landing page at `/dashboard`. It provides company-scoped HR and document compliance analytics.

## Access

- Route: `GET /dashboard` (`dashboard`)
- Middleware: `auth`, `verified`
- Data is scoped to the **active company** (`SetCurrentCompany` middleware)

## Backend

- Controller: `App\Http\Controllers\Organization\DashboardController`
- Service: `App\Support\Dashboard\DashboardAnalytics`
- Reuses `DocumentBrowseQuery::expirySummary()` for document expiry counts

## Metrics provided

### Employee analytics

- Total, active, inactive, on leave, terminated
- New hires this month
- Employees with / without linked user accounts

### Document compliance

- Total documents
- Expired, expiring in 30 / 15 / 7 days
- Uploads this month
- Compliance rate (non-expired ÷ total, when expiry is tracked)
- Average documents per employee

### Charts and breakdowns

- **Workforce trends** — last 6 months: headcount, new hires, document uploads
- **Employees by department** — distribution chart
- **Employees by branch** — distribution chart
- **Document health** — visual breakdown of expiry buckets
- **Organization snapshot** — department and branch counts
- **Recent hires** — latest employees (limited list)

## Frontend

- Page: `resources/js/pages/dashboard.tsx`
- Feature UI: `resources/js/features/dashboard/`
- Charts: Recharts with shared tooltip/styling patterns

## Navigation

Summary cards on the documents index (`/organization/documents`) use the same expiry buckets and link into filtered compliance views—complementary to dashboard document health, not a duplicate page.

## Permissions

Dashboard visibility is not gated by a dedicated permission today; authenticated users with company access see company-scoped data. Individual modules (employees, documents) remain permission-gated in the sidebar and routes.
