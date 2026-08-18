# CI quality gates

GitHub Actions **verifies** committed source. It does not reformat or auto-commit files.

The workflow is `.github/workflows/ci.yml` (`CI`). It is the required gate before [deployment](#deployment).

## Required gates

| Gate | CI step | Local command |
|------|---------|---------------|
| PHP formatting | `composer lint:check` | `composer lint:check` (`pint --parallel --test`) |
| ESLint | `npm run lint:check` | `npm run lint:check` |
| Prettier | `npm run format:check` | `npm run format:check` |
| TypeScript | `npm run types:check` | `npm run types:check` |
| Frontend tests | `npm run test:frontend` | `npm run test:frontend` |
| Production build | `npm run build` | `npm run build` |
| Pest | `php artisan test --compact --ansi` | `php artisan test --compact` or `composer test` |

Run the same set locally:

```bash
composer ci:check
```

That also runs `php artisan wayfinder:generate --with-form` first so TypeScript can resolve gitignored `@/actions` and `@/routes`.

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
| Node | 20 (lockfile via `npm ci`) |
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

## Deployment

`.github/workflows/deploy.yml` still performs the existing Hostinger SSH/rsync deploy. It no longer starts on every `push` to `main`.

It runs via `workflow_run` after **`CI` succeeds** for a **push** to **`main`** on this repository. Failed CI, pull requests, and other branches do not deploy.

If you skip GitHub Actions (for example a force-push that never ran CI), GitHub will not deploy that revision. That is intentional.

## GitHub vs local

Passing `composer ci:check` locally means the **same commands** succeeded on this machine. It does not prove the GitHub-hosted workflow ran. Confirm Actions on the repository after push.
