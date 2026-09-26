# OMS-HRM Documentation Router

Use this file to find the **smallest useful context** for a task in Herd OMS-HRM. OMS-HRM is a complete multi-tenant Organization Management and Human Resources platform; **Crew Operations is one domain, not the whole product**.

**Source of truth:** current code, routes, migrations, tests, and `database/seeders/PermissionsSeeder.php`. Documentation is the map; implementation wins when they disagree.

For the human-friendly documentation catalog, see [DOCUMENTATION_INDEX.md](./DOCUMENTATION_INDEX.md).

## Agent workflow

For normal work, do not load the whole repository or all documentation.

```text
AGENTS.md + .cursor/rules/project-rules.mdc
        ↓
docs/README.md
        ↓
one matching focused guide OR architecture/context-map.md
        ↓
relevant routes + permission names
        ↓
relevant models + Support/Services + frontend
        ↓
one or two sibling implementations + focused tests
```

Use [architecture/golden-files.md](./architecture/golden-files.md) when you need a preferred implementation example. Use [architecture/domains.md](./architecture/domains.md) only when a task needs broader business-domain relationships that the focused guide/current code does not answer. Load `AI_GUIDE.md` only for broad repository architecture work.

### Context budget

- Start with **one** matching guide. Load a second only when the task genuinely crosses domains.
- For domains without a focused guide, start with the small [architecture/context-map.md](./architecture/context-map.md), then inspect current implementation. Do **not** load the full `architecture/domains.md` by default.
- Inspect only the relevant route group, permission names, models, Support/Services, frontend feature, migrations, seeders, and tests.
- Do not initially load every migration, model, frontend module, `.cursor/rules` file, skill, or documentation file.
- Do not treat old prompts or stale docs as stronger evidence than current implementation.

## Full platform task router

| Domain / task | Read first |
| --- | --- |
| Dashboard / analytics | [dashboard.md](./dashboard.md) |
| Organization / companies / branches / tenancy structure | [architecture/context-map.md](./architecture/context-map.md), then current Organization routes/code; use `architecture/domains.md` only if broader relationships are needed |
| Employees / profiles / contracts / bank / education / experience / training / vaccination / languages / sea service | [architecture/context-map.md](./architecture/context-map.md), then current Employee implementation; also [architecture/active-employee-visibility.md](./architecture/active-employee-visibility.md) when employee-status visibility is involved |
| Master data / settings master data | [architecture/context-map.md](./architecture/context-map.md), then `routes/settings.php` and current Settings implementation |
| Documents / library / employee documents / company or branch documents / templates / e-signing | [document-management.md](./document-management.md); add [document-search.md](./document-search.md) or [document-sharing.md](./document-sharing.md) only when relevant |
| Document compliance / expiry Web Push | [document-compliance-web-push.md](./document-compliance-web-push.md) |
| Attendance / records | [architecture/context-map.md](./architecture/context-map.md), then current Attendance routes/code |
| Leave requests / approvals / policies | [architecture/context-map.md](./architecture/context-map.md), then current Leave implementation and permission names |
| Payroll | [payroll.md](./payroll.md) |
| Crew Payroll / Crew Timesheet preparation | [payroll.md](./payroll.md) and [architecture/crew-payroll-timeline-preparation.md](./architecture/crew-payroll-timeline-preparation.md) |
| Crew Operations / Crew Assignments / P0-P6 / planning / vessel manning / movements | [architecture/crew-movement-phases.md](./architecture/crew-movement-phases.md) |
| Crew movement corrections | [architecture/crew-movement-corrections.md](./architecture/crew-movement-corrections.md) |
| Crew operational alerts | [crew-operational-alerts-email.md](./crew-operational-alerts-email.md) or [crew-operational-alerts-web-push.md](./crew-operational-alerts-web-push.md) |
| Reports (general) | [architecture/context-map.md](./architecture/context-map.md), then the current report route/query/export; use a report-specific guide when one exists |
| Crew Movement History report | [reports/crew-movement-history.md](./reports/crew-movement-history.md) |
| Hotel Check-In & Check-Out report | [reports/hotel-checkin-checkout.md](./reports/hotel-checkin-checkout.md) |
| Leave Report | [reports/leave-report.md](./reports/leave-report.md) |
| Leave Balance Report | [reports/leave-balance-report.md](./reports/leave-balance-report.md) |
| Users / roles / permissions / tenant authorization | [permissions.md](./permissions.md) and `.cursor/rules/permissions.mdc` |
| Activity logs / audit trail | [permissions.md](./permissions.md#audit) and current activity-log implementation |
| User account status / login eligibility | [permissions.md](./permissions.md#global-user-account-status) |
| User email identity / duplicate login emails | [permissions.md](./permissions.md#global-user-email-identity) |
| Privileged 2FA | [privileged-2fa.md](./privileged-2fa.md) |
| Announcements | [announcements.md](./announcements.md); add [announcements-web-push.md](./announcements-web-push.md) for browser push |
| Email / SMTP integration | [email-configuration.md](./email-configuration.md) |
| WhatsApp integration | [whatsapp-integration.md](./whatsapp-integration.md) |
| Hikvision integration | [hikvision-integration.md](./hikvision-integration.md) |
| AI providers / Smart Employee Search | [ai-settings.md](./ai-settings.md) |
| Settings without a dedicated guide | [architecture/context-map.md](./architecture/context-map.md), then `routes/settings.php` and the relevant current Settings code |
| Global Search | [global-search.md](./global-search.md) |
| Navigation favorites | [navigation-favorites.md](./navigation-favorites.md) |
| Recently viewed records | [recent-items.md](./recent-items.md) |
| Saved views | [saved-views.md](./saved-views.md) |
| Mobile operational UX | [mobile-operational-lists.md](./mobile-operational-lists.md) |
| HTTP / browser security headers | [security-headers.md](./security-headers.md) |
| Job history retention / activity-log cleanup | [permissions.md](./permissions.md#job-history-and-activity-log-retention) |
| CI / quality gates | [ci.md](./ci.md) |
| Broad architecture / cross-domain analysis | [architecture/project-analysis.md](./architecture/project-analysis.md), then [architecture/domains.md](./architecture/domains.md) only as needed |

## Crew and Payroll terminology

Keep product-facing terminology distinct from compatibility names:

```text
CrewAssignment / CrewAssignmentPhase
    ↓ actual operational movement data (source of truth)
Crew Timesheet on /payroll/{period} (Draft)
    ↓ Populate from Assignments and/or Manual/Excel edits
Payroll generation → period approval → payment
```

- `CrewAssignment` / `CrewAssignmentPhase` remain the operational source of truth for Crew movements. Crew Timesheet edits never mutate them.
- Product-facing Payroll copy uses **Crew Timesheet** and **Crew Assignments**. There is no separate Crew Timeline review page or Crew Timesheet submit/approve/apply workflow.
- Internal persisted/technical compatibility names such as `source = crew_operations`, `CrewTimesheetSource::CrewOperations`, `CrewTimeline*`, `app/Support/Payroll/CrewTimeline/`, and `payroll.crew-timeline.prepare` remain valid implementation identifiers.
- Planned sign-off or planning dates are never actual disembarkation/payroll movement dates.
- `EmployeeDeployment` has been removed; do not reintroduce it unless an explicit migration task requires it.

## Cross-cutting implementation rules

- Resolve tenant ownership from trusted request `current_company_id`; never trust client-submitted `company_id`.
- Backend authorization is mandatory. Frontend permission flags are UX only.
- Sensitive credentials must stay masked and server-side.
- Keep the Laravel/Inertia monolith and reuse existing Support/Services/components before introducing abstractions or dependencies.
- Use explicit routes and Wayfinder; never manually edit generated route/action files.
- Preserve operational history, auditability, and planned-vs-actual distinctions.
- Meaningful changes require focused tests for the affected behavior, permissions, tenant isolation, workflow, audit, and regressions as applicable.
- If behavior, architecture, permissions, schema, integrations, or operations change, update the relevant existing Markdown guide in the same task.

## Developer helpers

| Need | Use |
| --- | --- |
| Laravel backend conventions | `.cursor/rules/backend.mdc` + `laravel-best-practices` skill |
| Inertia React UI | matching scoped `.cursor/rules/*.mdc` + `inertia-react-development` skill |
| Pest | `.cursor/rules/testing.mdc` + `pest-testing` skill |
| Wayfinder | `wayfinder-development` skill |
| End-to-end OMS change | `implement-oms-change` skill |
| Security-sensitive review | `review-oms-security` skill |
| Preferred implementation references | [architecture/golden-files.md](./architecture/golden-files.md) |
| Directory-level domain pointers | [architecture/context-map.md](./architecture/context-map.md) |
| Full documentation catalog | [DOCUMENTATION_INDEX.md](./DOCUMENTATION_INDEX.md) |
