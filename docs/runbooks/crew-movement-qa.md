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
- [ ] Repeat confirm is idempotent
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

- [ ] `transfer_vessel`, `redeploy`, `correct_movement` return clear validation/safe errors
