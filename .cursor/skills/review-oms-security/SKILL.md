---
name: review-oms-security
description: Review OMS-HRM code for tenant isolation, backend authorization, credential exposure, unsafe secret updates, mass assignment, and sensitive logging. Use for security reviews and any change involving authentication, company-owned data, private employee files, privileged actions, SMTP, WhatsApp, Hikvision, signing credentials, share links, webhooks, imports, or exports.
---

# Review OMS-HRM Security

Current code, routes, migrations, and tests are authoritative. Domain guides hold detail; this skill is the compact review control plane. Do not copy those guides here.

## Method

1. Trace the request from route middleware through Form Request or controller, domain query/action, model access, and Inertia or download response.
2. Verify every tenant-owned lookup and mutation is constrained to trusted request `current_company_id`; never trust submitted `company_id`. Test cross-company identifiers explicitly.
3. Verify backend authorization for every endpoint. Treat frontend visibility and `can` props as UX only. Spatie team context, ownership validation, permissions, and tenant isolation are separate controls.
4. Inspect all response paths: Inertia props, JSON, redirects, validation errors, logs, jobs, notifications, exports, and exception messages.
5. Never return decrypted credentials. Return a fixed masked placeholder only when useful and an explicit `has_*` boolean.
6. On update, interpret an empty credential field as “preserve the stored value” unless an explicit removal path exists. Replace a secret only when a non-empty validated value is submitted.
7. Check temporary links, signatures, tokens, uploaded files, and exports for expiry, tenant binding, permission checks, and accidental disclosure.
8. Add targeted Pest coverage for authorized use, missing permission, cross-company access, masked response values, and empty-secret preservation as applicable.
9. Report findings by severity with exact file and line references. Do not claim a control exists without tracing the executable path. Where effectiveness depends on the live host, label **DEPLOYMENT VERIFICATION REQUIRED**.

## Preserve these invariants

Do not regress these in reviews or unrelated refactors.

### Global login identity

User email is a global live login identity. Do not reintroduce tenant-scoped duplicate `User` rows. Authentication and password reset fail closed when a normalized email resolves to zero or multiple non-deleted users (`UserEmailIdentity::findUnique`). Preserve normalized/lowercase email handling and the DB unique live-email backstop (`users.active_login_email` / `uq_users_active_login_email`). Do not expose whether failure is missing, disabled, suspended, or ambiguous. See `docs/permissions.md#global-user-email-identity`.

### Active account requirement

Only `users.status = active` may authenticate, complete a pending Fortify 2FA login, remain authenticated, or return via remember-me. Account deactivation/suspension must continue revoking access. Do not confuse `users.status`, `company_user.status`, and `employees.status`. See `docs/permissions.md#global-user-account-status`.

### Session / password invalidation

Preserve remember-token rotation, invalidation of other sessions after password changes where intended, invalidation after account access is revoked, and Laravel `AuthenticateSession`. Do not remove these because database-session deletion appears duplicated.

### Privileged 2FA

`privileged.2fa` is independent defense-in-depth. `can:permission` does not replace it. Do not remove `privileged.2fa` from high-trust routes because a Spatie permission is already present. The catalog is `App\Support\Auth\PrivilegedTwoFactorPolicy`. When a change adds or modifies a sensitive operation, review whether it belongs in that catalog. Do **not** change `PRIVILEGED_2FA_ENFORCED` defaults or production rollout settings as part of unrelated work. See `docs/privileged-2fa.md`.

### Private employee files

Employee documents and training certificates remain behind the private file boundary (`EmployeePrivateFile`): private/local storage for new sensitive files, authorized preview/download routes, company/path-prefix validation, rejection of traversal/remote paths, and authorized private-file use by PDF/email/WhatsApp/bulk workflows. Do **not** emit public `/storage` URLs. Legacy public-disk compatibility is migration support, not the desired final architecture.

### Secret handling

Preserve encrypted credential storage where applicable. No decrypted secrets in Inertia props, logs, jobs, exceptions, or activity data. Use masked placeholders / `has_*`. Empty secret submissions preserve the stored secret unless explicit removal is supported.

### Hikvision webhook trust

The webhook payload is **not** attendance authority. It may trigger processing; trusted attendance/access data must continue coming from the configured Hikvision API fetch (`ProcessHikvisionWebhookEventJob`). Do not restore direct attendance upsert from webhook JSON. Preserve webhook authentication, timestamp checks, and tenant resolution. The GET HMAC handshake is an independently reviewable low-severity concern; do not redesign it in unrelated changes.

### WhatsApp webhook authentication

WhatsApp POST authenticity must validate Meta's signature against the **raw** request body (`$request->getContent()`). Do not authenticate reconstructed or parsed JSON. Keep verification-failure behavior and rate limiting intact.

### HTTP / production security

Preserve CSP/security-header middleware, framing protection, nosniff, referrer policy, HSTS behavior, production debug fail-safe, secure-session handling, and explicit trusted-proxy policy. Never recommend `TRUSTED_PROXIES=*`. Live host/header/cookie/HSTS effectiveness is **DEPLOYMENT VERIFICATION REQUIRED**. See `docs/security-headers.md`.

### Audit / privacy

`audit.view` remains required for audit information. Review activity/log changes for credentials, tokens, employee sensitive data, personal identifiers, and bank information. Security events should retain useful actor/company/route/context without logging secrets.

### Exports / imports

Inspect independently of page visibility: tenant scope, route permissions, field-level permissions for sensitive related-domain information, generated files, CSV/Excel disclosure/injection, and related-record ownership. Do not assume `employees.export` automatically authorizes bank-account, contract/payroll, or other separately permissioned domain data.

### Dependency security

For security audits with a local checkout, include non-mutating checks where appropriate: `composer audit`, `npm audit --json`, `npm audit --omit=dev --json`. Never automatically run audit fix/update commands. Differentiate production/dev and direct/transitive findings.

## References

- `docs/permissions.md` — tenancy, account status, email identity, audit
- `docs/privileged-2fa.md` — privileged-action 2FA
- `docs/security-headers.md` — CSP, HSTS, session, trusted proxies
- Matching integration guides for WhatsApp, Hikvision, and SMTP/email settings
