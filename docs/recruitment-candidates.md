# Recruitment Candidates

Candidate management through screening, interview selection, Offer/JOL (Phase 2), Joining & Internal Reminders (Phase 3), and Candidate Conversion to Employee & Recruitment Reports (Phase 4). Payroll / crew assignments remain downstream (out of scope).

## Migrations

Additive migrations (do not edit deployed history):

- `2026_10_09_051756_create_recruitment_candidates_tables.php` — `recruitment_candidates`, `recruitment_candidate_stage_transitions`
- `2026_10_09_051757_add_recruitment_candidates_permissions.php` — Spatie permission rows only (no auto-grant to existing roles)
- `2026_10_09_120000_create_recruitment_candidate_offers_table.php` — `recruitment_candidate_offers` (current + revision history)
- `2026_10_09_120001_add_recruitment_candidate_offer_permissions.php` — Offer/JOL Spatie permission rows
- `2026_10_10_120000_add_joining_fields_to_recruitment_candidates_table.php` — `expected_joining_date`, `actual_joining_date`, `joined_at`, `joined_by`, `joining_readiness_status`, `joining_readiness_notes`, `joining_blocker_notes`, `employee_id`
- `2026_10_10_120001_create_recruitment_candidate_internal_reminders_table.php` — `recruitment_candidate_internal_reminders`
- `2026_10_10_120002_add_recruitment_candidate_joining_permissions.php` — `recruitment.candidates.joining.confirm` permission

Legacy `candidates` / `interviews` / `job_offers` / `job_postings` tables are **unchanged**. Existing rows stay as-is; no requirement links are invented for them.

### Existing-data handling

- New candidates always require company + Open requirement + Open position line.
- Snapshots store `requirement_number_snapshot` and `position_title_snapshot` at create time.
- Requirement / line FKs use `nullOnDelete` so history survives parent removal.
- Application code **blocks** hard-deleting a requirement (force delete) or a position line when candidates still reference it.
- Soft-deleted requirements remain readable via snapshots when FKs are nullified or parents are missing; workflow actions stay disabled until a valid Open parent exists.

### Master data

`MasterDataUsage` counts both legacy `candidates.nationality_id` and `recruitment_candidates.nationality_id` for Countries delete protection.

## Permissions

Registered in `ApplicationPermissionDefinitions` and synced by `PermissionsSeeder`:

| Permission | Effect |
| --- | --- |
| `recruitment.candidates.view` | Company-wide list, Kanban, show |
| `recruitment.candidates.create` | Add candidate (plus ownership) |
| `recruitment.candidates.update` | Profile / interview / CV upload / readiness update (plus ownership) |
| `recruitment.candidates.move` | Stage moves, select, interview-path reject, undo selected (plus ownership) |
| `recruitment.candidates.manage` | Ownership override only; also required with `offer.revise` and joining correction/undo |
| `recruitment.candidates.cv.download` | Private CV download (also requires `view`) |
| `recruitment.candidates.offer.prepare` | Prepare Draft Offer/JOL from Selected Interview (plus ownership) |
| `recruitment.candidates.offer.update` | Edit Draft offer details/documents (plus ownership) |
| `recruitment.candidates.offer.send` | Mark offer Sent (records external send; does **not** email) |
| `recruitment.candidates.offer.decide` | Accept (→ Joining) or reject offer (→ Rejected) from Sent |
| `recruitment.candidates.offer.revise` | Create audited Draft revision of Sent/Accepted/Rejected (requires `manage`) |
| `recruitment.candidates.offer.download` | Private offer/acceptance document download (also requires `view`) |
| `recruitment.candidates.joining.confirm` | Confirm actual joined for candidate in Joining stage with readiness = Ready |
| `recruitment.candidates.convert` | Convert confirmed Joined candidate into an employee record with HR review |
| `reports.recruitment.view` | View recruitment report, candidate pipeline metrics, and fulfillment durations |
| `reports.recruitment.export` | Export recruitment report to Excel or CSV |

Re-seed: `php artisan db:seed --class=PermissionsSeeder`. Assign via Roles & permissions. Owner receives the catalog via AdminSeeder. Do not hardcode role names. CC notification recipients gain no candidate action rights.

### Ownership

Create / update / move / offer / confirm joined actions require the action permission **and** (`assigned_to` on the linked requirement **or** `recruitment.candidates.manage`). Ownership follows the requirement’s current assigned recruiter after reassignment or transfer. Reopen Rejected additionally requires `manage` + `move`. Offer revise requires `offer.revise` + `manage`. Correct / undo Joined requires `manage` only.

## Workflow

Forward path (manual only; no Client Approval stage):

**Applied → Screening → Interview → Offer/JOL → Joining → Joined**.

### Phase 1 (screening / interview)

- Forward only: Applied → Screening → Interview (explicit actions; no drag-and-drop).
- Reject from Applied, Screening, or Interview (reason required). Interview rejection sets `interview_outcome = not_selected`.
- Select keeps stage = Interview with Selected badge until Offer/JOL is prepared.
- Undo Selected clears outcome to pending (audited). Blocked after an Offer/JOL exists.
- Reopen Rejected restores recorded `pre_rejection_stage`. When restoring Offer/JOL, Selected outcome is preserved and Joining is normalized back to Offer/JOL.
- Profile updates never change stage, outcome, or requirement links.
- Interview field changes are audited separately.
- Optimistic `lock_version` + expected stage/outcome guard concurrent updates.

### Phase 2 (Offer/JOL) & Timezone Normalization

- Event timestamps (`sent_at`, `accepted_at`, `rejected_at`, `interview_scheduled_at`, `joined_at`) are parsed and validated in company timezone and stored normalized to application storage timezone (`app.timezone`).
- Date-only fields (`offer_date`, `expiry_date`, `expected_joining_date`, `actual_joining_date`) remain calendar dates.
- **Prepare Offer**: only Selected Interview candidates; Open requirement + Open line; creates Draft offer (`is_current`), moves stage to Offer/JOL, keeps `interview_outcome = selected`.
- Offer statuses: **Draft → Sent → Accepted | Rejected**.
- One current offer per candidate; previous revisions kept (`is_current = false`, `supersedes_offer_id`, `revision_number`).
- Safe upload rollback: newly uploaded files during offer creation or revision are deleted on rollback, while replaced files are deleted only post-commit.
- **Accepted** (from Sent only): records acceptance, moves candidate to **Joining**, initializes `expected_joining_date` and `joining_readiness_status = pending`.
- **Rejected** (from Sent only): reason + decision date, offer preserved as Rejected, candidate → Rejected with `pre_rejection_stage = offer_jol`. Does **not** set `interview_outcome = not_selected`.
- **Revise** (manage + revise + reason):
  - Creates a new Draft revision and returns candidate to Offer/JOL.
  - **Joined candidate protection**: Offer revision is strictly rejected when candidate stage is `Joined` (enforced in `CandidateWorkflowAuthorization`, `ReviseCandidateOffer`, `ReviseCandidateOfferRequest`, and UI `can_revise` permission). Joining data and joined counts remain preserved. Revising requires first using the audited Undo Joined flow (`CorrectCandidateJoined`), which retains its downstream `employee_id` conversion safeguard.

### Phase 3 (Joining Readiness, Confirmation & Reminders)

- **Date-Only Timezone Handling**:
  - `offer_date`, `expected_joining_date`, and `actual_joining_date` extract pure calendar `Y-m-d` before constructing `!Y-m-d` CarbonImmutable instances in company timezone. This eliminates timezone shifts (e.g. UTC 00:00 shifting to previous-day evening in UTC-4/UTC-5).
  - Joining countdowns in `CandidatePresenter` compare start-of-day calendar dates in company timezone using `(int) round($today->floatDiffInDays($expected, false))` to prevent fractional-day truncation during 23h/25h DST transitions and midnight boundaries.
- **Joining Section**: compact section on Candidate detail showing expected joining date, actual joining date, readiness status (`Pending` / `Ready`), optional readiness notes and blocker notes.
- Readiness edits are audited with actor, timestamp, and reason without altering accepted offer terms.
- **Confirm Joined**: explicit action requiring current Accepted offer, stage Joining, valid parents, and readiness = Ready. Requires actual joining date (historical valid, no future relative to company timezone, on/after offer acceptance date). Moves candidate to `Joined` with immutable transition record.
- **Undo Joined**: audited management-only correction path (`manage` + reason) reverting candidate to `Joining`. Blocked if downstream employee conversion exists (`employee_id !== null`).
- **Joining Action Error Handling**:
  - `joining-form-errors.ts` maps backend validation errors (`candidate`, `stage`, `offer`, `readiness_status`, `lock_version`, `notes`, `reason`) to banner and field alerts with an unrendered fallback.
  - Action dialogs preserve user inputs and remain open on failure, clearing stale errors when reopened. Action buttons disable during submission.
- **Requirement Progress**: Position line joined counts are dynamically derived from confirmed Joined candidates. UI displays required headcount, joined count, clamped remaining headcount, overfill flag, and suggests `Mark Filled` when target is reached (never auto-closes lines or requirements).
- Headcount reductions in `ChangeHeadcountAction` and `ApproveHeadcountRevisionAction` are strictly guarded against dropping below confirmed joined count.
- **Internal Reminders**:
  - Interview: 1 day before and day of (scheduled date).
  - Joining: 7 days before, 3 days before, and day of (expected joining date).
  - Overdue joining: once daily up to 7 days overdue.
  - **Delivery Recipient Revalidation**: `DeliverCandidateInternalReminderJob` rechecks that recipients have active company membership via `ResolveCompanyAccess` and currently qualify as the assigned recruiter, submitter/creator fallback, or configured notification recipient. Removed CCs, former recruiters, and revoked memberships are skipped (`unqualified_recipient`, `revoked_membership`). Recipients are deduplicated; notification receipt grants no action permissions.
  - **Milestone Expiration**: Each milestone's intended delivery date is calculated in company timezone. If delivery is delayed past that date (`todayLocal > intendedDeliveryDate`), the reminder is skipped with `milestone_expired`. Rescheduled candidates skip with `joining_rescheduled` / `interview_rescheduled`.
  - **Independent Stale Record Recovery**: `recoverStaleRemindersForCompany` executes a bounded recovery sweep for stale `Pending` or `Queued` records independent of today's milestone candidate queries. Records are claimed under `lockForUpdate()`, skipping expired/obsolete records and re-queuing eligible ones idempotently.
  - Delivery: in-app notifications only (no external candidate emails). Idempotent execution, stale queued recovery, invalidation on reschedule, and cancellation upon interview selection or joining confirmation.
  - Scheduler: `recruitment:dispatch-candidate-reminders` command runs hourly via `routes/console.php` targeting companies at local 09:00 window.

### Phase 4 (Candidate Conversion to Employee & Recruitment Reports)

- **Conversion to Employee**:
  - Requires confirmed `Joined` stage, no existing linked employee, and `recruitment.candidates.convert` alongside `recruitment.candidates.view` and `employees.create`.
  - Atomically creates employee via `CreateEmployee` action and links candidate `employee_id` in a single transaction.
  - Candidate context (`candidate_id`) is strictly preserved across profile template switches and validation errors; `candidate_lock_version` is required on final save to guard concurrent changes.
  - Form visits, template changes, or form cancellations never create employee records or provisional drafts.
- **Employee Information Security**:
  - Duplicate employee suggestions require `employees.view` in addition to tenant and department visibility scoping. Conversion permission alone does not grant employee viewing.
  - When the linked employee is inaccessible (missing `employees.view`, cross-company, or restricted department), employee IDs, numbers, names, and profile URLs are masked to `null` across candidate index/show payloads and report exports while retaining the safe `Converted` status indicator.
- **Accepted Offer Compensation Review**:
  - The conversion form displays the proposed total salary amount and currency code from the accepted current offer as an HR reference guide.
  - Invented basic/allowance breakdown mappings and hardcoded AED fallbacks are eliminated; contract inputs are never automatically overwritten.
- **Recruitment Report & Metrics**:
  - Navigation: Reports → Recruitment Report (`reports.recruitment.view`, `reports.recruitment.export`).
  - **Metric Definitions**:
    - **Selected**: Defined by `interview_outcome = 'selected'`. Reflects all candidates with a Selected interview outcome within the active filter scope (including those in Interview stage and those progressed to Offer/Joining/Joined).
    - **Pipeline Totals vs Position Fulfillment**: Separate metric scopes in UI. Pipeline cards (Applications, Selected, Joining, Joined, Rejected, Converted) reflect the active filter view. Position fulfillment (Headcount, Confirmed Joined, Remaining, Overfill) reflects total authorized slots and all confirmed Joined candidates for the scoped requirements and position lines, independent of candidate stage, search, conversion, or date filters.
    - **Per-Line Fulfillment**: Remaining and overfill are calculated per position line (`max(0, required - joined)` and `max(0, joined - required)`) and summed across scoped lines. An overfill in one position never cancels out a shortage in another position.
  - **Duration Calculations**:
    - Calendar durations (Time to Hire and Requirement Approval to Joined) use `actual_joining_date` in company timezone; `joined_at` serves strictly as audit confirmation time.
    - Authoritative timestamps (`created_at`, `approved_at`) are normalized to company timezone before extracting start-of-day dates.
    - If authoritative dates are missing, or if historical entry occurred after joining, duration returns unavailable (`null`).

## UI

- Navigation: Recruitment → Candidates; Reports → Recruitment Report.
- Index: Table and Kanban include Joined stage. Kanban columns are independently paginated with server stage totals and upcoming/overdue joining badges.
- Candidate detail: Joining panel with status, dates, notes, and actions (Update Readiness, Confirm Joined, Undo Joined, Convert to Employee).
- Requirement detail: Position lines card shows joined progress per position, target reached banner with Mark Filled suggestion, and links to filtered candidates by line.

## Out of scope

Workbook import, candidate-facing external emails, payroll/crew assignments, WMS reminders.
