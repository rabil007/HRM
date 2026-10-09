# Crew Scheduled Movements (Phase 2)

Automatic crew movement scheduling separates **recording actual movements** from **scheduling future movements**.

## Modes

| Mode | Endpoint | Effect |
|------|----------|--------|
| Record Now | `POST .../crew/{assignment}/actions` | Immediate operational transition via `CrewMovementService` |
| Schedule for Later | `POST .../crew/{assignment}/scheduled-movements` | Persists `CrewScheduledMovement` only — no phase, hotel, Sea Service, or payroll side effects |

There is **no manual confirmation mode** for due schedules. Execution is automatic only.

## Timestamp policy (UTC canonical)

| Field | Storage | Meaning |
|-------|---------|---------|
| `scheduled_at` | UTC `DATETIME` | Intended future instant (operator enters company-local time) |
| `scheduled_timezone` | IANA string | Company TZ snapshot used for input/display |
| `processing_started_at` | UTC `DATETIME` | Claim time |
| `executed_at` | UTC `DATETIME` | When the processor successfully ran |
| `effective_occurred_at` | UTC `DATETIME` | Operational occurrence applied (company-local wall passed into `CrewMovementService`) |
| `cancelled_at` | UTC `DATETIME` | When cancelled |

Columns use MySQL/SQLite **DATETIME** (not TIMESTAMP) so session timezones cannot rewrite stored UTC walls. Eloquent uses `UtcDateTimeCast`. Due selection, lateness, stale recovery, and index filters all compare UTC instants. Presenters convert UTC → company-local for operators. Daylight-saving nonexistent and ambiguous local walls are rejected at schedule create/update. Legacy company-local rows (if any from early PR builds) must be cancelled/recreated or converted using `scheduled_timezone` — never silently reinterpreted as UTC (see runbook).

## Data model

Table: `crew_scheduled_movements`

- One unresolved schedule per assignment (`scheduled` / `processing` / `needs_attention`) via generated unique `unresolved_assignment_id`
- Statuses: `scheduled`, `processing`, `executed`, `needs_attention`, `cancelled`
- Snapshots: expected current phase id/code/sequence, vessel id, result phase
- `action_payload` stores validated, allowlisted movement fields only (unknown nested keys rejected on update; training `planned_start_at` / `planned_end_at` / `sync_training_to_employee_training` and redeploy `planned_arrival_at` are permitted where the action uses them)

## Schedulable actions

Physical / operational transitions from `CrewSchedulableMovementActions`:

- Start Assignment, Record Arrival, Send/Complete Training, Join Vessel, Confirm Disembarkation, Travel Home, Transfer Vessel, Redeploy, Close Assignment

Not scheduled: Plan Sign-off, Cancel Assignment, Correct Movement, Void.

## Editing / rescheduling

- Manage permission required
- Update reuses the same Form Request action-field rules as create
- Edit / Reschedule UI submits only action-relevant allowlisted `action_fields` (not the full generic movement form)
- Existing payload fields are preserved when omitted; auto-synced hotel checkout dates follow the new schedule; manual overrides stay
- Persistence uses normalized validated fields, not raw nested input
- Assignment eligibility and one-unresolved uniqueness are rechecked on update
- Edit / reschedule never changes actual crew phases or payroll/sea-service data before execution

## Automatic execution

- Cron: `crew:process-scheduled-movements` every minute (`routes/console.php`)
- Support: `crew:recover-scheduled-movements --list` / `--process-due`
- Cron-compatible inline processor (`ProcessDueCrewScheduledMovements`) — does not require a long-lived queue worker
- Claim → validate snapshot/eligibility/conflicts → `CrewMovementService::perform` with system actor (`null`) → mark executed
- Lateness policy: if processing starts more than **15 minutes** after `scheduled_at`, mark Needs Attention (do not silently backdate)
- Unexpected / DB exceptions never expose SQL or internal details to operators

## Needs Attention notifications

Detected by `DetectCrewOperationalAlerts` as `scheduled_movement_needs_attention` (settings toggle `alert_scheduled_movement_needs_attention`). Uses the existing Crew Operations reconcile → recipient → Web Push / email digest pipeline with `(company_id, dedupe_key)` uniqueness and `notification_version` anti-spam. Employee visibility restrictions apply at read/present time.

## Permissions

| Permission | Purpose |
|------------|---------|
| `crew_operations.movements.schedule.view` | View schedules / index |
| `crew_operations.movements.schedule` | Create schedules |
| `crew_operations.movements.schedule.manage` | Edit, reschedule, cancel |

Granted from existing assignment view / movement perform roles via migration.

## Phase 1 Testing Override

`allow_future_actual_movement_dates` remains in the database for compatibility but the settings UI no longer offers enabling it for normal production. Operators should use Schedule for Later. Record Now rejects future actual timestamps when the override is off.

## Related

- [crew-movement-phases.md](./crew-movement-phases.md)
- [crew-movement-corrections.md](./crew-movement-corrections.md)
- [../crew-operational-alerts-email.md](../crew-operational-alerts-email.md)
- Runbook: [../runbooks/crew-scheduled-movements.md](../runbooks/crew-scheduled-movements.md)
