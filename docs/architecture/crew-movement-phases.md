# Crew Movement Phases

CrewAssignment is the **single source of truth** for crew movement.

```text
Crew Planning
    ↓ confirm / convert
Crew Assignment
    ↓ movement lifecycle
    Crew Assignment Phases
    ↓ completed P4
Employee Sea Service
```

**EmployeeDeployment has been removed.** There is no production deployment data and no legacy backfill is required.

## Domain model

| Concept | Role |
|------|------|
| **CrewPlanningAssignment** | Planned join/leave on the Gantt board; may convert into a draft assignment. |
| **CrewAssignment** | One mobilisation cycle (P0–P6). |
| **CrewAssignmentPhase** | Ordered occurrence of a phase on that cycle. |
| **EmployeeSeaService** | Historical sea time created from completed P4 phases. |

## P0–P6

| Code | Label |
|------|-------|
| P0 | Pre-Mobilisation |
| P1 | Travel In |
| P2A | Join Standby |
| P2B | Training |
| P3 | Ready to Join |
| P4 | On Vessel |
| P5 | Demobilisation Standby |
| P6 | Home / Redeployment |

## Tour of Duty

When Join Vessel creates active P4, the system resolves Tour of Duty directly from Global Rank Master (`ranks.max_tour_of_duty_days`) and suggests Planned Sign-Off.

### Resolution

Tour of Duty is global per Rank (`ranks.max_tour_of_duty_days`). There are no company policies or assignment overrides.

### Calculation (company timezone)

```text
suggested planned sign-off local date
    = actual P4 join local date + applied Tour of Duty days
```

Operational values for active P4:

```text
days_onboard        = whole local calendar days between actual join and today
current_duty_day    = days_onboard + 1
remaining_tour_days = planned sign-off local date − today (may be negative)
tour_progress_percent = days_onboard / applied Tour of Duty days
```

Display percentage may be clamped to 0–100; remaining days stay negative when overdue.

### Snapshot behaviour

After P4 join, `crew_assignments.tour_of_duty_days` stores an integer snapshot. Later changes to Rank Master do **not** rewrite existing assignments. New joins use the latest Rank Master value.

### Planned versus actual

- Planned Sign-Off is an expected date only (`planned_signoff_at` / P4 `planned_end_at`).
- A generated Planned Sign-Off must **never** complete P4, disembark the employee, close the assignment, create payroll days, or create Sea Service.
- Actual disembarkation remains a separate `confirm_disembarkation` action.

### Join Vessel sign-off choices

| Choice | Behaviour |
|--------|-----------|
| `tour_of_duty` | Use calculated suggestion |
| `existing_plan` | Keep existing Planning/assignment planned date (never silently overwritten) |
| `manual_override` | Enter another date; date and reason are both required when this choice is explicit |

If no Tour exists and no Planned Sign-Off is entered (and no explicit manual choice was supplied), join still succeeds and attention warnings surface `missing_tour_of_duty` / `missing_planned_signoff`.

### Tour status filters vs dashboard counts

`due_within_7_days`, `due_within_14_days`, and `due_within_30_days` Current Crew filters are **cumulative** (include due today and nearer windows) so they match Crew Operations dashboard “within N days” cards. Exclusive internal buckets remain only for analytics rollups. `due_today` stays independently filterable. Planned sign-off overdue uses company-local calendar dates so due-today assignments are never also overdue.

### Correction recalculation

When an approved correction changes P4 `actual_start_at` and `planned_signoff_source` is `tour_of_duty`, Planned Sign-Off is recalculated from the **snapshotted** tour days and written to:

1. `crew_assignments.planned_signoff_at`
2. P4 `crew_assignment_phases.planned_end_at`
3. Linked `crew_planning_assignments.planned_leave_date` (via `SyncPlanningAssignmentFromCrewAssignment` in the same approval transaction)

Manual / existing-plan sources are preserved. Pending corrections do not mutate official dates. A failure during approval rolls back assignment, phase, planning, and correction status together.

### Transfer / redeployment (Phase 2C.1)

Direct vessel transfer and direct-P4 redeploy create a **new linked assignment** (`previous_assignment_id`). They never mutate the source assignment into another vessel.

- Source P4 is completed at the actual handoff `occurred_at`; Sea Service syncs from that completed source P4 only.
- Destination starting in active P4 receives a **fresh Tour of Duty snapshot** via `CrewTourOfDutyResolver` + `CrewJoinVesselSignoffApplier` (same path as Join Vessel), based on **destination rank** and the handoff timestamp — not a copy of the source Tour.
- Redeploy to P0/P1/P2A/P3 does **not** snapshot Tour; Tour is applied later when that assignment performs Join Vessel.
- Planned Sign-Off remains forecast-only; only actual transfer/redeploy/`occurred_at` completes source P4.

### Notifications deferred

Email, browser Web Push, in-app notification feeds, escalation, and Announcement records for Tour of Duty are **not** implemented in Phase 1.

## Supported movement actions

| Action | Typical from phase |
|--------|--------------------|
| `approve_mobilisation` | P0 |
| `record_arrival` | P1 → P2A or P3 |
| `start_join_standby` | P1/P3 path helpers |
| `send_to_training` | P2A → P2B |
| `complete_training` | P2B → P2A or P3 |
| `mark_ready` | P2A → P3 |
| `join_vessel` | P3 (or direct paths) → P4 |
| `plan_signoff` | P4 plan only (does not disembark) |
| `confirm_disembarkation` | P4 → P5 or P6 |
| `start_demob_standby` | helper into P5 |
| `travel_home` | P5 → P6 |
| `close_assignment` | P6 → Completed |
| `cancel_assignment` | Draft/Active → Cancelled (not from active P4) |
| `void_erroneous_assignment` | Privileged admin cleanup (any P0–P6; separate route) |

## Void Erroneous Assignment

**Void** is not Cancel.

| | Cancel Assignment | Void Erroneous Assignment |
|--|-------------------|---------------------------|
| Meaning | Legitimate assignment stopped | Assignment / movement entered by mistake |
| Typical use | Client cancelled; mobilisation abandoned | Wrong employee, duplicate, erroneous progression |
| Permission | `crew_operations.assignments.cancel` | `crew_operations.assignments.void` |
| Phases | Not from active P4 | May be attempted from any P0–P6 |
| Result | Status `Cancelled` (record remains) | Soft-delete + void metadata; removed from active ops |
| Fake movements | Does not invent disembarkation | Does not invent disembarkation |

Void requires the dedicated permission **and** passes `CrewAssignmentVoidGuard`. Downstream blockers include:

- `payroll_applied` / `payroll_protected` — Applied, Approved/Submitted timeline prep, paid/approved work allocations, or timesheet segments
- `sea_service_exists` — linked `EmployeeSeaService` (never cascade-deleted)
- `linked_assignment_exists` — transfer/redeploy children via `previous_assignment_id`
- `already_voided` — already voided / soft-deleted

HTTP: `POST /organization/crew/{assignment}/void` (`organization.crew-assignments.void`) via `VoidCrewAssignment` Support action (transaction + `lockForUpdate()`). Linked assignment-derived planning bars are soft-deleted; phase history is retained under the soft-deleted assignment.

See also [crew-movement-corrections.md](./crew-movement-corrections.md).

## Linked assignment actions

### Transfer Vessel (`transfer_vessel`)

Available from Active P4 On Vessel. Completes the source P4 and assignment at `occurred_at`, syncs sea service and planning for the source, then creates a linked Active assignment (`previous_assignment_id`, `source = vessel_transfer`) that starts directly in active P4 on the destination vessel. Destination vessel must start blank in the form, must differ from the source, and is required. Rank/client may default from the current assignment. No artificial P5/P6/P0–P3 phases are created. The destination receives a fresh Tour of Duty snapshot (destination rank + handoff timestamp) via the same resolver/applier as Join Vessel. The movement controller redirects to the new assignment.

### Redeploy (`redeploy`)

Available from Active P5 or P6. Completes the source phase and assignment, then creates a linked assignment (`source = redeployment`) starting only at the chosen real phase: P0 (Draft + planned; vessel optional; planned sign-off cleared when not applicable), or P1 / P2A / P3 / P4 (Active; vessel optional except P4 requires vessel and rank). Same or different vessel/client is allowed. Direct P4 redeploy applies a fresh Tour snapshot; pre-P4 starts do not — Tour is applied later on Join Vessel. Hidden stale destination fields must not be submitted for P0. Earlier phases are never invented.

### Still unsupported as an immediate movement action

```text
correct_movement
```

Movement field corrections use a dedicated approval workflow instead of `correct_movement`. See [crew-movement-corrections.md](./crew-movement-corrections.md).

## Generic Assignment Editing

Generic Crew Assignment editing is limited to Draft/pre-P4 preparation. Once P4 begins, planned sign-off changes use Plan Sign-Off, historical field changes use Movement Corrections, and actual operational changes use movement actions.

## Permissions

Use Spatie permission names:

```text
crew_operations.assignments.view
crew_operations.assignments.create
crew_operations.assignments.update
crew_operations.movements.perform
crew_operations.assignments.cancel
crew_operations.assignments.void
crew_operations.corrections.view
crew_operations.corrections.request
crew_operations.corrections.approve
crew_operations.corrections.override
audit.view
```

Legacy `crew_operations.deployments.*` permissions are removed and migrated onto assignment permissions.

## Movement service

`CrewMovementService` runs every create/action in a company-scoped transaction with `lockForUpdate()`, invariant checks, and atomic phase updates. Completed P4 (`actual_end_at` set) syncs sea service via `SeaServiceSyncService` in the same transaction.

Tour resolution uses `CrewTourOfDutyResolver` / `CrewTourOfDutyCalculator`. Progress and status buckets use `CrewTourProgress` / `CrewTourStatusQuery`.

## Planning

Bidirectional sync:

1. **Planning → Assignment** — `CreateCrewAssignmentFromPlanning` creates a draft (`source = crew_planning`), links `crew_planning_assignments.crew_assignment_id`, then runs `SyncPlanningAssignmentFromCrewAssignment` so the original planning row is reused (no duplicate).
2. **Assignment → Planning** — `SyncPlanningAssignmentFromCrewAssignment` creates/updates the linked planning bar after Crew Assignments create/update and after every `CrewMovementService::perform()` action.

### Date precedence (Assignment → Planning)

- Join: `P4.actual_start_at` → `planned_join_at`
- Leave: `P4.actual_end_at` → `planned_signoff_at` → `P4.planned_end_at` → `null` (open-ended active P4)

Actual disembarkation replaces planned sign-off on the planning bar. Planned sign-off is never treated as actual disembarkation.

### Open-ended P4

Active P4 without planned/actual leave may store `planned_leave_date = null`. Gantt includes those rows (`planned_leave_date IS NULL` overlaps the range). Display `end` uses the requested Gantt `to` date only; it is not persisted. Payload includes `is_open_ended: true`.

### Linked-row ownership

Once `crew_assignment_id` is set, Crew Assignments is source of truth. Planning update/delete of linked rows is rejected; the UI links to the assignment instead.

### Cancellation

- Cancelled before any completed P4: soft-delete the linked planning bar.
- Completed P4 history: preserve the planning bar with actual join/leave dates.
- Incomplete pre-P4 eligibility (missing vessel/dates) does **not** delete an existing planning-origin row.

### Idempotency

Lookup by `crew_assignment_id` (unique), restore soft-deleted linked rows, never create a second planning row for one assignment.

Relief linking uses `relieves_crew_assignment_id`. Gantt `is_assigned` is true when `crew_assignment_id` is set.

## Phase 2A — Crew Relief Readiness

Crew Planning remains the management surface for creating and editing relief plans. Current Crew, Assignment Show, and the Crew Operations dashboard display derived readiness and risk; they do not introduce a separate Relief workflow or table.

### Derived readiness statuses

Resolved by `CrewReliefReadinessResolver` from the active operational Planning row where `relieves_crew_assignment_id` equals the onboard source assignment:

| Status | Meaning |
|--------|---------|
| `no_relief` | No active relief Planning row |
| `relief_planned` | Planning row exists; `crew_assignment_id` is null |
| `assignment_created` | Linked draft / not-yet-mobilising assignment |
| `mobilising` | Linked assignment in P0–P2B movement |
| `ready_to_join` | Linked assignment active P3 |
| `relief_onboard` | Linked assignment active P4 with actual join |

Soft-deleted Planning rows, cancelled or completed linked assignments, and linked Active assignments whose current phase is P5 or P6 do not count as operational relief. Operational linked relief is limited to Draft/Active assignments still in P0–P4. Vacant relief slots (null `employee_id`) still require source P4 / vessel / rank / duplicate validation. Authoritative duplicate and employee checks run inside `SaveCrewPlanningAssignment` after locking the source assignment.

### Risk (company-local calendar days until Planned Sign-Off)

| Condition | Risk |
|-----------|------|
| Ready to join or relief onboard | `none` |
| More than 14 days | `none` (unless otherwise invalid) |
| 14 days or fewer and not ready/onboard | `warning` |
| 7 days or fewer, due today, or overdue and not ready/onboard | `critical` |

### Workflow

1. Current Crew → **Plan Relief** opens Crew Planning with vessel/rank/source/prefill join (= source Planned Sign-Off).
2. Crew Planning creates/edits the row (`relieves_crew_assignment_id` set; same vessel/rank; one active relief per source).
3. `CreateCrewAssignmentFromPlanning` converts the same Planning row (preserves the relief link).
4. Real P0–P4 movement progresses on the linked assignment; readiness recalculates from phase.

Planning never starts movement, completes source P4, creates Sea Service, or creates payroll actuals.

### Deferred

- Phase 3: notifications (email, push, in-app, escalation)

## Phase 2B.1 — Projected Vessel Manning Engine

Read-only Support query: `CrewProjectedManningQuery`. Derives coverage from existing Crew Assignments, P4 phases, Crew Planning rows, relief links (`relieves_crew_assignment_id`), and company-scoped `VesselManning`. No projection table; no mutations.

### Data sources

| Input | Use |
|-------|-----|
| `VesselManning` | Required count per vessel + rank |
| P4 `actual_start_at` / `actual_end_at` | Actual onboard intervals and authoritative dates |
| Assignment `planned_join_at` / `planned_signoff_at` | Forecast when actuals absent |
| P4 `planned_end_at` | Forecast leave fallback |
| Planning `planned_join_date` / `planned_leave_date` | Planning-only joins; lowest precedence when linked |

### Join precedence

1. P4 `actual_start_at`
2. Assignment `planned_join_at`
3. Linked / planning-only `planned_join_date`

Vacant Planning (`employee_id` null) does not increase headcount. Soft-deleted Planning and cancelled assignments are excluded. Linked Planning + Assignment count once (assignment wins).

### Leave / sign-off precedence

1. P4 `actual_end_at`
2. Assignment `planned_signoff_at`
3. P4 `planned_end_at`
4. Planning `planned_leave_date`
5. Open-ended (remains onboard through the horizon)

Planned Sign-Off is forecast-only; it never completes P4, creates Sea Service, or payroll actuals.

### Same-day ordering

Company-local calendar days. Event **display** order on a shared date is join before sign-off. Min/max/gap/overlap are calculated from the **net end-of-day count** after all events for that date are applied, so a one-for-one handover does not create false overlap.

### Actual vs projected counts at range start

| Field | Meaning |
|-------|---------|
| `actual_onboard_at_start` | Only actual P4 intervals (`actual_start_at` ≤ `from`, and `actual_end_at` null or ≥ `from`). Forecast leave dates never reduce this count. |
| `projected_count_at_start` | Forecast coverage at `from` using actual end when present, otherwise planned sign-off / planned end / Planning leave |
| `starting_count` | Compatibility alias of `projected_count_at_start` |

Overdue Planning rows and pre-P4 Draft/planned assignments are **not** actual onboard. They may still contribute to `projected_count_at_start` when their resolved join ≤ `from` and leave is open or ≥ `from`. An open actual P4 with a planned sign-off already before `from` remains `actual_onboard_at_start = 1` and `projected_count_at_start = 0`. Planned Sign-Off is never Actual Disembarkation.

### Repeatable P4 phases

Each On Vessel phase with `actual_start_at` becomes its own projection segment (ordered by `sequence`). Assignment/Planning forecast dates are used only when they do not duplicate an actual P4 segment (typically Draft/Active with no actual P4 yet).

### Completed / P5 / P6

Completed assignments contribute historical actual P4 intervals only — never stale planned joins or Planning-driven future joins. Active P5/P6 may contribute historical P4 intervals but never projected fallback joins. Cancelled assignments remain excluded.

### Tenant-safe relations

Trusted `companyId` is enforced independently on Vessel Manning, assignments, phases, linked Planning, and employees. Malformed cross-company relations are omitted and their IDs are not exposed on events.

### Relief

Uses Phase 2A Planning relief links. Late relief → gap between source leave and relief join; early relief → overlap/excess. Historical P5/P6 / cancelled / completed linked relief follow assignment P4 history and status exclusions, not readiness labels alone.

### Summary overlap

`summary.overlap_positions` counts rows where `has_overlap` is true (not only when primary status is `overlap`).

## Phase 2B.2 — Projected Manning Engine & Surface Integration

There is **no standalone Projected Manning page**. Projected manning is an internal calculation engine (`CrewProjectedManningQuery`) that drives operational insights across Crew Operations:

- **Vessel Manning** (`/organization/vessel-manning`) configures required vessel/rank headcount.
- **Projected Manning Engine** (`CrewProjectedManningQuery`) calculates required vs actual/projected crew, gaps, and overlaps.
- **Crew Planning** (`/organization/crew-planning`) visually displays projected gap and overlap overlays on the Gantt timeline.
- **Crew Operations Overview** (`/organization/crew-operations`) surfaces projected risk analytics and action items, linking directly into Crew Planning.
- **Operational Alerts** (`ProjectedManningGap`) detect projected shortfall conditions and resolve URLs to Crew Planning (with Overview/Vessel Manning fallbacks).

### Deferred (still later)

- Notifications / escalations
- Phase 3 notifications

## Phase 2B.3A — Daily Operations Dashboard + projected manning risk integration

Crew Operations landing page (`/organization/crew-operations`) is an **operational cockpit**, not an analytics board.

### Sections

1. **Header / quick actions** — Current Crew, Planning (permission-gated; Settings is not a primary daily action).
2. **Daily Pulse** (max 4) — Onboard Now (actual P4), Joins Next 7 Days, Sign-offs Next 7 Days (+ overdue secondary), Coverage Risks (`current now · upcoming projected`).
3. **Action Required** — bounded (≤10) urgency-ordered items: current manning gap → overdue sign-off → due today no relief → critical relief → imminent not-ready relief → projected future gap → overdue corrections → needs update → over-home.
4. **Next 7 Days** — compact join/sign-off day list + Open Crew Planning.
5. **Manning & Relief Risks** — bounded mixed list with explicit Actual / Projected / Relief kinds + Open Crew Planning.

### Projected manning

- Still calculated only via `CrewProjectedManningQuery` (company-local today → +30 days).
- `projected_manning` is `null` without `crew_operations.vessel_manning.view`.
- Used for Coverage Risks upcoming, Action Required future-gap rows, and projected risk rows — not a large KPI card on the landing page.
- Projected manning calculations surface through Crew Planning overlays, Overview risks, and operational alert delivery; there is no standalone page.

### Removed from landing presentation

Deployment trends chart, phase-status grid, crew pool, recent activity, standalone movement-corrections card, Tour/Relief KPI grids, standalone Manning Gaps card, large Projected Manning card. Underlying routes/queries/features remain elsewhere.

### Deferred (still later)

- Notifications / escalations

## Phase 2B.3B — Crew Planning Gantt Projection Overlays

Crew Planning (`/organization/crew-planning`) layers read-only projected coverage onto the existing Gantt.

### Rules

- Projection comes only from `CrewProjectedManningQuery` using Planning’s exact `from` / `to` / `vessel_id` / `rank_id` (no separate 30/60/90 horizon).
- Compact payload via `CrewPlanningProjectionPresenter` (`projection` prop). Events / employee IDs / assignment IDs are omitted.
- Extra permission: `can.projection` = `crew_operations.vessel_manning.view`. Without it Planning works normally and `projection` is `null`.
- Gantt row catalog merges Planning rows with company-scoped Vessel Manning positions from projection so empty ranks with gaps still appear. Authoritative `required_count` comes from Vessel Manning when projection is present. No Planning records are created merely to show rows.
- Frontend draws only exception periods (`gap > 0` red, `excess > 0` amber) behind Planning bars. Covered periods stay quiet. Optional local “Show coverage” toggle (default on when `can.projection`).
- Gap band click (when `can.create`) opens the existing Assign Crew Sheet via `openCreateForRow` with vessel/rank and gap `from` as join date. DnD / row-click / edit / delete / zoom remain primary.
- No movement mutations from projection.

### Deferred (still later)

- Phase 3 notifications / escalations

## Phase 2C.1 — Transfer / Redeployment Tour Hardening

Hardens existing `TransferVessel` / `Redeploy` without redesigning Current Crew or Planning.

| Rule | Behaviour |
|------|-----------|
| Linked assignment | Destination is a new `CrewAssignment` with `previous_assignment_id`; source vessel/rank/P4 history is preserved |
| Direct P4 transfer / redeploy | Fresh Tour snapshot via `CrewTourOfDutyResolver` + `CrewJoinVesselSignoffApplier` (destination rank + `occurred_at`) |
| Pre-P4 redeploy | No Tour snapshot; Join Vessel applies Tour later |
| Sea Service | Source P4 completion syncs once; destination Sea Service waits until destination P4 completes |
| Planned vs actual | Only actual `occurred_at` handoff completes source P4 |
| Rollback | Tour/sign-off validation runs before source mutation; failures leave source active |

### Deferred (still later)

- Phase 3B+ delivery / escalations (in-app, Web Push); Phase 3C email

## Phase 2C.2 — Transfer / Redeploy Tour UI Alignment

Movement dialogs reuse shared Tour / Planned Sign-Off controls (`TourSignoffFields`) for Join Vessel, Transfer Vessel, and Redeploy starting at P4.

| Surface | Behaviour |
|---------|-----------|
| Transfer Vessel | Destination-rank Tour default (`tour_of_duty` when resolved); no `existing_plan` |
| Redeploy P4 | Same Tour / sign-off controls as Transfer |
| Redeploy P0–P3 | Tour fields hidden and excluded from submit; optional forecast sign-off only |
| Payload | Empty `tour_of_duty_days` omitted; non-manual choices drop stale date/reason |

Backend Tour resolution remains authoritative.

### Deferred (still later)

- Phase 3B+ delivery (in-app bell, Web Push, email)

## Phase 3A — Crew Operational Alerts Foundation + Company Notification Settings

Crew Operations → Settings gains a **Notifications** card. Defaults **OFF** so existing companies are not notified after migration.

| Concern | Behaviour |
|---------|-----------|
| Master switch | Company-level Crew Notifications ON/OFF (default OFF) |
| Recipients | Explicit selected active company users with active membership only — no role-based recipient config |
| Alert types | Independent toggles for overdue, no-relief, relief-not-ready, current gap, projected gap |
| Delivery | No separate company In-app / Browser Push / Email toggles |
| Persistence | `crew_operational_alerts` with unique `(company_id, dedupe_key)` and active/resolved lifecycle |
| Detection | `DetectCrewOperationalAlerts` uses Tour / Relief / Manning / Projected queries — not Action Required |
| Reconciliation | `ReconcileCrewOperationalAlerts` + `crew:reconcile-operational-alerts` every 10 minutes (`withoutOverlapping`) |
| Tenancy | Trusted `current_company_id`; per-company safe iteration |

One row per company condition. History is preserved via `detected_at` (first detection), `last_detected_at`, `resolved_at`, `status`, and activity log. When a resolved condition returns, the same row is reactivated.

## Phase 3B — Unified Notification Bell + Crew Browser Push + Escalation

Crew operational alerts appear in the existing notification bell alongside Announcements.

| Concern | Behaviour |
|---------|-----------|
| Unified feed | Server-backed `BuildUnifiedNotificationFeed` merges AnnouncementRecipient + CrewOperationalAlertRecipient |
| Recipient/read state | `crew_operational_alert_recipients` with unique `(alert_id, user_id)` and per-user `read_at` |
| Unread badge | Combined announcement + active Crew unread count |
| Browser Push | Extension of in-app Crew notifications; uses the user's existing device subscription preference. **No company Browser Push toggle** |
| Push privacy | Generic lock-screen copy only: “Crew Operations requires attention. Open OMS-HRM to review.” |
| Push dedupe | `crew_operational_alert_push_deliveries` unique on `(alert_id, user_id, notification_version)` |
| Version bumps | New alert, reactivation, and meaningful severity escalation increment `notification_version` and may push again |
| Escalation | Sign-off/relief windows tighten severity (8–14 warning, ≤7 critical); current manning critical; projected gap warning |
| Links | Permission-safe URLs to Current Crew / assignment, Vessel Manning, or Projected Manning |
| Jobs | `DeliverCrewOperationalAlertWebPushJob` afterCommit; re-checks company, membership, selection, alert activity, subscriptions |

### Deferred (still later)

- Further escalation / digest workflows beyond Phase 3C

## Phase 3C — Operational Alert Email Delivery + Retry Hardening

Email is an automatic extension of Crew operational notifications. There is **no company Email ON/OFF toggle** and no separate Crew email settings.

| Concern | Behaviour |
|---------|-----------|
| When emailed | Same meaningful `notification_version` events as Web Push: new alert, reactivation, severity escalation |
| Not emailed | Unchanged reconciliation, `last_detected_at` refresh, resolved alerts |
| Recipients | Selected active company members with a usable email address |
| SMTP | Existing application `MailSettingsService` (settings over env). No second SMTP system |
| Ledger | `crew_operational_alert_email_deliveries` unique on `(alert_id, user_id, notification_version)` |
| Jobs | `DeliverCrewOperationalAlertEmailJob` afterCommit; re-checks company, membership, selection, alert activity/version, email, SMTP |
| Retry | Bounded tries/backoff; transport failures keep queued until exhausted → `email_transport_exhausted` |
| Privacy | Subject: “Crew Operations requires attention.” No employee/vessel/rank/assignment detail |
| Links | Permission-safe CTA via `ResolveCrewOperationalAlertUrl`; omit CTA when unauthorized |
| Independence | Email and Web Push ledgers/queues are independent |

See [Crew operational alerts email delivery](../crew-operational-alerts-email.md).

## Sea service

Requires P4 with `actual_start_at`, `actual_end_at`, plus assignment vessel/rank/employee. Linked by unique `employee_sea_services.crew_assignment_phase_id`.

## Status / dashboard / manning / attention

- `CrewAssignmentStatusResolver` maps current phase → operational status.
- Dashboard counts use latest relevant assignment per employee, plus Tour of Duty sign-off buckets (within 30/14/7 days, due today, overdue, missing tour/sign-off).
- Onboard manning = active assignment + active P4 on vessel. Planned sign-off does not remove onboard crew.
- Attention rules live in `CrewMovementAttentionQuery` (stale draft/phase, overdue planned join/sign-off, missing vessel/rank, tour due/overdue/missing).

## Assignment numbers

`crew_assignment_sequences` per company/year, locked increment → `CA-{YEAR}-{000001}`.

## Timezone convention

- Interpret user-entered timestamps in the **company timezone**.
- Persist using the application/database datetime convention.
- Display dates consistently as date strings from presenters (`toDateString()` for calendar fields).
- Planned Sign-Off is never treated as Actual Disembarkation.

## Master data

Global (no `company_id`): ranks, vessels, clients, company visa types. Filter by `is_active` when present.

Tenant-scoped: employees (`company_id`, `employee_no`), crew assignments, phases, sequences, crew rank policies.

Rank CSV import supports `name,is_active,max_tour_of_duty_days` (blank Tour cell preserves existing value).

## Production verification commands

```bash
php artisan migrate
php artisan db:seed --class=PermissionsSeeder
composer ci:check
npm run lint:check
npm run format:check
npm run types:check
npm run build
php artisan test --compact --filter=Crew
```

See also `docs/runbooks/crew-movement-qa.md`.
