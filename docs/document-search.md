# Document search

Global search on the documents index helps HR find employees or files without opening every folder first.

## Where

- Page: `/organization/documents`
- UI: sticky search bar below summary cards
- Placeholder: `Search employee, document no, file name...`

## What is searched

| Target | Employee folder list | Document results table |
|--------|----------------------|-------------------------|
| Employee name | Yes | Yes (via employee relation) |
| Employee number | Yes | Yes |
| Document number | No | Yes |
| Document title | No | Yes |
| Original filename | No | Yes |
| Document type name | No | Yes |

Folder search intentionally matches **only** employee name and number so result modes stay unambiguous. Document field matches come from a separate query.

## Result modes (UX)

Frontend resolver: `resolveDocumentsIndexSearchMode()` in  
`resources/js/features/organization/documents/index/use-documents-index-search-mode.ts`

| Mode | When | What is shown |
|------|------|----------------|
| `browse` | Empty search | Folder grid only |
| `documents-only` | Documents match, no employee name/number match | Section **Documents (N)** + table |
| `employees-only` | Employee name/number match, no document field match | Section **Employees (N)** + folders |
| `tabbed` | Both match | Tabs: **All** (default), **Employees (N)**, **Documents (N)** |
| `empty` | No matches | Empty state with search hints |

Search terms do **not** appear as “Active filter” chips (only expiry filters do on compliance views).

## Backend

- Controller: `DocumentsFolderIndexController`
- Folders: `DocumentBrowseQuery::employeesWithDocuments($companyId, $search)`
- Files: `DocumentBrowseQuery::documentsForSearch($companyId, $search)` when `search` query param is non-empty and `expiry=all`
- Compliance + search: `DocumentBrowseQuery::documentsForCompliance()` (same text search rules, plus expiry filter)

Pagination: `searchDocuments` uses `per_page` (default 25, max 100) and `page` query string.

## Inertia props

| Prop | Description |
|------|-------------|
| `search` | Current query string |
| `employees` | Matching folders |
| `searchDocuments` | Paginated document rows (employee + document columns) |
| `complianceDocuments` | Set when an expiry card is active |
| `expiry` | `all` \| `expired` \| `expiring_30` \| … |

## Performance notes

- Search uses SQL `LIKE`—adequate for typical SMB tenants; very large tenants may need full-text indexes or a dedicated search service later.
- Debounced Inertia partial reload (400ms) via `useDocumentsIndexFilters`
- Partial reload keys: `summary`, `expiry`, `search`, `employees`, `searchDocuments`, `complianceDocuments`

## Employee folder page search

`/organization/documents/employees/{id}` uses **client-side** filtering (`filterDocuments`) over documents already loaded for that employee—separate from index global search.
