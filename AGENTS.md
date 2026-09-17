# OMS-HRM Agent Bootstrap

Keep this root file intentionally compact because Cursor loads root `AGENTS.md` globally. Detailed Laravel ecosystem guidance is available on demand through Laravel Boost skills and version-specific documentation.

## Stack

PHP 8.4 · Laravel 13 · Fortify 1 · Spatie Permission/Activity Log · Pest 4 · PHPUnit 12 · Inertia 3 · React 19 · TypeScript · Tailwind CSS 4 · Wayfinder · Vite · Laravel Boost 2.

## Start with the smallest useful context

1. Follow `.cursor/rules/project-rules.mdc` for OMS-HRM invariants.
2. Read `docs/README.md` and select only the matching domain guide.
3. Inspect current routes/permissions, models, Support/Services, one or two sibling implementations, and the smallest relevant tests.
4. Use `docs/architecture/golden-files.md` when a preferred implementation example is needed.
5. Broaden context only when the task crosses domains or current evidence requires it.

Current code, routes, migrations, tests, and `PermissionsSeeder` override stale documentation. Do not scan unrelated OMS-HRM modules for a narrow task.

## Skills

Use only the relevant skill when its domain is active:

- `laravel-best-practices` — Laravel backend PHP and Eloquent.
- `inertia-react-development` — Inertia React pages/forms/navigation.
- `tailwindcss-development` — Tailwind/UI styling.
- `wayfinder-development` — frontend ↔ Laravel route/controller wiring.
- `pest-testing` — Pest tests.
- `implement-oms-change` — genuinely end-to-end multi-file application changes.
- `review-oms-security` — auth, tenancy, permissions, secrets, private files, webhooks, imports/exports, privileged actions.

Do not load every skill for every task.

## Laravel Boost MCP

Use Boost tools when they materially reduce uncertainty:

- `search-docs` for version-sensitive Laravel/Inertia/Pest/Wayfinder/Tailwind behavior or unfamiliar APIs; skip framework searches for purely application-specific edits when the current local pattern is already clear.
- `database-schema` before schema-sensitive migrations/queries when the current schema is not already established by scoped code.
- `database-query` for read-only database investigation instead of ad-hoc mutation scripts.
- `browser-logs` for recent browser/runtime errors.
- `get-absolute-url` when an exact local Herd URL must be shared.

Prefer current application code and focused tests over broad exploratory commands.

## Herd and database safety

The app is served by Laravel Herd; do not start another development server unless explicitly required.

Never run `migrate:fresh`, `migrate:refresh`, `db:wipe`, or destructive database resets against the Herd application database unless explicitly requested. Pest uses its isolated test database configuration.

## Verification

**Default:** before every commit or push, run CI-matching checks on **changed files only**. See `.cursor/rules/pre-push-verification.mdc`. Skip only when the user's prompt explicitly overrides verification.

Run the narrowest relevant checks first.

- PHP edits: `vendor/bin/pint --dirty --format agent`, then focused Pest.
- Frontend edits on each changed `resources/js/**/*.{ts,tsx}` file: `npx prettier --write`, `npx eslint`, `npm run types:check`, plus matching `*.test.ts` files when present.
- Run `npm run build` only when the changed scope warrants it (Vite/Wayfinder-wide impact).

Never claim a command passed unless it actually ran successfully. Review the final diff for unrelated changes, generated files, exposed secrets, stale docs, tenancy, and backend authorization before finishing.
