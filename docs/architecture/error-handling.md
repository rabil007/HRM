# Centralized error handling

OMS-HRM serves shared Inertia error pages for common HTTP failures while preserving JSON/API contracts, authentication redirects, and tenant-safe 404 semantics.

## Backend

- Renderer: `App\Support\Http\InertiaHttpErrorRenderer`
- Registration: `bootstrap/app.php` (`$exceptions->render` + existing `SecurityHeaders` `respond` hook)
- Supported Inertia pages: `resources/js/pages/errors/{400,401,403,404,419,429,500,503}.tsx`

### Behavior matrix

| Status | Browser / Inertia HTML | JSON (`Accept: application/json`) | Notes |
| --- | --- | --- | --- |
| 400 | `errors/400` | Laravel JSON error | Invalid request |
| 401 | `errors/401` | JSON / redirect | Guests hitting protected routes still redirect to login (no 401 page) |
| 403 | `errors/403` | JSON message | Authorization failures |
| 404 | `errors/404` | JSON 404 | Includes hidden tenant-scoped records (404, not 403) |
| 419 | `errors/419` | JSON | CSRF / session token mismatch |
| 429 | `errors/429` | JSON | Optional `retry_after_seconds` prop from `Retry-After` |
| 500 | `errors/500` | JSON | Production only; debug mode keeps Laravel Ignition |
| 503 | `errors/503` | JSON | Maintenance / temporary outage |

Validation exceptions and custom exceptions that implement `render()` (for example privileged 2FA) are unchanged.

## Frontend

- Shared UI: `resources/js/components/errors/error-page.tsx`
- Copy/actions: `resources/js/lib/errors/error-page-config.ts`
- Layout: `errors/*` pages use `inertiaPageLayoutKind` → `none` (no authenticated shell)
- Global recovery: `resources/js/components/http-exception-toasts.tsx` (flash, HTTP exceptions, offline/network)

### Adding a new status

1. Add config in `error-page-config.ts`.
2. Add thin page `resources/js/pages/errors/{status}.tsx`.
3. Add the status to `InertiaHttpErrorRenderer::SUPPORTED_STATUSES`.
4. Add focused Pest + frontend config tests.

Do not duplicate headings, buttons, or layout markup in the page file.

## PWA / offline

- Static fallback: `public/offline.html` (no tenant data)
- Service worker (`public/service-worker.js`) caches only `offline.html` and serves it for **failed navigation** requests. Inertia JSON, authenticated HTML, and API responses are not cached.
- In-app offline/reconnect messaging uses the shared toast helpers in `resources/js/lib/errors/network-recovery.ts` (debounced, no automatic mutation replay).

## Security

- Error pages never include exception messages, SQL, or stack traces in production.
- Cross-company “not found” remains HTTP 404 with the same user-facing copy as a missing record.
- CSRF/session recovery does not replay POST/PUT/PATCH/DELETE automatically.
