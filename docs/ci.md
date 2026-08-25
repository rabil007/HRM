# CI quality gates

GitHub Actions **verifies** committed source. It does not reformat or auto-commit files.

The workflow is `.github/workflows/ci.yml` (`CI`). Successful `CI` on a **push** to **`main`** is what [deployment](#deployment) waits for.

## Workflow shape

1. **Detect changes** — classifies the diff as `docs-only` or full application CI.
2. **Backend** and **Frontend** — run in parallel when application files changed.
3. **Quality gates** — aggregator job that must succeed. Docs-only PRs skip install/test jobs and still pass this aggregator when change detection succeeds.

Docs-only paths include `docs/*`, `.cursor/*`, `.agents/*`, `.gemini/*`, root-level `*.md`, and a short list of agent/tooling files. Any unrecognized path forces full CI.

## Required gates (full application CI)

| Gate | Job / step | Local command |
|------|------------|---------------|
| PHP formatting | Backend → `composer lint:check` | `composer lint:check` (`pint --parallel --test`) |
| Pest | Backend → `php artisan test --compact --ansi` | `php artisan test --compact` or `composer test` |
| ESLint | Frontend → `npm run lint:check` | `npm run lint:check` |
| Prettier | Frontend → `npm run format:check` | `npm run format:check` |
| TypeScript | Frontend → `npm run types:check` | `npm run types:check` |
| Frontend tests | Frontend → `npm run test:frontend` | `npm run test:frontend` |
| Production build | Backend (before Pest) and Frontend | `npm run build` |

Backend installs Node and runs `npm run build` **before Pest** so Inertia HTML responses can resolve the gitignored Vite manifest.

Run the same local set:

```bash
composer ci:check
```

That also runs `php artisan wayfinder:generate --with-form` first so TypeScript can resolve gitignored `@/actions` and `@/routes`.

A documentation-only change should follow the docs-only fast path in GitHub Actions. Locally, `composer ci:check` still runs the full application suite.

## Fix vs verify

| Concern | Fix (mutates working tree) | Verify (CI / `ci:check`) |
|---------|----------------------------|---------------------------|
| PHP formatting | `composer lint` or `vendor/bin/pint` | `composer lint:check` |
| ESLint | `npm run lint` (`eslint --fix`) | `npm run lint:check` |
| Prettier | `npm run format` (`prettier --write`) | `npm run format:check` |

Do not rely on CI to rewrite files. The old `lint.yml` workflow used Pint/`npm run format`/`npm run lint` with `contents: write`; that pattern is removed.

## Versions

| Tool | Version |
|------|---------|
| PHP | 8.4 |
| Node | 22 (lockfile via `npm ci`) |
Pest uses sqlite `:memory:` from `phpunit.xml` (not the Herd MySQL database). PHP memory is set to `1G` in `phpunit.xml` and in the CI `setup-php` step so ZIP/export tests do not exhaust the default 512MB limit.

## Triggers

`CI` runs on:

- `pull_request` targeting `develop`, `main`, `master`, or `workos`
- `push` to those same branches

Concurrency: a new commit on the same ref **cancels** an in-progress CI run. Deployment uses a separate concurrency group and is not cancelled by CI.

## Wayfinder

`resources/js/actions/`, `resources/js/routes/`, and `resources/js/wayfinder/` are **gitignored**. The Vite plugin generates them during `npm run build` / `npm run dev`. CI generates them explicitly before TypeScript:

```bash
php artisan wayfinder:generate --with-form --no-interaction
```

Do not commit those directories.

## Caching

CI caches Composer’s **download** cache (keyed by `composer.lock`) and npm’s **download** cache (via `actions/setup-node`). It does not cache `vendor/` or `node_modules/` as restore artifacts. Puppeteer browsers are cached under `~/.cache/puppeteer`.

## Permissions

The CI workflow uses `contents: read` only. It does not need `contents: write`, `pull-requests: write`, or `packages: write`.

## Branch protection

Confirm required checks in GitHub **Settings → Branches / Rulesets**. This repository’s CI aggregator job is named `Quality gates`. This documentation does not assert that `main` is currently protected; verify the live GitHub configuration.

## Deployment

`.github/workflows/deploy.yml` performs the Hostinger SSH/rsync deploy. It runs via `workflow_run` after **`CI` succeeds** for a **push** to **`main`** on this repository:
- **Exact SHA checkout**: Deploys the exact CI-validated commit revision (`workflow_run.head_sha`) rather than `origin/main`.
- **Deploy serialization**: Uses `concurrency: group: deploy-main, cancel-in-progress: false` to ensure in-flight deployments are never aborted midway.
- Failed CI, pull requests, and other branches do not deploy.

## GitHub vs local

Passing `composer ci:check` locally means the **same commands** succeeded on this machine. It does not prove the GitHub-hosted workflow ran. Confirm Actions on the repository after push.
