# Application refresh and update synchronization

OMS-HRM notifies authenticated users when a new production release is deployed, lets every user manually refresh shared application state from the global header, and synchronizes Inertia authorization props when roles or memberships change — without logging out.

## Manual Refresh App

The global header includes a **Refresh App** control (Lucide `RefreshCw`) between notifications and theme settings:

`Search | Notifications | Refresh | Settings | User Avatar`

Behavior:

1. Requires network connectivity.
2. Calls `GET /app/version` for the deployed release identifier and the current authorization revision.
3. Reloads the current Inertia page when no newer deploy is waiting.
4. Opens the **Update Available** dialog when a newer deploy (or waiting service worker) is detected.
5. Never clears browser storage, cookies, drafts, or server caches.
6. Never runs Artisan cache-clear commands.

## Deployed version source

Production deploys already write `storage/app/deploy/revision.sha` (see `.github/workflows/deploy.yml`).

`App\Support\AppRefresh\DeployedApplicationVersion` resolves the identifier in this order:

1. `storage/app/deploy/revision.sha`
2. `APP_DEPLOY_VERSION` / `config('app.deploy_version')`
3. Stable fingerprint of `public/build/manifest.json` when present
4. Fallback string `dev`

The value is shared to the client as `app_refresh.version` and exposed by:

```http
GET /app/version
```

Authenticated JSON response:

```json
{
  "version": "deployment-release-id",
  "update_available": false,
  "authorization_revision": "1.3"
}
```

Responses use `Cache-Control: no-store`. The endpoint does not expose environment secrets.

**Important:** GitHub CI success alone does not mean production has the new version. Clients only see an update after the Hostinger deploy job writes the revision stamp.

## Update notification

When a newer version is detected (90s poll while visible, on tab focus, on reconnect, or via manual refresh):

- **Title:** Update Available
- **Later** dismisses the dialog for that version but keeps a subtle amber indicator on the refresh icon
- **Reload Now** warns when unsaved work is detected (`beforeunload` guards or `data-unsaved-changes="true"`), coordinates with the waiting service worker (`SKIP_WAITING`), then hard-reloads

A successful deploy must never silently interrupt an active workflow with unsaved changes.

## PWA / service worker

OMS-HRM registers the Laravel-served push worker at `/sw.js` (`public/service-worker.js`). VitePWA may emit a build worker, but the app does **not** register it.

`PwaUpdatePrompt` routes waiting worker installs into the same update dialog used for deploy-version updates. Full offline-first asset caching is intentionally out of scope for this feature; deploy detection + hard reload is the supported path.

Cache Storage is not wiped indiscriminately. Authentication sessions, IndexedDB data, and unrelated browser caches are left alone.

## Permission synchronization

Frontend permission lists come from Inertia shared props (`auth.permissions`, `auth.roles`). Polling is UX only — backend `can:` / policies remain authoritative.

Revision tokens:

| Component | When it bumps |
| --- | --- |
| `users.authorization_revision` | Membership add/update/remove, role assignment via `UserMembershipAccess::syncRole` |
| `companies.authorization_revision` | Role permission sync/create/duplicate/delete, company Owner bootstrap |

Combined token: `{userRevision}.{companyRevision}` shared as `app_refresh.authorization_revision`.

Inertia permission/role cache keys include the revision, so a bump naturally misses the previous 60s cache entry. Company switcher cache is forgotten on user membership bumps.

Client polling interval for authorization revisions: **30 seconds** while the tab is visible, plus checks on focus/reconnect/manual refresh. When the revision changes, the client reloads shared auth/company props without discarding unrelated client state when possible. If the current page becomes unauthorized, the user is redirected to the dashboard.

## Cache invalidation boundaries

| Action | Effect |
| --- | --- |
| Refresh App | Client Inertia reload / optional hard reload |
| Deploy stamp change | Client update dialog |
| Role/membership mutation | Revision bump + new Inertia auth cache key |
| Refresh App | Does **not** run `optimize:clear`, wipe Cache Storage, or flush Spatie permission cache globally |

Spatie still flushes its own permission cache when it mutates roles/permissions.

## Hosting requirements

- Hostinger deploy must continue writing `storage/app/deploy/revision.sha` after a successful release.
- Optional override: set `APP_DEPLOY_VERSION` when the stamp file is unavailable.
- No Redis, WebSockets, or long-running workers are required for this feature.
