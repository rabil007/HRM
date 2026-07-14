# Herd OMS-HRM

A multi-tenant **Organization Management System / Human Resources Management** built on Laravel 13 + Inertia v3 + React 19 + TailwindCSS v4.

Designed for SMBs (UAE-focused initially) to manage companies, branches, departments, positions, employees, contracts, documents, attendance, leave, payroll, training, and crew operations from a single, role-aware workspace.

**Extended documentation:** [docs/README.md](docs/README.md) (dashboard, documents, payroll, permissions, integrations, and architecture).

---

## Tech Stack

| Layer | Tech |
|---|---|
| Backend | PHP 8.4 / Laravel 13 |
| Frontend | React 19 + TypeScript + Inertia v3 |
| Styling | TailwindCSS v4 + shadcn-style Radix UI primitives |
| Auth | Laravel Fortify (sessions + 2FA) |
| Routing (FE) | Laravel Wayfinder typed routes |
| Permissions | Spatie laravel-permission (company-scoped teams) |
| Audit | Spatie laravel-activitylog |
| Exports | maatwebsite/excel + barryvdh/laravel-dompdf |
| Charts | Recharts |
| Notifications | Sonner |
| Build | Vite 8 |
| Tests | Pest 4 (Feature + Unit) |

---

## Highlights

- **Multi-tenant by company** — organization-owned records are expected to be scoped by `company_id`. `SetCurrentCompany` middleware sets the active tenant and Spatie's permissions team id on authenticated requests.
- **Company switcher** — sidebar dropdown swaps the active company per user session (`/organization/companies/switch`).
- **Role-based access control** — granular permissions per company; document and import permissions split by feature (see [docs/permissions.md](docs/permissions.md)).
- **Dashboard** — workforce trends, headcount, document compliance, expiry health, department/branch breakdowns ([docs/dashboard.md](docs/dashboard.md)).
- **Documents module** — employee folder index, global search, compliance expiry views, per-employee browse, profile uploads, share links, WhatsApp bulk share, ZIP/PDF merge ([docs/document-management.md](docs/document-management.md)).
- **Employee module**
  - Odoo-style click-to-edit details page with sticky Save/Discard bar
  - Contracts, bank accounts, documents (with document number, issue/expiry)
  - ADNOC seafarer CV print (`/organization/employees/{id}/cv`)
  - Create login from employee (`users.create`)
  - CSV / XLSX import with column mapping and granular sensitive-field permissions
  - Export as CSV / Excel / PDF
- **Users** — company membership, roles, link to employee, avatar upload or copy from employee photo, last login tracking
- **Employee profile templates** — company-specific field visibility, tab layout, and required-field rules used by employee create/edit and import workflows
- **Attendance and leave** — attendance records and calendar, leave requests, balances, types, and approval workflows
- **Payroll** — periods, salary inputs, timesheets, records, payslips, WPS export, approval and payment workflows ([docs/payroll.md](docs/payroll.md))
- **Crew operations** — deployments, vessel manning, crew planning, timesheet import, and employee sea-service synchronization
- **Bulk documents and e-signing** — document generation, distribution, signature requests, and public signed flows
- **Integrations** — SMTP, WhatsApp, and Hikvision configuration and operational workflows
- **Settings** — application branding, security, integration settings, and master data CRUD
- **Activity log** — `/organization/activity-logs`
- **Modern UI** — glass cards, dark mode, command palette (⌘K), top loading bar

---

## Project Structure

```
app/
├── Exports/                    # Maatwebsite Excel export classes
├── Imports/                    # Maatwebsite Excel import classes
├── Http/Controllers/
│   ├── Attendance/             # Attendance records, calendar, leave
│   ├── Hikvision/              # Devices, persons, events, sync
│   ├── Organization/           # Core HR, documents, training, crew operations
│   ├── Payroll/                # Payroll overview, records, payslips, WPS
│   ├── Public/                 # Public document and e-sign flows
│   └── Settings/               # Branding, integrations, master data
├── Support/
│   ├── Dashboard/              # DashboardAnalytics
│   ├── EmployeeDocuments/      # Document browse, expiry, sharing, storage
│   └── EmployeeProfileTemplates/
├── Models/
database/
├── migrations/
└── seeders/
    └── PermissionsSeeder
docs/                           # Product & developer guides (see docs/README.md)
resources/js/
├── pages/                      # Inertia pages grouped by domain
├── features/
│   ├── dashboard/
│   └── organization/documents/ # Browse, search, share, merge, email
├── components/
└── layouts/
routes/
├── web.php
└── settings.php
tests/
├── Feature/                    # HTTP and integration behavior by domain
└── Unit/                       # Isolated services, models, enums, support code
```

---

## Getting Started

### Prerequisites

- PHP **8.4**
- Node **20+**
- MySQL **8** (or MariaDB)
- [Laravel Herd](https://herd.laravel.com) (recommended) — auto-serves at `http://oms-hrm.test`

### Installation

```bash
git clone https://github.com/<you>/OMS-HRM.git
cd OMS-HRM

composer install
cp .env.example .env
php artisan key:generate
```

Set your DB in `.env`:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=oms_hrm
DB_USERNAME=root
DB_PASSWORD=
```

```bash
php artisan migrate --seed
npm install
npm run build
```

### Default Login

| Email | Password |
|---|---|
| `admin@example.com` | `password` |

---

## Running Dev

```bash
composer run dev
```

Or with Herd: `npm run dev` and visit `http://oms-hrm.test`.

---

## Testing

```bash
php artisan test --compact
php artisan test --compact tests/Feature/Organization/DocumentBrowseTest.php
```

```bash
composer ci:check    # lint + format + types + tests
```

---

## Code Style

```bash
vendor/bin/pint --dirty --format agent
npm run lint:check
npm run types:check
```

---

## Permissions Cheatsheet

Permissions are company-scoped (Spatie teams). Full list: `database/seeders/PermissionsSeeder.php` and [docs/permissions.md](docs/permissions.md).

| Domain | Permissions (summary) |
|---|---|
| Companies, Branches, Departments, Positions | `*.view/create/update/delete/export` |
| Roles, Users | `roles.*`, `users.*` |
| Employees | `employees.view/create/update/delete/export/import` |
| Contracts, bank accounts, training | `contracts.*`, `bank_accounts.*`, `training.*` |
| Documents | `documents.view/download/share/upload/delete` |
| Audit | `audit.view` |
| Employee profile templates | `employee_profile_templates.view/create/update/delete` |
| Attendance, leave, payroll | `attendance.*`, `payroll.*` (verify exact names in the seeder) |
| Crew operations | `crew_operations.*` |
| Settings | `settings.master-data.*`, `settings.security.*`, application settings |

```bash
php artisan db:seed --class=PermissionsSeeder
```

---

## Importing Employees

1. **Employees** → **Import** (`employees.import`).
2. Download template, upload CSV/XLSX, map columns, preview, commit.
3. Related module columns use `bank_accounts.import` or `contracts.import` when those records are imported through their own flows.

The base required columns are `employee_no` and `name`. A selected employee profile template can make additional mapped fields required.

---

## Useful Routes

| Route | Purpose |
|---|---|
| `/dashboard` | Analytics and charts |
| `/organization/documents` | Document folders + search + compliance filters |
| `/organization/documents/employees/{id}` | Employee document browse |
| `/organization/employees` | Employee list |
| `/organization/employees/{id}` | Employee profile (documents tab) |
| `/organization/employees/{id}/cv` | ADNOC CV print view |
| `/organization/users` | Users + employee link |
| `/organization/roles` | Roles & permissions |
| `/organization/activity-logs` | Audit log |
| `/organization/templates/employee-profile` | Employee profile template builder |
| `/attendance/overview` | Attendance, calendar, and leave overview |
| `/payroll` | Payroll periods and processing |
| `/organization/crew-operations` | Crew operations overview |
| `/settings/...` | Profile, SMTP, master data |

```bash
php artisan route:list --path=organization
php artisan route:list --path=settings
```

---

## License

Proprietary — internal project. Not licensed for redistribution.
