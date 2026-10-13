# Recovery: Premature Future Arrival (example CA-2026-000103)

## Problem

An operator entered a **future** arrival timestamp, but an older build immediately transitioned the assignment from **P0 Pre-Mobilisation** into **P2A Join Standby**. The employee should have remained in P0 with a **scheduled** arrival until execution.

This runbook documents a **manual, authorized recovery**. Do **not** auto-repair during migration or deployment.

## Example

- Assignment: `CA-2026-000103` (ABDUL RAJAB)
- Incorrect outcome: immediate P0 → P2A with a future-dated P2A actual start
- Desired outcome: restore P0 (when safe) and create a scheduled Record Arrival for the intended future instant

## Preconditions

1. Authorized operator with crew movement / correction privileges.
2. Company timezone confirmed.
3. Backup / point-in-time restore available if repair fails mid-way.
4. Stakeholder confirmation that modifying this assignment is approved.

## Inspection checklist (read-only first)

For the assignment id / `assignment_no`:

1. **Phases** — current phase code/status, all phase `actual_start_at` / `actual_end_at`, sequences.
2. **Activity log** — movement performed / phase transitions around the mistaken arrival.
3. **Accommodation stays** — open or closed hotel stays created by the premature arrival.
4. **Sea Service** — any employee sea-service rows linked to this assignment (usually none for P2A).
5. **Payroll / timesheet references** — any prepared or locked periods that already consumed the premature P2A window.
6. **Scheduled movements** — whether an unresolved schedule already exists.
7. **Corrections / voids** — pending correction requests that would conflict.

Classify:

| Class | Meaning | Recovery posture |
| --- | --- | --- |
| A — Soft | Only phase rows + activity; no accommodation/payroll/sea-service side effects | Prefer audited correction / controlled rewind to P0 |
| B — Contained | Hotel stay opened but unused; no payroll lock | Close/void accommodation via supported services, then rewind |
| C — Protected | Payroll, sea service, or later movements already depend on P2A | **Do not rewind.** Document; use corrections forward; leave history |

## Safe repair strategy (Class A / B only)

Use existing application services and audited correction workflows. Prefer:

1. `CrewMovementCorrection` (override/request) where fields can be corrected without inventing a new lifecycle, **or**
2. A controlled admin Support action that:
   - runs in a DB transaction
   - re-opens / restores P0 as current only when no later legitimate phases exist
   - closes the incorrect P2A without deleting rows
   - writes an activity log entry describing the mistake and repair
   - creates a `CrewScheduledMovement` for Record Arrival at the intended future company-local time **after** assignment state is valid again

### Hard rules

- No raw SQL `DELETE` of phases, stays, or sea-service rows.
- No `migrate:fresh` / wipe.
- Do not silently rewrite historical payroll or sea-service facts.
- Preserve evidence of the original mistaken transition in activity history.
- Require explicit confirmation before mutating production data.
- If Class C, stop and escalate — document separately rather than forcing P0.

## After repair verification

1. Current phase is P0 (or documented Class C exception).
2. Upcoming scheduled movement shows Record Arrival at the intended timestamp.
3. Phase Timeline shows only actual persisted phases (no future schedule as a completed phase).
4. No negative “days in phase”.
5. Accommodation / sea service / payroll unchanged until schedule execution (Class A/B).

## Prevention (shipped with automatic scheduling)

Operators now select only a movement date/time. Future timestamps automatically create a scheduled movement; past/current timestamps record immediately. The Phase 1 `allow_future_actual_movement_dates` testing override is retired.
