# Payroll

This guide describes the payroll implementation currently present in the repository. The module supports office and crew pay periods, writes both categories to the same `payroll_periods` and `payroll_records` tables, and exposes one unified payroll workspace.

## Navigation and pages

The Payroll sidebar contains four entries:

| Label | Path | Inertia page |
|---|---|---|
| Overview | `/payroll/overview` | `resources/js/pages/payroll/overview.tsx` |
| Payroll | `/payroll` | `resources/js/pages/payroll/index.tsx` |
| Payroll records | `/payroll/records` | `resources/js/pages/payroll/records.tsx` |
| Salary inputs | `/payroll/salary-inputs` | `resources/js/pages/payroll/salary-inputs.tsx` |

An individual period is displayed by `resources/js/pages/payroll/show.tsx` at `/payroll/{payrollPeriod}`.

There are no standalone `/payroll/payslips` or `/payroll/wps` pages. Payslip delivery and WPS export are embedded in an approved or paid period through `PayrollPeriodDeliveryPanel`.

## Core model

Each `PayrollPeriod` belongs to one company and has a `payroll_category` of `office` or `crew`. Both categories create `PayrollRecord` rows; category-specific calculators populate a shared record structure and preserve a calculation breakdown.

The normal lifecycle is:

```text
draft -> generate -> processing -> approve -> approved -> mark paid -> paid
```

Other supported transitions are:

- `processing -> draft`: removes payroll records and salary inputs; crew periods also lose their timesheets.
- `approved -> processing`: clears approval data, generated payslip files, and WPS submission fields.
- `paid -> approved`: removes the paid state from records.
- `draft`, `processing`, or `approved` -> `cancelled`: removes payroll records and salary inputs.

The transition rules live in `app/Models/PayrollPeriod.php` and `app/Support/Payroll/Actions/`.

## Office payroll

`GenerateOfficePayroll` selects active employees whose current contract is categorized as office payroll. Each employee needs an active basic monthly salary component; housing, transport, and other monthly components are optional.

Calculation inputs include:

- the inclusive pay-period day count;
- approved leave requests overlapping the period;
- unpaid leave types identified by the `UL`, `UNPAID`, or `LOP` code;
- optional per-employee start and end dates supplied during generation;
- excluded employee IDs;
- period salary inputs added after the base calculation.

The calculator prorates contract salary components when employee-specific dates shorten the payable period, applies unpaid-leave deductions, and records leave usage in `calculation_breakdown`. `GenerateOfficePayroll` then calls `RecalculateOfficePayroll`, which applies configured salary input additions and deductions.

Key implementation files:

- `app/Support/Payroll/Actions/GenerateOfficePayroll.php`
- `app/Support/Payroll/OfficePayrollCalculator.php`
- `app/Support/Payroll/OfficeLeavePeriodSummary.php`
- `app/Support/Payroll/Actions/RecalculateOfficePayroll.php`
- `app/Support/Payroll/ApplyOfficeSalaryInputs.php`

## Crew payroll

Crew periods use `CrewTimesheet` data and support daily and monthly contract salary structures.

For a daily structure, the calculator uses standby days, onsite days, overtime hours, and active daily contract rates. Standby and onsite pay, site allowance, supplementary allowance, overtime, additions, and deductions are recorded separately in the calculation breakdown.

For a monthly structure, `CrewMonthlyPayrollCalculator` uses monthly basic, housing, transport, and other components, then prorates them using the period and timesheet days. Salary inputs use the office-style addition and deduction application for monthly crew records.

Legacy `standby_days` on monthly crew timesheets continue to represent leave/unpaid days in the current calculator. Phase 1A adds separate sign-on/sign-off standby columns for future daily operational mapping without changing monthly payroll behavior yet.

Key implementation files:

- `app/Support/Payroll/Actions/GenerateCrewPayroll.php`
- `app/Support/Payroll/CrewPayrollCalculator.php`
- `app/Support/Payroll/CrewMonthlyPayrollCalculator.php`
- `app/Support/Payroll/Actions/RecalculateCrewPayroll.php`
- `app/Support/Payroll/ApplyCrewSalaryInputs.php`

### Crew timeline preparation (Phase 1A)

Phase 1A adds versioned preparation tables and additive standby/source metadata on `crew_timesheets` for a future Crew Operations → crewing approval → timesheet apply workflow. This phase is data foundation only; no UI, routes, permissions, or payroll blocking exist yet.

See [architecture/crew-payroll-timeline-preparation.md](./architecture/crew-payroll-timeline-preparation.md).

### Timesheet entry and import

Crew timesheets can be entered from the period board or imported from an XLSX/XLS file.

The import template is generated dynamically by `CrewTimesheetTemplateExporter`; there is no static workbook under `resources/templates`. The generated workbook contains a `Crew Timesheets` sheet populated with active crew employees and active salary input type columns.

The import flow is:

1. Download the template for the crew period.
2. Fill standby dates, onsite dates, overtime hours, salary input columns, and optional remarks.
3. Upload for preview.
4. Review validation errors and warnings.
5. Import valid rows.

The importer matches employees by employee number, rejects duplicate employee numbers in the workbook, and requires an active crew contract. Imported salary input columns are synchronized for the employee and period. If a payroll record already exists, that employee is recalculated immediately.

Key files:

- `app/Support/Payroll/Services/CrewTimesheetTemplateExporter.php`
- `app/Imports/CrewTimesheetsImport.php`
- `app/Support/Payroll/Services/CrewTimesheetImportOrchestrator.php`
- `resources/js/features/payroll/components/crew-timesheet-import-dialog.tsx`

## Salary inputs

Salary input types define company-specific additions or deductions. Default types are provisioned through `ProvisionDefaultSalaryInputTypes`; administrators can create, edit, activate, deactivate, and delete unused types from `/payroll/salary-inputs`.

Inputs are assigned to an employee within a pay period. Creating, updating, or deleting an input recalculates that employee's record when possible. Full-period recalculation is available for draft or processing periods.

Key files:

- `app/Models/SalaryInputType.php`
- `app/Models/SalaryInput.php`
- `app/Http/Controllers/Payroll/SalaryInputTypeController.php`
- `app/Http/Controllers/Payroll/SalaryInputController.php`
- `resources/js/features/payroll/salary-inputs/`

## Payroll exports

Approved and paid periods with payroll records can be exported as XLSX salary sheets:

- office periods use `OfficePayrollSalarySheetExporter` and the `Office Payroll` worksheet;
- crew periods use `CrewPayrollSalarySheetExporter` and the `Salary Sheet` worksheet.

The period export endpoint chooses the exporter from the period category. Office export requires `payroll.periods.view`; crew export requires `payroll.crew_timesheets.view`.

## Payslips

Payslips use `resources/views/payroll/payslip.blade.php` and DomPDF. Generated files are stored on the local disk at:

```text
payslips/{company_id}/{period_id}/{employee_no}.pdf
```

Approving a processing period updates its records to approved and automatically dispatches background payslip generation. `GeneratePayrollPayslips` splits pending records into jobs of 25, and the period page polls the payslip summary while generation is incomplete. A working queue is therefore required for automatic generation.

From the delivery panel, an authorized user can:

- view or download one payslip;
- download all generated payslips as a ZIP;
- merge generated payslips into one PDF;
- regenerate a whole period in the background or generate selected records synchronously;
- queue payslip emails using the enabled payroll email template.

Email delivery skips records without a generated file or an employee email address. It prefers the employee work email and falls back to the personal email.

Key files:

- `app/Support/Payroll/Actions/ApprovePayrollPeriod.php`
- `app/Support/Payroll/Actions/GeneratePayrollPayslips.php`
- `app/Jobs/GeneratePayrollPayslipsJob.php`
- `app/Support/Payroll/Actions/GeneratePayslip.php`
- `app/Support/Payroll/Actions/SendPayslipEmails.php`
- `resources/js/features/payroll/components/payslip-delivery-card.tsx`
- `resources/js/features/payroll/hooks/use-payslip-generation-poll.ts`

### Payslips from a salary sheet

The payroll overview has a separate salary-sheet workflow for users with `payroll.payslips.generate`:

1. Upload an XLSX/XLS workbook containing a `Salary Sheet` worksheet.
2. Preview parsed employee rows.
3. Select and order the rows to include.
4. Choose a year and month.
5. Download one merged PDF containing the generated payslips.

This workflow reads calculated values directly from the uploaded sheet and does not create or update `PayrollPeriod` or `PayrollRecord` rows.

Key files:

- `app/Support/Payroll/Services/SalarySheetPayslipParser.php`
- `app/Support/Payroll/Actions/GeneratePayslipsFromSalarySheet.php`
- `resources/js/features/payroll/overview/salary-sheet-payslip-dialog.tsx`

## WPS export

WPS is part of the delivery panel on approved or paid period pages. It is not a separate page. The panel previews eligible and skipped records and can export either the full eligible set or selected payroll records.

Supported formats are SIF and XLSX. A successful export marks exported records as submitted and stores the WPS reference, agent reference, and submission timestamp.

Company requirements:

- WPS MOL UID;
- WPS agent code;
- WPS employer IBAN.

Record requirements:

- status is approved or paid;
- salary payment method is eligible for WPS;
- labor contract ID is available;
- primary bank account has an IBAN;
- bank has a UAE routing code;
- a crew record has an active basic daily rate in its calculation breakdown.

Key files:

- `app/Http/Controllers/Payroll/WpsExportController.php`
- `app/Support/Payroll/Wps/WpsExportValidator.php`
- `app/Support/Payroll/Wps/WpsExportPreview.php`
- `app/Support/Payroll/Wps/WpsSifExporter.php`
- `app/Support/Payroll/Wps/WpsExcelExporter.php`
- `resources/js/features/payroll/components/wps-delivery-card.tsx`

## Routes and authorization

All routes below are inside the authenticated and verified web group. Some use route middleware; others enforce permissions in controllers or Form Requests.

### Pages and records

| Method | Path | Route name | Effective authorization |
|---|---|---|---|
| GET | `/payroll/overview` | `payroll.overview` | `payroll.periods.view` or `payroll.crew_timesheets.view` |
| GET | `/payroll` | `payroll.index` | `payroll.periods.view` or `payroll.crew_timesheets.view` |
| POST | `/payroll/periods` | `payroll.periods.store` | `payroll.periods.create` |
| GET | `/payroll/{payrollPeriod}` | `payroll.show` | `payroll.periods.view` or `payroll.crew_timesheets.view` |
| GET | `/payroll/records` | `payroll.records.index` | `payroll.records.view` |
| GET | `/payroll/{payrollPeriod}/export` | `payroll.export` | Approved/paid period plus category view permission |
| DELETE | `/payroll/{payrollPeriod}/records/{payrollRecord}` | `payroll.records.destroy` | `payroll.periods.update` |

### Generation and workflow

| Method | Path | Route name | Permission |
|---|---|---|---|
| POST | `/payroll/{payrollPeriod}/generate` | `payroll.generate` | `payroll.periods.update` |
| POST | `/payroll/{payrollPeriod}/recalculate` | `payroll.recalculate` | `payroll.periods.recalculate` or `payroll.periods.update` |
| POST | `/payroll/{payrollPeriod}/revert-to-draft` | `payroll.revert-to-draft` | `payroll.periods.revert_to_draft` |
| POST | `/payroll/{payrollPeriod}/revert-to-approved` | `payroll.revert-to-approved` | `payroll.periods.revert_to_approved` |
| POST | `/payroll/{payrollPeriod}/revert-to-processing` | `payroll.revert-to-processing` | `payroll.periods.revert_to_processing` |
| POST | `/payroll/{payrollPeriod}/approve` | `payroll.approve` | `payroll.periods.approve` |
| POST | `/payroll/{payrollPeriod}/mark-paid` | `payroll.mark-paid` | `payroll.periods.mark_paid` |
| GET | `/payroll/{payrollPeriod}/payment-proof` | `payroll.payment-proof` | `payroll.periods.view` |
| POST | `/payroll/{payrollPeriod}/cancel` | `payroll.cancel` | `payroll.periods.cancel` |

### Crew timesheets and salary inputs

| Method | Path | Route name | Effective authorization |
|---|---|---|---|
| POST | `/payroll/{payrollPeriod}/timesheets` | `payroll.timesheets.store` | `payroll.crew_timesheets.create` or `payroll.crew_timesheets.update` |
| GET | `/payroll/{payrollPeriod}/timesheets/import/template` | `payroll.timesheets.import.template` | No dedicated permission check beyond authenticated access and company/category checks |
| POST | `/payroll/{payrollPeriod}/timesheets/import/preview` | `payroll.timesheets.import.preview` | `payroll.crew_timesheets.import` or `payroll.crew_timesheets.create` |
| POST | `/payroll/{payrollPeriod}/timesheets/import` | `payroll.timesheets.import` | `payroll.crew_timesheets.import` or `payroll.crew_timesheets.create` |
| GET | `/payroll/salary-inputs` | `payroll.salary-inputs.index` | `payroll.salary_inputs.view` or `payroll.periods.update` |
| POST | `/payroll/salary-inputs` | `payroll.salary-input-types.store` | `payroll.salary_inputs.create` or `payroll.periods.update` |
| PUT | `/payroll/salary-inputs/{salaryInputType}` | `payroll.salary-input-types.update` | `payroll.salary_inputs.update` or `payroll.periods.update` |
| PUT | `/payroll/salary-inputs/{salaryInputType}/status` | `payroll.salary-input-types.update-status` | `payroll.salary_inputs.update` or `payroll.periods.update` |
| DELETE | `/payroll/salary-inputs/{salaryInputType}` | `payroll.salary-input-types.destroy` | `payroll.salary_inputs.delete` or `payroll.periods.update` |
| POST | `/payroll/{payrollPeriod}/salary-inputs` | `payroll.salary-inputs.store` | `payroll.salary_inputs.create` or `payroll.periods.update` |
| PUT | `/payroll/{payrollPeriod}/salary-inputs/{salaryInput}` | `payroll.salary-inputs.update` | `payroll.salary_inputs.update` or `payroll.periods.update` |
| DELETE | `/payroll/{payrollPeriod}/salary-inputs/{salaryInput}` | `payroll.salary-inputs.destroy` | `payroll.salary_inputs.delete` or `payroll.periods.update` |

### Payslips and WPS

| Method | Path | Route name | Effective authorization |
|---|---|---|---|
| GET | `/payroll/payslips-zip` | `payroll.payslips.download-zip` | `payroll.records.view` or `payroll.periods.view` |
| GET | `/payroll/payslips-pdf` | `payroll.payslips.download-pdf` | `payroll.records.view` or `payroll.periods.view` |
| GET | `/payroll/payslips/{payrollRecord}` | `payroll.payslips.show` | `payroll.records.view` or `payroll.periods.view` |
| GET | `/payroll/payslips/{payrollRecord}/download` | `payroll.payslips.download` | `payroll.records.view` or `payroll.periods.view` |
| POST | `/payroll/payslips/generate` | `payroll.payslips.generate` | `payroll.payslips.generate` |
| POST | `/payroll/payslips/email` | `payroll.payslips.email` | `payroll.payslips.email` |
| POST | `/payroll/payslips/from-salary-sheet/preview` | `payroll.payslips.from-salary-sheet.preview` | `payroll.payslips.generate` |
| POST | `/payroll/payslips/from-salary-sheet` | `payroll.payslips.from-salary-sheet` | `payroll.payslips.generate` |
| POST | `/payroll/wps/export` | `payroll.wps.export` | `payroll.wps.export` |

## Permission catalog

The current payroll permissions seeded by `database/seeders/PermissionsSeeder.php` are:

```text
payroll.periods.view
payroll.periods.create
payroll.periods.update
payroll.periods.delete
payroll.periods.revert_to_draft
payroll.periods.revert_to_approved
payroll.periods.revert_to_processing
payroll.periods.approve
payroll.periods.mark_paid
payroll.periods.cancel
payroll.periods.recalculate
payroll.crew_timesheets.view
payroll.crew_timesheets.create
payroll.crew_timesheets.update
payroll.crew_timesheets.import
payroll.salary_inputs.create
payroll.salary_inputs.update
payroll.salary_inputs.delete
payroll.salary_inputs.view
payroll.records.view
payroll.payslips.generate
payroll.payslips.email
payroll.wps.export
```

There are no `payroll.payslips.view` or `payroll.wps.view` permissions.

## Tests

Payroll feature coverage is under `tests/Feature/Payroll/`, including period lifecycle, office and crew generation, timesheet imports, recalculation, salary inputs, records, exports, payslips, salary-sheet payslips, WPS, permissions, and activity logging.

Calculator and support-level coverage is under `tests/Unit/Support/Payroll/`.

Run the focused suite with:

```bash
php artisan test --compact tests/Feature/Payroll tests/Unit/Support/Payroll
```
