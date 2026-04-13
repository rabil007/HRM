# AI Guide (OMS-HRM)

This file documents the **current, preferred patterns** in this repo so future changes stay consistent and avoid guesswork.

## Stack

- Laravel 13 + PHP 8.4
- Inertia v3 + React 19 + TypeScript
- Tailwind CSS v4
- Pest for tests
- Pint for PHP formatting

## Frontend structure

- **Pages (Inertia entrypoints)**: `resources/js/pages/**`
  - Keep these thin: define page prop types, render a feature component.
- **Feature modules**: `resources/js/features/**`
  - Example modules:
    - `resources/js/features/organization/companies`
    - `resources/js/features/organization/branches`
- **Shared components**: `resources/js/components/**`
- **UI primitives**: `resources/js/components/ui/**`

## Backend structure

- Controllers: `app/Http/Controllers/**`
- Form requests: `app/Http/Requests/**`
- Routes: `routes/web.php`

## Routing conventions

- Organization routes use `/organization/...` paths.
- Resource-ish routes are explicit in `routes/web.php`:
  - Companies: index/show/store/update/destroy + export
  - Branches: index/show/store/update/destroy + export

## Inertia shared props

- Sidebar company switcher uses shared prop:
  - `company_switcher_companies`
- Avoid naming collisions with page props (don’t name a page prop `companies` if it can conflict with shared props).

## Reusable UI patterns (prefer using these)

- **List pages**
  - `PageHeader`: `resources/js/components/page-header.tsx`
  - `SearchBar`: `resources/js/components/search-bar.tsx`
  - `ExportMenu`: `resources/js/components/export-menu.tsx`
  - `FiltersSheet`: `resources/js/components/filters-sheet.tsx`
  - `EmptyState`: `resources/js/components/empty-state.tsx`

- **Delete confirmation**
  - `ConfirmDeleteDialog`: `resources/js/components/confirm-delete-dialog.tsx`

## “Golden reference” files (copy patterns from here)

- Companies list patterns: `resources/js/features/organization/companies/index.tsx`
- Branches list patterns: `resources/js/features/organization/branches/index.tsx`
- Company form styling: `resources/js/features/organization/companies/components/company-form-sheet.tsx`
- Branch form styling: `resources/js/features/organization/branches/components/branch-form-sheet.tsx`
- Company details page: `resources/js/pages/organization/company.tsx`
- Branch details page: `resources/js/pages/organization/branch.tsx`

## Export conventions

- Exports support `format=csv|xlsx|pdf` and should respect current search/filters via query string.
- Companies export endpoint: `/organization/companies/export`
- Branches export endpoint: `/organization/branches/export`

## Testing & quality gates

- **Every change should be programmatically tested**
  - Prefer targeted tests: `php artisan test --compact <file>`
- If PHP is changed:
  - Run `vendor/bin/pint --dirty --format agent`
- If TS/React is changed:
  - Run `npx eslint <touched files> --fix`
  - Run `npm run types:check`

## Do / Don’t

- Do follow existing conventions in sibling files.
- Do reuse existing components before creating new ones.
- Don’t add narration-style comments in code.
- Don’t introduce new top-level folders without approval.

