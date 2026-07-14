# OMS-HRM Project Analysis

Architecture analysis derived from the existing codebase (Laravel 13 + Inertia v3 + React 19). Use this as the source of truth for how the application is structured today—not as a target architecture.

**Related docs:** [AI_GUIDE.md](../../AI_GUIDE.md), [AGENTS.md](../../AGENTS.md), [docs/README.md](../README.md)

---

## 1. Architecture Overview

OMS-HRM is a **multi-tenant HR/operations monolith** served by Laravel Herd. Each request runs in a **company context** (`current_company_id`) that scopes data queries and Spatie permissions.

```
Browser
  └── Laravel (Fortify auth, route middleware, Form Requests)
        └── Inertia::render() → React SPA (resources/js)
              └── Wayfinder-generated routes/actions
```

**Key characteristics:**

| Area | Pattern |
|------|---------|
| Data fetching | Server-driven Inertia props; no React Query / TanStack Query |
| Mutations | `useForm`, `<Form>`, or `router.post/put/delete` |
| Authorization | Spatie Permission with company teams; primarily route `can:` middleware, with known gaps documented below |
| Domain logic | `app/Support/` (queries, actions, presenters) |
| Integrations | `app/Services/` (WhatsApp, Hikvision, email, PDF merge) |
| Audit | Spatie Activity Log via `LogsActivityWithCompany` |
| Frontend routing | Wayfinder (`@/routes`, `@/actions`) + hardcoded paths (mixed adoption) |
| Tests | Pest v4; feature tests with `RefreshDatabase` + Inertia assertions |

There is **no REST API layer** (`routes/api.php` is empty). JSON responses exist only for narrow cases (document versions, master-data quick-create, webhooks, downloads).

---

## 2. Directory Structure

### Repository root

```
app/                    Application code
├── Actions/            Fortify user actions
├── Http/
│   ├── Controllers/    Grouped by domain (Organization, Settings, Attendance, Payroll, Hikvision, Public)
│   ├── Middleware/     SetCurrentCompany, HandleInertiaRequests, HandleAppearance
│   └── Requests/       Form requests grouped by domain
├── Models/             Eloquent models + Concerns/
├── Services/           External integrations & app-wide services
├── Support/            Domain queries, actions, presenters
└── Providers/          FortifyServiceProvider, AppServiceProvider

bootstrap/app.php       Middleware stack, 403 Inertia rendering
config/                 fortify, permission, inertia, activitylog, documents, …
database/               migrations, factories, seeders
docs/                   Product & architecture documentation
resources/
├── css/                Tailwind v4 + design-system tokens
└── js/                 React/Inertia frontend
routes/
├── web.php             Main app routes
├── settings.php        Settings hub & master data
└── api.php             Empty
tests/
├── Feature/            Domain feature tests
├── Unit/               Support logic tests
└── Support/            Test helpers (spatie.php, document-fixtures.php, …)
```

### Frontend (`resources/js/`)

```
pages/          Inertia page entrypoints (thin wrappers)
features/       Domain UI modules (fat screens)
components/     Shared UI (data-table, page-header, pagination, …)
  ui/           shadcn/Radix primitives (31 components)
layouts/        app-layout, auth-layout, settings layout
hooks/          Global custom hooks
lib/            utils, toast, design-system tokens, cookies
types/          Global TS types + Inertia module augmentation
actions/        Wayfinder-generated controller actions (do not edit)
routes/         Wayfinder-generated named routes (do not edit)
```

**Page colocation** (complex screens only):

```
pages/organization/
├── _components/    Employee profile tabs, document dialogs
├── _hooks/         use-employee-profile-form, use-template-record-fields
└── _lib/           Form state, template field helpers
```

### Feature module shape (typical)

```
features/organization/branches/
├── index.tsx              # BranchesContent (main screen)
├── types.ts               # Domain TypeScript types
└── components/
    ├── branch-form-sheet.tsx
    ├── branch-delete-dialog.tsx
    ├── branch-filters-sheet.tsx
    └── branch-card.tsx
```

---

## 3. Component Patterns

### Three-tier model

| Layer | Location | Responsibility |
|-------|----------|----------------|
| **Pages** | `resources/js/pages/**` | `<Head>`, prop typing, delegate to feature or render inline |
| **Features** | `resources/js/features/**` | Full CRUD screens, domain dialogs, table rows |
| **Components** | `resources/js/components/**` | Cross-cutting reusable UI |

### Thin page → fat feature (preferred)

```tsx
// pages/organization/employees.tsx
export default function Employees({ employees, pagination, ... }) {
    return (
        <>
            <Head title="Employees" />
            <EmployeesContent employees={employees} ... />
        </>
    );
}
```

### Fat page with colocation (exception)

Employee profile (`pages/organization/employee.tsx`) is large and uses `_components/`, `_hooks/`, `_lib/` because of tab complexity and template-driven fields.

### Naming conventions (frontend)

| Pattern | Example | Use |
|---------|---------|-----|
| `*-content.tsx` | `employees-content.tsx` | Main feature screen body |
| `*-form-sheet.tsx` | `branch-form-sheet.tsx` | Create/edit in right Sheet |
| `*-delete-dialog.tsx` | `branch-delete-dialog.tsx` | Destructive confirm |
| `*-filters-sheet.tsx` | `branch-filters-sheet.tsx` | Filter panel |
| `*-table-row.tsx` | `document-compliance-table-row.tsx` | Domain table row |
| `show.tsx` | `crew-deployments/show.tsx` | Detail/show page |

### Detail/show page layout

Standard pattern used by branch, company, crew deployment, document show:

1. `DocumentsBreadcrumbs` or breadcrumbs
2. `DetailsHeader` — back link, title, permission-gated actions
3. Main content cards (overview, metadata, preview)
4. `RecentActivityCard` when `can_view_audit` (requires `audit.view`)

Reference files:

- `resources/js/pages/organization/branch.tsx`
- `resources/js/pages/organization/crew-deployments/show.tsx`
- `resources/js/pages/organization/documents/show.tsx`

### List page layout

1. `PageHeader` — title, create action, export
2. `SearchBar` — debounced server search
3. `ViewToggle` — grid/table (persisted via `useViewPreference`)
4. `FiltersSheet` — optional filter panel
5. `OrganizationDataTable` or grid of cards
6. `Pagination` — server-side page changes
7. `EmptyState` — zero results

Reference files:

- `resources/js/features/organization/branches/index.tsx`
- `resources/js/features/organization/companies/index.tsx`

---

## 4. API Patterns

### Primary: Inertia server props

Controllers return `Inertia::render('path/to/page', [...])` with typed props. Lists include pagination meta, filters, and permission flags.

```php
return Inertia::render('organization/branches', [
    'branches' => $branches,
    'pagination' => [...],
    'can' => ['create' => $user->can('branches.create'), ...],
]);
```

### Mutations

| Pattern | Where | Example |
|---------|-------|---------|
| Redirect + flash | Most CRUD | `redirect()->route(...)->with('success', '...')` |
| `back()` + flash | Inline updates | `back()->with('success', '...')` |
| Partial reload | Document/profile edits | `only: ['document']` on frontend |

### Secondary: JSON endpoints (rare)

- Document versions: `EmployeeDocumentController::versions()` → consumed by `useHttp()`
- Master-data quick-create: `ReturnsQuickCreateJson` trait when `wantsJson()`
- Webhooks: WhatsApp, Hikvision
- File downloads: `StreamedResponse`

### No React Query

There is no client-side query cache. Data refreshes via:

- Full Inertia visit on navigation
- `router.get()` with `preserveState` for filters/pagination
- `only: [...]` partial reloads after mutations
- `useHttp()` for isolated XHR (2FA setup, legacy version sheet)

### Backend domain layer

| Location | Role | Examples |
|----------|------|----------|
| `app/Support/` | Queries, actions, array mappers | `DocumentBrowseQuery`, `EmployeeDirectoryQuery`, `CreateEmployee` |
| `app/Support/*/Resources/` | Static `toArray()` mappers (not Laravel API Resources) | `EmployeeListResource` |
| `app/Services/` | Integrations, cross-cutting | `WhatsAppService`, `SettingService`, `DocumentMergeService` |

### Wayfinder (frontend route typing)

Generated at build time by `@laravel/vite-plugin-wayfinder`:

```tsx
import { employees } from '@/routes/organization';
import * as EmployeeDocumentController from '@/actions/App/Http/Controllers/Organization/EmployeeDocumentController';

employees.url({ employee: id });
EmployeeDocumentController.destroy.url({ employee, document });
```

**Adoption is partial** — newer code (auth, documents) uses Wayfinder; some org CRUD lists still use hardcoded paths like `'/organization/branches'`.

---

## 5. Permission Patterns

### Backend

1. **`SetCurrentCompany` middleware** sets `current_company_id` and Spatie team ID.
2. **Capability middleware** is the dominant enforcement pattern: `->middleware('can:documents.view')`.
3. **No Eloquent policies currently exist** — there is no `app/Policies/` directory. This describes the present implementation, not a prohibition against policies.
4. **Form Request `authorize()` is part of the authorization boundary.** It may delegate to a permission/policy check. Returning only `(bool) $this->user()` is safe only when the exact action is independently protected before controller execution; new code should make that dependency explicit and add a forbidden-access test.

### Known authorization gaps

The codebase does **not** currently protect every authenticated route with a capability permission. At the time of this analysis, examples include the application log viewer, queue/job controls, database viewer, attendance overview, some crew-operations surfaces, and several payroll/timesheet/salary-input/payslip endpoints. These are current exceptions and security debt—not patterns to copy.

For new or changed privileged endpoints:

- require the narrowest seeded permission at the route, Form Request, controller, gate, or policy layer;
- enforce tenant ownership separately from capability checks;
- add tests proving an authenticated user without the permission receives `403`;
- never rely on frontend `can` flags or hidden buttons as enforcement.

Permissions use dot notation seeded in `database/seeders/PermissionsSeeder.php`:

```
employees.view
documents.upload
settings.master-data.genders.create
audit.view
```

### Shared to frontend

`HandleInertiaRequests` shares:

```typescript
auth.permissions: string[]   // current company scope
auth.roles: string[]
company_switcher_companies
current_company_id
flash: { success?, error?, info? }
```

### Frontend permission checks

| Method | When to use |
|--------|-------------|
| Server `can` prop on page | Module-specific flags (`DocumentPagePermissions::for()`) |
| `useHasPermission('permission.name')` | Nav, settings, cross-cutting UI |
| `useSettingsMasterDataCan('genders')` | Master data CRUD buttons |
| Route middleware | Authoritative — UI hiding is not security |

Example:

```tsx
const canCreate = useHasPermission('branches.create');
{can.create ? <Button onClick={openSheet}>Create</Button> : null}
```

### Audit permission

`audit.view` gates:

- `/organization/activity-logs` page
- `RecentActivityCard` on detail pages
- `RecentActivityQuery::for()` returns `[]` without permission

---

## 6. State Management Approach

**No global store** (no Redux, Zustand, Jotai).

| Mechanism | Usage |
|-----------|-------|
| `useForm` | Primary CRUD forms |
| `<Form>` (Inertia v3) | Auth pages, some security flows |
| `useState` | Dialog open, selected row, view mode |
| `usePage()` | Shared Inertia props (auth, settings, flash) |
| `router` | Navigation, deletes, filter visits |
| `useHttp` | Rare standalone XHR |
| React Context | Layout preferences, search only |

### Server-side filter/pagination state

`useServerPaginationFilters` debounces search and calls `router.get()` with `preserveState`, `preserveScroll`, `replace: true`.

Domain-specific hooks live in features:

- `features/organization/documents/use-documents-index-filters.ts`
- `features/organization/employees/profile/use-ensure-employee.ts`

---

## 7. Naming Conventions

### PHP

| Element | Convention | Example |
|---------|------------|---------|
| Controllers | `{Entity}Controller` | `BranchController.php` |
| Invokable controllers | Descriptive purpose | `DocumentBulkPdfMergeController.php` |
| Form requests | `{Verb}{Entity}Request` | `StoreBranchRequest.php` |
| Support classes | Domain noun/verb | `DocumentBrowseQuery`, `LeaveBalanceManager` |
| Actions | Verb phrase + `handle()` | `CreateEmployee.php` |
| Permissions | dot-separated | `contracts.update` |
| Routes (URL) | kebab-case | `/organization/crew-deployments` |
| Routes (name) | dot notation | `organization.documents.employee.files.show` |

### TypeScript / React

| Element | Convention | Example |
|---------|------------|---------|
| Components | PascalCase | `DocumentComplianceTableRow` |
| Hooks | `use-kebab-case.ts` | `use-server-pagination-filters.ts` |
| Feature types | `features/**/types.ts` | `DocumentShowItem` |
| Page types | Inline or `*-page.types.ts` | `employee-page.types.ts` |
| Props | Explicit interfaces on page/feature | `type Props = { ... }` |

---

## 8. Reusable Abstractions

### Frontend components (copy from here)

| Component | Path | Use |
|-----------|------|-----|
| `OrganizationDataTable` | `components/data-table.tsx` | List tables |
| `ListTableCrudActions` | `components/list-table-actions.tsx` | View/Edit/Delete row actions |
| `DetailsHeader` | `components/details-header.tsx` | Show page header |
| `PageHeader` | `components/page-header.tsx` | List page header |
| `SearchBar` | `components/search-bar.tsx` | Debounced search input |
| `Pagination` | `components/pagination.tsx` | Server pagination |
| `FiltersSheet` | `components/filters-sheet.tsx` | Filter panel |
| `ConfirmDeleteDialog` | `components/confirm-delete-dialog.tsx` | Delete confirmation |
| `RecentActivityCard` | `components/recent-activity-card.tsx` | Audit trail on show pages |
| `EmptyState` | `components/empty-state.tsx` | Zero results |
| `ExportMenu` | `components/export-menu.tsx` | CSV/XLSX/PDF export |
| `ViewToggle` | `components/view-toggle.tsx` | Grid/table toggle |
| `InputError` | `components/input-error.tsx` | Field validation display |
| `HttpExceptionToasts` | `components/http-exception-toasts.tsx` | Global flash + HTTP errors |

### Frontend hooks

| Hook | Path |
|------|------|
| `useServerPaginationFilters` | `hooks/use-server-pagination-filters.ts` |
| `useHasPermission` | `hooks/use-has-permission.ts` |
| `useViewPreference` | `hooks/use-view-preference.ts` |
| `useDialogState` | `hooks/use-dialog-state.tsx` |
| `useCreatableMasterData` | `hooks/use-creatable-master-data.ts` |

### Backend abstractions

| Class | Path | Role |
|-------|------|------|
| `RecentActivityQuery` | `Support/Activity/RecentActivityQuery.php` | Show page audit log |
| `DocumentAccess` | `Support/EmployeeDocuments/DocumentAccess.php` | Company/employee/document guards |
| `DocumentPagePermissions` | `Support/EmployeeDocuments/DocumentPagePermissions.php` | Document module `can` flags |
| `DocumentBrowseQuery` | `Support/EmployeeDocuments/DocumentBrowseQuery.php` | Document index/browse queries |
| `EmployeeDirectoryQuery` | `Support/Employees/EmployeeDirectoryQuery.php` | Employee list |
| `ResolvesPerPage` | `Support/Pagination/ResolvesPerPage.php` | List pagination |
| `LogsActivityWithCompany` | `Models/Concerns/LogsActivityWithCompany.php` | Multi-tenant audit |
| `SetCurrentCompany` | `Http/Middleware/SetCurrentCompany.php` | Tenant context |
| `ReturnsQuickCreateJson` | `Http/Controllers/Concerns/ReturnsQuickCreateJson.php` | Master-data inline create |

### Design system

- **TS tokens:** `lib/design-system.ts` — `tables`, `surfaces.glassCard`, `typography`
- **CSS:** `resources/css/design-system.css` — `glass-card`, `ds-*` classes
- **Utility:** `cn()` from `lib/utils.ts`

---

## 9. Important Files and Folders

### Entry points

| File | Role |
|------|------|
| `resources/js/app.tsx` | Inertia bootstrap, providers, Sonner |
| `bootstrap/app.php` | Middleware, exception rendering |
| `routes/web.php` | Main application routes |
| `routes/settings.php` | Settings & master data |
| `app/Http/Middleware/HandleInertiaRequests.php` | Shared Inertia props |

### Golden reference implementations

| Feature | List | Form | Show |
|---------|------|------|------|
| Branches | `features/organization/branches/index.tsx` | `branch-form-sheet.tsx` | `pages/organization/branch.tsx` |
| Companies | `features/organization/companies/index.tsx` | `company-form-sheet.tsx` | `pages/organization/company.tsx` |
| Documents | `pages/organization/documents/index.tsx` | `pages/organization/_components/documents/upload-dialog.tsx` | `pages/organization/documents/show.tsx` |
| Crew deployments | `pages/organization/crew-deployments/index.tsx` | `deployment-form-dialog.tsx` | `pages/organization/crew-deployments/show.tsx` |

### Configuration

| File | Purpose |
|------|---------|
| `config/permission.php` | Spatie teams (`company_id`) |
| `config/inertia.php` | SSR, pages path |
| `config/activitylog.php` | Audit settings |
| `components.json` | shadcn config (new-york, neutral) |
| `vite.config.ts` | Wayfinder plugin, Tailwind v4 |

### Testing helpers

| File | Purpose |
|------|---------|
| `tests/Support/spatie.php` | `grantCompanyPermissions()` |
| `tests/Support/document-fixtures.php` | `makeDocumentFixtures()` |
| `tests/Pest.php` | Test case setup |

---

## 10. Conventions That Must Not Be Broken

### Architecture

- Do **not** introduce a REST API or React Query unless explicitly approved.
- Prefer the established Spatie permission names and route middleware. A policy or gate is appropriate when authorization depends on the model or business state; do not leave an action unauthorised merely to avoid introducing one.
- Do **not** create new top-level folders (`app/`, `resources/js/`) without approval.
- Put domain logic in `app/Support/`, not controllers.
- Keep Inertia pages thin; put UI in `features/`.

### Multi-tenancy

- Always scope queries by `current_company_id` from request attributes.
- Never trust client-provided company ID for authorization.
- Spatie permissions are team-scoped — middleware must set team ID before checks.

### Frontend

- Use shadcn components from `components/ui/` — do not add competing UI libraries.
- Use `OrganizationDataTable` for org list tables — not TanStack Table.
- **Sheets** for create/edit forms; **AlertDialog** for deletes; **Dialog** for heavy modals.
- Server-side validation only — no Zod/Yup/react-hook-form.
- Prefer Wayfinder routes over hardcoded URLs for new code.
- Use server flash messages for CRUD success — do not duplicate with client toasts.

### Backend

- Explicit routes per action — no `Route::resource()`.
- Form Requests for validation — not inline controller validation.
- Run Pint on PHP changes: `vendor/bin/pint --dirty --format agent`.
- Every change needs a Pest test.

### Permissions

- Backend authorization is authoritative. Route `can:` middleware is preferred for simple capability checks; policies, gates, or Form Request authorization may enforce model- or state-specific rules.
- Frontend `can` props and `useHasPermission` are for UX only.
- Pass module-specific `can` objects from dedicated Support classes (e.g. `DocumentPagePermissions`).
- Treat authenticated-only operational routes without capability middleware as known gaps to fix, not precedent.

### Activity / audit

- Use `RecentActivityQuery::for()` on show pages.
- Return `recent_activity: []` and `can_view_audit: false` without `audit.view`.
- Models use `LogsActivityWithCompany` — do not log manually unless for custom events (email send, etc.).

### Details pages

- Use `DetailsHeader` with `backHref` / `backLabel`.
- Pass whitelisted back-navigation query params (see `DocumentShowBackNavigation`, crew deployment `back_query`).
- Row click navigates to show page; eye icon = View link (`ListTableCrudActions`).

### Documents module specifics

- `DocumentBrowseQuery` powers index, search, compliance views.
- Search modes: `browse | documents-only | employees-only | tabbed | empty`.
- Document show page owns inline preview + version history (not list modals).
- `buildDocumentShowUrl()` for navigation with `from` back context.

---

## Appendix: Technology Stack

| Layer | Technology | Version |
|-------|------------|---------|
| Runtime | PHP | 8.4 |
| Framework | Laravel | 13 |
| Auth | Fortify | v1 |
| Permissions | spatie/laravel-permission | teams enabled |
| Audit | spatie/laravel-activitylog | — |
| SPA | Inertia Laravel + React | v3 |
| UI | shadcn/ui + Radix + Tailwind | v4 |
| Icons | lucide-react | — |
| Routes (FE) | Wayfinder | v0 |
| Tests | Pest | v4 |
| Formatter | Pint | v1 |
