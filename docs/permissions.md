# Permissions

Authorization uses [Spatie Laravel Permission](https://github.com/spatie/laravel-permission) with **company teams**. `SetCurrentCompany` sets `current_company_id` on the request and configures the same value as Spatie's active team before company-scoped permission checks run.

The authoritative permission catalog is `database/seeders/PermissionsSeeder.php`. Route coverage is defined by `routes/web.php` and `routes/settings.php`; do not treat this document as a substitute for checking both.

## Enforcement rules

1. Protect every privileged backend action with the narrowest applicable permission through route `can:` middleware, a gate/policy, controller authorization, or Form Request authorization.
2. Enforce company ownership independently of the capability check. Never trust a client-supplied `company_id`.
3. Treat Inertia `can` props, shared `auth.permissions`, and hidden UI controls as presentation only.
4. Add a test proving an authenticated user without the permission receives `403`.

Most module routes use `middleware('can:permission.name')`. Some Payroll and operations endpoints instead use controller helpers, Form Requests, or Support access classes. That distributed model is intentional where documented (for example Payroll timesheet import template uses `payroll.crew_timesheets.import` or `payroll.crew_timesheets.create`). Authenticated-only routes without a capability check remain exceptions to review, not a pattern to copy.

Cmd/Ctrl+K [global search](./global-search.md) is one of those authenticated JSON lookups. It still authorizes **each record category** with the matching view permission and scopes every tenant source to `current_company_id`. Navigation command visibility stays in `nav-visibility.ts` and is not a substitute for those backend checks.

[Navigation favorites](./navigation-favorites.md) are personal destination shortcuts. They do not grant access. A stored favorite is shown only when the active company's Phase 3A nav visibility would also show that destination.

[Recent items](./recent-items.md) are personal per-company show-page history in Cmd/Ctrl+K. They do not grant access, are not audit logs, and are hidden when the matching view permission or record is gone.

[Saved views](./saved-views.md) are personal per-company named filter combinations on selected list pages. They do not grant access and never store arbitrary URLs.

[Privileged two-factor](./privileged-2fa.md) is defense-in-depth on top of these permission checks. Fortify 2FA enrollment is required for a small catalog of high-trust capabilities (and all platform access) when `PRIVILEGED_2FA_ENFORCED` is on. It does not replace Spatie permissions, tenant isolation, or the global [user account status](#global-user-account-status) check that must succeed before anyone is authenticated.

[HTTP security headers](./security-headers.md) are a separate browser-response control (CSP, HSTS, framing). They do not grant or replace permissions.

Platform diagnostic surfaces (`/log`, `/jobs`, `/mysql`) are **not** tenant Spatie permissions. They use a separate user-level `users.platform_access` flag. See [Platform administration](#platform-administration).

Re-seed after changing the catalog:

```bash
php artisan db:seed --class=PermissionsSeeder
```

Assign permissions through **Organization → Roles & permissions** (`/organization/roles`).

## Permission groups

| Area | Current permission families |
|------|-----------------------------|
| Organization | `companies.*`, `branches.*`, `departments.*`, `positions.*`, `users.*`, `roles.*` |
| Employees | `employees.view|create|update|delete|export|import`, and `employees.salary_certificate.print` / `employees.salary_declaration.print` |
| Contracts / bank / training / sea service / profile tabs | `contracts.view|create|update|delete|import`, `contracts.salary_revisions.view|create|update|delete`, `bank_accounts.view|create|update|delete|import`, `training.view|create|update|delete|import`, `sea_services.view|create|update|delete|import`, `education.view|create|update|delete`, `work_experience.view|create|update|delete|import`, `vaccination.view|create|update|delete|import`, `languages.view|create|update|delete` |
| Documents | `documents.view|download|share|upload|delete`, `documents.templates.view|create|update|delete`, `documents.requests.view|create|review|approve|cancel`, `documents.workflow-presets.view|create|update|delete`, `documents.recipient-requests.view|create|cancel` |
| Bulk documents / signatures | `bulk_documents.view|generate|delete|email`, `bulk_documents.signatures.review` |
| Crew operations | `crew_operations.overview.view`, `crew_operations.vessels.*`, `crew_operations.vessel_manning.*`, `crew_operations.planning.*`, `crew_operations.assignments.*` (incl. `void`), `crew_operations.movements.perform`, `crew_operations.corrections.view|request|approve|override` |

`crew_operations.assignments.void` (Void Erroneous Assignment) is high-trust only: auto-granted to roles that already hold `roles.update` (same convention as `corrections.override`). Permission alone is not sufficient — `CrewAssignmentVoidGuard` blocks voids that would affect protected payroll, sea service, or linked assignment chains.

Re-seed after catalog changes: `php artisan db:seed --class=PermissionsSeeder`. The seeded `Owner` role receives the full catalog when `AdminSeeder` runs.
| Reports | `reports.crew_movement_history.view|export` |
| Attendance / leave | `attendance.overview.view`, `attendance.records.*`, `attendance.types.*`, `attendance.leave-requests.*` (incl. `view_all` and privileged `delete_any`; approve is step-scoped; `assigned_to_me` is historical assignment visibility only), `attendance.leave-approval-policies.*`, `attendance.leave-approval-settings.view|update` (company-scoped approver defaults and leave-request email notification switches; Email Templates still control content/enabled state) |
| Payroll | `payroll.overview.view`, `payroll.periods.*`, `payroll.crew_timesheets.*`, `payroll.salary_inputs.*`, `payroll.records.view`, `payroll.payslips.*`, `payroll.wps.export` |
| Hikvision | `hikvision.persons.*`, `hikvision.devices.*`, `hikvision.events.*`, `hikvision.webhook.manage` |
| Employee profile templates | `employee_profile_templates.view|create|update|delete` |
| Settings | `settings.security.*`, `settings.appearance.view`, `settings.application.*`, integration/template permissions, and `settings.master-data.{resource}.*` |
| Audit | `audit.view` |

The `*` notation above is descriptive only; permissions are seeded as explicit strings, not wildcard grants.

## Global search

Cmd/Ctrl+K record search authorizes each category on the backend (`employees.view`, `documents.view`, `crew_operations.assignments.view`, `crew_operations.vessels.view`, `departments.view`, `positions.view`, and `payroll.periods.view` or `payroll.crew_timesheets.view`). Navigation command visibility stays in `nav-visibility.ts`. See [Global search](./global-search.md).

Crew timesheet timeline workflow permissions (Phase 1C–1D):

| Permission | Capability |
|------------|------------|
| `payroll.crew_timesheets.view` | View timeline preparation review page |
| `payroll.crew_timesheets.prepare` | Create a new draft preparation version |
| `payroll.crew_timesheets.clear` | Clear all Manual/Import timesheets on a Draft crew period |
| `payroll.crew_timesheets.submit` | Submit latest draft timeline preparation, or submit a Manual/Import timesheet for approval |
| `payroll.crew_timesheets.approve` | Approve a submitted preparation, or approve a submitted Manual/Import timesheet |
| `payroll.crew_timesheets.return` | Return a submitted preparation or Manual/Import timesheet with notes |
| `payroll.crew_timesheets.apply_approved` | Apply an approved preparation to crew timesheets |

## Leave request deletion

| Permission | Capability |
|------------|------------|
| `attendance.leave-requests.delete` | Ordinary soft-delete of **pending or cancelled** requests owned by the linked employee (or when combined with `view_all`). Blocked once any approval step has been acted; cancels should be used instead to preserve history. |
| `attendance.leave-requests.delete_any` | Privileged administrative **void and remove**. Requires `view` + `view_all` + `delete_any`. Soft-deletes the request in any workflow status, reverses balance (pending → release pending; approved → reverse used; rejected/cancelled → no balance change), cancels open approval steps while preserving completed history, keeps attachments on disk, and writes a company-scoped audit event. |

Ordinary `delete` must never be broadened to cover approved or mid-approval requests. Administrative deletion uses route `attendance.leave-requests.administrative-destroy` and row capability `can_administratively_delete`.

## Employee and document details

Employee import has one employee permission plus module imports for related records:

| Permission | Import scope |
|------------|--------------|
| `employees.import` | Employee import workflow, including passport and Emirates ID columns |
| `contracts.import` | Contract columns and contract import workflow |
| `bank_accounts.import` | Bank-account columns and bank import workflow |
| `training.import` | Training import workflow |
| `sea_services.import` | Sea service import workflow |
| `work_experience.import` | Work experience import workflow |
| `vaccination.import` | Vaccination import workflow |

Salary certificate and salary declaration prints use `employees.salary_certificate.print` and `employees.salary_declaration.print` (separate from `employees.view`). Education, work experience, vaccination, languages, contracts, bank accounts, training, and sea services use their own view/create/update/delete families rather than the removed `employees.education.manage`, `employees.work_experience.manage`, `employees.vaccination.manage`, `employees.languages.manage`, `employees.contracts.manage`, `employees.bank_accounts.manage`, and `employees.sea_service.manage` names.

Document pages receive their UI flags from `DocumentPagePermissions::for($user)`:

```php
[
    'download' => bool,
    'share' => bool,
    'upload' => bool,
    'delete' => bool,
    'request_approval' => bool,
    'whatsapp_template' => bool,
    'whatsapp_templates' => array,
    'email_templates' => array,
]
```

Review/approval list and decision routes enforce `documents.requests.*` independently. Workflow creation validates that each stage assignee holds the required company-scoped permission for that stage (`documents.requests.review` or `documents.requests.approve`) plus `documents.requests.view`. A user with `documents.requests.approve` may only act on tasks assigned to them; review permission does not grant approval on approval-stage tasks.

Workflow preset management uses `documents.workflow-presets.*`. Selecting an active preset during request creation requires only `documents.requests.create`; preset CRUD permissions are separate. Preset names are unique within the active company. `workflow_preset_id` on request creation is validated against `current_company_id`, not globally by id alone.

Unified document signing and acknowledgement (Phase 6A) uses `documents.recipient-requests.view|create|cancel`. These permissions are separate from legacy `bulk_documents.signatures.review` and from Phase 5 workflow permissions. Token regeneration reuses `documents.recipient-requests.create`. Public `/document-action/{token}` completion requires no authenticated permission; company scope is derived from the persisted request bound to the token hash.

The request creator cannot review or approve their own workflow request (backend enforced in Phase 5A).

These flags do not authorize requests. Document routes enforce `documents.*` permissions and document support classes additionally verify company/employee ownership.

Creating a login from an employee requires `users.create`. User–employee linking is otherwise managed through the user edit workflow.

## Audit

`audit.view` controls the global activity-log page and recent-activity sections on supported detail pages. Without it, recent-activity queries return no entries and the UI hides the section.

Automatic Spatie activity logging now covers a broad set of organization, master-data, employee sub-record, crew, attendance/leave, and payroll models through `LogsActivityWithCompany`. The implementation is the source of truth: search `app/Models` for the trait rather than maintaining a fragile model count here. Operational events that are not model CRUD may be logged manually by services.

## Settings and integrations

Settings are separated cleanly by **ownership**:

### 1. Platform-Global Settings & Integrations
Installation-wide configurations are singleton resources shared across all companies and are governed exclusively by user-level **Platform Authority** (`platform:view` and `platform:manage`), not tenant Spatie permissions:
- **Application Settings** (`/settings/application`): System name, support contact, regional fallbacks, branding, SMTP configuration, AI providers / Smart Employee Search, and e-signature placements. AI setting changes are platform activity (`company_id` null) and are not listed in a tenant Activity Log.
- **WhatsApp Integration** (`/settings/application?tab=whatsapp`): Singleton Meta Cloud API credentials, phone number IDs, and webhooks. Credential mutations enforce `privileged.2fa`.
- **WhatsApp Templates** (`/settings/application/whatsapp-templates`): Global Meta template library mappings (`whatsapp_templates` table).
- **Email Templates** (`/settings/application/email-templates`): Global email template library presets (`email_templates` table).

Legacy Spatie permission names (`settings.application.*`, `settings.integrations.whatsapp.*`, `settings.integrations.whatsapp-templates.*`, `settings.integrations.email-templates.*`) are retained in the permission catalog and seeders for backward compatibility, but do **not** authorize global singleton resources.

### 2. Company-Scoped Settings & Integrations
Tenant-specific configurations are scoped to `current_company_id` and use Spatie **team-scoped permissions**:
- **Company Identity & Regional Defaults** (`/organization/companies/{company}`): Company name, logo, address, legal documents, timezone, currency, and working days (`companies.view`, `companies.update`).
- **Company Document Signing Assets**: Salary certificate signature, company stamp, and authorized signatory (`companies.view`, `companies.update`).
- **Company Document Library**: Membership-based document storage (`company_documents.*`).
- **Hikvision Access Control Integration** (`/settings/integrations/hikvision`): Per-company device endpoints, OpenAPI credentials, and sync settings (`settings.integrations.hikvision.view|update`, `hikvision.webhook.manage`, `hikvision.devices.sync`).
- **Security & Appearance**: Tenant security settings (`settings.security.view|update`) and visual theme overrides (`settings.appearance.view|update`).
- **Master Data**: Tenant-managed dictionaries (`settings.master-data.{resource}.view|create|update|delete`).

### Ownership Matrix

| Concern | Source | Authority |
|---------|--------|-----------|
| Platform name, support email/phone, fallback timezone, date format, branding, SMTP, AI providers / Smart Employee Search, e-sign placement | Global `app_settings` | `platform:view` / `platform:manage` |
| WhatsApp Meta Cloud API singleton integration | Global `whatsapp_settings` | `platform:view` / `platform:manage` + `privileged.2fa` |
| WhatsApp & Email template libraries | Global `whatsapp_templates`, `email_templates` | `platform:view` / `platform:manage` |
| Company name, logo, address, phone, email, website, currency, timezone, payroll cycle, working days, WPS | `companies` row | `companies.view|update` |
| Salary certificate signature/stamp/signatory | `company_document_settings` | `companies.view|update` |
| Hikvision access control device integration | Company-scoped `hikvision_settings` | `settings.integrations.hikvision.*` |

Credential permissions and platform access never imply that decrypted secrets may be sent to the browser. Settings responses expose masked placeholders and `has_*` flags, and empty secret submissions preserve the stored value.

## Users and memberships

User rows are **global identities**. The login identifier is `users.email` (Fortify username, lowercased when `fortify.lowercase_usernames` is true). Company access is the `company_user` membership (plus the legacy `users.company_id` home-company rule), not a second User row with the same email. `users.*` is a Spatie **team** permission for the active company.

Duplicate non-deleted User rows that share one email are **invalid**. Login and password reset fail closed until operators resolve them. See [Global user email identity](#global-user-email-identity).

Membership store/update/destroy are **active-company** operations, matching the Companies registry:

- Trusted company is `current_company_id`. Client `company_id`, hidden fields, and the route `{company}` parameter cannot choose another tenant.
- **Create** may attach a user who is not yet a member of the active company. The membership is always created for the active company.
- **Update/destroy** require an existing membership in the active company. Cross-company targets are 404.
- Dual-company administrators must **switch** before managing the other tenant. `platform_access` is not membership.
- Role IDs are resolved in the active Spatie team (`spatie_roles.company_id`). A role from another company is rejected.

Anyone with `users.update` in the active company may assign any role that exists **for that company**, including Owner. Editing the role/permission matrix remains `roles.update`. This is the current product policy, not a wildcard across tenants.

The Users directory lists people who have active membership in the active company (either home `users.company_id` or an active `company_user` membership pivot). Users who belong natively to Company A and have active membership in Company B are visible within Company B's tenant context. Membership-only users can have their **company membership** managed in that tenant; their **global identity** (name, email, avatar, global status, password, sessions, deletion) can be mutated only from their home company. Frontend row/card/detail actions also require the matching per-user `capabilities` flags in addition to the actor's Spatie permission; those flags are UX only — `GlobalIdentityAccessGuard` remains the backend boundary.

Normal User Edit never changes passwords. A submitted `password` field on user update is ignored: the stored hash, remember token, and sessions stay as they are. Password changes happen only through the user's Security settings, Fortify reset, or the admin password-reset security action.

### Last Active Owner Protection

The `LastCompanyOwnerGuard` ensures that a tenant company never ends up with zero active Owners. The guard inspects the company's active owners (users with the Spatie `Owner` role in that company, `users.status = 'active'`, and an active company membership) within a database transaction using row-level locking (`lockForUpdate()`). An action is rejected if it would deactivate, reassign away from Owner, delete, or detach the membership of the company's sole remaining active Owner. User Update runs that locked check before applying any requested change, so a rejected last-Owner mutation does not leave employee links, identity fields, roles, or avatars partially updated.

### User Invitations and Acceptance Flow

Tenant administrators with `users.create` may invite users into the company via `UserInvitation`:
- **Cryptographic Tokens**: Each invitation generates a cryptographically secure 40-character random token. Only the SHA-256 hash (`token_hash`) is persisted at rest.
- **Tenant Isolation**: Invited roles and employees are strictly validated to belong to `current_company_id`. Employees must be unlinked (`user_id IS NULL`).
- **Resend & Revoke**: Resending an invitation invalidates the previous token by re-generating a fresh token and extending expiration by 7 days. Revoking immediately marks the invitation inactive.
- **Dual-Path Acceptance**:
  - **New User Flow**: If the invited email does not exist as a global identity, the user sets up their name and password (matching standard Fortify password policy). Upon acceptance, the User is created, linked to the company, assigned their role/employee, logged in, and redirected to the dashboard.
  - **Existing User Flow (Zero 2FA / Password Bypass)**: If an account already exists for the invited email, the acceptance flow **NEVER** bypasses login or 2FA. Merely possessing an invitation token does not grant authentication. Unauthenticated visitors are prompted to sign in with their existing credentials. Once authenticated via Fortify (including any required 2FA challenge), the acceptance endpoint verifies that the authenticated user matches the invitation email before linking membership and roles.
- **Fail-Fast Concurrency**: Acceptance operations lock the invitation row with `lockForUpdate()`. Invalid, expired, accepted, or revoked invitations fail closed with immediate transaction rollback.
- **Email template**: Invitation mail uses the branded `user_invitation` template from **Settings → Application → Email Templates** (not Laravel markdown mail). Placeholders include `{{invitee_name}}`, `{{inviter_name}}`, `{{company_name}}`, `{{brand_name}}`, `{{accept_url}}`, `{{expires_at}}`, and `{{role_name}}`. If the template is missing or disabled, the same branded layout is still sent with default copy so the invite is not silently dropped.

### User Security Operations

Administrators with `users.password_reset` or `users.sessions.revoke` (subject to `privileged.2fa` enforcement) may manage security actions against **home-company** users:
- **Password Reset**: Sends a standard Fortify password reset link to the user's email. Restricted to the user's home company via `GlobalIdentityAccessGuard`. This is the admin path for credential rotation; it is not part of normal User Edit.
- **Session Revocation**: Uses `InvalidateUserSessions` to rotate the user's `remember_token` and delete all active database sessions, immediately terminating sessions across all devices.
- **Audit Logging**: All invitation events, security actions, and membership changes are logged to Spatie Activity Log tagged with the company ID.

### Presence & Directory Telemetry

User presence is computed server-side from database session activity and login history:
- **Online**: Active session activity within the last 5 minutes.
- **Recent**: Active session activity between 5 and 30 minutes ago.
- **Offline**: Activity older than 30 minutes or previous login with no active session.
- **Never**: No login or session activity recorded.
- **Two-Factor Status**: Displayed strictly as a boolean (`two_factor_enabled`) indicating whether Fortify 2FA has been fully confirmed; secrets and recovery codes are never exposed.

## Global user account status

`users.status` is a **global account-access** control on the User row. It is not a Spatie permission, is not company-team scoped, and is enforced only on the server.

Only `users.status = active` may authenticate or remain authenticated. `inactive`, `suspended`, null, and any other value are denied (fail closed). Platform users are still `User` rows and must also be `active`.

This is separate from:

- **`company_user.status`** — tenant membership (which company the identity may enter after login). `SetCurrentCompany` still requires an active membership (or the legacy home-company path). Disabling a membership does not, by itself, change `users.status`.
- **`employees.status`** — workforce visibility for operational lists. See [Active employee visibility](./architecture/active-employee-visibility.md).

Frontend status chips are UX only.

### Login

Fortify login uses `Fortify::authenticateUsing()` → `App\Support\Auth\AuthenticateActiveUser`. Identity lookup uses the unique-email users provider (`App\Support\Auth\UniqueEmailUserProvider` → `UserEmailIdentity::findUnique`) then `validateCredentials`. The attempt succeeds only when **exactly one** non-deleted User matches the normalized email, credentials are valid, **and** `UserAccountStatus::allowsAuthentication()` is true.

Zero matches, more than one match, a disabled account, or a bad password all return Fortify's normal invalid-credentials response (`auth.failed`). The response does not say whether the account exists, is inactive, is suspended, or is an ambiguous duplicate identity.

Login throttling, email verification, remember-me, 2FA, password rehashing, and successful-login behavior for **unique active** users are unchanged.

### Password reset

Forgot-password and reset-token completion use the same users provider, so they follow the same unambiguous-email rule. A unique User still receives a reset link (throttled as usual). Duplicate non-deleted rows sharing one email do not send or apply a reset to an arbitrary account; the response matches the normal unknown-user reset failure (`passwords.user`) and does not describe account topology.

### Existing sessions

`EnsureActiveUser` runs on the `web` middleware group (before `SetCurrentCompany`). Guests pass through.

If the currently authenticated user is not active, the middleware logs them out, invalidates the session, regenerates the CSRF token, and redirects to login. They cannot continue using HRM on the next authenticated request.

Login, password reset, Fortify 2FA challenge, signed document shares, public routes, and webhooks remain available to guests. An inactive cookie on those routes is cleared and does not block the public response.

### Remember-me and session stores

Any Eloquent update that changes `users.status` away from `active` runs `RevokeDisabledUserAccess` from `User::updated` (including `UserController::updateStatus` and user edit). That path:

- rotates the remember token so a remembered browser cannot silently authenticate again
- deletes `sessions` rows when `session.driver` is `database` and the table has `user_id`

File, cookie, Redis, array, and similar stores are **not** bulk-deleted (Laravel does not index those stores by user; PHP session files are never guessed). `EnsureActiveUser` still rejects the account on the next authenticated web request.

Reactivation does not restore old sessions or the previous remember token. The user may log in normally again.

Mass updates that skip model events (`User::query()->update(...)`) do not run revocation. Request-time middleware still logs the user out on the next authenticated request.

### Password change and reset

A password change is treated as a security-sensitive account change, same family as disabling the user: leftover sessions must not keep working.

Eloquent `User` updates that change `password` run `InvalidateUserSessions` (Security settings and Fortify reset). Normal organization User Edit cannot set or rotate another user's password:

- rotate the remember token
- delete other `sessions` rows when `session.driver` is `database` (the acting user's **current** session is kept when they change their own password; Fortify reset completions drop every session for that user)

Laravel `AuthenticateSession` (`auth.session` on the `web` group) is the request-time net for leftover sessions on any driver: the stored password hash must match. After a password change, another browser is logged out on its next request even if that store was not bulk-deleted.

Fortify already regenerates the session after password login and after the 2FA challenge (session fixation). Logout invalidates the current session and regenerates the CSRF token. Changing a password does **not** disable Fortify 2FA enrollment.

### Pending Fortify 2FA

Enrolled users remain guests until the login challenge (`two-factor.login`) succeeds. If the account is disabled after the password step and before that challenge completes, pending `login.id` / `login.remember` are forgotten and the challenge cannot finish.

Privileged-action 2FA (enrollment required for high-trust operations) is a separate gate. See [Privileged two-factor](./privileged-2fa.md).

### Tests

- `tests/Feature/Auth/ActiveUserAuthenticationTest.php`
- `tests/Feature/Auth/UniqueUserEmailIdentityTest.php`
- `tests/Feature/Auth/UniqueUserEmailPasswordResetTest.php`
- `tests/Feature/Organization/UniqueUserEmailWritesTest.php`
- `tests/Feature/Auth/AuditDuplicateUserEmailsCommandTest.php`
- `tests/Unit/Support/Auth/RevokeDisabledUserAccessTest.php`
- `tests/Unit/Support/Auth/InvalidateUserSessionsTest.php`
- `tests/Feature/Auth/PasswordSessionInvalidationTest.php`
- `tests/Unit/Support/Auth/UserEmailIdentityTest.php`

## Global user email identity

`User` is a **global login identity**. Email is the intended global login identifier. Access to a company is membership (`company_user`), not another User row with the same email.

The historical unique index `uq_user_email_company` on `(company_id, email)` allowed the same email on two User rows in different companies. Fortify authenticates through the `users` provider by email, so those rows made login and password reset order-dependent. Application validation and fail-closed auth from that work remain. Live uniqueness is now also enforced in the database (see below).

### Going forward

- Create and update reject an email already owned by another **non-deleted** User, globally. Uniqueness is not scoped to `current_company_id`. Application `UniqueUserEmail` is the friendly first layer (validation errors).
- The database also enforces one normalized non-deleted email identity via generated `users.active_login_email` (`CASE WHEN deleted_at IS NULL THEN LOWER(email) ELSE NULL END`) and unique index `uq_users_active_login_email`. Concurrent or direct writes that bypass validation are rejected by this unique index (TOCTOU / query-builder inserts).
- Company ownership still comes from trusted `current_company_id`. Client `company_id` is not used to choose a tenant.
- If an existing person needs another company, use the [membership workflow](#users-and-memberships). Do not create a second User with the same email.
- Email writes follow Fortify `lowercase_usernames` (stored lowercase). Lookup compares `LOWER(email)` so mixed-case legacy rows are the same identity.

### Soft-deleted Users

Authentication and password reset consider only non-deleted rows. A soft-deleted User cannot log in and cannot hijack a remaining live identity that shares the email.

The generated live-email key is **NULL** while `deleted_at` is set, so a soft-deleted User does not occupy the global live identity. A new live User may reuse that email in **another** home company.

Restoring a soft-deleted User is rejected by the database if another live User now owns that email (`QueryException` / unique violation).

The legacy unique index `uq_user_email_company` on `(company_id, email)` is **retained**. It still occupies that pair even after soft-delete, so recreating the same email in the **same** home company can still fail at the database. That limitation is unchanged.

### Existing duplicates

`users:audit-duplicate-emails` remains the operational diagnostic. It never modifies data.

```bash
php artisan users:audit-duplicate-emails
php artisan users:audit-duplicate-emails --show-emails
```

The command prints User IDs, home company IDs, membership counts/status, employee-link counts, and role-assignment counts. Emails are masked unless `--show-emails` is passed. It returns `0` when clean and non-zero when duplicate identities exist.

The uniqueness migration runs a self-contained preflight (query builder, no Eloquent) for duplicate non-deleted `LOWER(email)` groups. If any exist, it aborts **without** changing rows and tells operators to run the audit command with `--show-emails`. It does not print email addresses, merge accounts, or rewrite data.

Production was confirmed clean with the audit command before this database constraint was added.

### Tests

- `tests/Feature/Auth/UniqueUserEmailIdentityTest.php`
- `tests/Feature/Auth/UniqueUserEmailPasswordResetTest.php`
- `tests/Feature/Organization/UniqueUserEmailWritesTest.php`
- `tests/Feature/Auth/AuditDuplicateUserEmailsCommandTest.php`
- `tests/Unit/Support/Auth/UserEmailIdentityTest.php`
- `tests/Feature/Migrations/AddUsersActiveLoginEmailUniquenessTest.php`

## Attendance records

`attendance.records.view|create|update|delete` are self-service for the authenticated user's **linked Employee in the active company**. `attendance.records.manage` enables same-company employee management (list, create, update, export). `employee_id` is not an authorization mechanism.

- Self-service create/update may only target the linked active-company Employee. A coworker or Company B employee is 404, including when the payload is otherwise invalid.
- Managers may select another **active-company** employee. Cross-company IDs remain 404.
- Linked Employee in Company B does not authorize writes while `current_company_id` is Company A.
- Hikvision/mobile sync remains a separate ingestion path and is not self-service HTTP.
- `platform_access` does not grant tenant attendance management.

## Platform administration

Tenant administration (Owner roles, `roles.update`, `companies.*`) is **company-team scoped**. Spatie sets `company_id` as the permission team. A permission granted in one tenant must never unlock global/cross-tenant tooling or global application settings.

Platform administration is a user-level attribute: `users.platform_access` (`view` or `manage`). It is **not** a Spatie permission, is **not** seeded in `PermissionsSeeder`, and is **not** mass-assignable on the User model.

Installation-wide configuration and tooling require platform authority:
- `platform:view` (`platform_access = view` or `manage`): view installation-wide configuration, masked credentials, diagnostics, and template libraries.
- `platform:manage` (`platform_access = manage`): modify installation-wide configuration, branding assets, test sends, template libraries, and diagnostic actions.
- High-trust mutations (SMTP credentials, WhatsApp credentials, and e-sign placement updates) additionally enforce `privileged.2fa` when enabled.
- Legacy `settings.application.*`, `settings.integrations.whatsapp.*`, and template Spatie permissions are retained for compatibility but do **not** authorize mutations to platform-global settings.

| Capability | Who | Surfaces |
|------------|-----|----------|
| View | `platform_access = view` or `manage` | Application logs (`/log`, export). Queue/job history (`/jobs` GET). Database table browse/export (`/mysql`) only when the database viewer is enabled. Platform settings (`/settings/application`). |
| Manage | `platform_access = manage` | Everything in View, plus clear logs, retry/delete failed jobs, delete history, clear pending jobs, and modify installation-wide application settings, branding, SMTP, and e-sign placement. |

Arbitrary SQL execution (`/mysql/query`) has been **removed**. Table browsing still exposes tenant data, so it remains platform-only. Credential/session/cache/queue-payload tables are hidden; secret-like columns (passwords, tokens, `app_settings.value`, payloads) are redacted even for platform users.

### Assigning platform access

Existing installations do not receive platform access automatically. Tenant Owner roles do **not** include it.

- Fresh `AdminSeeder` grants `manage` only to `admin@example.com`.
- Grant or revoke later with:

```bash
php artisan platform:access user@example.com view
php artisan platform:access user@example.com manage
php artisan platform:access user@example.com revoke
```

Frontend `auth.platform` flags (`view`, `manage`, `database`) are UX only. Route middleware `platform:view`, `platform:manage`, and `platform:database` is authoritative.

When `PRIVILEGED_2FA_ENFORCED` is true, **all** platform access (view, manage, and database viewer) also requires Fortify-confirmed 2FA. See [Privileged two-factor](./privileged-2fa.md).

### Database viewer production default

When `PLATFORM_DATABASE_VIEWER_ENABLED` is unset, the viewer is enabled outside production and **disabled in production**. Set the env var to `true` or `false` to override.

### Audit

Meaningful platform actions write Spatie activity rows with log name `platform` and `scope=platform`. Logged metadata includes actor, action, table/file/job identifiers, IP, and user agent. Query result bodies, log file contents, and serialized job payloads are never stored in the audit record.
