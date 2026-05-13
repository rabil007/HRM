# Herd OMS-HRM

A multi-tenant **Organization Management System / Human Resources Management** built on Laravel 13 + Inertia v3 + React 19 + TailwindCSS v4.

Designed for SMBs (UAE-focused initially) to manage companies, branches, departments, positions, employees, contracts, documents, onboarding, recruitment, attendance, leave, and payroll from a single, role-aware workspace.

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

- **Multi-tenant by company** — every domain table is scoped by `company_id`. A `SetCurrentCompany` middleware sets the active tenant and Spatie's permissions team id on every request.
- **Company switcher** — sidebar dropdown swaps the active company per user session (`/organization/companies/switch`).
- **Role-based access control** — granular permissions like `employees.view`, `employees.update`, `employees.import`, `companies.export`, etc., grouped into roles per company.
- **Employee module**
  - Odoo-style click-to-edit details page with sticky Save/Discard bar
  - Contracts, primary bank account, multi-document uploads (passport, EID, labor card, etc.)
  - Permission-aware editing + unsaved-changes guard
  - Searchable selects for Branch / Department / Position / Manager
  - **CSV / XLSX import** with auto column mapping, preview, validation, and one-click commit
  - **Export** as CSV / Excel / PDF
- **Onboarding** — JSON-driven templates with multi-stage forms (basic info → documents → bank fields)
- **Master data** — countries, currencies, banks, religions, genders, document types, visa types — all CRUD with permissions
- **Activity log** — every create/update/delete is tracked and viewable at `/organization/activity-logs`
- **Modern UI** — glass cards, dark mode, responsive grids, command palette (⌘K), top loading bar

---

## Project Structure

```
app/
├── Exports/                    # Maatwebsite Excel export classes
├── Imports/                    # Maatwebsite Excel import classes (employees, etc.)
├── Http/
│   ├── Controllers/
│   │   ├── Onboarding/
│   │   └── Organization/       # Companies, Branches, Departments, Positions,
│   │                           # Employees, Roles, Users, ActivityLog, CompanySwitch
│   ├── Middleware/
│   │   ├── HandleInertiaRequests.php   # Shares auth.permissions, companies, flash
│   │   └── SetCurrentCompany.php       # Resolves active tenant per request
│   └── Requests/               # FormRequest validation
├── Models/                     # Eloquent models, all guarded by company_id
database/
├── factories/                  # Model factories
├── migrations/                 # Schema (multi-tenant from day one)
└── seeders/
    ├── PermissionsSeeder       # All permissions
    ├── CountrySeeder / CurrencySeeder / BanksSeeder / ...
    └── AdminSeeder             # Default admin@example.com / password
resources/
├── js/
│   ├── pages/                  # Inertia pages (organization, settings, onboarding)
│   │   └── organization/
│   │       ├── employees.tsx
│   │       ├── employee.tsx
│   │       ├── employee-create.tsx
│   │       ├── _components/    # Page-scoped extracted components
│   │       └── ...
│   ├── features/               # Feature-scoped UI (content, cards, dialogs)
│   ├── components/             # Shared UI primitives + layout
│   ├── layouts/                # AppLayout, AuthLayout, SettingsLayout
│   ├── hooks/                  # use-appearance, use-view-preference, ...
│   ├── lib/                    # toast, utils
│   └── types/                  # Global Inertia/page types
└── views/app.blade.php
routes/
├── web.php                     # All app routes (auth-guarded)
└── settings.php                # Settings sub-routes
tests/
├── Feature/                    # Pest feature tests (per module)
├── Browser/                    # Pest 4 browser tests
└── Support/spatie.php          # grantCompanyPermissions() helper
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

Set your DB in `.env` (defaults to MySQL `oms_hrm`):

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=oms_hrm
DB_USERNAME=root
DB_PASSWORD=
```

Create the DB then migrate + seed:

```bash
php artisan migrate --seed
```

Install frontend dependencies and build:

```bash
npm install
npm run build
```

### Default Login

After seeding:

| Email | Password |
|---|---|
| `admin@example.com` | `password` |

The admin user is automatically granted all permissions on the default seeded company.

---

## Running Dev

Single command (server + queue + logs + vite):

```bash
composer run dev
```

This runs concurrently:

- `php artisan serve`
- `php artisan queue:listen --tries=1 --timeout=0`
- `php artisan pail --timeout=0` (log tail)
- `npm run dev` (vite)

Or with Herd, just run `npm run dev` and visit `http://oms-hrm.test`.

---

## Testing

```bash
php artisan test --compact           # All tests
php artisan test --compact --filter EmployeesTest
php artisan test tests/Feature/Organization/EmployeesTest.php --compact
```

The suite covers feature tests for every module (companies, branches, departments, positions, employees, users, roles, onboarding, settings, exports, imports, activity log).

---

## Code Style

PHP:

```bash
vendor/bin/pint --parallel           # format
vendor/bin/pint --parallel --test    # check only
```

Frontend:

```bash
npm run lint                         # eslint --fix
npm run lint:check
npm run format                       # prettier --write
npm run format:check
npm run types:check                  # tsc --noEmit
```

Composer ships a full CI gate:

```bash
composer ci:check                    # lint + format + types + tests
```

---

## Permissions Cheatsheet

Permissions are grouped by domain and scoped per company via Spatie's "teams":

| Domain | Permissions |
|---|---|
| Companies | `companies.view/create/update/delete/export` |
| Branches | `branches.view/create/update/delete/export` |
| Departments | `departments.view/create/update/delete/export` |
| Positions | `positions.view/create/update/delete/export` |
| Roles | `roles.view/create/update/delete/export` |
| Users | `users.view/create/update/delete/export` |
| Employees | `employees.view/create/update/delete/export/import` |
| Audit | `audit.view` |
| Onboarding | `onboarding.templates.view/create/update/delete` |
| Settings · Master data | `settings.master-data.{countries,currencies,banks,religions,genders,document-types,visa-types}.{view,create,update,delete}` |
| Settings · Security | `settings.security.view/update` |

Re-seed permissions after adding new ones:

```bash
php artisan db:seed --class=PermissionsSeeder
```

---

## Importing Employees (Odoo-style)

1. Open **Employees** → **Import** (requires `employees.import`).
2. Download the CSV template (footer of the dialog).
3. Upload the filled CSV / XLSX.
4. Review auto-detected column mapping + first 10 rows + validation errors.
5. Click **Import N rows** — invalid rows are skipped, valid rows create Employee + primary EmployeeContract (and optional bank account).

Required columns: `employee_no`, `first_name`, `last_name`, `contract_type`, `start_date`.

Branch / Department / Position / Manager / Gender / Religion / Nationality / Bank are matched **by name** (case + punctuation insensitive). Manager can also be matched by `manager_employee_no`.

---

## Useful Routes

| Route | Purpose |
|---|---|
| `/dashboard` | Landing page after login |
| `/organization/companies` | Companies CRUD |
| `/organization/branches` | Branches |
| `/organization/departments` | Departments |
| `/organization/positions` | Positions |
| `/organization/employees` | Employees list + grid/table view + filters + import/export |
| `/organization/employees/{id}` | Inline-editable employee details |
| `/organization/users` | App users + company memberships |
| `/organization/roles` | Roles + permissions matrix |
| `/organization/activity-logs` | Audit log |
| `/onboarding/templates` | Onboarding template builder |
| `/settings/...` | Profile, security, appearance, master data |

List everything: `php artisan route:list --except-vendor`.

---

## License

Proprietary — internal project. Not licensed for redistribution.
