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
- `paid -> approved`: removes the paid state from records and clears `payment_date`; `generated_at` is preserved.
- `draft`, `processing`, or `approved` -> `cancelled`: Draft/Processing cancel force-deletes payroll records and salary inputs. Approved cancel preserves payroll records (status `cancelled`), salary inputs, payslips, and WPS fields; work allocations are reversed but keep `payroll_record_id`.

The transition rules live in `app/Models/PayrollPeriod.php` and `app/Support/Payroll/Actions/`.

### Generation and payment timestamps

`payroll_periods` distinguishes when payroll was calculated from when employees were paid:

- `generated_at` — set (and refreshed) whenever `GenerateCrewPayroll` or `GenerateOfficePayroll` successfully produces payroll records. Regeneration refreshes it; failed generation or a run that produces no records leaves it unchanged. It is never treated as a payment date.
- `payment_date` — the actual salary payment date. It is `null` for draft, processing, and approved periods and is only set during the Mark as Paid transition. Payroll generation never sets `payment_date`.

Mark as Paid (`MarkPayrollPeriodPaid`) accepts an optional `payment_date`; when omitted it defaults to the company-local current date, and it is rejected if it falls before the pay period start. A paid period always has a non-null `payment_date`. Reverting `paid -> approved` clears `payment_date` while keeping `generated_at`; marking the period paid again stores a fresh `payment_date`. Period creation does not accept a payment date.

Payment-date analytics in `PayrollOverviewSummary` remain scoped to paid periods, so unpaid periods (with a null `payment_date`) are excluded from paid aggregations.

### Automatic rolling period creation

Every active company always maintains a three-month rolling window of Draft payroll periods: the current month, the next month, and the following month. Both a Crew and an Office period are created for each month.

- `EnsureFuturePayrollPeriods` (`app/Support/Payroll/Actions/`) resolves the company timezone via `CompanyTimezone`, determines the company-local current month, and ensures the automatic Crew and Office periods exist for the window. It returns a result with created/skipped counts, created period IDs, and a month/category summary.
- The `payroll:ensure-future-periods` command runs the action for every active company (`status = active`, not soft-deleted). It accepts `--company=` to target one company and `--months=` to change the window size (default 3). It continues past per-company failures, logging them with company context.
- The command is scheduled daily at `00:45` with `withoutOverlapping`. Daily execution self-heals missed runs, picks up newly activated companies, and keeps the third month populated.

Automatic Crew periods are always created with `crew_timesheet_mode = hybrid` and the display name `{Month} - Crew`. Automatic Office periods have no crew mode. Every automatic period is `draft` with `payment_date = null`, `generated_at = null`, `notes = "Automatically created"`, and `created_by = null`. The automation never creates crew timesheets, timeline preparations, payroll records, salary inputs, approvals, payment dates, or generation timestamps.

Auto-created rows are identified by `creation_source = automatic` and a deterministic `automatic_period_key` (`company:{id}:{category}:{YYYY-MM}`). Regular full-month periods (automatic or user-created) also store `regular_period_key` with the same shape and a unique index, so there is at most one normal monthly period per company, category, and month. The scheduler skips creating an automatic period when any regular period for that month already exists.

### Creation source vs timesheet source

`creation_source` is audit metadata only:

- `automatic` / Created by system
- `manual` / Created by user

It does not control how timesheets are entered or how payroll is calculated.

### Duplicate normal period prevention

For a regular full-month period (`start_date` = first day of month and `end_date` = last day of the same month), at most one period may exist for:

- company
- payroll category
- payroll month

Enforced by unique `regular_period_key` plus create-time validation. Off-cycle or correction date ranges (non full-month) keep a null `regular_period_key` and remain allowed.

### Historical contract and salary resolution

Payroll salary is resolved from the earning or payment period date, not from the date payroll happens to be generated. A future salary revision therefore never changes an older payroll period.

- Office and Monthly Crew payroll use the contract covering the payroll period start, then the latest non-deleted salary revision whose `effective_from` is on or before that date.
- Office contract selection is company-, employee-, category-, and date-scoped. A missing or overlapping Office contract blocks that employee instead of selecting the present-day active contract.
- Daily Crew movement pay continues to resolve the exact contract and salary revision independently for every work date. Prior-period arrears keep those day-level contract, revision, and rate snapshots.
- Daily Crew overtime has no separate earned date, so it remains rated from the salary package applicable to the payment period. Additions and deductions remain direct payment-period amounts.
- Current contract salary mirroring remains the source for current employee/profile displays, but mirrored contract fields are never treated as evidence of historical salary.
- A contract that has never had salary revision history may use its legacy baseline salary components. Once any revision history exists, including soft-deleted history, payroll blocks with `missing_historical_salary_revision` when no non-deleted revision covers the historical date.
- Revision lines are the complete salary package for their effective month. A missing historical component is shown as unavailable and is not filled from a current/future contract column.

When a legacy contract with reliable salary values receives its first revision for a later month, the existing package is preserved as a baseline revision effective from the contract start month before the requested revision is created. Both rows are written atomically. No baseline is invented when the contract has no reliable salary values or start date, and contracts that already have revision history never receive another baseline.

## Office payroll

`GenerateOfficePayroll` selects active employees with an Office contract covering the payroll period start. It blocks ambiguous overlaps and resolves that contract's historical salary package for the same date. Each employee needs an active basic monthly salary component; housing, transport, and other monthly components are optional.

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

For a daily structure, the calculator uses the three explicit operational categories — Sign-On Standby, Onsite, and Sign-Off Standby — plus overtime hours and active daily contract rates. Each category's days and pay are recorded separately in the calculation breakdown, along with `total_standby_days` (= `sign_on_standby_days` + `sign_off_standby_days`) and `total_standby_pay`. Site allowance, supplementary allowance, overtime, additions, and deductions are recorded separately.

#### Incomplete unused flat-field movement categories

Legacy parent flat-field pairs with only one date set (for example Sign-On Standby end without start) are **warnings**, not blocking errors:

- Incomplete categories produce **no movement days and no movement pay**. Stored `*_days` values for those incomplete pairs are ignored.
- Complete valid categories on the same timesheet still calculate normally.
- Overtime-only, addition-only, deduction-only, and salary-input-only Daily Crew rows can be processed when there are no complete movement periods (`present_days` stays 0; no attendance is manufactured).
- Overtime still requires a resolvable Daily Crew contract and the required active basic daily rate.
- Malformed persisted `crew_timesheet_segments` (missing dates, reversed ranges, negative days, overlaps) remain **blocking**.
- Missing or overlapping historical Daily Crew contracts and missing historical salary revisions remain **blocking**.
- Preview exposes `warning_issues` / `warning_count` separately from `blocking_issues` / `blocking_count`. `can_generate` depends only on true blockers and ready employees.

For a monthly structure, `CrewMonthlyPayrollCalculator` uses monthly basic, housing, transport, and other components, then prorates them by `unpaid_leave_days` over the period working days. Salary inputs use the office-style addition and deduction application for monthly crew records.

Daily crew uses only Sign-On Standby → Onsite → Sign-Off Standby. Monthly crew uses `unpaid_leave_days`. The legacy generic standby columns (`standby_from`, `standby_to`, `standby_days`) were intentionally removed by migration `2026_07_21_100000_replace_legacy_standby_fields_on_crew_timesheets` before any production payroll data existed; no compatibility bridge, mirroring, or source-based fallback remains.

### Daily Crew prior-period arrears

Daily Crew payroll automatically pays unpaid work that falls **before** the payment period start, using historical rates. There is no ON/OFF setting.

**Sources**

- Manual movement edits and Excel import may enter ranges that start before the payroll period.
- Original movement ranges are stored as `crew_timesheet_segments` (not physically split).
- Payroll generation expands segments day-by-day into `payroll_work_allocations`.
- Dates after the payroll period end remain rejected.
- Crew Operations timeline apply still clips phases to the current period and does **not** invent arrears from earlier periods.

**Historical rate resolution**

- Each payable work date resolves the Daily Crew contract covering that date (company + employee + Crew + Daily + not soft-deleted). No “current contract” fallback for arrears dates.
- Missing or overlapping covering contracts block generation.
- Salary components resolve for the work date: latest revision with `effective_from <= work_date`. If the contract has revisions but none cover the date, generation is blocked. If the contract has no revisions, baseline contract components are used.
- Standby day stores basic and supplementary amounts separately. Onsite stores basic + site + supplementary separately.
- Overtime, additions, and deductions stay payment-period values and are **not** historically rated in this phase.

**Duplicate-payment protection**

- `payroll_work_allocations` stores one day allocation with lifecycle statuses (`reserved` → `approved` → `paid`, or `reversed`).
- Active uniqueness uses nullable unique `active_allocation_key` = `{company_id}:{employee_id}:{work_date}` for reserved/approved/paid rows (null when reversed). Multiple NULLs are allowed, so reversed dates can be re-allocated.
- Already approved/paid dates are excluded from new generation with a warning; reserved-by-other-payroll dates are blocking conflicts.
- Draft regeneration deletes and recreates only `reserved` allocations for that record. Approved/paid rows are never silently replaced.
- Cancelling a Draft/Processing period releases reserved allocations and force-deletes payroll records + salary inputs. Cancelling an Approved period reverses allocations (history retained, `payroll_record_id` kept), marks payroll records `cancelled`, and preserves payslip/WPS fields — financial records are not force-deleted.
- Daily Crew movement allocations use one work_date = exactly 1 payable day. Segment `days` must equal the inclusive calendar day count (integer). Fractional days are rejected.
- Generation stores `source_fingerprint` and `currency_code` in `calculation_breakdown`. Approve recomputes the fingerprint and blocks if segments or historical contract/revision resolution drifted; Mark Paid does not re-check (approved snapshot is frozen).
- `payroll_work_allocations.payroll_period_id` uses `restrictOnDelete` so periods cannot be hard-deleted while allocations remain.

**Attendance and breakdown**

- `present_days` / `leave_days` and current-period standby/onsite summaries count **current-period days only**.
- Parent timesheet present-day fields also count only calendar days inside the payroll period.
- `calculation_breakdown` includes `current_period`, `prior_period`, `prior_period_adjustments` / `earning_periods` for payslip/export.
- The single `payroll_records.contract_id` remains a compatibility snapshot (usually the payment-period primary contract). Per-date contracts and revisions live on allocations.
- Payslip shows separate prior-period and current-period earnings lines with date range, days, and rate. Salary export marks Movement Details rows with PERIOD / rates.
- Overtime remains a payment-period value and is not historically rated until overtime has an earned date.

Key implementation files:

- `app/Support/Payroll/Actions/GenerateCrewPayroll.php`
- `app/Support/Payroll/CrewPayrollCalculator.php`
- `app/Support/Payroll/CrewMonthlyPayrollCalculator.php`
- `app/Support/Payroll/Actions/RecalculateCrewPayroll.php`
- `app/Support/Payroll/ApplyCrewSalaryInputs.php`
- `app/Support/Payroll/BuildDailyCrewPayrollAllocationPlan.php`
- `app/Support/Payroll/ResolveCrewContractForWorkDate.php`
- `app/Support/Payroll/PersistPayrollWorkAllocations.php`
- `app/Support/Payroll/SplitCrewMovementRangeAcrossPeriod.php`
- `app/Support/Payroll/Actions/PersistCrewTimesheetMovements.php`

### Crew timeline preparation (Phase 1A–1D)

Phase 1A added versioned preparation tables and additive standby/source metadata on `crew_timesheets`.

Phase 1B adds the automatic draft preparation engine:

- authorized users can `POST /payroll/{payrollPeriod}/crew-timeline/prepare`
- actual Crew Operations phases are clipped into the pay period and allocated by day
- a new draft `CrewTimesheetPreparation` version is created with lines and warning codes
- successful prepare redirects to the timeline review page
- prepare is blocked after an Applied preparation exists

Phase 1C adds review, submission, return, and approval:

- `GET /payroll/{payrollPeriod}/crew-timeline/{preparation}` for review
- `POST .../submit`, `POST .../approve`, `POST .../return`
- Draft → Submitted → Approved or Returned
- previous Approved versions become Superseded when a newer version is approved
- approval requires the `payroll.crew_timesheets.approve` permission (no maker-checker restriction; the preparer/submitter may also approve)
- stale source hash and blocking warnings prevent submit/approve

Phase 1D applies an Approved preparation to `crew_timesheets`:

- `POST .../apply` with `payroll.crew_timesheets.apply_approved`
- creates one parent timesheet per employee and one `crew_timesheet_segments` row per payable preparation line
- preserves exact assignment/vessel/date periods; parent category from/to are null when multiple segments exist for that category
- preserves overtime, additions, deductions, remarks, and salary inputs
- sets `source = crew_operations` and locks operational fields while Applied
- writes parent day totals from segments; legacy flat from/to columns remain for single-segment and pre-segment rows

Vessel transfer and redeployment create linked assignments so one employee can have multiple exact onsite periods in the same payroll month without inventing phases or duplicating final salary records.

### Manual and Excel movement periods

- Daily Crew manual entry supports a **Movement Periods** editor: one parent `CrewTimesheet` with many `crew_timesheet_segments` (pay category, optional vessel/client/rank, From/To, days, remarks). The dialog focuses on **Movement Details** (category, dates, days, remarks); Vessel/Client/Rank sit in a secondary **Assignment** summary with **Change Assignment** to expand selectors.
- The main payroll board autosaves **financial fields only** (`overtime_hours`, `unpaid_leave_days` where applicable). Movement dates on the board are read-only summaries (including “Multiple periods”); operational edits go only through **Edit movement periods**.
- Employee-level financial fields (`unpaid_leave_days`, overtime, additions, deductions, salary inputs, parent remarks) stay once per employee — not per segment.
- **Financial autosave** (`PATCH …/timesheets/{timesheet}/financials`) never deletes or rebuilds movement segments. Non-nullable numeric fields (`overtime_hours`, `overtime_amount`, `additional_amount`, `deduction_amount`) normalize blank/`null` to `0`; omitted keys preserve existing values. Explicitly submitted empty/`null` remarks clear the saved remark; omitted remarks preserve the existing value. Failed autosaves keep the local draft and show a retryable row error. Financial autosaves are **serialized per employee**: rapid edits collapse into the latest queued draft, and only the latest generation may clear drafts/errors. Different employees save concurrently via Inertia `async: true` visits (`showProgress: false`). Movement periods wait for all active and queued financial saves for that employee before segment replacement; Clear Timesheets invalidates late autosaves so they cannot recreate cleared rows.
- **Segment replacement** (`PUT …/timesheets/{timesheet}/segments`) requires an explicit non-empty `segments` array. Tenant ownership of period/timesheet is confirmed before payload validation (cross-company → 404). Validation runs before any delete; empty lists are rejected.
- Vessel, Client, and Rank on segments are **global** master-data tables (active-only `exists` checks), not company-owned rows.
- Overlapping payable calendar dates for the same employee are rejected; consecutive or gapped ranges are allowed.
- Crew Operations Applied operational data remains locked; financial fields may still update under existing rules. Clear Timesheets soft-deletes Manual/Import parents and segments only.
- Daily Crew Excel may repeat the same employee number once per movement row. Preview groups rows, validates overlaps/period bounds using original Excel row numbers, and rejects duplicated non-zero employee-level amounts (enter once; blanks/zeros on other rows are OK). Monthly Crew duplicate employee rows remain invalid.
- Payroll generation still produces **one** `PayrollRecord`, payslip, and payment per employee/period. Salary export keeps one consolidated Salary Sheet row and a **Movement Details** worksheet (one row per segment, including rank).

Permissions:

- `payroll.crew_timesheets.prepare`
- `payroll.crew_timesheets.view` (review page)
- `payroll.crew_timesheets.submit`
- `payroll.crew_timesheets.approve`
- `payroll.crew_timesheets.return`
- `payroll.crew_timesheets.apply_approved`

See [architecture/crew-payroll-timeline-preparation.md](./architecture/crew-payroll-timeline-preparation.md).

### Unified Crew timesheet sources (hybrid)

Each crew pay period stores `crew_timesheet_mode`:

| Mode | Value | Behavior |
|------|-------|----------|
| Hybrid (default) | `hybrid` | One Crew period supports mixed employee-level sources. UI label: **Crew Payroll**. |
| Manual / Excel Timesheet | `manual` | Historical exclusive Manual/Import mode |
| Crew Operations Timeline | `crew_operations` | Historical exclusive timeline mode |

Office periods keep `crew_timesheet_mode = null`.

Operational source belongs to each employee’s `CrewTimesheet`, not the period:

| Priority | Source | Notes |
|----------|--------|-------|
| 1 | Approved Crew Operations | Highest; locks operational fields; replaces Manual/Import operational values automatically |
| 2 | Excel Import | Fallback when no Applied movement coverage |
| 3 | Manual Entry | Fallback when no Applied movement coverage |

Rules:

- A movement timeline is optional per employee. Missing assignment/phases/preparation lines do not block payroll by themselves.
- Import may replace Manual before Crew Operations is applied.
- Manual/Import must never overwrite Applied Crew Operations operational fields.
- Applying Approved movement data replaces only operational fields, preserves financial fields, sets `source = crew_operations`, and locks operational fields. No confirmation dialog.
- Monthly Crew continues to use unpaid leave / monthly contract calculation with no movement requirement.

Creation:

- new and automatic Crew periods always use `hybrid`
- create form no longer asks for Manual vs Crew Operations
- Draft Crew periods are migrated to `hybrid` when safe; Approved/Paid/Processing historical modes are left unchanged

Generation readiness (hybrid / manual):

- `BuildCrewPayrollGenerationPreview` classifies every included employee as Ready, Missing timesheet, Awaiting approval, Excluded, or Blocking
- Missing Daily timesheets and legacy unapproved Manual/Import timesheets are **skipped warnings**, not period blockers
- Applied Crew Operations rows count as approved (no second Manual/Import-style approval)
- Invalid approved timesheets and broken Crew Operations linkage are **blocking**
- Clicking Generate opens a server-backed preview; confirmation recomputes under the period lock and generates only Ready employees
- No movement timeline is required for every employee; Monthly Crew follows existing monthly rules without a timesheet
- Exclusive historical `crew_operations` periods still require exactly one Applied preparation for the period

Manual/Import timesheet approval:

- Manual save and confirmed Excel import automatically set `approval_status = approved` with `approved_by` / `approved_at` from the authenticated actor
- Valid Manual/Import rows are immediately Ready for payroll generation; operational integrity validation still runs at generation time
- Legacy Draft/Submitted/Returned Manual/Import rows on editable Draft periods can be normalized with `php artisan payroll:normalize-legacy-crew-timesheet-approvals`
- Submit/Approve/Return routes remain for backward compatibility on legacy rows; the payroll board no longer shows those actions for Manual/Import
- Crew Operations still uses Prepare → Approve → Apply and displays **Applied** on the board (not Manual/Import-style Approved)
- Financial-only updates on locked Crew Operations rows preserve operational values and Applied approval

Clear Timesheets (Draft crew periods only):

- One period-level action clears every Manual and Excel Import timesheet in the current Draft crew pay period (`DELETE /payroll/{payrollPeriod}/crew-timesheets/manual-import`)
- Requires `payroll.crew_timesheets.clear`
- Soft-deletes matching rows; Crew Operations timesheets, preparation-linked rows, timeline/preparation history, contracts, salary inputs, and exclusions are never modified
- Legacy `source = null` rows are treated as Manual via `resolvedSource()` and are included
- After clearing, Manual save and Excel import restore the soft-deleted row (no duplicate-key failure) and re-apply auto-approval
- Daily Crew rows return to Not Entered; Monthly Crew Manual/Import overrides are removed so default monthly eligibility applies again
- Show page exposes trusted `clearable_timesheet_count` for the whole period (not the current page only)
- Audits with `event = crew_timesheets_cleared` including company, period, actor, cleared count, sources, and timesheet IDs

Key files:

- `app/Support/Payroll/ApplyManualImportTimesheetAutoApproval.php`
- `app/Support/Payroll/Actions/NormalizeLegacyManualImportTimesheetApprovals.php`
- `app/Support/Payroll/Actions/ClearManualImportCrewTimesheets.php`
- `app/Support/Payroll/ClearableManualImportCrewTimesheetsQuery.php`
- `app/Console/Commands/NormalizeLegacyCrewTimesheetApprovalsCommand.php`

- `app/Enums/CrewTimesheetMode.php`
- `app/Enums/CrewTimesheetApprovalStatus.php`
- `app/Support/Payroll/BuildCrewPayrollGenerationPreview.php`
- `app/Support/Payroll/CrewPayrollGenerationPreview.php`
- `app/Support/Payroll/CrewOperationsPayrollGenerationGuard.php`
- `app/Support/Payroll/RegularPayrollPeriodKey.php`
- `app/Support/Payroll/Actions/UpdatePayrollPeriodCrewTimesheetMode.php`
- `app/Support/Payroll/Actions/SubmitCrewTimesheetApproval.php`
- `app/Support/Payroll/Actions/ApproveCrewTimesheetApproval.php`
- `app/Support/Payroll/Actions/ReturnCrewTimesheetApproval.php`
- `resources/js/features/payroll/types.ts`
- `resources/js/features/payroll/show.tsx`
- `resources/js/features/payroll/components/payroll-generate-dialog.tsx`

### Production hardening (post Phase 1E / hybrid)

Data-integrity and concurrency hardening applied on top of Phases 1A–1E and the hybrid mixed-source model.

Payroll-period contract resolution:

- `app/Support/Payroll/ResolveCrewContractForPayrollPeriod.php` resolves the crew contract applicable to the payroll period (company + employee + Crew category + not soft deleted + overlapping the period dates), with a deterministic latest-active fallback for legacy data.
- Used by preparation, issue detection, day allocation, application, manual/import upserts, generation, readiness, the source hasher, and Daily-vs-Monthly classification, so a historical period never uses an unrelated present-day contract.

Financial import preservation:

- Crew Operations financial imports use explicit-presence handling. A blank field preserves the stored value, an explicit zero clears it, and an explicit amount updates it. Applied operational metadata is never changed by import.

Payable-category filtering:

- `app/Support/Payroll/CrewTimeline/PayableCrewPreparationLines.php` is the single source of truth for payable lines (sign-on standby, onsite, sign-off standby with days > 0). Excluded, warning-only, and zero-day lines never require a linked Crew Operations timesheet, and the same predicate is used by application, readiness, and generation validation.

Readiness / generation parity:

- Show-page readiness uses `BuildCrewPayrollCoverageSummary` once for period-level blockers only. Per-employee skip counts run on Generate click via `BuildCrewPayrollGenerationPreview` and again under lock on confirm.
- Public preview JSON returns counts and capped blocking issues only; Ready employee IDs stay server-side.
- Exclusive historical `crew_operations` still uses period-level Applied preparation checks.
- Confirmation soft-deletes draft skipped records (never approved/paid), loads existing payroll rows once, and skips full-period salary-input recalculation when no inputs exist.
- Indexed lookups: `crew_timesheets (company_id, period_id, approval_status)`, `payroll_records (company_id, period_id, payroll_category)`, `crew_timesheet_preparations (company_id, payroll_period_id, status)`.

Source freshness:

- The preparation source hash covers period boundaries, cutoff, phases (code/status/actual dates), the period-applicable contract (id, category, salary structure, effective dates), and pending movement correction state. Contract changes, salary-structure changes, new pending corrections, actual-movement changes, and added/removed phases all make an approved preparation stale.

Timeline queries:

- `CrewTimelinePhaseQuery::issuePhases()` includes phases missing `actual_start_at` (by planned window) so a blocking `missing_actual_start` warning is raised instead of silently dropping the phase; `overlappingPhases()` (payable allocation) still uses actual timestamps only. Both resolve period boundaries in the company timezone and compare in UTC so phases crossing a UTC midnight boundary are not excluded.

Overlap detection:

- Movement actions use one `occurred_at`, so a phase ending exactly when the next begins (`actual_end_at == actual_start_at`) is a valid exact handoff. The transition calendar day is still allocated once by category priority (Onsite > Sign-Off Standby > Sign-On Standby > Excluded) and does **not** raise a warning. A blocking `overlapping_phases` warning is raised only for a genuine positive-duration timestamp overlap (`left.start < right.end AND right.start < left.end`), decided by `CrewPhaseIntervalOverlapDetector` on absolute instants. No separate phase start/end inputs are needed for normal movement entry; actual-date changes go through the Crew Movement Correction workflow.

Empty Applied preparation:

- An Approved preparation with zero payable Daily employees can be applied, is marked Applied with zero applied employees, remains idempotent on repeated apply, and does not block generation.

Concurrency & history:

- Generation and `UpsertCrewTimesheet` lock the payroll period (and target rows) and revalidate status/mode/lock state after acquiring the lock.
- `CrewTimesheetPreparation` and `CrewTimesheetPreparationLine` use `SoftDeletes` so approved/applied history is never hard-deleted; creation migrations recover missing columns and indexes idempotently.

Excel template:

- Hybrid periods lock/protect operational cells only for employees with Applied Crew Operations coverage (pre-filled `From Crew Operations`); uncovered Daily employees can enter operational dates; financial fields stay editable. Exclusive historical Crew Operations mode still locks all Daily operational cells. Backend validation stays authoritative. Import preview row modes include `crew_operations_locked`, `import_fallback`, and `monthly_crew`.

There is no Applied-preparation replacement or Approved/Paid payroll correction workflow — that remains an optional future phase.

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
| DELETE | `/payroll/{payrollPeriod}/crew-timesheets/manual-import` | `payroll.crew-timesheets.clear-manual-import` | `payroll.crew_timesheets.clear` |
| POST | `/payroll/{payrollPeriod}/crew-timeline/prepare` | `payroll.crew-timeline.prepare` | `payroll.crew_timesheets.prepare` |
| GET | `/payroll/{payrollPeriod}/crew-timeline/{preparation}` | `payroll.crew-timeline.show` | `payroll.crew_timesheets.view` |
| POST | `/payroll/{payrollPeriod}/crew-timeline/{preparation}/submit` | `payroll.crew-timeline.submit` | `payroll.crew_timesheets.submit` |
| POST | `/payroll/{payrollPeriod}/crew-timeline/{preparation}/approve` | `payroll.crew-timeline.approve` | `payroll.crew_timesheets.approve` |
| POST | `/payroll/{payrollPeriod}/crew-timeline/{preparation}/return` | `payroll.crew-timeline.return` | `payroll.crew_timesheets.return` |
| POST | `/payroll/{payrollPeriod}/crew-timeline/{preparation}/apply` | `payroll.crew-timeline.apply` | `payroll.crew_timesheets.apply_approved` |
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
payroll.crew_timesheets.clear
payroll.crew_timesheets.prepare
payroll.crew_timesheets.submit
payroll.crew_timesheets.approve
payroll.crew_timesheets.return
payroll.crew_timesheets.apply_approved
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

Payroll feature coverage is under `tests/Feature/Payroll/`, including period lifecycle, automatic rolling period creation (`EnsureFuturePayrollPeriodsTest`), office and crew generation, timesheet imports, recalculation, salary inputs, records, exports, payslips, salary-sheet payslips, WPS, permissions, and activity logging.

Calculator and support-level coverage is under `tests/Unit/Support/Payroll/`.

Run the focused suite with:

```bash
php artisan test --compact tests/Feature/Payroll tests/Unit/Support/Payroll
```
