# Marine & Offshore Payroll Implementation Plan

## Overview

The HRM system supports two payroll methodologies:

1. **Office Payroll**
2. **Crew Payroll (Marine & Offshore)**

Both payroll types will share:

- Employees
- Contracts
- Payroll Records
- Payslips
- WPS
- Salary Adjustments

But each payroll type will have its own calculation engine.

---

# Guiding Principles

## Single Employee Table

```text
employees
```

## Single Contract Table

```text
employee_contracts
```

## Single Payroll Output

```text
payroll_records
```

## Two Calculation Engines

```text
Office Payroll Engine
Crew Payroll Engine
```

---

# Phase 1 — Extend Contract Structure

## Goal

Support both Office and Crew contracts without creating separate contract tables.

---

## Add Payroll Category

### employee_contracts

```php
payroll_category

office
crew
```

---

## Result

### Office Employee

```text
Payroll Category = office
```

### Crew Employee

```text
Payroll Category = crew
```

---

# Phase 2 — Salary Component Architecture

## Goal

Avoid adding numerous columns to contracts.

Instead of:

```php
standby_rate
onsite_rate
site_allowance
supplementary_allowance
ot_rate
...
```

Create dynamic salary components.

---

## New Table

```text
contract_salary_components
```

### Fields

```php
id
company_id
contract_id

component_code
component_name

rate_type
amount

status

created_at
updated_at
```

---

## Rate Types

```php
monthly
daily
hourly
fixed
```

---

# Office Components

Example:

| Component | Rate Type | Amount |
|------------|-----------|--------|
| BASIC | monthly | 5000 |
| HOUSING | monthly | 2000 |
| TRANSPORT | monthly | 1000 |

---

# Crew Components

Example:

| Component | Rate Type | Amount |
|------------|-----------|--------|
| BASIC | daily | 50 |
| SITE_ALLOWANCE | daily | 50 |
| SUPPLEMENTARY_ALLOWANCE | daily | 75 |

OT is **not** stored on the contract. Enter the already-calculated overtime amount on the crew timesheet when running payroll.

---

# Phase 3 — Contract UI

## Office Contract

Show:

```text
Basic Salary
Housing Allowance
Transport Allowance
Other Allowances
```

---

## Crew Contract

Show:

```text
Basic Salary (daily rate — used for standby and onsite)
Site Allowance
Supplementary Allowance
```

---

# Phase 4 — Crew Timesheet Module

## Goal

Crew payroll should not depend on attendance.

---

## Create

```text
crew_timesheets
```

---

### Fields

```php
id

company_id
employee_id
period_id

standby_from
standby_to
standby_days

onsite_from
onsite_to
onsite_days

overtime_amount

additional_amount
deduction_amount

remarks

created_at
updated_at
```

---

# Phase 5 — Office Payroll Engine

## Source

Attendance Module

---

## Calculation

```text
Basic Salary
+ Allowances
+ OT
+ Bonus

- Deductions

= Net Salary
```

---

## Output

```text
payroll_records
```

---

# Phase 6 — Crew Payroll Engine

## Source

Crew Timesheets

---

## Calculation

### Standby Salary

```text
Standby Days × Basic Salary (daily rate)
```

---

### Onsite Salary

```text
Onsite Days × Basic Salary (daily rate)
```

---

### Site Allowance

```text
Onsite Days × Site Allowance
```

---

### Supplementary Allowance

```text
Onsite Days × Supplementary Allowance
```

---

### OT

```text
Overtime amount from crew timesheet (already calculated — not contract rate × hours)
```

---

### Gross Salary

```text
Standby Salary
+ Onsite Salary
+ Site Allowance
+ Supplementary Allowance
+ OT
+ Additional Amount
```

---

### Net Salary

```text
Gross Salary
- Deduction Amount
```

---

## Output

```text
payroll_records
```

---

# Phase 7 — Payroll Menu Structure

```text
Payroll
│
├── Office Payroll
├── Crew Payroll
├── Payroll Records
├── Salary Adjustments
├── Payslips
└── WPS Export
```

---

# Phase 8 — Excel Import Module

## Purpose

Import monthly crew timesheets.

---

## Flow

```text
Upload Excel
↓

Validate Employee Codes

↓

Create Crew Timesheets

↓

Review Data

↓

Generate Crew Payroll
```

---

# Phase 9 — Payslips

Both payroll engines should generate:

```text
payroll_records
```

Therefore:

- One payslip module
- One PDF generator
- One email system
- One WPS system

---

# Phase 10 — Future Expansion

Architecture should support:

### Marine Payroll

✔

### Offshore Payroll

✔

### Rotation Payroll

✔

### Vessel Payroll

✔

### Daily Rate Contracts

✔

### Hourly Contracts

✔

### Multiple Payroll Types

✔

### Internal Transfers

✔

### Project-Based Payroll

✔

---

# Final Architecture

```text
Employees
│
└── Contracts
        │
        ├── Payroll Category
        │
        └── Salary Components
                │
                ├── Office Components
                │
                └── Crew Components

Payroll
│
├── Office Payroll Engine
│
├── Crew Payroll Engine
│
└── Payroll Records
        │
        ├── Payslips
        └── WPS

Crew Module
│
└── Crew Timesheets
        │
        └── Excel Import
```

---

# Important Rule

DO NOT create:

```text
office_contracts
crew_contracts
office_payroll_records
crew_payroll_records
```

Maintain:

- One employee system
- One contract system
- One payroll output system

Separate only the calculation engines.