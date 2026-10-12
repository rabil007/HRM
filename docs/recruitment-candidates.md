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
- `2026_10_12_120000_normalize_recruitment_candidate_joining_stage_to_offer_jol.php` — remaps active `joining` candidate rows to `offer_jol` (transition history unchanged)
- `2026_10_12_123454_add_interview_notes_to_recruitment_candidates_table.php` — additive `interview_notes` (pre-interview preparation) separate from `interview_feedback`

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
| `recruitment.candidates.move` | Stage moves, backward stage corrections, select, interview-path reject, undo selected (plus ownership) |
| `recruitment.candidates.manage` | Ownership override only; also required with `offer.revise` and joining correction/undo |
| `recruitment.candidates.cv.download` | Private CV download (also requires `view`) |
| `recruitment.candidates.offer.prepare` | Prepare Draft Offer/JOL from Selected Interview (plus ownership) |
| `recruitment.candidates.offer.update` | Edit Draft offer details/documents (plus ownership) |
| `recruitment.candidates.offer.send` | Mark offer Sent (records external send; does **not** email) |
| `recruitment.candidates.offer.decide` | Accept (stay in Offer/JOL; start joining preparation) or reject offer (→ Rejected) from Sent |
| `recruitment.candidates.offer.revise` | Create audited Draft revision of Sent/Accepted/Rejected (requires `manage`) |
| `recruitment.candidates.offer.download` | Private offer/acceptance document download (also requires `view`) |
| `recruitment.candidates.joining.confirm` | Confirm actual joined from Offer/JOL when current offer is Accepted and readiness = Ready |
| `recruitment.candidates.convert` | Convert confirmed Joined candidate into an employee record with HR review |
| `reports.recruitment.view` | View recruitment report, candidate pipeline metrics, and fulfillment durations |
| `reports.recruitment.export` | Export recruitment report to Excel or CSV |

Re-seed: `php artisan db:seed --class=PermissionsSeeder`. Assign via Roles & permissions. Owner receives the catalog via AdminSeeder. Do not hardcode role names. CC notification recipients gain no candidate action rights.

### Ownership

Create / update / move / offer / confirm joined actions require the action permission **and** (`assigned_to` on the linked requirement **or** `recruitment.candidates.manage`). Ownership follows the requirement’s current assigned recruiter after reassignment or transfer. Reopen Rejected additionally requires `manage` + `move`. Offer revise requires `offer.revise` + `manage`. Correct / undo Joined requires `manage` only.

## Workflow

Forward path (manual only; no Client Approval stage):

**Applied → Screening → Interview → Offer/JOL → Joined**.

Joining preparation (expected date, readiness, blockers, reminders, Confirm Joined) happens inside **Offer/JOL** after the current offer is **Accepted**. Historical transition rows may still contain stage value `joining`; the enum case remains for casting/history labels only. Active candidates previously stored as `joining` are normalized to `offer_jol` by migration.

### Phase 1 (screening / interview)

- Forward: Applied → Screening → Interview via authorized `move` (Candidate Detail, table, Move to Stage menu, or Kanban drag-and-drop). Drag/drop never writes the database; it opens the same movement dialog as the menu, and the UI updates only after a successful backend response. The whole Kanban card body is the drag surface; a normal click opens Candidate Detail (interactive buttons/menus stay independent).
- Optional interview **preparation** fields (schedule, mode, interviewer, location, `interview_notes`) may be submitted with Screening → Interview in a single transactional move. Pre-interview notes are distinct from post-interview `interview_feedback`. The Move to Interview dialog does **not** include a redundant generic Note field.
- **Backward corrections** (authorized `move`, audited `stage_corrected`): Screening → Applied; Interview → Screening or Applied. Mandatory correction reason (min 3 chars). Leaving Interview snapshots the active interview cycle into transition `context.interview_cycle_snapshot` and clears active interview fields so Screening → Interview re-entry starts a clean cycle. Prior feedback/notes remain in history and are shown in Recruitment Movement History as expandable **Previous Interview Details**. Blocked when a current Offer/JOL exists — use offer revise / management workflows instead.
- Routine interview schedule/prep edits are allowed only while stage is Interview and outcome is still pending (backend-enforced). While outcome is pending, the Interview panel does **not** expose an editable feedback textarea — optional final feedback is captured through **Mark Selected**. After Selected / Offer/JOL / Joined / Rejected, feedback is read-only; use Undo Selected or other authorized correction paths to restore permitted editing.
- **Protected transitions**: Offer/JOL → Interview is not a free correction (blocked destination with explanation). Joined → Offer/JOL uses Undo Joined. Rejected → previous stage uses Reopen (recorded `pre_rejection_stage` only).
- Reject from Applied, Screening, or Interview (reason required). Interview rejection sets `interview_outcome = not_selected`.
- **Mark Selected** opens a shared confirmation dialog (Kanban, Table, Detail / Recruiter Next Action) with optional Interview Feedback. Selection + any supplied feedback save in one transactional request. Empty optional feedback never overwrites existing feedback. Keeps stage = Interview with Selected badge until Offer/JOL is prepared.
- Undo Selected clears outcome to pending (audited) and preserves recorded feedback. Blocked after an Offer/JOL exists.
- Reopen Rejected restores recorded `pre_rejection_stage`. When restoring Offer/JOL, Selected outcome is preserved; any historical Joining restore value is normalized back to Offer/JOL.
- Profile updates never change stage, outcome, or requirement links.
- Interview field changes are audited separately.
- Optimistic `lock_version` + expected stage/outcome guard concurrent updates.
- Server-authored `move_destinations` drive valid Kanban drops and Move to Stage options (permissions + ownership + parent openness + stage state).
- Candidate Detail shows a **Recruiter Next Action** panel (recommended step, why, other authorized actions, attention items) derived from the same permissions / destinations — not a second workflow engine.
- Recruitment forms show required markers that match Form Requests (e.g. Add Candidate: requirement, position, name unconditional; Email/Phone “at least one”).

### Phase 2 (Offer/JOL) & Timezone Normalization

- Event timestamps (`sent_at`, `accepted_at`, `rejected_at`, `interview_scheduled_at`, `joined_at`) are parsed and validated in company timezone and stored normalized to application storage timezone (`app.timezone`).
- Date-only fields (`offer_date`, `expiry_date`, `expected_joining_date`, `actual_joining_date`) remain calendar dates.
- **Prepare Offer**: only Selected Interview candidates; Open requirement + Open line; creates Draft offer (`is_current`), moves stage to Offer/JOL, keeps `interview_outcome = selected`. Initial preparation opens a shared **Prepare Offer/JOL dialog** directly from Kanban, table, Next Action, and Detail (no intermediate “open sheet” step). Existing Draft edit / Sent / decide / revise stay on the detail Offer panel.
- **Mark Sent**: records an actual send event outside the system (no email). Actual Sent Date cannot be in the future or before Offer Date (company timezone). UI explains Offer Date vs Actual Sent Date and blocks obviously invalid dates before submit.
- Offer statuses: **Draft → Sent → Accepted | Rejected**.
- One current offer per candidate; previous revisions kept (`is_current = false`, `supersedes_offer_id`, `revision_number`).
- Safe upload rollback: newly uploaded files during offer creation or revision are deleted on rollback, while replaced files are deleted only post-commit.
- **Accepted** (from Sent only): records acceptance, **keeps candidate in Offer/JOL**, initializes `expected_joining_date` from the offer’s proposed joining date and `joining_readiness_status = pending`. Accepted offer status distinguishes this from Draft/Sent; do not treat Accepted + Offer/JOL as an error.
- **Rejected** (from Sent only): reason + decision date, offer preserved as Rejected, candidate → Rejected with `pre_rejection_stage = offer_jol`. Does **not** set `interview_outcome = not_selected`.
- **Revise** (manage + revise + reason):
  - Creates a new Draft revision and returns candidate to Offer/JOL.
  - **Joined candidate protection**: Offer revision is strictly rejected when candidate stage is `Joined` (enforced in `CandidateWorkflowAuthorization`, `ReviseCandidateOffer`, `ReviseCandidateOfferRequest`, and UI `can_revise` permission). Joining data and joined counts remain preserved. Revising requires first using the audited Undo Joined flow (`CorrectCandidateJoined`), which retains its downstream `employee_id` conversion safeguard.
  - **Employee conversion protection**: Offer revision is also rejected whenever `employee_id` is set, regardless of candidate stage. The workflow capability and locked action both enforce this safeguard.

### Phase 3 (Joining Preparation inside Offer/JOL, Confirmation & Reminders)

- **Date-Only Timezone Handling**:
  - `offer_date`, `expected_joining_date`, and `actual_joining_date` extract pure calendar `Y-m-d` before constructing `!Y-m-d` CarbonImmutable instances in company timezone. This eliminates timezone shifts (e.g. UTC 00:00 shifting to previous-day evening in UTC-4/UTC-5).
  - Joining countdowns in `CandidatePresenter` compare start-of-day calendar dates in company timezone using `(int) round($today->floatDiffInDays($expected, false))` to prevent fractional-day truncation during 23h/25h DST transitions and midnight boundaries.
- **Joining preparation** (Offer/JOL panel): expected joining date, readiness status (`Pending` / `Ready`), optional readiness notes and blocker notes. Editable only while stage is Offer/JOL **and** the current offer is Accepted. Draft/Sent/Rejected offers cannot update readiness.
- **Update Readiness** from Kanban/Table opens a shared readiness dialog using the same backend mutation as Detail. On Candidate Detail, Joining & Readiness is an inline editable form with **Save Readiness** (no extra Update Readiness dialog). Confirm Joined remains a separate protected action.
- Readiness edits are audited with actor, timestamp, and reason without altering accepted offer terms.
- **Confirm Joined**: explicit action requiring current Accepted offer, stage Offer/JOL, valid parents, and readiness = Ready. Requires actual joining date (historical valid, no future relative to company timezone, on/after offer acceptance date). Moves candidate **Offer/JOL → Joined** with immutable transition record. Accepted offers alone never count as Joined.
- **Undo Joined**: audited management-only correction path (`manage` + reason) reverting candidate to **Offer/JOL**, preserving Accepted offer and readiness fields while clearing actual joining confirmation data. Blocked if downstream employee conversion exists (`employee_id !== null`).
- **Joining Action Error Handling**:
  - `joining-form-errors.ts` maps backend validation errors (`candidate`, `stage`, `offer`, `readiness_status`, `lock_version`, `notes`, `reason`) to banner and field alerts with an unrendered fallback.
  - Action dialogs preserve user inputs and remain open on failure, clearing stale errors when reopened. Action buttons disable during submission.
- **Requirement Progress & Fulfillment**:
  - Position line joined counts are dynamically derived from confirmed Joined candidates. UI displays required headcount, joined count, clamped remaining headcount, overfill flag, and suggests `Mark Filled` when target is reached (never auto-closes lines or requirements).
  - Headcount reductions in `ChangeHeadcountAction` and `ApproveHeadcountRevisionAction` are strictly guarded against dropping below confirmed joined count.
  - **Requirement Filled Completion & Management Override**: Marking a requirement Filled (`recruitment.requirements.close`) checks required headcount, confirmed joined count, remaining vacancies, and overfill per position line. Full fulfillment completes normally when all lines have reached their target (`unfilled_vacancies === 0`). Requirements with zero active positions cannot be marked filled. When unfilled vacancies remain, normal closure is blocked unless an authorized user with `recruitment.requirements.override_fill` provides a mandatory business reason override. Overfill in one line never offsets vacancies in another, required headcount is never silently reduced, and position lines are independently resolved upon closure: lines with met targets become `Filled`, while lines with remaining vacancies become `Closed` (preventing actionable open lines inside completed requirements while preserving snapshots). Audit records capture actor, mandatory reason, remaining vacancies, and fulfillment snapshot.
  - **Requirement Reopening & Position Restoration**: Reopening a Completed requirement restores position lines that were closed as `Filled` or `Closed` back to `Open`, preserving deliberate line-level cancellations (`Cancelled`). Reopening a Cancelled requirement restores only position lines that were actively open prior to requirement cancellation (tracked via cancellation snapshot metadata or historical activity log fallback with original cancellation timestamps), keeping lines that were independently cancelled before requirement closure unchanged. Reopening fails safely with clear feedback if all lines were deliberately cancelled beforehand. Confirmed candidate joining and employee conversion records are never modified on reopen.
- **Internal Reminders**:
  - Interview: 1 day before and day of (scheduled date).
  - Joining: 7 days before, 3 days before, and day of (expected joining date) for Offer/JOL candidates with an Accepted current offer.
  - Overdue joining: once daily up to 7 days overdue for the same eligibility.
  - Reminders stop after Confirm Joined (or when the candidate/offer is no longer eligible).
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
  - **Candidate Conversion Mode Guard & Provisional Draft Isolation**:
    - When `candidate_context` is present, provisional employee creation via `/organization/employees/ensure` is disabled and prohibited with a 422 validation response.
    - All record-tab mutations (contracts, salary revisions, bank accounts, documents, education, work experience, vaccinations, languages, trainings, sea service) are disabled during conversion. Unavailable record tabs display: *"Create the employee first to add these records."*
    - Profile fields on the Personal tab and header card remain fully editable for HR review and adjustments prior to conversion.
    - Requests combining `candidate_id` with a provisional `employee_id` (via query parameter on `create` or payload on `store`) are rejected with 422 validation errors; provisional drafts are never silently reused or merged.
    - Final conversion atomically creates exactly one employee record and links the candidate; authorized users can then open the finalized employee profile and add record tabs normally.
  - Form visits, template changes, or form cancellations never create employee records or provisional drafts.
- **Linking Existing Employee**:
  - Links an existing employee record to a confirmed Joined candidate (`recruitment.candidates.convert`, `recruitment.candidates.view`, and `employees.view`).
  - Candidate `lock_version` is mandatory; stale submissions are rejected with a clear refresh prompt.
  - Inside the transaction, revalidates candidate Joined stage, unlinked employee status, employee company tenant match, and department visibility under row locks (`lockForUpdate`).
  - Rejects cross-company employee selections and respects department scoping (`Role::SCOPE_SELECTED_DEPARTMENTS`).
  - Prevents duplicate employee links on the same requirement while allowing legitimate associations across distinct requirements (rehires, separate recruitment campaigns).
  - Preserves explicit confirmation, mandatory reason, audit trail, and downstream employee masking rules when employee access is restricted.
- **Employee Information Security**:
  - Duplicate employee suggestions require `employees.view` in addition to tenant and department visibility scoping. Conversion permission alone does not grant employee viewing.
  - When the linked employee is inaccessible (missing `employees.view`, cross-company, or restricted department), employee IDs, numbers, names, and profile URLs are masked to `null` across candidate index/show payloads and report exports while retaining the safe `Converted` status indicator.
- **Accepted Offer Compensation Review**:
  - The conversion form displays the proposed total salary amount and currency code from the accepted current offer as an HR reference guide.
  - Invented basic/allowance breakdown mappings and hardcoded AED fallbacks are eliminated; contract inputs are never automatically overwritten.
- **Recruitment Report & Metrics**:
  - Navigation: Reports → Recruitment Report (`reports.recruitment.view`, `reports.recruitment.export`).
  - **Recruiter Options Filter**:
    - Populates recruiter filter options (`RecruiterOptionsQuery::forCompany`) using active `company_user` memberships to include all recruiters belonging to the selected company. Excludes inactive accounts and revoked company memberships while preventing cross-company exposure.
  - **Metric Definitions**:
    - **Selected**: Defined by `interview_outcome = 'selected'`. Reflects all candidates with a Selected interview outcome within the active filter scope (including those in Interview stage and those progressed to Offer/JOL/Joined).
    - **Awaiting join (`joining_count`)**: Offer/JOL candidates with an Accepted current offer (not a separate Joining stage). Joined remains the only stage counting toward confirmed requirement fulfillment.
    - **Pipeline Totals vs Position Fulfillment**: Separate metric scopes in UI. Pipeline cards (Applications, Selected, Awaiting join, Joined, Rejected, Converted) reflect the active filter view. Position fulfillment (Headcount, Confirmed Joined, Remaining, Overfill) reflects total authorized slots and all confirmed Joined candidates for the scoped requirements and position lines, independent of candidate stage, search, conversion, or date filters.
    - **Per-Line Fulfillment**: Remaining and overfill are calculated per position line (`max(0, required - joined)` and `max(0, joined - required)`) and summed across scoped lines. An overfill in one position never cancels out a shortage in another position.
  - **Duration Calculations**:
    - Calendar durations (Time to Hire and Requirement Approval to Joined) calculate date differences using naive calendar dates (`!Y-m-d` in UTC) derived from company-timezone date strings. This eliminates fractional-day and DST transition (23h/25h) truncation artifacts across timezone boundaries.
    - `actual_joining_date` serves as the authoritative joining date; `joined_at` serves strictly as audit confirmation time.
    - Returns `null` when authoritative dates are missing or chronologically invalid (e.g. historical creation or approval recorded after joining).

## UI

- Navigation: Recruitment → Candidates; Reports → Recruitment Report.
- Index workspace:
  - Table and Kanban views share the same server filters (search, requirement, position, stage, interview outcome, needs-attention).
  - Kanban is a viewport-fitted board (`Main fixed`): sticky toolbar, independently scrolling columns, sticky column headers. Active pipeline columns are Applied → Screening → Interview → Offer/JOL → Joined. Rejected remains fully accessible via a collapsible secondary lane and the Rejected pipeline chip (no Joining column). Index has no Recruitment → Candidates breadcrumb row.
  - Drag-and-drop (`@dnd-kit`) between permitted columns highlights valid/invalid destinations, supports empty columns and keyboard sensors, and opens movement dialogs on drop (cancel/validation keeps the card in its original stage). Card overflow **Move to Stage** and Candidate Detail actions share the same destinations and `CandidateStageMoveDialog` (including optional internal interviewer selection when moving Screening → Interview).
  - **Reject** and **Reopen** stay dedicated menu/button workflows (reason required); they are not unrestricted stage drops. Protected Offer/JOL and Joined corrections continue through prepare-offer, confirm-joined, and undo-joined paths.
  - Compact cards show stage-relevant cues (Selected / interview outcome, interview date/time, offer/joining urgency, blockers, conversion) plus primary next actions. Stage-age badges (“Today” / “Xd waiting”) are not shown on Kanban cards. Whole-card mouse drag remains; a subtle grip supports keyboard drag without blocking Enter-to-open-detail. Offer/JOL priority is Confirm Joined (when permitted) before Update Readiness, then Review Sent / Complete Draft / Convert to Employee as applicable.
  - Table/Kanban toggle changes the current view only. Pin saves the default to `localStorage` (`recruitment-candidates:view`). Explicit `?view=` overrides the pin; visits without `view` apply the pinned default.
  - **Needs attention** (server filter + card highlight) = joining due/overdue, recorded blockers, or joined-but-not-converted. **Stalled** is a separate client-only age cue (`days_in_stage ≥ 7`) and is not a domain status.
- Candidate detail (stage-aware recruiter workspace):
  - Compact header: identity, position, requirement, stage/outcome/offer badges, days in stage, shared next-action controls (`CandidateMovementActions` + `CandidateStageMoveDialog`), Edit profile, and CV access (or a clear missing-CV indicator).
  - Compact pipeline progress: Applied → Screening → Interview → Offer/JOL → Joined (Rejected called out separately; not an unrestricted stage editor).
  - Desktop two-column layout: primary current-stage workflow (~2/3) and sticky summary/requirement/document sidebar (~1/3). Mobile stacks naturally.
  - Stage priority: Applied/Screening guidance → Interview form when Interview → Offer/JOL Draft/Sent first → Accepted Offer/JOL leads with inline Joining & Readiness, then **Offer/JOL — Accepted** collapsed underneath → Joined conversion/confirmation with historical Offer/JOL collapsible → Rejected reason/reopen. Historical Interview/Offer/Joining panels stay collapsed by default and expand for `#interview`, `#offer-jol`, and `#joining` hash links (including Prepare Offer / Update Readiness from Kanban/Table, and Revise Offer).
  - Recruitment movement history is a compact action timeline (stage moves, selection, rejection/reopen, offer/joining/conversion cues, correction reasons, expandable previous-interview snapshots) and stays separate from general profile audit activity; both remain permission-gated. Transition context is sanitized — only approved fields are returned.
- Requirement detail: Position lines card shows joined progress per position, target reached banner with Mark Filled suggestion, and links to filtered candidates by line.

## Out of scope

Workbook import, candidate-facing external emails, payroll/crew assignments, WMS reminders.
