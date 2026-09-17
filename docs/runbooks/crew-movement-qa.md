# Crew Movement QA Checklist

Operational checklist after deploying Crew Movement changes.

## 1. Migration and permissions

- [ ] `php artisan migrate`
- [ ] `php artisan db:seed --class=PermissionsSeeder` (idempotent)
- [ ] Confirm no `employee_deployments` table
- [ ] Confirm roles that previously had deployments permissions now have assignment permissions
- [ ] Confirm `crew_operations.deployments.*` permissions no longer exist

## 2. Start Assignment (Arrival-first)

- [ ] Open Crew Assignments → Start Assignment
- [ ] Create form loads employees, ranks, vessels, clients, and operational status
- [ ] Start Assignment creates Active P0 Pre-Mobilisation at company-local server submit time (no start datetime field; crafted `stage_started_at` is ignored)
- [ ] No initial-stage selector is shown on Create or Edit
- [ ] Normal web Create never offers P1 Travel In as an initial stage
- [ ] Assignment is Active with Active P0, not Draft + Planned P0
- [ ] Assignment number format `CA-{YEAR}-{######}`
- [ ] Save as Draft still creates Draft + Planned P0
- [ ] Expected Vessel Join stores in `planned_join_at`
- [ ] Arrival Date (`planned_arrival_at`) is optional forecast only on Create and Edit
- [ ] Create and Edit use the same **Arrival Date** label and helper copy (not “Planned Arrival Date” on Edit)
- [ ] Edit Assignment matches Single Create layout: Crew Members (Employee locked, Rank, Arrival Date, Current Assignment Stage read-only) + Assignment Details (Client, Vessel, Expected Vessel Join, Remarks)
- [ ] Planned Sign-Off, Planned Travel Home, and Assignment Start Date & Time are not on Edit; those values stay owned by movement/planning workflows
- [ ] Expected Vessel Join cannot be saved after an existing Planned Sign-Off; the sign-off plan is not silently changed
- [ ] Arrival Date cannot be saved after Expected Vessel Join

## 2A. Unified Start / Bulk Create

- [ ] Current Crew shows only **Start Assignment** (no separate Bulk Add Crew header action)
- [ ] Start Assignment opens `/organization/crew/create` with **one** crew row
- [ ] **Add Another Crew Member** is visible only with Start capability (`can.start`); create-only users stay on one row with Save as Draft only
- [ ] **Add Another Crew Member** adds a second row; Save as Draft disappears; Start becomes **Start N Assignments**
- [ ] Create-only user opening `/organization/crew/create?mode=bulk` still receives one crew row
- [ ] A visible bulk row without an employee is marked incomplete; Start stays disabled until the row is completed or removed
- [ ] Incomplete bulk rows are submitted to the server (not silently filtered) and rejected by backend validation with zero assignments created
- [ ] Removing rows back to one restores Single mode (Start Assignment + Save as Draft)
- [ ] Legacy `/organization/crew/bulk-create` redirects to the unified Create page for bookmarks
- [ ] One-row Start posts to the normal Store endpoint; one-row Draft posts to Store with `submission_intent=draft`
- [ ] Two-or-more-row Start posts to the existing bulk Store endpoint (atomic)
- [ ] Common Client / Vessel auto-resolve identically for single and bulk; Expected Join, remarks, and per-row Arrival Date are shared semantics
- [ ] Per-row employee + rank; rank defaults from the employee and can be changed
- [ ] Duplicate employees are blocked in the UI and rejected by the server
- [ ] Single mode: On Vessel on a different vessel opens Transfer Vessel recommendation (not bulk auto-transfer)
- [ ] Bulk mode: On Vessel rows stay blocked with transfer guidance; no transfer dialogs
- [ ] An active-assignment employee blocks bulk submission; removing that row lets the rest of a valid batch start
- [ ] Start N Assignments is all-or-nothing: a blocked row creates zero assignments from that submission
- [ ] Successful batch redirects to Current Crew with `{N} crew assignments started successfully.`
- [ ] Every created assignment is a normal Active CrewAssignment with exactly one initial P0 phase, the same server-generated start timestamp, no invented P1/P2A/P3/P4, no Sea Service, and no payroll payable days from P0
- [ ] Spreadsheet import, per-row vessel/stage, backdated start time, bulk Save Draft, and Skip Blocked Rows are not present

## 3. Standard lifecycle (Arrival-first normal path)

- [ ] Start Assignment from Draft P0 → Active P0 (`approve_mobilisation`; user-facing label **Start Assignment**)
- [ ] Active P0 cannot Start Assignment again; progression is **Record Arrival**
- [ ] Record Arrival from Active P0 → P2A Join Standby at one exact timestamp (`P0.actual_end_at = P2A.actual_start_at`)
- [ ] Record Arrival default path records hotel accommodation (Hotel, optional Room Type, Check-in Date defaulting to Actual Arrival local date)
- [ ] Record Arrival **No hotel accommodation** creates explicit `pre_join` / `no_accommodation` stay with no hotel/date fields
- [ ] Record Arrival rejects foreign/inactive Hotel or Room Type and invalid check-in before Actual Arrival
- [ ] Active P0 Record Arrival does not offer P3 selector (P2A only)
- [ ] Join Vessel from P2A → P4 (optional planned sign-off only)
- [ ] Join Vessel with open pre-join hotel stay requires Hotel Check-out Date and closes the stay atomically
- [ ] Join Vessel with explicit `no_accommodation` proceeds without checkout fields
- [ ] Join Vessel with missing legacy accommodation shows warning but remains allowed
- [ ] P2A → P2B → P2A training loop keeps the same open pre-join hotel stay until Join Vessel
- [ ] Plan Sign-Off updates plan without leaving P4
- [ ] Confirm Disembarkation → P5 default path records post-sign-off hotel accommodation (Hotel, optional Room Type, Check-in Date defaulting to Actual Disembarkation local date)
- [ ] Confirm Disembarkation **No hotel accommodation** creates explicit `post_signoff` / `no_accommodation` stay with no hotel/date fields
- [ ] Confirm Disembarkation rejects foreign/inactive Hotel or Room Type and invalid check-in before Actual Disembarkation
- [ ] Direct Confirm Disembarkation → P6 hides accommodation fields and creates no post-sign-off stay
- [ ] Legacy Confirm Disembarkation → P5 without accommodation payload still succeeds with derived `missing` context
- [ ] Return Home with open post-sign-off hotel stay requires Hotel Check-out Date and closes the stay atomically
- [ ] Return Home with explicit `post_signoff` / `no_accommodation` proceeds without checkout fields
- [ ] Return Home with missing legacy post-sign-off accommodation shows warning but remains allowed
- [ ] Return Home (default **Return Home & Close Assignment**) → P6 recorded + assignment **Completed** with `closed_at` = actual return-home timestamp
- [ ] Return Home with **Keep open for Redeployment** → active P6; assignment stays **Active**; hotel stay still closed
- [ ] Close Assignment from active P6 still works for intentionally open P6 / legacy records
- [ ] Only one active phase at a time
- [ ] Activity/audit entries present

### Legacy P1 support (historical records only)

- [ ] Existing Active P1 assignments still show Travel In wording
- [ ] Record Arrival from Active P1 → P2A only
- [ ] Existing Active P3 assignments can still Join Vessel → P4
- [ ] Normal web Create / Planning Start / Bulk Start do not manufacture new P1 or P3 assignments
- [ ] Redeploy normal choices exclude P1 and P3

## 4. Training loop

- [ ] P2A → Send to Training → P2B
- [ ] Complete Training → P2A
- [ ] Loop again if needed
- [ ] Join Vessel from P2A → P4

## 5. Sea service

- [ ] Active P4 does not create completed sea service
- [ ] Completed P4 creates/updates `EmployeeSeaService`
- [ ] Link uses `crew_assignment_phase_id`
- [ ] Re-running sync remains idempotent

## 6. Planning

- [ ] Planning row **Start Assignment** opens the unified Start form without creating a CrewAssignment
- [ ] Planning master data (Employee, Rank, Client, Vessel, Expected Join) is read-only on the handoff form
- [ ] Arrival Date is editable on the handoff form and is operational assignment data (not Crew Planning authoritative)
- [ ] Confirm Start creates one Active CrewAssignment with one P0 phase, `source = crew_planning`, and links the original planning row
- [ ] Expected Join and Planned Sign-Off remain forecasts; actual start uses trusted server submit time
- [ ] Arrival Date provenance displays **Entered on assignment**, not **From Crew Planning**
- [ ] No initial-stage selector; crafted `current_stage` is ignored
- [ ] Back/Cancel returns to Crew Planning with practical filter context preserved
- [ ] User without `crew_operations.planning.view` cannot open or submit the Planning → Start handoff
- [ ] Crafted POST cannot substitute employee/rank/vessel/client/planned join — server uses locked Planning values
- [ ] Arrival Date after Expected Vessel Join is rejected using calendar-date comparison (no timezone shift on date-only Planning values)
- [ ] Expected Join after Planned Sign-Off is rejected at Start
- [ ] Relief planning preserves `relieves_crew_assignment_id` and rejects incompatible relief state
- [ ] Linked Active assignment opens existing record without duplicate
- [ ] Planning employee already Active P4 on another vessel opens Start form with conflict warning, no Start Assignment button, no Transfer Vessel dialog, and Open Current Assignment when view permission is granted
- [ ] Crafted Planning Start POST for an employee with another Active assignment is rejected; planning `crew_assignment_id` stays null and no duplicate planning row is created
- [ ] Relief planning Start still works when only the source crew member is Active P4
- [ ] Linked Draft assignment remains backward compatible (redirect to existing draft workflow)
- [ ] Repeat Start on linked Active is idempotent and keeps exactly one planning row
- [ ] Manual Crew Assignments draft with vessel/rank/join/sign-off creates a planning bar
- [ ] Join vessel without sign-off shows an open-ended Assigned bar on the Gantt
- [ ] Plan sign-off updates the same planning bar leave date
- [ ] Confirm disembarkation sets leave to actual end and keeps one planning row
- [ ] Linked planning bars cannot be edited/deleted from Planning (open Crew Assignments instead)
- [ ] Unlinked planned-relief bars remain editable
- [ ] Cancel before P4 removes the future planning bar
- [ ] Gantt shows `is_assigned` / Assigned styling
- [ ] No EmployeeDeployment created

## 7. Dashboard / manning

- [ ] Overview counts exclude completed history as current
- [ ] On-vessel manning uses active P4 only
- [ ] Planned sign-off does not remove onboard count

## 8. Company isolation

- [ ] Company A cannot open Company B assignment URL
- [ ] Company A cannot perform actions on Company B assignment
- [ ] Company A cannot assign Company B employee

## 9. Mobile UI

- [ ] Crew Assignments list usable on narrow viewport
- [ ] Movement action menu and dialogs usable on mobile
- [ ] No horizontal overflow on assignment detail

## 10. Transfer, redeploy, and corrections

- [ ] Active P4 exposes Transfer Vessel; destination vessel starts empty, differs from source, and is required; source stays on original vessel
- [ ] Transfer creates linked Active P4 assignment with no invented standby/home phases
- [ ] Separate Planning bars exist for source and destination; completed source P4 creates sea service
- [ ] Crew Assignments shows only the new Active assignment; Movement History shows both
- [ ] Redeploy from P5/P6 starts only at the chosen phase (P0/P2A/P4); P1 and P3 are not offered; same vessel is allowed; P0 clears planned sign-off and hidden destination fields
- [ ] P5 Redeploy with open post-sign-off hotel requires Source Hotel Check-out Date and closes the stay atomically with source completion
- [ ] P5 Redeploy → P2A shows Destination Pre-Join Accommodation (Hotel / Room Type / Check-in / No hotel)
- [ ] P5 Redeploy → P0 or P4 handles source accommodation but creates no destination pre-join stay
- [ ] Cancel Assignment with one open pre-join or post-sign-off hotel stay requires Hotel Check-out Date and closes the stay atomically
- [ ] Cancel Assignment with multiple open hotel stays shows an accommodation integrity warning, surfaces backend `check_out_date` errors when the checkout field is hidden, and is rejected with no partial mutation
- [ ] Redeploy with `starting_phase = P0`, redeploy date changed before switching to P2A, then P0 → P2A auto-syncs Destination Check-in to the current redeploy local date unless the operator manually edited it
- [ ] Redeploy P2A with **No hotel accommodation** keeps Check-in empty; unchecking restores the current redeploy local date
- [ ] Void Erroneous Assignment is blocked when accommodation history exists (hotel stay or explicit no-accommodation record)
- [ ] Movement controller redirects to the new linked assignment after transfer/redeploy
- [ ] Daily Crew payroll board shows one employee row for multiple movement periods; Movement Periods dialog edits Manual segments; Applied Crew Operations segments are read-only
- [ ] Daily Crew Excel allows repeated employee rows as separate periods; employee-level overtime/salary amounts are entered once; overlaps fail preview with Excel row numbers
- [ ] Salary export has one consolidated row plus Movement Details (including rank); Clear Timesheets removes Manual/Import segments only
- [ ] Movement corrections go through request → approve/reject (not immediate `correct_movement`)
- [ ] Pending/rejected/cancelled corrections leave official phase dates unchanged
- [ ] Approved corrections update assignment/phase fields and re-sync planning + completed P4 sea service

## 11. Crew Movement History report

- [ ] Report is visible under Reports only with `reports.crew_movement_history.view`
- [ ] Draft P0, active P1, P3, active P4, completed P4/P5/P6, and cancelled assignments appear once each
- [ ] The same employee can have multiple assignment rows
- [ ] Repeated P2A and P2B periods remain visible in sequence order
- [ ] Training provider and course values match each P2B occurrence
- [ ] Active phases display Ongoing and calculate through company-local today
- [ ] Planned Sign-Off remains separate from Actual Disembarkation
- [ ] Arrival Date provenance shows **Entered on assignment** for manual and Planning-started assignments
- [ ] Filters, sorting, 25/50/100 page sizes, and Clear Filters work
- [ ] View Assignment and View Employee open the existing read-only destinations
- [ ] Excel and CSV contain the same filtered assignment set as the table
- [ ] Company A cannot view or export Company B assignment history
- [ ] No `EmployeeDeployment` or duplicate movement/report table is created

## 12. Current Crew operational cards

- [ ] **Current Assignments** opens the normal operational queue (`view=crew`) without stale location-view state
- [ ] **Needs Attention** shows current assignments requiring review (`movement_attention=1`) on the normal crew list
- [ ] **Pre-Join Hotel** shows Active current P2A/P2B and legacy P3 (`view=pre_join_hotel`)
- [ ] **Crew On-Site** shows only Active current P4 grouped by vessel (`view=vessel`)
- [ ] **Post-Sign-Off Hotel** shows only Active current P5 (`view=post_signoff_hotel`)
- [ ] **On Home** shows active employees home between mobilisation cycles (`view=on_home`), including active P6 and completed assignments with no newer Draft/Active assignment
- [ ] **On Home** card secondary text uses the company `max_home_days` Availability Rule (for example `5 over 30-day limit`)
- [ ] **On Home** list shows Home Since, Days at Home, availability status against `max_home_days`, and links to the latest assignment when present
- [ ] Employees with a newer Active or Draft assignment do not appear in **On Home**
- [ ] Inactive/terminated employees never appear in **On Home**
- [ ] **P0 Pre-Mobilisation** remains accessible through Filters → Current Phase (not a dashboard card)
- [ ] **P6** appears only in **On Home**, not in the hotel/on-site location cards
- [ ] Completed/historical assignments never appear in Hotel or On-Site location views
- [ ] After setting **Status = Completed**, clicking **Crew On-Site** clears the conflicting status and shows the current Active P4 board (not an empty misleading board)
- [ ] After setting **Phase = P0** and **Include Completed = Yes**, clicking **Pre-Join Hotel** clears those conflicting filters and shows Active P2A/P2B and legacy P3
- [ ] Operational location views do not expose misleading **Current Phase**, **Status**, or **Include Completed** controls in the Filters sheet (controls are disabled with operational-view context copy)
- [ ] Compatible filters (search, vessel, rank, client, employee, planned dates, tour, relief) still work inside an operational card view
- [ ] Pagination and search preserve the selected operational card/view
- [ ] Selecting **Pre-Join Hotel** and then enabling **Needs Attention** keeps **Pre-Join Hotel** visually selected while filtering that hotel population for attention

## 13. Payroll safeguards (Arrival-first regression)

- [ ] P0 and P1 remain payroll-excluded
- [ ] P2A begins Sign-On Standby payroll behavior after Record Arrival
- [ ] Arrival Date (`planned_arrival_at`) remains forecast-only and does not create payroll days
- [ ] Existing Arrival-first payroll regression tests remain green

## Movement Correction Production Readiness QA

### Reusable pre-deployment checklist

- [ ] Pending request leaves assignment, phase, Planning, Sea Service, and Movement History unchanged
- [ ] Approval by another authorized user applies the correction atomically
- [ ] Approved completed P4 correction re-syncs Planning and Sea Service
- [ ] Rejection and requester cancellation preserve official movement data
- [ ] Self-approval is blocked without override and succeeds with override
- [ ] Stale originals block approval without partial writes
- [ ] Pending ages classify 0–1 On Time, 2–3 Needs Attention, and 4+ Overdue
- [ ] Overdue filter and priority sorting are company-scoped
- [ ] Correction detail shows Pending Age only while pending
- [ ] Crew Operations shows only pending count, overdue count, and review link
- [ ] Crew Operations hides correction summary without view permission
- [ ] Company A cannot view, decide, filter, or count Company B corrections
- [ ] Narrow viewport and dark mode remain readable without horizontal page overflow
- [ ] Focused correction tests, related regressions, full backend suite, and frontend checks pass
- [ ] UI shows Request Status, On Time, Needs Attention, Overdue, and Pending Age with no SLA wording

### Production-readiness execution record

| Field | Value |
|-------|-------|
| Test date | 2026-07-17 |
| Environment | Local Laravel Herd application and test database |
| Tester | Cursor Agent |

| Scenario | Expected result | Actual result | Result | Notes / reference |
|----------|-----------------|---------------|--------|-------------------|
| A — Pending correction | Official P4, Planning, and Sea Service remain unchanged; request appears pending | Local integration workflow left official phase data unchanged and returned one pending request | Pass | `CrewMovementCorrectionRequestTest` |
| B — Approval and re-sync | P4, Planning, Sea Service, report metadata, and audit update atomically | Local integration workflow applied approved values and re-synced Planning and completed P4 Sea Service | Pass | `CrewMovementCorrectionApprovalTest`, `CrewMovementCorrectionSyncTest` |
| C — Rejection | Official movement data remains unchanged and reason is visible | Rejection persisted decision status and notes without changing the phase | Pass | `CrewMovementCorrectionLifecycleTest` |
| D — Cancellation | Official movement data remains unchanged and status is Cancelled | Requester cancellation persisted Cancelled without changing the phase | Pass | `CrewMovementCorrectionLifecycleTest` |
| E — Self-approval | Blocked without override; allowed with override | Permission integration blocked self-approval and separately verified override access | Pass | `CrewMovementCorrectionPermissionsTest` |
| F — Stale request | Conflict blocks approval with no partial changes | Stale originals produced a conflict and transaction assertions confirmed no partial writes | Pass | `CrewMovementCorrectionConflictTest` |
| G — Pending Age | 0–1 On Time, 2–3 Needs Attention, 4+ Overdue in company timezone | Unit and feature age tests plus browser verification of Request Status labels | Pass | `CrewMovementCorrectionAgeTest` |
| H — Dashboard simplification | Only pending, overdue, and review link are shown | Dashboard shows one compact card; no correction rows or actor data | Pass | `CrewOperationsCorrectionSummaryTest` |
| I — Tenant isolation | Cross-company records are absent from pages, counts, and direct URLs | Company-scoped list, direct URL, decision, and dashboard count assertions excluded foreign-company corrections | Pass | `CrewMovementCorrectionCompanyScopeTest`, `CrewOperationsCorrectionSummaryTest` |
| J — Terminology cleanup | UI shows Request Status / On Time / Needs Attention / Overdue / Pending Age | No user-facing SLA, Normal, or Attention age labels remain | Pass | Manual terminology QA |

Do not record employee names, credentials, or other sensitive production data in this execution record. Store screenshots only in approved QA evidence storage and reference them here without embedding sensitive content.

The correction list, detail Pending Age panel, Request Status filters, and compact dashboard card passed desktop dark-mode and narrow-viewport checks. The pre-existing operational phase summary strip remains dense at a 390 px viewport; this does not duplicate correction data or affect correction actions, but should be handled as a separate Crew Operations responsive-layout improvement.
