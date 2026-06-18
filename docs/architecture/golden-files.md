# Golden Files — Reference Implementations

Curated templates from the OMS-HRM codebase. When building a new feature, **copy patterns from these files** before inventing new ones.

Related: [project-analysis.md](./project-analysis.md) · [AI_GUIDE.md](../../AI_GUIDE.md) · [.cursor/rules/](../.cursor/rules/)

> **Note:** This app does not use React Query or a REST API. Categories like “Query hook” and “API service” refer to existing Inertia + Laravel patterns.

---

## Index page

**File:** `resources/js/features/organization/branches/index.tsx`

**Why it is a good example**

The branches module is the canonical organization list screen cited in `AI_GUIDE.md`. It demonstrates every standard list concern in one place: search, filters, grid/table toggle, pagination, export, inline CRUD via sheet, status toggle, and delete confirmation—without unnecessary complexity.

**Important patterns to follow**

- Wrap content in `Main`; header via `PageHeader`.
- Server-driven state through `useServerPaginationFilters` (search debounce + filter visits).
- View mode persisted with `useViewPreference('branches:view', 'grid')`.
- Single `useForm<BranchFormData>` owned by the list; passed into `BranchFormSheet`.
- Dialog/sheet state: `currentBranch`, `isSheetOpen`, `isDeleteDialogOpen`, `isFiltersOpen`.
- Table uses `OrganizationDataTable` + `ListTableCrudActions`; grid uses domain cards.
- `EmptyState` when no rows; `ExportMenu` with current query params.
- Mutations use `router.put` / `form.post` with `preserveScroll: true`.

**What future code should imitate**

- New org list modules should match this file’s structure before adding domain-specific extras.
- Keep the Inertia page (`pages/organization/branches.tsx`) as a thin wrapper that only passes props to `BranchesContent`.

**Also see (complex index):** `resources/js/pages/organization/documents/index.tsx` — multi-mode search, compliance view, folder grid, and summary cards.

---

## Show page

**File:** `resources/js/pages/organization/documents/show.tsx`

**Why it is a good example**

Most complete modern show page: breadcrumbs, `DetailsHeader` with back navigation, permission-gated header actions, structured metadata card, embedded preview, inline version history, grouped management dialogs, and conditional audit section. It also uses Wayfinder for delete URLs and supports redirect-after-delete.

**Important patterns to follow**

- `DetailsHeader` with `backHref` / `backLabel` from server (`DocumentShowBackNavigation`).
- Header actions via `DocumentShowHeaderActions`; row-level shortcuts stay consistent.
- Domain data shaped on backend (`EmployeeDocument::toShowArray()`).
- `can` object from `DocumentPagePermissions::for()`.
- `RecentActivityCard` rendered only when `can_view_audit`.
- `DocumentManagementDialogs` with `partialReloadKeys={['document']}` and `deleteRedirectUrl={back.href}`.

**What future code should imitate**

- Any new entity detail page: header + cards + optional activity, with back context preserved in query params.
- Do not fetch audit or versions client-side when they can be inlined in the show controller.

**Also see:** `resources/js/pages/organization/crew-deployments/show.tsx` — Wayfinder `back_query`, lifecycle timeline, edit dialog on show page.

---

## Form page

**File:** `resources/js/features/organization/branches/components/branch-form-sheet.tsx`

**Why it is a good example**

OMS-HRM rarely uses dedicated form routes for org CRUD; forms live in **right-side sheets** opened from index pages. This file is the styling and structure reference for create/edit forms across the app (`AI_GUIDE.md` golden reference).

**Important patterns to follow**

- `Sheet` + `SheetHeader` (title switches on create vs edit) + scrollable body + footer actions.
- Receives `form: InertiaFormProps<BranchFormData>` from parent—sheet does not own submission logic.
- Uses shared inputs: `AppSelect`, `Input`, `Switch`, inline `form.errors.*` messages.
- Visual language: `glass-card`, `rounded-xl`, uppercase field labels consistent with design system.

**What future code should imitate**

- New create/edit UX → sheet on list page, not a new Dialog or standalone page, unless the workflow is exceptionally heavy (see Modal below).
- Parent handles `form.post` / `form.put`, reset on add, populate on edit.

**Also see**

- `resources/js/components/settings/master-data-form-sheet.tsx` — reusable sheet shell for settings master data.
- `resources/js/pages/settings/master-data/genders.tsx` — self-contained settings page with inline sheet + delete (when the whole page is CRUD).

---

## Table component

**File:** `resources/js/features/organization/documents/document-compliance-table-row.tsx`

**Why it is a good example**

Shows the current best-practice table row: compositional (not TanStack Table), row-click navigation to show page, extracted row component, shared cell classes, domain badges/icons, and delegated row actions with `stopPropagation` on the actions column.

**Important patterns to follow**

- `TableRow` with `dataTableBodyRowClass()` + `cursor-pointer` + `onClick={() => router.visit(viewHref)}`.
- Cells use `dataTableCellClass()` / domain-specific layout.
- Actions in `DocumentModuleRowActions` with `viewHref` (eye = view link, not preview modal).
- Parent table wraps rows in `OrganizationDataTable` (`documents-index-documents-table.tsx`).

**What future code should imitate**

- Extract a `*-table-row.tsx` when columns are domain-specific.
- Pass `viewHref` from a URL builder (e.g. `buildDocumentShowUrl`) rather than inline string concatenation.

**Also see:** `resources/js/features/organization/employees/employees-content.tsx` — simpler org list table with status toggle.

---

## Dialog

**File:** `resources/js/components/confirm-delete-dialog.tsx`

**Why it is a good example**

Single reusable **AlertDialog** wrapper used across the app. Domain modules thin-wrap it (`BranchDeleteDialog`, `ConfirmDeleteDocumentDialog`) instead of reimplementing delete UX.

**Important patterns to follow**

- Controlled `open` / `onOpenChange` props.
- Customizable `title`, `description`, `confirmText`, styling via `contentClassName`.
- `onConfirm` callback—parent performs `router.delete` or `form.delete`.
- Uses shadcn `AlertDialog*` primitives, not browser `confirm()`.

**What future code should imitate**

- All destructive confirmations → extend `ConfirmDeleteDialog` or copy `branch-delete-dialog.tsx` wrapper pattern.

**Also see:** `resources/js/features/organization/branches/components/branch-delete-dialog.tsx` — domain wrapper with entity name in description.

---

## Modal

**File:** `resources/js/pages/organization/_components/documents/upload-dialog.tsx`

**Why it is a good example**

Represents **center Dialog** modals for heavy, multi-step workflows (not CRUD sheets). Handles multi-file upload, compression, progress overlay, template-driven fields, Wayfinder controller URLs, bulk validation errors, and partial Inertia reload.

**Important patterns to follow**

- `Dialog` + `DialogHeader` / `DialogFooter` for workflows that do not fit a side sheet.
- Wayfinder: `EmployeeDocumentController.store.url({ employee })`.
- Client preparation (compress, drafts) in feature helpers under `features/organization/documents/upload/`.
- Submit with `router.post` + `only: ['documents']` + `preserveScroll`.
- Progress UI via `DocumentUploadProgressOverlay`.

**What future code should imitate**

- Use center modals only for complex flows (upload, merge, send confirm)—not for simple create/edit.
- Lazy-load heavy modals when they significantly increase bundle size (`employee.tsx` PDF merge pattern).

**Also see:** `resources/js/features/organization/crew-deployments/deployment-form-dialog.tsx` — large form in Dialog with Wayfinder store/update actions and creatable selects.

---

## React hook

**File:** `resources/js/hooks/use-server-pagination-filters.ts`

**Why it is a good example**

The most reused data-navigation hook in the app. Encapsulates debounced search, filter changes, and pagination into Inertia `router.get()` visits with consistent options—replacing ad-hoc search logic in every list.

**Important patterns to follow**

- Syncs local `searchInput` when server `initialSearch` changes.
- Debounce (default 400ms) before visiting with new search.
- `cleanParams()` strips empty/false values from query string.
- Visits use `preserveState`, `preserveScroll`, `replace: true`.
- Supports custom `searchKey` (e.g. `q` on activity logs page).

**What future code should imitate**

- Any server-paginated list with search/filters should use this hook unless there is a strong domain reason not to (then extend, do not duplicate).

**Also see**

- `resources/js/hooks/use-has-permission.ts` — permission checks from Inertia shared props.
- `resources/js/hooks/use-view-preference.ts` — persist grid/table preference.

---

## Permission implementation

**File:** `app/Support/EmployeeDocuments/DocumentPagePermissions.php`

**Why it is a good example**

Shows the preferred way to build **module-specific `can` props**: centralize permission checks, integration preconditions, and related template data in one Support class instead of scattering `$user->can()` across controllers and React.

**Important patterns to follow**

- Static `for(?User $user): array` with PHPDoc array shape.
- Combines Spatie permissions with business rules (WhatsApp configured, templates exist).
- Controller passes result as `'can' => DocumentPagePermissions::for($request->user())`.
- Frontend gates buttons with `can.upload`, `can.delete`, etc.
- Route middleware remains authoritative (`can:documents.upload`).

**What future code should imitate**

- New modules with non-trivial action matrices → add `{Module}PagePermissions` in `app/Support/`.
- Simple modules may inline booleans in controller, but follow this class when `can` grows beyond 2–3 flags.

**Also see**

- `app/Support/EmployeeDocuments/DocumentAccess.php` — static guards for tenant/ownership.
- `resources/js/hooks/use-has-permission.ts` — frontend cross-cutting checks.
- `docs/permissions.md` — permission catalog.

---

## API service

**File:** `app/Services/DocumentEmailService.php`

**Why it is a good example**

Illustrates `app/Services/` usage: orchestrates an integration (mail), validates business constraints (attachment size), maps domain models to mailables, handles failures with `ValidationException`, and logs custom Spatie activity for non-model events.

**Important patterns to follow**

- Services for **external side effects** (email, WhatsApp, PDF merge, Hikvision)—not for simple CRUD queries.
- Inject dependencies via constructor when coordinating other classes.
- Throw `ValidationException` for user-facing failures; `report()` unexpected errors.
- Log operational events manually when they are not model create/update/delete.

**What future code should imitate**

- New integration workflows → new Service class, called from invokable controller or existing controller action.
- Keep Inertia as the primary transport; services return void/DTOs, not JSON API responses.

**Also see:** `app/Services/DocumentMergeService.php` — PDF merge integration.

---

## Query hook

**File:** `resources/js/features/organization/documents/use-documents-index-filters.ts`

**Why it is a good example**

There is **no React Query** in this repo. Domain-specific “query hooks” wrap Inertia navigation for a particular index—composing URL building, expiry/search/page params, and `router.get` behavior analogous to `useServerPaginationFilters` but tailored to the documents module.

**Important patterns to follow**

- Colocate in `features/{domain}/` when list behavior is not generic.
- Read initial values from page props; write changes via `router.get` / `router.visit`.
- Preserve query params that define view state (`expiry`, `search`, `page`).
- Pair with backend query class (`DocumentBrowseQuery`)—frontend hook navigates, backend query fetches.

**What future code should imitate**

- Prefer `useServerPaginationFilters` for standard lists.
- Add a domain hook when filters/search semantics are module-specific (multiple modes, tabs, compliance vs browse).

**Backend pair:** `app/Support/EmployeeDocuments/DocumentBrowseQuery.php`

---

## Controller

**File:** `app/Http/Controllers/Organization/EmployeeDocumentShowController.php`

**Why it is a good example**

Clean **invokable controller** for a single show action: resolves tenant context, delegates authorization to Support guards, eager-loads relations, maps to array DTO, composes permissions/back/audit props, renders Inertia—without business logic bloat.

**Important patterns to follow**

- Read `$companyId` from `$request->attributes->get('current_company_id')`.
- Use Support guards: `DocumentAccess::assert*`.
- Load relations explicitly before `toShowArray()`.
- Pass `recent_activity` + `can_view_audit` on show pages.
- Dedicated invokable controller for one-off pages/endpoints.

**What future code should imitate**

- New show pages → invokable controller + Support query/presenter + thin Inertia render.
- Multi-action CRUD → follow `BranchController` (`index`, `show`, `store`, `update`, `destroy`, export).

**Also see:** `app/Http/Controllers/Organization/CrewDeploymentController.php` — show with `back_query`, presenter, and form options.

---

## Request validation

**File:** `app/Http/Requests/Organization/EmployeeDocument/StoreEmployeeDocumentRequest.php`

**Why it is a good example**

Demonstrates advanced validation: domain folder structure, shared rules via Concerns trait, template-driven conditional rules, and file upload constraints—typical of real HR document uploads.

**Important patterns to follow**

- Namespace mirrors domain: `Requests/Organization/EmployeeDocument/`.
- `use AppliesEmployeeDocumentTemplateRules` for conditional fields from employee profile template.
- Explicit file rules: `mimes`, `mimetypes`, max sizes.
- `authorize()` returns true when route middleware already enforces permission (documented pattern).

**What future code should imitate**

- Extract reusable rule groups into `Requests/.../Concerns/` traits.
- Simple entities can follow `StoreBranchRequest.php`; use this file when validation varies by template/config.

**Also see:** `app/Http/Requests/Organization/Branch/StoreBranchRequest.php` — straightforward rules array.

---

## Support class

**File:** `app/Support/EmployeeDocuments/DocumentBrowseQuery.php`

**Why it is a good example**

Canonical **query Support class**: encapsulates complex Eloquent queries (folders, compliance lists, search, expiry summary) with typed return shapes, company scoping, and efficient aggregates—keeping controllers thin.

**Important patterns to follow**

- One class per domain query surface; methods named by use case (`employeesWithDocuments`, `documentsForSearch`, `expirySummary`).
- PHPDoc `@return` array shapes for Inertia props.
- Use model scopes (`forCompany`, `whereExpired`) and `selectRaw` for summaries.
- No HTTP concerns—pure data retrieval/transformation.

**What future code should imitate**

- Any non-trivial listing, dashboard metric, or filter query → Support class, not controller private methods.
- Pair with array mappers on models (`toProfileArray`, `toShowArray`) or Support Resources.

**Also see:** `app/Support/Employees/Actions/CreateEmployee.php` — action class with `handle()` for multi-model orchestration.

---

## Activity logging

**File:** `app/Models/EmployeeDocument.php` (trait + `getActivitylogOptions()`)

**Supporting trait:** `app/Models/Concerns/LogsActivityWithCompany.php`

**Why it is a good example**

Shows the standard model audit setup: `LogsActivityWithCompany` sets `company_id` on each activity row; `getActivitylogOptions()` defines `logOnly` fields and `logOnlyDirty()` to avoid noise.

**Important patterns to follow**

- Use `LogsActivityWithCompany` on models that need tenant-scoped audit.
- `logOnly([...])` — explicit field allowlist.
- `logOnlyDirty()` — log updates only when values change.
- Custom events (email send, bulk actions) logged manually in Services with `activity()` helper when not model CRUD.

**What future code should imitate**

- New auditable models → same trait + options pattern as `EmployeeDocument`, `Branch`, `Employee`.
- Do not build a parallel audit table.

**Also see:** `app/Services/DocumentEmailService.php` — manual activity log for email sends.

---

## File upload

**File:** `app/Support/EmployeeDocuments/StoresEmployeeDocument.php`

**Why it is a good example**

Complete upload pipeline: optimization (`DocumentUploadOptimizer`), storage path convention, checksum/size metadata, version snapshot on replace, DB transaction, and cleanup in `finally`—the backend counterpart to `upload-dialog.tsx`.

**Important patterns to follow**

- `create()` for new documents; `replace()` archives old file to `EmployeeDocumentVersion` then updates current.
- Store under company/employee scoped paths via `UploadedFileStorage`.
- Set `status` from `DocumentExpiry::persistedStatus()`.
- Always cleanup temp prepared files in `finally`.

**What future code should imitate**

- New file uploads → Support class + Form Request file rules + frontend Dialog/modal.
- Do not store uploads directly in controllers.

**Frontend pair:** `resources/js/pages/organization/_components/documents/upload-dialog.tsx`

---

## Pagination

**Files (pair):**

- `resources/js/components/pagination.tsx` — UI component
- `resources/js/hooks/use-server-pagination-filters.ts` — server navigation

**Why they are good examples**

Pagination is always **server-side**. The component renders Laravel paginator meta; the hook changes `page` and `per_page` via Inertia visits. Together they define the full pattern used on branches, employees, activity logs, and documents.

**Important patterns to follow**

- Component receives `currentPage`, `lastPage`, `from`, `to`, `total`, `perPage`, `onPageChange`, optional `onPerPageChange`.
- Returns `null` when `total === 0`.
- Backend uses `ResolvesPerPage` trait on list controllers.
- Paginator uses `->withQueryString()` to preserve filters.

**What future code should imitate**

- Never client-slice full datasets that are server-paginated.
- Wire `onPageChange` to `useServerPaginationFilters().onPageChange` or domain equivalent.

**Also see:** `resources/js/types/pagination.ts` — `PaginationMeta`, `PAGINATION_PER_PAGE_OPTIONS`.

---

## Audit trail

**Files (pair):**

- **Show-page slice:** `resources/js/components/recent-activity-card.tsx` + `app/Support/Activity/RecentActivityQuery.php`
- **Global log:** `resources/js/pages/organization/activity-logs.tsx`

**Why they are good examples**

Two complementary audit UX levels: per-entity recent changes on show pages ( gated by `audit.view` ) and searchable company-wide log with filters and pagination.

**Important patterns to follow**

- `RecentActivityQuery::for()` returns `[]` without `audit.view`.
- Controller passes `can_view_audit` boolean alongside `recent_activity`.
- UI hides sensitive keys (`company_id`, `password`, etc.) when rendering diffs.
- Global page uses `useServerPaginationFilters` with `searchKey: 'q'`.

**What future code should imitate**

- Every new show page for an auditable model → add `RecentActivityQuery` + optional `RecentActivityCard`.
- Do not build custom diff UI—reuse `RecentActivityCard` or crew deployment activity component.

---

## Delete flow

**File:** `resources/js/features/organization/documents/shared/document-management-dialogs.tsx`

**Why it is a good example**

End-to-end delete orchestration: confirm dialog, Wayfinder delete URL, conditional partial reload vs redirect, state cleanup on success—covers list context and show-page redirect (`deleteRedirectUrl`).

**Important patterns to follow**

- Confirm first (`ConfirmDeleteDocumentDialog`), then `router.delete`.
- Use Wayfinder: `EmployeeDocumentController.destroy.url({ employee, document })`.
- List deletes: `only: partialReloadKeys` (e.g. `['documents']`).
- Show page deletes: skip partial reload, `router.visit(deleteRedirectUrl)` on success.
- Reset `deleteDocId` / close dialog in `onSuccess`.

**What future code should imitate**

- Group related edit/replace/delete dialogs in one `{Domain}ManagementDialogs` component when they share employee/entity context.
- Simpler modules: `branches/index.tsx` + `BranchDeleteDialog` + inline `router.delete`.

---

## Bulk actions

**Files (pair):**

- **Frontend:** `resources/js/pages/organization/documents/employee.tsx` + `resources/js/features/organization/documents/shared/use-bulk-selection.ts` + `resources/js/features/organization/documents/shared/bulk-toolbar.tsx`
- **Backend:** `app/Support/EmployeeDocuments/DocumentBulkActionService.php`

**Why they are good examples**

Most complete bulk workflow: checkbox selection with select-all, sticky toolbar, permission-gated actions (download ZIP, delete, merge, email, WhatsApp), and backend validation that every ID belongs to the employee/company.

**Important patterns to follow**

- `useBulkSelection(visibleIds)` — tracks selection across visible rows; `toggleAll`, `clear`, `isPartiallySelected`.
- `DocumentsBulkToolbar` — only renders when `count > 0`; actions passed as `ReactNode`.
- `stopPropagation` on checkbox cells so row click still works.
- Bulk delete: `router.delete` with `{ data: { document_ids } }` to bulk destroy route.
- Backend deduplicates IDs, verifies count matches, scopes by company + employee.

**What future code should imitate**

- Reuse `useBulkSelection` + toolbar pattern for any multi-select list actions.
- Add Support service methods for bulk operations with strict ID validation—never delete by IDs without company scope check.

---

## Quick reference table

| Category | Golden file |
|----------|-------------|
| Index page | `features/organization/branches/index.tsx` |
| Show page | `pages/organization/documents/show.tsx` |
| Form (sheet) | `features/organization/branches/components/branch-form-sheet.tsx` |
| Table row | `features/organization/documents/document-compliance-table-row.tsx` |
| Dialog (confirm) | `components/confirm-delete-dialog.tsx` |
| Modal (workflow) | `pages/organization/_components/documents/upload-dialog.tsx` |
| React hook | `hooks/use-server-pagination-filters.ts` |
| Permissions | `app/Support/EmployeeDocuments/DocumentPagePermissions.php` |
| API service | `app/Services/DocumentEmailService.php` |
| Query hook | `features/organization/documents/use-documents-index-filters.ts` |
| Controller | `app/Http/Controllers/Organization/EmployeeDocumentShowController.php` |
| Request validation | `app/Http/Requests/Organization/EmployeeDocument/StoreEmployeeDocumentRequest.php` |
| Support class | `app/Support/EmployeeDocuments/DocumentBrowseQuery.php` |
| Activity logging | `app/Models/EmployeeDocument.php` + `LogsActivityWithCompany` |
| File upload | `app/Support/EmployeeDocuments/StoresEmployeeDocument.php` |
| Pagination | `components/pagination.tsx` + `use-server-pagination-filters.ts` |
| Audit trail | `components/recent-activity-card.tsx` + `RecentActivityQuery.php` |
| Delete flow | `features/organization/documents/shared/document-management-dialogs.tsx` |
| Bulk actions | `pages/organization/documents/employee.tsx` + `DocumentBulkActionService.php` |
