# AI Guide (OMS-HRM)

Concise repository-wide architecture for contributors and coding agents.

**Do not load this file for a narrow task.** Start with [docs/README.md](docs/README.md), then the one matching domain guide. Current code, routes, migrations, tests, and `database/seeders/PermissionsSeeder.php` override stale documentation.

Laravel Boost / package rules live in [AGENTS.md](AGENTS.md). Always-on product invariants live in `.cursor/rules/project-rules.mdc`. Preferred copy-from examples live in [docs/architecture/golden-files.md](docs/architecture/golden-files.md). Directory pointers: [docs/architecture/context-map.md](docs/architecture/context-map.md).

---

## Stack

| Layer | Technology |
|-------|------------|
| Backend | PHP 8.4, Laravel 13 |
| Frontend | Inertia v3, React 19, TypeScript |
| Styling | Tailwind CSS v4, shadcn/ui (new-york) |
| Auth | Laravel Fortify (+ 2FA) |
| Permissions | Spatie Laravel Permission (teams = `company_id`) |
| Routes (FE) | Laravel Wayfinder (`@/routes`, `@/actions`) |
| Tests | Pest 4 |

OMS-HRM is a **Laravel + Inertia monolith**. There is **no REST API layer** (`routes/api.php` is empty). The browser loads an Inertia SPA; Laravel renders pages with props and handles mutations via form posts.

---

## Context loading (normal change)

Load only what the task needs, in this order:

1. [docs/README.md](docs/README.md) — pick the matching guide
2. That one domain guide (not the whole `docs/` tree)
3. Relevant routes and the permission names for that module
4. Relevant models
5. Relevant `app/Support/` or `app/Services/` classes
6. One or two sibling implementations
7. Relevant tests

**Do not initially load:** every migration, every model, every documentation file, the entire PermissionsSeeder, the entire frontend tree, or unrelated Crew / Payroll / Documents modules.

Broaden context only when the task crosses domains or evidence requires it.

---

## Tenancy and authorization

- Session `current_company_id` is the trusted tenant. Controllers read `(int) $request->attributes->get('current_company_id')` after `SetCurrentCompany`.
- Scope every tenant-owned query and mutation to that company. Never trust client-supplied `company_id` for authorization.
- Spatie team ID = `company_id`. Permission checks run in the active company team.
- **Backend authorization is mandatory.** Frontend `can` flags and `useHasPermission` are UX only.
- Typical backend patterns:
  - Route `can:` middleware for simple capability checks
  - Policies (for example `CrewAssignmentPolicy`), gates, Form Request `authorize()`, and Support guards for model ownership or state
- Verify the specific endpoint. Neighboring routes are not proof of protection.

Never return decrypted SMTP, WhatsApp, Hikvision, signing, or other credentials. Return masked values and `has_*` flags; preserve stored secrets when an update submits an empty credential.

---

## Architecture

```
Browser
  └── Fortify auth + SetCurrentCompany
        └── Backend authorization (can: / policy / gate / Form Request / guard)
              └── Controller → Support (domain) or Services (integrations)
                    └── Inertia::render() → React
                          └── Wayfinder typed routes/actions
```

| Concern | Pattern |
|---------|---------|
| Data fetching | Server Inertia props — **not** React Query / TanStack Query |
| Mutations | `useForm`, `<Form>`, `router.post/put/delete` |
| Domain logic | `app/Support/` (queries, actions, presenters, guards) |
| Integrations | `app/Services/` (email, WhatsApp, PDF merge, Hikvision) |
| Lists | Server pagination + `useServerPaginationFilters` |
| Tables | `OrganizationDataTable` — **not** TanStack Table |
| Validation | Laravel Form Requests — **not** Zod / Yup / react-hook-form |

**Do not introduce without architectural approval:** REST APIs, React Query / TanStack Query, Redux / Zustand, TanStack Table, or client-side validation libraries.

### Backend layout

```
app/Http/Controllers/     Domain groups (Organization, Settings, Attendance, Payroll, Hikvision)
app/Http/Requests/        Form validation grouped by domain
app/Policies/             Eloquent policies where model/state rules apply
app/Support/              Preferred home for business logic
app/Services/             External integrations
routes/web.php            Main app routes (no Route::resource())
routes/settings.php       Settings + master data
```

Controllers stay thin: HTTP, Inertia render, redirect + flash. Multi-action controllers for CRUD; invokable controllers for single-purpose endpoints.

### Frontend layout

```
resources/js/pages/       Inertia entrypoints — keep thin
resources/js/features/    Domain UI
resources/js/components/  Shared UI (`ui/` = shadcn primitives)
resources/js/hooks/       Global hooks
resources/js/routes/      Wayfinder generated — do not edit
resources/js/actions/     Wayfinder generated — do not edit
```

New simple CRUD: thin page + `features/{domain}/`. Sheets for create/edit; AlertDialog for deletes; center Dialog only for heavy workflows.

---

## Testing

Every behavior change needs a Pest 4 test. Prefer `RefreshDatabase` feature tests with `grantCompanyPermissions()`. Cover allowed and forbidden (and cross-company) paths when tenant-owned data is involved.

```bash
php artisan test --compact tests/Feature/.../FileTest.php
vendor/bin/pint --dirty --format agent   # after PHP edits
```

Do not run `migrate:fresh` / `db:wipe` against the Herd app database. Pest uses sqlite `:memory:` via `phpunit.xml`.

---

## Domain docs (do not duplicate here)

| Task | Guide |
|------|-------|
| Crew Assignments / P0–P6 | [docs/architecture/crew-movement-phases.md](docs/architecture/crew-movement-phases.md) |
| Crew movement corrections | [docs/architecture/crew-movement-corrections.md](docs/architecture/crew-movement-corrections.md) |
| Crew payroll timeline | [docs/payroll.md](docs/payroll.md) and [docs/architecture/crew-payroll-timeline-preparation.md](docs/architecture/crew-payroll-timeline-preparation.md) |
| Permissions / tenancy | [docs/permissions.md](docs/permissions.md) |
| Full router | [docs/README.md](docs/README.md) |

**EmployeeDeployment has been removed.** CrewAssignment is the source of truth for one mobilisation cycle. Do not describe deployments, `crew-deployments` routes, or `crew_operations.deployments.*` as current architecture.

---

## Quality checklist

1. Follow sibling files and [golden-files.md](docs/architecture/golden-files.md).
2. Scope queries by trusted `current_company_id`.
3. Enforce authorization on the backend.
4. Add or update the smallest relevant Pest test.
5. Prefer Wayfinder in new frontend code.
6. Extend existing Support / Service / component / hook abstractions.
