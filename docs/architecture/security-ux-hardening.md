# Security and UX hardening

This guide records the cross-cutting hardening added in the security/search/mobile productivity pass.

## WhatsApp webhook authentication

WhatsApp webhook GET verification continues to use the configured verification token.

Webhook POST requests now require Meta's `X-Hub-Signature-256` header. The application computes an HMAC-SHA256 over the raw request body using the encrypted WhatsApp `app_secret` and rejects missing or mismatched signatures before processing delivery statuses.

The app secret remains server-side and is never exposed through Inertia props.

## Global search

`/search` is an authenticated web endpoint used by the command palette. It is not a general REST API.

Search rules:

- Resolve the company only from trusted `current_company_id`.
- Search each record family only when the user has its backend view permission.
- Scope every query by `company_id` before matching.
- Return navigation metadata only; do not return sensitive record payloads.
- Current families: employees, employee documents, crew assignments/vessels, and payroll periods.

## Authorization consistency audit

Authorization is considered valid when the request reaches one of the project's authoritative backend boundaries:

1. route `can:` middleware for simple capabilities;
2. Form Request `authorize()` when the request owns mutation authorization;
3. Gate/domain guard when authorization depends on model ownership or workflow state;
4. an explicit controller guard for existing complex payroll/read surfaces.

Do not label a route insecure solely because it lacks route-level `can:` middleware. Verify its controller, Form Request, and domain guard first.

### Verified examples

- Crew movement actions: company guard plus Gate authorization.
- Payroll hub/show: controller permission guard plus active-company ownership.
- Crew timesheet upsert: Form Request permission authorization and tenant-scoped period/employee existence validation.
- Payslip generate/email: Form Request permissions; read/download paths additionally check company ownership and access permissions.
- Global search: authenticated route plus per-result-family permission and company scoping.

### Known platform-administration debt

The authenticated `/log`, `/jobs`, and `/mysql` surfaces remain separate platform-administration security debt. They were intentionally not changed in this pass because the selected scope did not include the earlier P0 platform-operations items. Do not treat their current authorization pattern as precedent.

## Navigation authorization

Navigation is UX only. Backend authorization remains authoritative.

A view permission must make a view discoverable even when the same role cannot create records. For example, `users.view` is sufficient to show Users navigation; `users.create` controls create actions separately.

Top-navigation landing pages must also be reachable by the exact permission combination that caused the link to appear. Corrections-only Crew users land on Movement Corrections, and Payroll overview-only users land on Payroll Overview.

## Mobile list behavior

For organization modules with an existing grid/card representation, a persisted desktop `list` preference is converted to `grid` on screens below 768px. The stored desktop choice is not overwritten, so returning to desktop restores the user's table preference.

Employee cards are included in this behavior because the employee table is intentionally wide and the card view is already the better mobile representation.

## Saved views, favorites, and recent destinations

The command palette stores workspace shortcuts in browser `localStorage`, scoped by authenticated user ID and active company ID.

- Favorites: manually pin the current page.
- Saved views: retain the current URL including filters/query parameters.
- Recent: remember destinations opened through the command palette.

This is intentionally local-first. It introduces no tenant-owned database table and no server synchronization. Cross-device synchronization can be added later as a separate product decision.

## Privileged two-factor rollout

Privileged 2FA enforcement is controlled by:

```env
PRIVILEGED_2FA_ENFORCE=false
```

The configured permission list lives in `config/security.php`.

Recommended rollout:

1. Confirm Fortify 2FA is enabled.
2. Ensure every affected privileged user can access Security settings.
3. Have privileged users enroll and retain recovery codes.
4. Enable `PRIVILEGED_2FA_ENFORCE=true` in production.
5. Verify privileged users can continue through payroll, role, signing, integration, and crew correction workflows.

When enforcement is enabled, an unenrolled privileged user is redirected to Security settings. Enrollment/logout endpoints are exempt so the user can recover.

## Security headers

Web responses add conservative headers that do not require CSP nonce changes:

- `X-Content-Type-Options: nosniff`
- `X-Frame-Options: SAMEORIGIN`
- `Referrer-Policy: strict-origin-when-cross-origin`
- `Permissions-Policy: geolocation=(), microphone=(), payment=()`
- HSTS for HTTPS responses in production

A strict Content Security Policy is intentionally not introduced in this pass because it must be validated against Vite assets, PDF previews, signing flows, PWA behavior, and any integration-hosted resources before enforcement.

## CI quality gate

CI now uses Node 24, matching the current Puppeteer and pdf.js engine requirements. The main test workflow builds the frontend and runs the PHP test suite. The read-only quality workflow checks PHP formatting, Prettier formatting, TypeScript, and frontend tests.

The repository currently has a pre-existing backlog of auto-fixable ESLint import-order violations. Until that backlog is cleaned in a dedicated formatting-only change, CI validates ESLint with `--fix-dry-run`: ESLint applies auto-fixes in memory only, writes nothing to the checkout, and still fails on non-auto-fixable lint errors. This prevents legacy style debt from blocking unrelated product changes while avoiding the previous CI behavior of rewriting source files.
