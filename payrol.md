# Marine & Offshore Payroll — Implementation Reference

> **Status:** Phases 1–9 implemented (as of June 2026).  
> Use this document as the source of truth for payroll architecture, routes, and what was built vs deferred.

---

## Overview

The HRM system supports two payroll methodologies:

1. **Office Payroll** — driven by attendance
2. **Crew Payroll (Marine & Offshore)** — driven by crew timesheets

Both payroll types share:

- `employees`
- `employee_contracts` + `contract_salary_components`
- `payroll_periods`
- `payroll_records`
- Payslips (PDF + email)
- WPS export (SIF file download)

Each payroll type has its **own calculation engine** but writes to the **same** `payroll_records` table.

---

## Guiding Principles

| Rule | Implementation |
|------|----------------|
| Single employee table | `employees` |
| Single contract table | `employee_contracts` with `payroll_category` (`office` \| `crew`) |
| Single payroll output | `payroll_records` with `payroll_category` |
| Two calculation engines | `OfficePayrollCalculator`, `CrewPayrollCalculator` |
| No split contract/payroll tables | No `office_contracts`, `crew_contracts`, etc. |

---

## Implementation Status Summary

| Phase | Topic | Status |
|-------|--------|--------|
| 1 | Contract `payroll_category` | Done |
| 2 | `contract_salary_components` | Done |
| 3 | Office / crew contract UI | Done |
| 4 | Crew timesheets (manual) | Done |
| 5 | Office payroll engine | Done |
| 6 | Crew payroll engine | Done |
| 7 | Payroll menu & records index | Done (see deviations below) |
| 8 | Excel crew timesheet import | Done |
| 9 | Payslips + WPS export | Done |
| 10 | Future expansion types | Architecture only — not separate products |

---

# Phase 1 — Extend Contract Structure ✅

### `employee_contracts.payroll_category`

```php
office | crew
```

- Enum: `App\Enums\PayrollCategory`
- Office employees: active contract with `payroll_category = office`
- Crew employees: active contract with `payroll_category = crew`

---

# Phase 2 — Salary Component Architecture ✅

### Table: `contract_salary_components`

| Field | Notes |
|-------|--------|
| `component_code` | `BASIC`, `HOUSING`, `TRANSPORT`, `OTHER`, `SITE_ALLOWANCE`, `SUPPLEMENTARY_ALLOWANCE`, etc. |
| `rate_type` | `monthly`, `daily`, `hourly`, `fixed` |
| `amount` | Decimal rate |
| `status` | Active / inactive |

**Office example:** BASIC, HOUSING, TRANSPORT (monthly)

**Crew example:** BASIC, SITE_ALLOWANCE, SUPPLEMENTARY_ALLOWANCE (daily)

**OT:** Not on contract. Crew OT is entered as **already-calculated amount** on the timesheet (`overtime_amount`).

**Key code:** `SyncContractSalaryComponentsFromContract`, `ContractSalaryComponentCatalog`

---

# Phase 3 — Contract UI ✅

- Office: Basic, Housing, Transport, Other allowances
- Crew: Basic (daily — standby + onsite), Site allowance, Supplementary allowance
- UI: `resources/js/pages/organization/_components/employee-contract-tab.tsx`

---

# Phase 4 — Crew Timesheet Module ✅

### Table: `crew_timesheets`

Unique per `(company_id, employee_id, period_id)`.

| Field | Purpose |
|-------|---------|
| `standby_from`, `standby_to`, `standby_days` | Standby period |
| `onsite_from`, `onsite_to`, `onsite_days` | Onsite period |
| `overtime_amount` | Pre-calculated OT (not rate × hours) |
| `additional_amount`, `deduction_amount` | Adjustments on timesheet |
| `remarks` | Free text |

**Manual entry:** Crew pay period → Timesheets tab → per-employee form sheet.

**Key code:** `UpsertCrewTimesheet`, `CrewTimesheetFormSheet`, `PayrollController@storeTimesheet`

---

# Phase 5 — Office Payroll Engine ✅

**Source:** Attendance module (`attendance_records`, leave, etc.)

**Calculation:** Basic + allowances + OT + bonus − deductions = net

**Output:** `payroll_records` (`payroll_category = office`)

**Key code:** `GenerateOfficePayroll`, `OfficePayrollCalculator`, `OfficeAttendanceSummary`

---

# Phase 6 — Crew Payroll Engine ✅

**Source:** `crew_timesheets` for the pay period

| Line | Formula |
|------|---------|
| Standby pay | `standby_days × basic daily rate` |
| Onsite pay | `onsite_days × basic daily rate` |
| Site allowance | `onsite_days × site allowance daily rate` |
| Supplementary | `onsite_days × supplementary daily rate` |
| OT | `overtime_amount` from timesheet |
| Gross | standby + onsite + allowances + OT + additional |
| Net | gross − deduction |

**Output:** `payroll_records` (`payroll_category = crew`), `calculation_breakdown` JSON

**Key code:** `GenerateCrewPayroll`, `CrewPayrollCalculator`

### Pay period workflow

`draft` → generate → `processing` → approve → `approved` → mark paid → `paid`  
(revert to draft / cancel supported)

---

# Phase 7 — Payroll Menu Structure ✅

### Implemented sidebar (unified hub — not separate Office/Crew menu items)

```text
Payroll
├── Overview          → /payroll/overview
├── Payroll           → /payroll  (filter by category: office | crew)
├── Payroll records   → /payroll/records
├── Payslips          → /payroll/payslips
└── WPS export        → /payroll/wps
```

**Deviation from original mockup:** Office and crew are **one hub** at `/payroll` with category filter and period `payroll_category`, not two separate sidebar links.

### Removed (by product decision)

- **Salary adjustments** — module was built then **removed**. No routes/UI. Legacy `salary_adjustments` table/migrations may still exist in DB but are unused.

### Payroll records index

- Route: `payroll.records.index`
- Controller: `PayrollRecordController`
- UI: `resources/js/features/payroll/records/`

---

# Phase 8 — Excel Crew Timesheet Import ✅

### Purpose

Bulk import monthly crew timesheets from the company Excel template.

### Flow

```text
Open crew pay period (draft)
→ Import Excel (preview validates rows)
→ Upsert crew_timesheets
→ Review on period board
→ Generate crew payroll
```

### Template

- **File:** `resources/templates/crew-monthly-timesheet.xlsx`
- **Worksheet:** `Salary Sheet` (ignore `Sheet1` summary pivot)
- **Header rows:** 1–4
- **Data from row:** 5
- **Stop when:** column B (`EMP.NO.`) empty or footer row (`DATE:`)

### Column mapping (fixed positions)

| Col | Maps to | Notes |
|-----|---------|--------|
| B | `employee_no` → lookup | Match `employees.employee_no` |
| G–I | standby from / to / days | Excel serial dates; `-` = null |
| J–L | onsite from / to / days | Same |
| R | addition or deduction | Positive → `additional_amount`; negative → `deduction_amount` |
| S | `overtime_amount` | Pre-calculated OT |
| M–O | *(not imported)* | Preview **warnings** if file rates differ from contract |
| P, Q, T | *(ignored)* | Calculated salaries — engine recalculates |
| C–F | preview only | Name, designation, client, project |
| U | optional | Appended to `remarks` as payment method |

### Routes

| Method | Path |
|--------|------|
| GET | `payroll/{period}/timesheets/import/template` |
| POST | `payroll/{period}/timesheets/import/preview` (JSON) |
| POST | `payroll/{period}/timesheets/import` |

### Key code

- `app/Imports/CrewTimesheetsImport.php`
- `app/Support/Payroll/Services/CrewTimesheetImportOrchestrator.php`
- `resources/js/features/payroll/components/crew-timesheet-import-dialog.tsx`

### Permission

- `payroll.crew_timesheets.import` (also allowed with `payroll.crew_timesheets.create`)

---

# Phase 9 — Payslips & WPS ✅

Both engines write `payroll_records`; one payslip + WPS layer on top.

## Payslips

| Feature | Implementation |
|---------|----------------|
| PDF generation | DomPDF — `resources/views/payroll/payslip.blade.php` |
| Storage | `storage/app/payslips/{company_id}/{period_id}/{employee_no}.pdf` → `payroll_records.payslip_path` |
| List UI | `/payroll/payslips` — bulk generate, bulk email |
| Preview / download | `payroll.payslips.show`, `payroll.payslips.download` |

**Not implemented:** Auto-generate payslip on pay period approve (on-demand only).

### Email

- Uses `EmailTemplateCategory::Payroll` default template
- Seeder: `EmailTemplatesSeeder` → slug `payslip_delivery`
- Migration: `2026_06_23_154613_seed_payslip_email_template.php`

**Placeholders:**

```text
{{employee_name}}
{{employee_no}}
{{period_name}}
{{net_salary}}
{{company_name}}
```

**Key code:** `GeneratePayslip`, `SendPayslipEmails`, `PayslipMail` (queued)

## WPS export

| Feature | Implementation |
|---------|----------------|
| UI | `/payroll/wps` — pick period, see eligible vs skipped |
| File | UAE-style SIF `.sif` download (comma-separated SCR + EDR lines) |
| On export | Sets `wps_status = submitted`, `wps_reference`, `wps_submitted_at` |

**Requirements before export:**

| Entity | Field |
|--------|--------|
| Company | `wps_mol_uid`, `wps_agent_code` |
| Employee | `labor_card_number` |
| Employee bank | Primary `iban`, bank `uae_routing_code_agent_id` |
| Payroll record | `status` = `approved` or `paid` |

**Not implemented:** Upload/submit to bank WPS portal API (download only).

**Key code:** `WpsSifExporter`, `WpsExportValidator`, `WpsExportController`

### `payroll_records` WPS / payslip columns

```text
payslip_path
wps_reference, wps_agent_ref, wps_status, wps_submitted_at
```

Enum: `App\Enums\WpsStatus` (`pending`, `submitted`, `accepted`, `rejected`)

---

# Phase 10 — Future Expansion

Architecture supports additional payroll **patterns** via same tables and engines (crew daily rates, rotation, vessel assignments, etc.). No separate Phase 10 modules were built.

Possible future work (not started):

- Rotation / vessel-specific payroll rules
- Hourly office contracts in engine
- Project-based pay periods
- Salary adjustments module (re-introduced and wired into generation)
- Auto-payslip on approve
- WPS bank API integration
- Payslip shortcuts on payroll records index

---

# Permissions

```text
payroll.periods.view|create|update|delete|revert_to_draft|approve|mark_paid|cancel
payroll.crew_timesheets.view|create|update|delete|import
payroll.records.view
payroll.payslips.view|generate|email
payroll.wps.view|export
```

Seeder: `database/seeders/PermissionsSeeder.php`

---

# Routes Reference

```text
GET  /payroll/overview
GET  /payroll
POST /payroll/periods
GET  /payroll/{period}
POST /payroll/{period}/timesheets
GET  /payroll/{period}/timesheets/import/template
POST /payroll/{period}/timesheets/import/preview
POST /payroll/{period}/timesheets/import
POST /payroll/{period}/generate
POST /payroll/{period}/revert-to-draft|approve|mark-paid|cancel

GET  /payroll/records
GET  /payroll/payslips
GET  /payroll/payslips/{record}
GET  /payroll/payslips/{record}/download
POST /payroll/payslips/generate
POST /payroll/payslips/email

GET  /payroll/wps
POST /payroll/wps/export
```

Legacy redirects: `/organization/payroll`, `/organization/crew-payroll` → unified payroll routes.

---

# Key Backend Paths

```text
app/Http/Controllers/Payroll/
  PayrollController.php
  PayrollRecordController.php
  PayrollOverviewController.php
  PayslipController.php
  WpsExportController.php

app/Support/Payroll/
  OfficePayrollCalculator.php
  CrewPayrollCalculator.php
  Actions/GenerateOfficePayroll.php
  Actions/GenerateCrewPayroll.php
  Actions/UpsertCrewTimesheet.php
  Actions/GeneratePayslip.php
  Actions/SendPayslipEmails.php
  Services/CrewTimesheetImportOrchestrator.php
  Wps/WpsSifExporter.php
  Wps/WpsExportValidator.php

app/Imports/CrewTimesheetsImport.php
```

---

# Key Frontend Paths

```text
resources/js/features/payroll/
  index.tsx              — period hub
  show.tsx               — period board (timesheets / payroll tabs)
  overview/              — payroll overview dashboard
  records/               — company-wide records
  payslips/              — payslip list & actions
  components/
    crew-timesheet-form-sheet.tsx
    crew-timesheet-import-dialog.tsx

resources/js/pages/payroll/
  index.tsx, show.tsx, overview.tsx, records.tsx, payslips.tsx, wps.tsx
```

---

# Tests

```text
tests/Feature/Payroll/
  CrewPayrollTest.php
  GenerateCrewPayrollTest.php
  GenerateOfficePayrollTest.php
  CrewTimesheetImportTest.php
  PayslipTest.php
  WpsExportTest.php
  PayrollPeriodsTest.php
  PayrollPeriodWorkflowTest.php
  PayrollRecordsIndexTest.php
  ...

tests/Feature/Settings/EmailTemplatesSeederTest.php
```

Run: `php artisan test --compact tests/Feature/Payroll/`

---

# Important Rules (unchanged)

**DO NOT create:**

```text
office_contracts
crew_contracts
office_payroll_records
crew_payroll_records
```

**Maintain:**

- One employee system
- One contract system
- One payroll output system
- Separate only the **calculation engines**

---

# Final Architecture (as built)

```text
employees
└── employee_contracts
        ├── payroll_category (office | crew)
        └── contract_salary_components

payroll_periods (per company, per category)
├── office → attendance → OfficePayrollCalculator → payroll_records
└── crew   → crew_timesheets → CrewPayrollCalculator → payroll_records
                └── Excel import (Salary Sheet template)

payroll_records
├── payslips (PDF + email)
└── WPS (SIF export)
```
