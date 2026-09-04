# CI quality gates

GitHub Actions **verifies** committed source. It does not reformat or auto-commit files.

The workflow is `.github/workflows/ci.yml` (`CI`). Successful `CI` on a **push** to **`main`** is what [deployment](#deployment) waits for.

## Workflow shape

```text
Detect changes (system PHP classifier + CI plan artifact)
    |
    +-- PHP Style (Pint)                 [if PHP changed]
    |
    +-- Frontend Static                  [if frontend / Wayfinder inputs changed]
    |     ESLint, Prettier, TypeScript, unit tests in parallel
    |
    +-- Frontend Build                   [if the production bundle is affected]
    |     Vite plugin generates Wayfinder; uploads public/build
    |
    +-- PDF Renderer                     [if Chromium/PDF inputs changed]
    |
    +-- Pest 1/6 .. Pest 6/6             [if backend/Pest inputs changed]
              |
        Quality gates (needs-based aggregator, no checkout)
```

Jobs that are not required are **skipped**. The quality gate treats `skipped` as success for those jobs and **fails** if a required job is skipped or unsuccessful.

## Change classification

Classification is fail-safe: empty or unreadable diffs run full CI. Unrecognized paths also run full CI. `.github/` and `composer.json` / `composer.lock` run full CI so infrastructure and PHP dependency changes self-validate.

Independent flags (not a coarse backend→frontend coupling):

| Change | Pint | Pest | Frontend static | Frontend build | PDF Renderer | Deploy |
|--------|------|------|-----------------|----------------|--------------|--------|
| `tests/**` only (non-PDF) | run | run | skip | skip | skip | skip |
| Unrelated backend service | run | run | skip | skip | skip | run |
| `routes/**` or `app/Http/Controllers/**` | run | run | run (Wayfinder) | run | skip | run |
| `resources/js/**` / CSS / Vite / ESLint | skip | skip | run | run | skip | run |
| PDF renderer / Browsershot / overlay preflight | run | run | skip | skip | run | run |
| Dedicated PDF production test file | run | run | skip | skip | run | skip |
| `package-lock.json` | skip | skip | run | run | run | run |
| `docs/**`, root `*.md` | skip | skip | skip | skip | skip | skip |
| `.github/workflows/ci.yml`, `ci.php` | run | run | run | run | run | skip |
| Composer lock | run | run | run | run | run | run |

Docs-only paths include `docs/*`, `.cursor/*`, `.agents/*`, `.gemini/*`, root-level `*.md`, and a short list of agent/tooling files.

## Required gates (when classified)

| Gate | Job / step | Local command |
|------|------------|---------------|
| PHP formatting | PHP Style (Pint) → `composer lint:check` | `composer lint:check` (`pint --parallel --test`) |
| Pest | Pest 1/6 .. 6/6 (each file runs in exactly one shard) | `php artisan test --compact` or `composer test` |
| ESLint | Frontend Static → `npm run lint:check` | `npm run lint:check` |
| Prettier | Frontend Static → `npm run format:check` | `npm run format:check` |
| Frontend tests | Frontend Static → `npm run test:frontend` | `npm run test:frontend` |
| TypeScript | Frontend Static → TypeScript → `npm run types:check` | `npm run types:check` |
| Production build | Frontend Build → `npm run build` | `npm run build` |
| PDF Chromium | PDF Renderer job | same three production test files with Puppeteer installed |

Frontend Build uploads `public/build` as `vite-build-<sha>-<run_id>-<run_attempt>`. Detect changes uploads a secret-free `ci-plan-<sha>-<run_id>-<run_attempt>` artifact so deploy can skip or reuse work.

Pest sharding uses largest-processing-time packing over `.github/ci/pest-timings.json` (not file-count round-robin). Pest 4.4 in this repo has no native `--shard` flag. Tests keep sqlite `:memory:` and `RefreshDatabase` isolation (one runner per shard, **not** Pest `--parallel`). Helpers used by more than one test file must live in `tests/Support/`.

Refresh timings after a representative green suite:

```bash
php artisan test --compact --log-junit=storage/logs/pest-junit.xml
php .github/scripts/ci.php pest-timings-from-junit --junit=storage/logs/pest-junit.xml --output=.github/ci/pest-timings.json
```

Do not run a second full suite on every CI job just to rebalance shards. Unknown `*Test.php` files still run; they get the manifest `default_seconds` weight.

**PDF Renderer** stays a dedicated job. Normal Pest shards do not require Chrome. This job installs Node + Chromium, sets `REQUIRE_PDF_RENDERER_TESTS=true`, and runs `PdfOverlayTemplatePdfRendererTest` plus Salary Certificate/Declaration print tests.

```bash
composer ci:check
```

Local `ci:check` is sequential and unsharded; GitHub Actions is the parallel layout above.

## Fix vs verify

| Concern | Fix (mutates working tree) | Verify (CI / `ci:check`) |
|---------|----------------------------|---------------------------|
| PHP formatting | `composer lint` or `vendor/bin/pint` | `composer lint:check` |
| ESLint | `npm run lint` (`eslint --fix`) | `npm run lint:check` |
| Prettier | `npm run format` (`prettier --write`) | `npm run format:check` |

## Versions

| Tool | Version |
|------|---------|
| PHP | 8.4 |
| Node | 22 (lockfile via `npm ci` on cache miss) |

## Caching

- **node_modules**: exact key `node-modules-<os>-<arch>-node22-<package-lock hash>`. No `restore-keys`. Cache hit skips `npm ci`. First lockfile change is a cold install.
- npm download cache via `actions/setup-node` as cold-cache fallback.
- Composer download cache keyed by OS + PHP 8.4 + `composer.lock` (CI test jobs do **not** use `--optimize-autoloader`; production deploy still does).
- Pint, ESLint, Prettier (`--cache --cache-location .cache/.prettiercache`), TypeScript `.tsbuildinfo`.
- Puppeteer browser cache for the PDF job (`storage/app/puppeteer`), still verified with `browsershot:install` / `browsershot:doctor`.

Project `postinstall` installs Chrome unless `CI_SKIP_PUPPETEER_BROWSER_INSTALL=1`. Frontend CI jobs skip that download; PDF/production install Chromium through `php artisan browsershot:install`.

## Wayfinder

`resources/js/actions/`, `resources/js/routes/`, and `resources/js/wayfinder/` are **gitignored**. Frontend Static generates them before `tsc`. Frontend Build relies on the Vite Wayfinder plugin during `npm run build`.

## Permissions

CI uses `contents: read`. Deploy uses `contents: read` plus `actions: read`.

## Deployment

`.github/workflows/deploy.yml` runs via `workflow_run` after **`CI` succeeds** for a **push** to **`main`**:

- Downloads the CI plan. If `deploy_required=false` (tests/docs only), it finishes with **No deployable application changes** and does not SSH.
- **Exact SHA**: `workflow_run.head_sha` then `git reset --hard` on the server. Never `git pull` latest main.
- If `frontend_build_required=true`, the exact CI Vite artifact is required. Missing artifact **fails** deploy (no production rebuild).
- If `frontend_build_required=false`, production keeps the existing gitignored `public/build` directory (`git reset --hard` does not delete it). No Vite, no rsync of frontend assets.
- Production Node/Puppeteer: skip `npm ci` when `storage/app/deploy/npm-lock.sha256` matches `package-lock.json` and `browsershot:doctor` passes. Otherwise `npm ci --omit=dev` then a single Browsershot install, then write the stamp.
- Deploy concurrency: `deploy-main`, `cancel-in-progress: false`.

## GitHub vs local

Passing `composer ci:check` locally does not prove GitHub Actions. Confirm the Actions run after push. First `package-lock.json` change is expected to be slower (cold `node_modules` cache).
