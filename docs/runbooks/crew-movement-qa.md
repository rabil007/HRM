# Crew Movement QA Checklist

Operational checklist after deploying Crew Movement changes.

## 1. Migration and permissions

- [ ] `php artisan migrate`
- [ ] `php artisan db:seed --class=PermissionsSeeder` (idempotent)
- [ ] Confirm no `employee_deployments` table
- [ ] Confirm roles that previously had deployments permissions now have assignment permissions
- [ ] Confirm `crew_operations.deployments.*` permissions no longer exist

## 2. Create draft

- [ ] Open Current Crew → New Assignment
- [ ] Create form loads employees, ranks, vessels, clients, visa types
- [ ] Create draft for an active company employee
- [ ] Assignment number format `CA-{YEAR}-{######}`
- [ ] Current phase is P0 Pre-Mobilisation

## 3. Standard lifecycle

- [ ] Approve Mobilisation → P1
- [ ] Record Arrival → P2A
- [ ] Mark Ready → P3
- [ ] Join Vessel → P4 (optional planned sign-off only)
- [ ] Plan Sign-Off updates plan without leaving P4
- [ ] Confirm Disembarkation → P5
- [ ] Travel Home → P6
- [ ] Close Assignment → Completed
- [ ] Only one active phase at a time
- [ ] Activity/audit entries present

## 4. Training loop

- [ ] P2A → Send to Training → P2B
- [ ] Complete Training → P2A
- [ ] Loop again if needed
- [ ] Exit to P3 then join vessel

## 5. Sea service

- [ ] Active P4 does not create completed sea service
- [ ] Completed P4 creates/updates `EmployeeSeaService`
- [ ] Link uses `crew_assignment_phase_id`
- [ ] Re-running sync remains idempotent

## 6. Planning

- [ ] Confirming a planning assignment creates one draft CrewAssignment
- [ ] Repeat confirm is idempotent and keeps exactly one planning row
- [ ] Manual Current Crew draft with vessel/rank/join/sign-off creates a planning bar
- [ ] Join vessel without sign-off shows an open-ended Assigned bar on the Gantt
- [ ] Plan sign-off updates the same planning bar leave date
- [ ] Confirm disembarkation sets leave to actual end and keeps one planning row
- [ ] Linked planning bars cannot be edited/deleted from Planning (open Current Crew instead)
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

- [ ] Current Crew list usable on narrow viewport
- [ ] Movement action menu and dialogs usable on mobile
- [ ] No horizontal overflow on assignment detail

## 10. Unsupported actions

- [ ] `transfer_vessel` and `redeploy` return clear validation/safe errors
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
- [ ] Filters, sorting, 25/50/100 page sizes, and Clear Filters work
- [ ] View Assignment and View Employee open the existing read-only destinations
- [ ] Excel and CSV contain the same filtered assignment set as the table
- [ ] Company A cannot view or export Company B assignment history
- [ ] No `EmployeeDeployment` or duplicate movement/report table is created

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
