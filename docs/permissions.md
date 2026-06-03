# Permissions

Authorization uses [Spatie Laravel Permission](https://github.com/spatie/laravel-permission) with **company teams**: each permission check is scoped to `current_company_id`.

## How it works

1. `SetCurrentCompany` middleware sets the active company on the request and Spatie team id.
2. Routes use `middleware('can:permission.name')` or policies.
3. Inertia shares permission names on the auth user for UI gating.

Re-seed after adding permissions:

```bash
php artisan db:seed --class=PermissionsSeeder
```

Grant roles in **Organization → Roles & permissions** (`/organization/roles`).

## Document permissions

| Permission | Typical use |
|------------|-------------|
| `documents.view` | Documents index, employee browse, preview |
| `documents.download` | Single download, folder ZIP, bulk ZIP, PDF merge |
| `documents.share` | Share links, bulk WhatsApp send, and per-document WhatsApp templates |
| `documents.upload` | Upload, replace, update metadata on profile |
| `documents.delete` | Delete documents (browse bulk + profile) |

Frontend `can` object on document pages comes from `DocumentPagePermissions::for($user)`:

```php
['download' => bool, 'share' => bool, 'delete' => bool, 'whatsapp_template' => bool, 'whatsapp_templates' => array]
```

`whatsapp_template` requires `documents.share` plus configured WhatsApp integration and at least one enabled document template.

Upload is enforced on routes (`documents.upload`), not always repeated in that array—check route middleware in `routes/web.php`.

## Employee import permissions (granular)

Sensitive CSV import columns are gated separately from base import:

| Permission | Import group |
|------------|----------------|
| `employees.import` | Base employee import |
| `employees.identity.import` | Identity-related columns |
| `employees.bank_accounts.import` | Bank account columns |
| `employees.contracts.import` | Contract / payroll columns |

UI groups permissions by feature section in the roles matrix (not a single “IMPORT” bucket).

## Other employee permissions (sample)

| Permission | Area |
|------------|------|
| `employees.view` / `create` / `update` / `delete` / `export` | Core employee CRUD |
| `employees.contracts.manage` | Contracts on profile |
| `employees.bank_accounts.manage` | Bank accounts |
| `employees.education.manage` | Education |
| `employees.work_experience.manage` | Work experience |
| `employees.sea_service.manage` | Sea service |
| `employees.training.manage` | Training |
| `employees.vaccination.manage` | Vaccination |
| `employees.languages.manage` | Languages |

## Organization and settings

See [README.md](../README.md#permissions-cheatsheet) for companies, branches, departments, positions, roles, users, audit, onboarding, and master data permissions.

## Users and employees

- `users.create` — required to create a login from an employee (`POST organization/employees/{employee}/user`)
- User ↔ employee linking is managed in user edit (employee dropdown, optional avatar from employee photo)

## Audit

- `audit.view` — **Activity logs** page (`/organization/activity-logs`) and **Recent activity** cards on company, branch, department, position, and user detail pages

### Activity logging (Spatie)

Models that record changes today: `Company`, `Branch`, `Department`, `Position`, `User`, `Employee`, `EmployeeDocument`. Entries are scoped by `company_id` and appear on the global activity log when the user has `audit.view`.

Not logged yet: master data, employee sub-records (contracts, bank accounts, etc.), roles, settings changes. Employee changes are logged but there is no per-employee “Recent activity” card yet—use **Activity logs** and filter by subject.

## Settings

Master data and application settings use `settings.*` permissions; SMTP updates use application settings routes in `routes/settings.php` (see [Email configuration](./email-configuration.md)).

### WhatsApp integration (Owner only by default)

| Permission | Typical use |
|------------|-------------|
| `settings.integrations.whatsapp.view` | View Settings → Application → WhatsApp tab |
| `settings.integrations.whatsapp.update` | Save WhatsApp credentials, test connection |
| `settings.integrations.whatsapp-templates.view` | View Settings → WhatsApp templates library |
| `settings.integrations.whatsapp-templates.create` | Add WhatsApp templates |
| `settings.integrations.whatsapp-templates.update` | Edit WhatsApp templates |
| `settings.integrations.whatsapp-templates.delete` | Delete non-default WhatsApp templates |

Granted to the **Owner** role only on migration; assign manually to other roles if needed. Existing roles with WhatsApp integration permissions receive matching template permissions automatically.
