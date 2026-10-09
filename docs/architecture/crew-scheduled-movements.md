# Crew Scheduled Movements (Phase 2)

Automatic crew movement scheduling separates **recording actual movements** from **scheduling future movements**.

## Modes

| Mode | Endpoint | Effect |
|------|----------|--------|
| Record Now | `POST .../crew/{assignment}/actions` | Immediate operational transition via `CrewMovementService` |
| Schedule for Later | `POST .../crew/{assignment}/scheduled-movements` | Persists `CrewScheduledMovement` only — no phase, hotel, Sea Service, or payroll side effects |

There is **no manual confirmation mode** for due schedules. Execution is automatic only.

## Data model

Table: `crew_scheduled_movements`

- One unresolved schedule per assignment (`scheduled` / `processing` / `needs_attention`) via generated unique `unresolved_assignment_id`
- Statuses: `scheduled`, `processing`, `executed`, `needs_attention`, `cancelled`
- Snapshots: expected current phase id/code/sequence, vessel id, result phase
- Timestamps: `scheduled_at` (intent), `executed_at` (system run), `effective_occurred_at` (applied operational time)

## Schedulable actions

Physical / operational transitions from `CrewSchedulableMovementActions`:

- Start Assignment, Record Arrival, Send/Complete Training, Join Vessel, Confirm Disembarkation, Travel Home, Transfer Vessel, Redeploy, Close Assignment

Not scheduled: Plan Sign-off, Cancel Assignment, Correct Movement, Void.

## Automatic execution

- Cron: `crew:process-scheduled-movements` every minute (`routes/console.php`)
- Support: `crew:recover-scheduled-movements --list` / `--process-due`
- Cron-compatible inline processor (`ProcessDueCrewScheduledMovements`) — does not require a long-lived queue worker
- Claim → validate snapshot/eligibility/conflicts → `CrewMovementService::perform` with system actor (`null`) → mark executed
- Lateness policy: if processing starts more than **15 minutes** after `scheduled_at`, mark Needs Attention (do not silently backdate)

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
- Runbook: [../runbooks/crew-scheduled-movements.md](../runbooks/crew-scheduled-movements.md)
