# HTTP / browser security headers

OMS-HRM sets an explicit browser security-header policy in Laravel for `web` responses. Reverse proxies (Herd, nginx, Hostinger, CDN) may add overlapping headers; this application does not overwrite an existing `Strict-Transport-Security` value.

This does **not** replace tenant isolation, Spatie permissions, or privileged 2FA. See [permissions.md](./permissions.md) and [privileged-2fa.md](./privileged-2fa.md).

## What Laravel controls

Middleware: `App\Http\Middleware\SecurityHeaders`, registered last on the `web` group in `bootstrap/app.php`. The same policy is applied to rendered exception responses via `$exceptions->respond()` so 403/404 HTML still carries the headers.

| Header | Laravel value | Notes |
|--------|---------------|--------|
| `Content-Security-Policy` | See [CSP](#content-security-policy) | Enforced by default. `SECURITY_CSP_REPORT_ONLY=true` switches to `Content-Security-Policy-Report-Only` and removes the enforcing header. No reporting endpoint. |
| `X-Frame-Options` | `DENY` | Defense in depth with CSP `frame-ancestors 'none'`. |
| `X-Content-Type-Options` | `nosniff` | Applied to HTML, JSON, downloads, and `/sw.js`. |
| `Referrer-Policy` | `strict-origin-when-cross-origin` | Avoids leaking signed-URL paths on HTTPS cross-origin navigations; keeps a same-origin referrer. |
| `Permissions-Policy` | Restricts unused device APIs | See [Permissions-Policy](#permissions-policy). |
| `Strict-Transport-Security` | `max-age=31536000; includeSubDomains` | Only when the request is HTTPS **and** HSTS is enabled. **No `preload`.** |
| `Cache-Control` | Unchanged globally | Platform logs/jobs/database viewer get `no-store` if it is not already present. Authenticated Inertia HTML/JSON already use `no-store` via `HandleInertiaRequests`. |

`DenyFraming` remains on public announcement routes and only sets `X-Frame-Options: DENY`. It must **not** set `Content-Security-Policy`, because that would replace the full policy with `frame-ancestors` only.

WhatsApp webhook routes use `withoutMiddleware('web')` and therefore do **not** receive these headers. That is intentional (machine callbacks, not browser UI). Hikvision webhook routes stay on `web` and get the headers; they are harmless on those responses.

## What infrastructure should control

The repository does **not** ship nginx, Docker, or CDN config. Operators may also set:

| Header | Recommendation |
|--------|----------------|
| TLS / certificates | Terminate HTTPS at Herd (local) or Hostinger/nginx (production). |
| `Strict-Transport-Security` | Optional duplicate at the proxy. Laravel skips HSTS if the header is already present. Do **not** add `preload` without an explicit operational decision. |
| `TRUSTED_PROXIES` | Required when TLS terminates in front of PHP so `$request->secure()` (HSTS and `SESSION_SECURE_COOKIE`) is correct. Set explicit proxy address(es). `*` is **ignored** (clients could spoof `X-Forwarded-Proto` / `X-Forwarded-For`). |

Herd local HTTPS with `APP_ENV=local` does **not** emit HSTS. That is intended.

## Production environment

| Variable | Production recommendation |
|----------|---------------------------|
| `APP_ENV` | `production` |
| `APP_URL` | `https://…` |
| `SESSION_SECURE_COOKIE` | `true` |
| `SESSION_HTTP_ONLY` | default `true` (do not disable) |
| `SESSION_SAME_SITE` | default `lax` (required for email GET links to shares/e-sign) |
| `SESSION_DOMAIN` | leave unset unless you have a deliberate cookie-domain design |
| `TRUSTED_PROXIES` | explicit proxy IPs when TLS is terminated upstream. `*` is ignored |
| `SECURITY_CSP_REPORT_ONLY` | unset/`false` (enforced). Set `true` only during a short inspection window |
| `SECURITY_HSTS` | unset (on in production when HTTPS). `false` disables; `true` forces on even outside production (not for local HTTP) |
| `PRIVILEGED_2FA_ENFORCED` | `true` after operators enroll (Phase 4A; unrelated to this header set) |

## Content-Security-Policy

One profile for the authenticated app, Fortify auth pages, public document shares, e-sign, and public announcements. Preview/PDF needs are expressed as `frame-src 'self'`, `worker-src 'self' blob:` (pdf.js worker + blob fallback), and `img-src` `data:` / `blob:` (signatures, object URLs) — not as a weaker global framing policy. `font-src` is `'self'` only (bundled `@fontsource` files). `media-src` is `'self'` only; blob object URLs are used for images and downloads, not `<audio>`/`<video>`.

### Production / `testing`

```
default-src 'self'
base-uri 'self'
form-action 'self'
object-src 'none'
frame-ancestors 'none'
script-src 'self'
style-src 'self' 'unsafe-inline'
img-src 'self' data: blob:
font-src 'self'
connect-src 'self'
worker-src 'self' blob:
frame-src 'self'
manifest-src 'self'
media-src 'self'
```

Never `'unsafe-eval'`. Never `*` or broad `https:` / `wss:` in production.

### Why `'unsafe-inline'` on styles

React `style={{}}` attributes and the FOUC `<style>` block in `resources/views/app.blade.php` require `style-src 'unsafe-inline'`. A nonce architecture was not added in this phase. Treat hashed/nonced styles as follow-up debt.

Appearance bootstrap lives in `public/js/appearance.js` (with `data-appearance` on `<html>`) so production `script-src` does **not** need `'unsafe-inline'`.

Legacy password-protected share Blade (`documents/share-password`) is self-contained CSS. It must not load `cdn.tailwindcss.com` or Google Fonts.

### Local development (`APP_ENV=local`)

In addition to the production directives:

- `script-src` also allows `'unsafe-inline'` (Vite `@viteReactRefresh`) and configured Vite origins
- `style-src` / `font-src` / `img-src` also allow those Vite HTTP(S) origins so `npm run dev` can load `/resources/css/app.css` and bundled fonts from `:5173`
- `connect-src` also allows those Vite HTTP(S) and WS(S) origins for HMR

Vite origins come from `SECURITY_CSP_VITE_ORIGINS` / `config('security.headers.csp.vite_dev_origins')`. Only `http`/`https`/`ws`/`wss` URLs whose host is `.test`, `localhost`, `127.0.0.1`, or `::1` are accepted. The `Host` header is never trusted.

`testing` uses the production policy so Pest does not inherit Vite HMR allowances.

## HSTS

Emitted only when **all** of the following hold:

1. `$request->secure()` is true
2. HSTS is enabled (`SECURITY_HSTS` unset ⇒ `APP_ENV=production`; explicit boolean otherwise)
3. The response does not already have `Strict-Transport-Security`

Value: `max-age=31536000; includeSubDomains`. Preload is not set.

Local HTTP and Herd HTTPS with `APP_ENV=local` do not send HSTS. Production HTTP (misclassified TLS) also does not — fix `TRUSTED_PROXIES` rather than forcing the header.

## Frame protection

All OMS-HRM HTML surfaces are **not** embeddable:

| Surface | `frame-ancestors` | `X-Frame-Options` |
|---------|-------------------|-------------------|
| Authenticated app | `'none'` | `DENY` |
| Login / Fortify / Security | `'none'` | `DENY` |
| Public signed document share | `'none'` | `DENY` |
| Public e-sign | `'none'` | `DENY` |
| Public announcements | `'none'` | `DENY` (also `DenyFraming`) |

Same-origin PDF/document **previews** use `<iframe src={same-origin file URL}>` or `srcDoc`. That is `frame-src 'self'`, not a `frame-ancestors` exception. `object-src` stays `'none'`; pdf.js uses a worker + canvas, not `<object>`.

## Permissions-Policy

Disables unused powerful features:

`camera`, `microphone`, `geolocation`, `payment`, `usb`, `serial`, `bluetooth`, `accelerometer`, `gyroscope`, `magnetometer`, `display-capture`, `browsing-topics`

OMS-HRM does not use `getUserMedia` or geolocation in the browser. Hikvision “camera” settings are server-side device config, not the Web Camera API.

Web Push / Notifications are **not** disabled here (they are not the same as those Permissions-Policy features). Do not disable `publickey-credentials-*` (Fortify / WebAuthn-adjacent).

## Session cookies

Laravel `config/session.php`:

| Setting | Default | Do not change without product review |
|---------|---------|--------------------------------------|
| `http_only` | `true` | XSS must not read the session cookie |
| `same_site` | `lax` | Email GET links to shares and e-sign must still send the cookie on top-level navigations |
| `secure` | `env('SESSION_SECURE_COOKIE')` | Set `true` in production HTTPS |
| `domain` | `env('SESSION_DOMAIN')` | Leave null unless you have a cookie-domain plan |

Overly strict `SameSite=None`/`Strict` was not applied.

## Cache

Do **not** apply `no-store` to the entire application (PWA, static assets, public marketing-free HTML).

Already covered:

- Inertia JSON: `no-store, private, max-age=0`
- Authenticated HTML document responses: `private, no-cache, no-store, max-age=0, must-revalidate`
- `/sw.js`: `no-cache, no-store, must-revalidate`

Additionally, named routes `log`, `log.*`, `jobs.*`, and `mysql.*` receive `no-store` when missing.

## Public routes

| Class | Routes | Header profile |
|-------|--------|----------------|
| Authenticated app | `/organization/*`, platform, settings | Full policy |
| Public signed shares | `/documents/shared/{token}`, legacy `/organization/documents/share/{document}` | Same CSP/framing; downloads keep `Content-Disposition` + `nosniff` |
| Public e-sign | `/esign/{token}` | Same; `form-action 'self'` |
| Public announcements | `/announcements/public/{token}` | Same + `DenyFraming` |
| Auth | Fortify login / 2FA challenge / security | Same |
| WhatsApp webhooks | `whatsapp/webhook`, `webhooks/whatsapp` | No `web` middleware / no these headers |

## PWA / Vite

- Manifest: `manifest-src 'self'` (`/manifest.json`)
- Service worker: Laravel `/sw.js` (`injectRegister: false`). Worker does not fetch third-party origins
- pdf.js worker: `/pdf.worker.min.js` (`worker-src 'self' blob:`)
- Production Vite chunks: same origin (`script-src 'self'`)
- Dev HMR: local CSP only

## Manual QA checklist (production-like `npm run build`)

1. Login and dashboard load with no CSP violations in the browser console
2. Cmd/Ctrl+K, Favorites, Recent Items, Saved Views
3. Document preview (iframe) and download (`Content-Disposition` intact)
4. Public signed share portal, password unlock, download
5. Public e-sign page submit
6. Public announcement page (must not be embeddable)
7. Settings → Security / 2FA
8. Service worker registers at `/sw.js`; web push permission still works
9. Confirm response headers: CSP, `nosniff`, `DENY`, Referrer-Policy, Permissions-Policy
10. On production HTTPS only: HSTS without `preload`

## Tests

- `tests/Feature/SecurityHeadersTest.php`
- `tests/Unit/Support/ContentSecurityPolicyTest.php`
- Assertions on document share, e-sign, announcements, and `/sw.js`
