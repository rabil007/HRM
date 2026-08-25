# Privileged two-factor authentication

Fortify already provides login 2FA, recovery codes, and the Security settings page. This guide describes the **additional** privileged-action gate: users who hold high-trust capabilities must be enrolled in Fortify 2FA before those operations run.

2FA does **not** replace authorization. The model remains:

authenticated + correct tenant + correct backend permission + 2FA when the action is privileged

Frontend `auth.two_factor` flags are UX only. Direct HTTP requests are still enforced on the server.

## What this application can prove

There are two different 2FA ideas. Only the first is implemented as a privileged-action check.

| Concept | Meaning | Proven by |
|---------|---------|-----------|
| **Enrolled / confirmed** | The user has configured and confirmed Fortify 2FA (`hasEnabledTwoFactorAuthentication()`). With current Fortify `confirm` enabled, that requires both `two_factor_secret` and `two_factor_confirmed_at`. | Privileged middleware and `PrivilegedTwoFactorPolicy` |
| **Recently verified / step-up** | The user completed a second-factor challenge in this session or within a short window, per action. | **Not supported.** Do not invent client-side “recently verified” state. |

Enrolled users are challenged at **login** (`two-factor.login`) and remain guests until the TOTP or recovery-code challenge succeeds. That login challenge still requires `users.status = active`; a disabled account cannot complete it. See [Global user account status](./permissions.md#global-user-account-status). After a successful login, the privileged gate checks that enrollment is **still** confirmed. Disabling 2FA immediately blocks privileged actions even if the browser still shows an old page.

A non-null `two_factor_secret` without confirmation is **not** enrolled.

## Privileged capability catalog

Authoritative list: `App\Support\Auth\PrivilegedTwoFactorPolicy::PERMISSIONS`.

Do not duplicate this array in React, navigation, or extra controller checks. Route middleware `privileged.2fa` (and the crew override Support call) is the enforcement point.

### Included Spatie permissions

| Class | Permissions | Why |
|-------|-------------|-----|
| Roles / RBAC | `roles.create`, `roles.update`, `roles.delete` | Permission administration |
| Users / membership | `users.create`, `users.update`, `users.delete` | Role assignment Policy A: `users.update` may assign any active-company role, including Owner. Create can assign a role at insert time. |
| Settings credentials | `settings.application.update`, `settings.integrations.whatsapp.update`, `settings.integrations.hikvision.update`, `hikvision.webhook.manage` | SMTP, WhatsApp, Hikvision, and webhook secret mutation. `settings.application.update` is catalogued because it also unlocks SMTP and e-sign placement; branding/general routes are **not** wrapped. |
| Payroll high-trust | `payroll.periods.approve`, `payroll.periods.mark_paid`, `payroll.wps.export` | Approval, mark paid, and WPS execution/export (WPS also marks records submitted) |
| Crew high-trust | `crew_operations.assignments.void`, `crew_operations.corrections.override` | Void assignments. Override is enforced when it is **used** (self-approval of a correction), not on ordinary `corrections.approve`. |
| Leave high-trust | `attendance.leave-requests.delete_any` | Administrative destroy |

### Intentionally not catalogued

Ordinary create/update work stays usable without 2FA, including employee edits, leave requests, attendance entry, master data, branding/general application settings, email/WhatsApp **templates**, ordinary leave approval, payroll cancel/revert, and company profile updates (`companies.update`).

Signing **configuration** is still gated: company document-settings mutations and bulk e-sign placement update/destroy use `privileged.2fa` even though `companies.update` is not in the catalog (that permission also covers ordinary company profile edits).

### Platform access

`users.platform_access` remains a **user-level** flag (`view` / `manage`). It is not converted back to Spatie permissions.

**Decision: all platform access requires 2FA when enforcement is on**, including read-only `view`. Application logs, job history, and the database viewer can contain sensitive operational and tenant data.

| Access | 2FA when enforced |
|--------|-------------------|
| `platform_access = view` | Required (`/log`, `/jobs`, and `/mysql` when the viewer is enabled) |
| `platform_access = manage` | Required (clear logs, retry/delete jobs, …) |
| Database viewer | Required in addition to `PLATFORM_DATABASE_VIEWER_ENABLED` |

## Enforcement

Alias: `privileged.2fa` → `EnsurePrivilegedTwoFactor`.

Typical route order (unchanged group structure; middleware appended on confirmed routes):

1. `auth` / `verified`
2. tenant (`SetCurrentCompany`)
3. `can:…` or `platform:…`
4. `privileged.2fa`
5. controller / Form Request business guards

A user **without** the permission receives the existing 403, not a “enable 2FA” message.

When enforcement is on and the user is not enrolled:

- Inertia / browser: redirect to `security.edit` with flash error `Two-factor authentication is required for this action.`
- JSON (`Accept: application/json`, not Inertia): `403` with that message
- No mutation occurs

Security / Fortify enrollment routes are **not** in this catalog (no redirect loop). Password confirmation for the Security page is unchanged Fortify behavior.

Crew override is checked inside `ApproveCrewMovementCorrection` when the actor is the requester and uses `crew_operations.corrections.override`. Ordinary second-person approval does not require 2FA.

## Enrollment UX

If a user receives a privileged permission but has not enrolled:

- Dashboard and ordinary modules remain usable
- Privileged destinations/actions are blocked as above
- Security settings stay reachable (`settings.security.view` + existing password confirmation)
- After Fortify enable + confirm, the same action succeeds

Privileged buttons are not hidden as if the permission was removed. The Security page shows a short notice when `auth.two_factor.required_for_privileged_actions` is true and `enabled` is false.

Privileged users should retain `settings.security.view` so they can enroll. That permission is not newly granted by this phase.

## Shared Inertia state

Safe booleans only (`HandleInertiaRequests`):

```
auth.two_factor.enabled
auth.two_factor.required_for_privileged_actions
```

| Flag | Meaning |
|------|---------|
| `enabled` | Fortify `hasEnabledTwoFactorAuthentication()` |
| `required_for_privileged_actions` | Enforcement is on **and** the user currently holds platform access or a catalog permission |

Never shared: `two_factor_secret`, recovery codes, encrypted payloads, provisioning URIs (except the existing Security setup XHR, which is unchanged). `two_factor_confirmed_at` is hidden on the User model.

Do not trust these flags for security.

## User-level 2FA vs company permissions

2FA enrollment is **user-level**. Spatie permissions remain **company/team-scoped**.

Example: enrolled in Company A with `payroll.periods.approve`, member of Company B without that permission — enrollment does not grant payroll approval in B. Company switching changes permissions normally.

## Rollout / configuration

`PRIVILEGED_2FA_ENFORCED` (config `security.privileged_two_factor.enforced`).

| Value | Behavior |
|-------|----------|
| unset / `false` (default) | Privileged routes run with permission checks only. Existing administrators are not locked out. |
| `true` | Catalog + platform privileged routes require enrolled 2FA |

This is a **server** env/config flag, not a client-controlled setting.

**Production recommendation: ON**, after operators enroll.

Safe sequence for existing installations:

1. Leave `PRIVILEGED_2FA_ENFORCED` unset/false
2. Operators log in and enroll 2FA on Security settings
3. Set `PRIVILEGED_2FA_ENFORCED=true` and reload config
4. Confirm privileged actions still work for enrolled operators

Pest defaults to enforcement **off** so existing Fortify/auth tests stay independent. Feature tests turn the flag on explicitly.

## Platform bootstrap

Platform access is still granted with Artisan (no 2FA secrets in CLI):

```bash
php artisan platform:access user@example.com view
php artisan platform:access user@example.com manage
```

Recommended sequence:

1. Grant platform access
2. Log in (password; 2FA challenge only after enrollment)
3. Enroll and confirm 2FA on Security settings
4. Enable `PRIVILEGED_2FA_ENFORCED`

## Recovery codes

Unchanged Fortify behavior. Recovery-code login completes the same challenge as TOTP and then uses the same enrolled-user privileged checks. Recovery codes are not logged, not put in shared Inertia props, and not copied elsewhere.

## Audit

Blocked privileged attempts log a notice: user id and route name only. Secrets and recovery codes are never logged. Fortify enable/disable is not duplicated here.

## Tests

- Catalog unit tests: `tests/Unit/Support/PrivilegedTwoFactorPolicyTest.php`
- Enforcement: `tests/Feature/PrivilegedTwoFactorTest.php`
- Fortify recovery login: `tests/Feature/Auth/TwoFactorChallengeTest.php`
