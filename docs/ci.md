# CI quality gates

GitHub Actions **verifies** committed source. It does not reformat or auto-commit files.

The workflow is `.github/workflows/ci.yml` (`CI`). Successful `CI` on a **push** to **`main`** is what [deployment](#deployment) waits for.

## Workflow shape

```text
Detect changes
    |
    +-- PHP Style (Pint)
    |
    +-- Frontend Static
    |     ESLint, Prettier, TypeScript, frontend tests
    |
    +-- Frontend Build
          Wayfinder, npm run build, upload public/build
                    |
          +---------+---------+
          |         |         |
       Pest 1    Pest 2    Pest 3
          |         |         |
          +---------+---------+
                    |
              Quality gates
```

1. **Detect changes** — classifies the diff as `docs-only`, `backend-only`, `frontend-only`, or `full`. Unknown paths force full CI.
2. Independent checks run in parallel. Pest shards wait only for the Vite build artifact (Inertia tests need `public/build/manifest.json`).
3. **Quality gates** — aggregator job that must succeed. Expected skips (for example docs-only) still pass this aggregator when change detection succeeds.

Docs-only paths include `docs/*`, `.cursor/*`, `.agents/*`, `.gemini/*`, root-level `*.md`, and a short list of agent/tooling files. Any unrecognized path forces full CI.

## Change classification

Classification is fail-safe: empty or unreadable diffs run full CI. Shared or uncertain application files also run full CI.

| Scope | Pint | Frontend static | Frontend build | Pest |
|-------|------|-----------------|----------------|------|
| `docs-only` | skip | skip | skip | skip |
| `backend-only` | run | skip | run | run (3 shards) |
| `frontend-only` | skip | run | run | skip |
| `full` | run | run | run | run (3 shards) |

Examples that force **full** CI (both sides):

- `routes/`, `app/Http/`, `app/Providers/`, `bootstrap/`, `config/`
- `database/` (including migrations)
- `composer.json` / `composer.lock` / `package.json` / `package-lock.json`
- `vite.config.*`, `tsconfig.json`, `.env.example`
- Inertia pages (`resources/js/pages/`, `resources/js/app.tsx`)
- `resources/views/`, `public/`
- `.github/` (including this workflow)
- any path the classifier does not recognize

`backend-only` still builds the frontend because Pest needs the Vite manifest. `frontend-only` still runs the production build gate.

## Required gates (full application CI)

| Gate | Job / step | Local command |
|------|------------|---------------|
| PHP formatting | PHP Style (Pint) → `composer lint:check` | `composer lint:check` (`pint --parallel --test`) |
| Pest | Pest 1/3, 2/3, 3/3 (each file runs in exactly one shard) | `php artisan test --compact` or `composer test` |
| ESLint | Frontend Static → `npm run lint:check` | `npm run lint:check` |
| Prettier | Frontend Static → `npm run format:check` | `npm run format:check` |
| TypeScript | Frontend Static → `npm run types:check` | `npm run types:check` |
| Frontend tests | Frontend Static → `npm run test:frontend` | `npm run test:frontend` |
| Production build | Frontend Build → `npm run build` | `npm run build` |

Frontend Build uploads `public/build` as a workflow artifact named `vite-build-<sha>-<run_id>-<run_attempt>`. Each Pest shard downloads that same artifact and refuses to start without `public/build/manifest.json` for this SHA. Shards do not rebuild Vite.

Pest sharding is deterministic file round-robin over `tests/Unit/**/*Test.php` and `tests/Feature/**/*Test.php` via `.github/scripts/ci.php`. Pest 4.4 in this repo has no native `--shard` flag; the helper splits the suite so the full set runs exactly once. Tests keep sqlite `:memory:` and `RefreshDatabase` isolation (one runner per shard, not Pest `--parallel`).

Run the same local set:

```bash
composer ci:check
```

That also runs `php artisan wayfinder:generate --with-form` first so TypeScript can resolve gitignored `@/actions` and `@/routes`. Local `ci:check` is sequential and unsharded; GitHub Actions is the parallel layout above.

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

`resources/js/actions/`, `resources/js/routes/`, and `resources/js/wayfinder/` are **gitignored**. The Vite plugin generates them during `npm run build` / `npm run dev`. CI generates them explicitly before TypeScript and before the production build:

```bash
php artisan wayfinder:generate --with-form --no-interaction
```

Do not commit those directories.

## Caching

CI caches Composer’s **download** cache (keyed by `composer.lock`) and npm’s **download** cache (via `actions/setup-node`). It does not cache `vendor/` or `node_modules/` as restore artifacts. Puppeteer browsers are cached under `~/.cache/puppeteer` on jobs that run `npm ci`.

## Permissions

The CI workflow uses `contents: read` only. It does not need `contents: write`, `pull-requests: write`, or `packages: write`. Deploy uses `contents: read` plus `actions: read` so it can download the CI Vite artifact from the triggering workflow run.

## Branch protection

Confirm required checks in GitHub **Settings → Branches / Rulesets**. This repository’s CI aggregator job is named `Quality gates`. This documentation does not assert that `main` is currently protected; verify the live GitHub configuration.

## Deployment

`.github/workflows/deploy.yml` performs the Hostinger SSH/rsync deploy. It runs via `workflow_run` after **`CI` succeeds** for a **push** to **`main`** on this repository:

- **Exact SHA checkout**: Deploys the exact CI-validated commit revision (`workflow_run.head_sha`) rather than `origin/main`.
- **Validated frontend assets**: Downloads the Vite `public/build` artifact from that same CI run and SHA. If the artifact is missing (docs-only CI) or the embedded SHA/run id does not match, deploy rebuilds with `npm ci && npm run build` instead of using another run’s files.
- **Deploy serialization**: Uses `concurrency: group: deploy-main, cancel-in-progress: false` to ensure in-flight deployments are never aborted midway.
- Failed CI, pull requests, and other branches do not deploy.

Production Hostinger still runs `npm ci --omit=dev`, Puppeteer/Browsershot setup, and Artisan cache commands. Those runtime steps are not replaced by the CI artifact.

## GitHub vs local

Passing `composer ci:check` locally means the **same commands** succeeded on this machine. It does not prove the GitHub-hosted workflow ran. Confirm Actions on the repository after push.
