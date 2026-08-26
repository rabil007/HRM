# Global search (Cmd/Ctrl+K)

The command palette (`Cmd`/`Ctrl`+`K`) searches **navigation commands** and **tenant records** together. It is a read-only omnibox: find a record, read a short subtitle, open the existing authorized show page.

This is separate from [document index search](./document-search.md), which stays on `/organization/documents`.

## What is searched

| Group | Fields | Opens |
|-------|--------|--------|
| Employees | Employee number, name | Employee show |
| Documents | Document number, title, type name, employee name/number | Employee document show |
| Crew | Assignment number, employee name/number, vessel name | Crew assignment show |
| Vessels | Name, IMO, official number | Vessel show |
| Departments | Name, code | Department show |
| Positions | Title, grade | Position show |
| Payroll | Period name | Payroll period show |
| Commands | Accessible sidebar destinations **plus** authorized Settings destinations that are intentionally omitted from the sidebar (especially Master Data) | Existing module URLs |

Empty groups are omitted. Each record group is capped at **5** results (`LIMIT` on the query, not a client slice).

## Tenant scoping

Every tenant-owned source uses `request.attributes.current_company_id` from `SetCurrentCompany`. The client cannot send `company_id` (or a category list) to change ownership or dynamically choose models. With no active company, record groups are empty.

Vessels, departments, positions, employees, documents, crew assignments, and payroll periods are company-owned in the current schema.

## Permissions

The backend authorizes **each category independently**. Phase 3A nav visibility is frontend UX only and is not reused for record search.

| Category | Permission |
|----------|------------|
| Employees | `employees.view` |
| Documents | `documents.view` |
| Crew | `crew_operations.assignments.view` |
| Vessels | `crew_operations.vessels.view` |
| Departments | `departments.view` |
| Positions | `positions.view` |
| Payroll | `payroll.periods.view` **or** `payroll.crew_timesheets.view` |

`platform_access` does not grant tenant record search.

## Ranking and query behavior

- Trimmed query; minimum 2 characters; maximum 80.
- `GET /search?q=` (auth + verified, throttled).
- Exact match, then prefix, then contains. `LIKE` metacharacters (`%`, `_`, `\`) are escaped.
- Soft-deleted rows are excluded by Eloquent.
- Debounce is 250ms in the command palette, with stale-response protection.
- While record search is active (2+ characters), the palette turns off cmdk’s client filter so async record hits are not hidden. Destinations and favorites stay visible only when their labels match the query; records from the server are listed first.

## What is not returned

Search subtitles use only safe display fields. They do **not** include salary, bank details, payroll totals, document file contents, credentials, passport/ID numbers, or private contact data.

Expiry dates on documents are included because the documents index already shows them to `documents.view` users.

Result URLs are the same show routes the rest of the app uses; opening a hit still requires that page’s authorization.

## Implementation

- Route: `search` → `GlobalSearchController`
- Query: `App\Support\Search\GlobalSearchQuery`
- Presentation: `App\Support\Search\GlobalSearchResultPresenter`
- Palette: `resources/js/components/command-menu.tsx`, `resources/js/lib/settings-nav.ts`, `resources/js/hooks/use-global-search.ts`

Accessible [navigation favorites](./navigation-favorites.md) appear as a Favorites command group and are omitted from the normal command list so destinations are not duplicated. Record search is unchanged.

Cmd/Ctrl+K navigation commands are **not** limited to visible sidebar items. Settings keeps many Master Data pages out of the sidebar on purpose; those pages remain discoverable in the palette when the current user/company/platform context grants the matching view permission (`filterSettingsNavItems` / `auth.permissions` / `auth.platform`). Duplicate URLs already present in the sidebar (for example Settings → Security) are shown once. Frontend visibility is UX only; destination routes stay backend-authorized.

Record-search categories are unchanged. Searching “project” opens the Settings → Master Data → Projects **module**; it does not search Project master-data records.

When the query is empty, [recent items](./recent-items.md) appear as a Recent group (loaded once when the palette opens). At 2+ characters, matching recents stay visible above live record search.

Saved views are not part of this surface. See [Saved views](./saved-views.md).
