# Herd OMS-HRM

A multi-tenant **Organization Management System / Human Resources Management** built on Laravel 13 + Inertia v3 + React 19 + TailwindCSS v4.

Designed for SMBs (UAE-focused initially) to manage companies, branches, departments, positions, employees, contracts, documents, onboarding, recruitment, attendance, leave, and payroll from a single, role-aware workspace.

**Extended documentation:** [docs/README.md](docs/README.md) (dashboard, documents, search, sharing, permissions, email).

---

## Tech Stack

| Layer | Tech |
|---|---|
| Backend | PHP 8.3+ / Laravel 13 |
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
| Tests | Pest 4 (Feature + Browser) |

---

## Highlights

- **Multi-tenant by company** — every domain table is scoped by `company_id`. `SetCurrentCompany` middleware sets the active tenant and Spatie's permissions team id on every request.
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
- **Onboarding** — JSON-driven templates with multi-stage forms (basic info → documents → bank fields)
- **Settings** — SMTP + test email, application branding, master data CRUD
- **Activity log** — `/organization/activity-logs`
- **Modern UI** — glass cards, dark mode, command palette (⌘K), top loading bar

---

## Project Structure

```
app/
├── Exports/                    # Maatwebsite Excel export classes
├── Imports/                    # Maatwebsite Excel import classes
├── Http/Controllers/
│   ├── Onboarding/
│   ├── Organization/           # Companies, employees, documents, dashboard, …
│   └── Settings/               # Application SMTP, branding, master data
├── Support/
│   ├── Dashboard/              # DashboardAnalytics
│   └── EmployeeDocuments/      # DocumentBrowseQuery, expiry, sharing, storage
├── Models/
database/
├── migrations/
└── seeders/
    └── PermissionsSeeder
docs/                           # Product & developer guides (see docs/README.md)
resources/js/
├── pages/organization/         # Inertia pages (employees, documents, users, …)
├── features/
│   ├── dashboard/
│   └── organization/documents/ # Browse, search, share, merge, email
├── components/
└── layouts/
routes/
├── web.php
└── settings.php
tests/Feature/Organization/     # DocumentBrowseTest, DocumentShareTest, …
```

---

## Getting Started

### Prerequisites

- PHP **8.3+**
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
| Employees | `employees.view/create/update/delete/export/import` + feature imports (`identity`, `bank_accounts`, `contracts`) + profile sections (`contracts.manage`, `bank_accounts.manage`, …) |
| Documents | `documents.view/download/share/upload/delete` |
| Audit | `audit.view` |
| Onboarding | `onboarding.templates.*` |
| Settings | `settings.master-data.*`, `settings.security.*`, application settings |

```bash
php artisan db:seed --class=PermissionsSeeder
```

---

## Importing Employees

1. **Employees** → **Import** (`employees.import`).
2. Download template, upload CSV/XLSX, map columns, preview, commit.
3. Sensitive columns require `employees.identity.import`, `employees.bank_accounts.import`, or `employees.contracts.import` as applicable.

Required columns: `employee_no`, `first_name`, `last_name`, `contract_type`, `start_date`.

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
| `/onboarding/templates` | Template builder |
| `/settings/...` | Profile, SMTP, master data |

```bash
php artisan route:list --path=organization
php artisan route:list --path=settings
```

---

## License

Proprietary — internal project. Not licensed for redistribution.
