# Permissions

Authorization uses [Spatie Laravel Permission](https://github.com/spatie/laravel-permission) with **company teams**. `SetCurrentCompany` sets `current_company_id` on the request and configures the same value as Spatie's active team before company-scoped permission checks run.

The authoritative permission catalog is `database/seeders/PermissionsSeeder.php`. Route coverage is defined by `routes/web.php` and `routes/settings.php`; do not treat this document as a substitute for checking both.

## Enforcement rules

1. Protect every privileged backend action with the narrowest applicable permission through route `can:` middleware, a gate/policy, controller authorization, or Form Request authorization.
2. Enforce company ownership independently of the capability check. Never trust a client-supplied `company_id`.
3. Treat Inertia `can` props, shared `auth.permissions`, and hidden UI controls as presentation only.
4. Add a test proving an authenticated user without the permission receives `403`.

Most module routes use `middleware('can:permission.name')`. Some Payroll and operations endpoints instead use controller helpers, Form Requests, or Support access classes. That distributed model is intentional where documented (for example Payroll timesheet import template uses `payroll.crew_timesheets.import` or `payroll.crew_timesheets.create`). Authenticated-only routes without a capability check remain exceptions to review, not a pattern to copy.

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
| Documents | `documents.view|download|share|upload|delete` |
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
    'whatsapp_template' => bool,
    'whatsapp_templates' => array,
    'email_templates' => array,
]
```

These flags do not authorize requests. Document routes enforce `documents.*` permissions and document support classes additionally verify company/employee ownership.

Creating a login from an employee requires `users.create`. User–employee linking is otherwise managed through the user edit workflow.

## Audit

`audit.view` controls the global activity-log page and recent-activity sections on supported detail pages. Without it, recent-activity queries return no entries and the UI hides the section.

Automatic Spatie activity logging now covers a broad set of organization, master-data, employee sub-record, crew, attendance/leave, and payroll models through `LogsActivityWithCompany`. The implementation is the source of truth: search `app/Models` for the trait rather than maintaining a fragile model count here. Operational events that are not model CRUD may be logged manually by services.

## Settings and integrations

Application Settings (platform-wide identity, branding, SMTP, and global e-signature placement) use:

| Permission | Scope |
|------------|-------|
| `settings.application.view` | View Application Settings for the entire OMS-HRM installation |
| `settings.application.update` | Update Application Settings for the entire OMS-HRM installation |

These permissions are **team-scoped Spatie permissions**, even though the settings themselves are installation-wide. Granting `settings.application.*` inside one company does **not** grant platform database, log, or queue tooling. Those surfaces use the separate platform administration flag below. They must not be replaced with `companies.*`. The former `platform.settings.view` and `platform.settings.update` aliases have been removed as a duplicate permission family; grants were migrated onto `settings.application.*`.

Company identity and regional values are managed on the Company record under **Organization → Companies**, gated by `companies.view` and `companies.update`.

The Companies index, show, update, status, destroy, and export actions apply only to the **active** company (`current_company_id`). They are not a global tenant registry. `companies.*` granted in company A cannot list, read, or mutate company B. Other memberships are entered through company switch, which still requires an active `company_user` (or the legacy home-company rule). Platform access does not imply company membership.

Company document files under `/organization/companies/{company}/documents` are **membership-based**, not active-company registry CRUD. `CompanyDocumentAccess` requires an active membership in the route `{company}` plus that company's `company_documents.*` team permissions. A dual-company user may open the other tenant's document library without switching; they still cannot use Companies registry routes for that tenant until they switch. This split is intentional.

Company document signing assets (salary certificate signature/stamp/signatory) also use `companies.view` / `companies.update` on the company show page for the **active** company only.

Trusted tenant context is always `current_company_id` from middleware/session. Client-supplied `company_id` cannot authorize cross-company updates.

## Users and memberships

User rows are **global identities**. Company access is the `company_user` membership (plus the legacy `users.company_id` home-company rule). `users.*` is a Spatie **team** permission for the active company.

Membership store/update/destroy are **active-company** operations, matching the Companies registry:

- Trusted company is `current_company_id`. Client `company_id`, hidden fields, and the route `{company}` parameter cannot choose another tenant.
- **Create** may attach a user who is not yet a member of the active company. The membership is always created for the active company.
- **Update/destroy** require an existing membership in the active company. Cross-company targets are 404.
- Dual-company administrators must **switch** before managing the other tenant. `platform_access` is not membership.
- Role IDs are resolved in the active Spatie team (`spatie_roles.company_id`). A role from another company is rejected.

Anyone with `users.update` in the active company may assign any role that exists **for that company**, including Owner. Editing the role/permission matrix remains `roles.update`. This is the current product policy, not a wildcard across tenants.

The Users directory lists people whose **home** `users.company_id` is the active company. A person whose home company is A and who only has membership in B does not appear in B's directory, and B's user show/destroy URLs 404 while B is active. That is UX/data-model debt, not a cross-tenant leak. Membership store/update/destroy still manage B members while B is active.

There is no last-Owner or self-membership-removal guard. Removing a membership detaches `company_user` and clears that team's role. The next request recovers through `SetCurrentCompany` (fallback to another accessible company, or no tenant). Product has not required a last-admin lock.

## Attendance records

`attendance.records.view|create|update|delete` are self-service for the authenticated user's **linked Employee in the active company**. `attendance.records.manage` enables same-company employee management (list, create, update, export). `employee_id` is not an authorization mechanism.

- Self-service create/update may only target the linked active-company Employee. A coworker or Company B employee is 404, including when the payload is otherwise invalid.
- Managers may select another **active-company** employee. Cross-company IDs remain 404.
- Linked Employee in Company B does not authorize writes while `current_company_id` is Company A.
- Hikvision/mobile sync remains a separate ingestion path and is not self-service HTTP.
- `platform_access` does not grant tenant attendance management.

### Ownership

| Concern | Source |
|---------|--------|
| Platform name, platform support email/phone, fallback timezone, default date format, branding, SMTP | Global `app_settings` |
| Company name, logo, address, phone, email, website, currency, timezone, payroll cycle, working days, WPS | `companies` row |
| Salary certificate signature/stamp/signatory | `company_document_settings` |

Legacy global keys (`company_name`, `company_address`, `currency`, `salary_certificate_signature`, `salary_certificate_stamp`) remain as fallbacks only. Prefer company-scoped values after migration.

Shared Inertia props expose `settings.platform` and `settings.company` separately. Deprecated flat keys (`settings.currency`, `settings.company_name`, …) remain temporarily for compatibility.

Security and appearance have separate permissions. Master data uses `settings.master-data.{resource}.view|create|update|delete`.

Integration permission families include:

- `settings.integrations.whatsapp.view|update`
- `settings.integrations.hikvision.view|update`
- `settings.integrations.whatsapp-templates.view|create|update|delete`
- `settings.integrations.email-templates.view|create|update|delete`

Hikvision administration additionally uses the `hikvision.*` permissions listed above. SMTP updates use the application-settings routes; see [Email configuration](./email-configuration.md).
Hikvision settings and records are additionally scoped to the active company; webhook callback identifiers resolve one company before signature verification.

Credential permissions never imply that decrypted secrets may be sent to the browser. Settings responses expose masked placeholders and `has_*` flags, and empty secret submissions preserve the stored value.

## Platform administration

Tenant administration (Owner roles, `roles.update`, `companies.*`, `settings.application.*`) is **company-team scoped**. Spatie sets `company_id` as the permission team. A permission granted in one tenant must never unlock global/cross-tenant tooling.

Platform administration is a separate user-level flag: `users.platform_access` (`view` or `manage`). It is **not** a Spatie permission, is **not** seeded in `PermissionsSeeder`, and is **not** mass-assignable on the User model. Granting a fake `platform.database.view` permission inside a company does nothing.

| Capability | Who | Surfaces |
|------------|-----|----------|
| View | `platform_access = view` or `manage` | Application logs (`/log`, export). Queue/job history (`/jobs` GET). Database table browse/export (`/mysql`) only when the database viewer is enabled. |
| Manage | `platform_access = manage` | Everything in View, plus clear logs, retry/delete failed jobs, delete history, and clear pending jobs. |

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

### Database viewer production default

When `PLATFORM_DATABASE_VIEWER_ENABLED` is unset, the viewer is enabled outside production and **disabled in production**. Set the env var to `true` or `false` to override.

### Audit

Meaningful platform actions write Spatie activity rows with log name `platform` and `scope=platform`. Logged metadata includes actor, action, table/file/job identifiers, IP, and user agent. Query result bodies, log file contents, and serialized job payloads are never stored in the audit record.
