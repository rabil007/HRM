# Permissions

Authorization uses [Spatie Laravel Permission](https://github.com/spatie/laravel-permission) with **company teams**. `SetCurrentCompany` sets `current_company_id` on the request and configures the same value as Spatie's active team before company-scoped permission checks run.

The authoritative permission catalog is `database/seeders/PermissionsSeeder.php`. Route coverage is defined by `routes/web.php` and `routes/settings.php`; do not treat this document as a substitute for checking both.

## Enforcement rules

1. Protect every privileged backend action with the narrowest applicable permission through route `can:` middleware, a gate/policy, controller authorization, or Form Request authorization.
2. Enforce company ownership independently of the capability check. Never trust a client-supplied `company_id`.
3. Treat Inertia `can` props, shared `auth.permissions`, and hidden UI controls as presentation only.
4. Add a test proving an authenticated user without the permission receives `403`.

Most module routes use `middleware('can:permission.name')`, but coverage is not universal. Authenticated-only log/job/database routes and parts of payroll and operations remain known gaps. Their current lack of capability middleware is security debt, not a convention to copy.

Re-seed after changing the catalog:

```bash
php artisan db:seed --class=PermissionsSeeder
```

Assign permissions through **Organization → Roles & permissions** (`/organization/roles`).

## Permission groups

| Area | Current permission families |
|------|-----------------------------|
| Organization | `companies.*`, `branches.*`, `departments.*`, `positions.*`, `users.*`, `roles.*` |
| Employees | `employees.view|create|update|delete|export|import`, and employee sub-record `.manage` permissions |
| Contracts / bank / training / sea service | `contracts.view|create|update|delete|import`, `contracts.salary_revisions.view|create|update|delete`, `bank_accounts.view|create|update|delete|import`, `training.view|create|update|delete|import`, `sea_services.view|create|update|delete|import` |
| Documents | `documents.view|download|share|upload|delete` |
| Bulk documents / signatures | `bulk_documents.view|generate|delete|email`, `bulk_documents.signatures.review` |
| Crew operations | `crew_operations.overview.view`, `crew_operations.vessel_manning.*`, `crew_operations.planning.*`, `crew_operations.assignments.*`, `crew_operations.movements.perform`, `crew_operations.corrections.view|request|approve|override` |
| Reports | `reports.crew_movement_history.view|export` |
| Attendance / leave | `attendance.overview.view`, `attendance.records.*`, `attendance.types.*`, `attendance.leave-requests.*` |
| Payroll | `payroll.overview.view`, `payroll.periods.*`, `payroll.crew_timesheets.*`, `payroll.salary_inputs.*`, `payroll.records.view`, `payroll.payslips.*`, `payroll.wps.export` |
| Hikvision | `hikvision.persons.*`, `hikvision.devices.*`, `hikvision.events.*`, `hikvision.webhook.manage` |
| Employee profile templates | `employee_profile_templates.view|create|update|delete` |
| Settings | `settings.security.*`, `settings.appearance.view`, `settings.application.*`, `platform.settings.*`, `company.settings.*`, `company.document-settings.*`, integration/template permissions, and `settings.master-data.{resource}.*` |
| Audit | `audit.view` |

The `*` notation above is descriptive only; permissions are seeded as explicit strings, not wildcard grants.

## Employee and document details

Employee import has one employee permission plus module imports for related records:

| Permission | Import scope |
|------------|--------------|
| `employees.import` | Employee import workflow, including passport and Emirates ID columns |
| `contracts.import` | Contract columns and contract import workflow |
| `bank_accounts.import` | Bank-account columns and bank import workflow |
| `training.import` | Training import workflow |
| `sea_services.import` | Sea service import workflow |

Employee profile sub-records use `employees.education.manage`, `employees.work_experience.manage`, `employees.vaccination.manage`, and `employees.languages.manage`. Contracts, bank accounts, training, and sea services use their own view/create/update/delete families rather than the removed `employees.contracts.manage`, `employees.bank_accounts.manage`, and `employees.sea_service.manage` names.

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

Platform (application) settings use `settings.application.view|update` and the newer `platform.settings.view|update` aliases. Existing administrators receive the platform permissions through a migration that copies grants from `settings.application.*`.

Company identity and regional values are managed on the Company record under **Organization → Companies**, gated by `companies.*` and `company.settings.view|update`.

Company document signing assets (salary certificate signature/stamp/signatory) use:

| Permission | Scope |
|------------|-------|
| `company.document-settings.view` | View salary certificate settings on the company show page |
| `company.document-settings.update` | Update company document settings for the **active** company only |

Trusted tenant context is always `current_company_id` from middleware/session. Client-supplied `company_id` cannot authorize cross-company updates.

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
