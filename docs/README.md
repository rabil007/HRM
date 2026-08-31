# OMS-HRM Documentation

Product and developer documentation for the Herd OMS-HRM application. These guides describe **implemented** behavior in this repository.

**Authoritative sources:** current code, routes, migrations, tests, and `database/seeders/PermissionsSeeder.php`. If documentation disagrees with those, follow the implementation.

## Agent context routing

Load **only** documentation relevant to the current task. Do **not** load every domain guide, `AI_GUIDE.md`, or the entire repository for a normal change.

```text
.cursor/rules/project-rules.mdc     always-on invariants
        ↓
docs/README.md                      this router
        ↓
one matching domain guide
        ↓
relevant routes + permission names
        ↓
relevant models + Support/Services
        ↓
one or two sibling implementations + tests
```

Directory-level pointers (without architecture prose): [architecture/context-map.md](./architecture/context-map.md).

Inspect the current implementation **before** relying on documentation. Broaden context only when the task crosses domains or evidence requires it.

### Do not initially load

- Every migration, every model, or the entire frontend tree
- Every documentation file or `AI_GUIDE.md` for a narrow task
- The entire PermissionsSeeder when only one permission group is needed
- Unrelated Crew, Payroll, or Documents modules

### Task → guide

| Task | Read first |
|------|------------|
| Crew Operations / Crew Assignments / P0–P6 | [architecture/crew-movement-phases.md](./architecture/crew-movement-phases.md) |
| Crew movement corrections | [architecture/crew-movement-corrections.md](./architecture/crew-movement-corrections.md) |
| Crew Movement History report | [reports/crew-movement-history.md](./reports/crew-movement-history.md) |
| Crew payroll / timeline preparation | [payroll.md](./payroll.md) and [architecture/crew-payroll-timeline-preparation.md](./architecture/crew-payroll-timeline-preparation.md) |
| Tenant access or permissions | [permissions.md](./permissions.md) and `.cursor/rules/permissions.mdc` |
| User account status / login eligibility | [permissions.md](./permissions.md#global-user-account-status) |
| User email identity / duplicate login emails | [permissions.md](./permissions.md#global-user-email-identity) |
| Documents, sharing, or search | The matching document guide below; [global-search.md](./global-search.md) for Cmd/Ctrl+K |
| Global Search | [global-search.md](./global-search.md) |
| Navigation favorites | [navigation-favorites.md](./navigation-favorites.md) |
| Recently viewed records | [recent-items.md](./recent-items.md) |
| Saved list filters | [saved-views.md](./saved-views.md) |
| Privileged 2FA | [privileged-2fa.md](./privileged-2fa.md) and `.cursor/rules/permissions.mdc` |
| AI providers / Smart Employee Search | [ai-settings.md](./ai-settings.md) |
| HTTP / browser security headers | [security-headers.md](./security-headers.md) |
| Security (credentials, tenancy, auth) | Matching security guide ([permissions.md](./permissions.md#global-user-account-status) for login account status, [permissions.md](./permissions.md#global-user-email-identity) for global email identity, [privileged-2fa.md](./privileged-2fa.md), [security-headers.md](./security-headers.md)) + `review-oms-security` skill |
| CI quality gates | [ci.md](./ci.md) |
| Operational lists on phones | [mobile-operational-lists.md](./mobile-operational-lists.md) |
| Payroll (non-crew) | [payroll.md](./payroll.md) |
| Laravel backend | `.cursor/rules/backend.mdc`; `laravel-best-practices` skill |
| Inertia React UI | The matching scoped UI rule; `inertia-react-development` skill |
| End-to-end change | `implement-oms-change` skill |
| Preferred copy-from examples | [architecture/golden-files.md](./architecture/golden-files.md) |
| Architecture overview | [architecture/project-analysis.md](./architecture/project-analysis.md) |

## Index

| Guide | Audience | Topics |
|-------|----------|--------|
| [Dashboard](./dashboard.md) | HR, developers | Analytics, charts, document health, workforce trends |
| [Document management](./document-management.md) | HR, developers | Overview, Library, Configuration (Document Types), folders, employee browse, upload, expiry, required-document compliance |
| [Document search](./document-search.md) | HR, developers | Documents index search UX, result modes, backend queries |
| [Global search](./global-search.md) | HR, developers | Cmd/Ctrl+K omnibox: commands plus permission-aware record search |
| [Navigation favorites](./navigation-favorites.md) | HR, developers | Personal pinned navigation destinations; permission-aware, not record shortcuts |
| [Recent items](./recent-items.md) | HR, developers | Recently viewed business records in Cmd/Ctrl+K; per user and company, not audit history |
| [Saved views](./saved-views.md) | HR, developers | Personal named list-filter combinations on Employees, Documents, Crew, Leave, and Payroll |
| [Privileged two-factor](./privileged-2fa.md) | Admins, developers | Fortify 2FA enrollment required for high-trust actions; does not replace permissions |
| [HTTP security headers](./security-headers.md) | Admins, developers | CSP, HSTS, framing, Referrer-Policy, session cookie production settings |
| [CI quality gates](./ci.md) | Developers | Change classifier, parallel Pint / frontend static / Vite build, sharded Pest, `Quality gates` aggregator |
| [Mobile operational lists](./mobile-operational-lists.md) | Developers | Compact phone cards for selected operational indexes; desktop tables stay standard |
| [Document sharing](./document-sharing.md) | HR, developers | Share links, WhatsApp, bulk actions |
| [Permissions](./permissions.md) | Admins, developers | Spatie permissions, documents, imports, global user account status, global user email identity |
| [Email configuration](./email-configuration.md) | Admins, developers | SMTP settings, test email |
| [AI settings](./ai-settings.md) | Admins, developers | Platform OpenAI/OpenRouter credentials, Smart Employee Search toggle, Employee Directory Beta UI |
| [WhatsApp integration](./whatsapp-integration.md) | Admins, developers | Meta Cloud API settings, webhook verification, signed status callbacks |
| [Announcement Web Push](./announcements-web-push.md) | Admins, developers | Browser push as an extension of in-app announcements |
| [Document compliance Web Push](./document-compliance-web-push.md) | Admins, developers | Browser push for the daily document expiry summary |
| [Hikvision integration](./hikvision-integration.md) | Admins, developers | Company settings, webhooks, scheduled syncs |
| [Payroll](./payroll.md) | Payroll users, developers | Periods, salary inputs, timesheets, payslips, WPS, state transitions |
| [Crew payroll timeline preparation](./architecture/crew-payroll-timeline-preparation.md) | Payroll, operations, developers | Prepare / review / approve / apply crew timeline from Crew Operations actuals |
| [Crew Movement History](./reports/crew-movement-history.md) | Operations, management, developers | One-row assignment history, phase mapping, durations, exports |
| [Crew Movement Corrections](./architecture/crew-movement-corrections.md) | Operations, developers | Request/approve workflow for in-place movement field corrections |
| [Crew Movement Phases](./architecture/crew-movement-phases.md) | Operations, developers | CrewAssignment source of truth, P0–P6, planning sync, sea service, manning, alerts |
| [Crew operational alerts Web Push](./crew-operational-alerts-web-push.md) | Admins, developers | Unified bell, recipient/read state, privacy-safe Crew browser push |
| [Crew operational alerts email](./crew-operational-alerts-email.md) | Admins, developers | Privacy-safe Crew alert email delivery, ledger, SMTP, retries |
| [Architecture overview](./architecture/project-analysis.md) | Developers | Application structure, stack, conventions |
| [Domain map](./architecture/domains.md) | Product, developers | Core HR, documents, attendance, payroll, Crew Operations |
| [Context map](./architecture/context-map.md) | Developers, agents | Directory-level pointers by domain |
| [Golden files](./architecture/golden-files.md) | Developers | Preferred implementation references |
| [Active employee visibility](./architecture/active-employee-visibility.md) | Product, developers | Operational vs historical employee status filtering |
| [Documentation audit](./DOCUMENTATION_AUDIT.md) | Maintainers | Historical May/July audits plus later follow-ups |

## Implemented module coverage

The application currently includes core organization and employee management, employee profile templates, documents and e-signing, attendance and leave, payroll, training, **Crew Operations** (Crew Assignments, P0–P6 movements, planning/Gantt, vessel manning, sea-service synchronization, movement history/corrections), users and roles, activity logging, bulk documents, and SMTP/WhatsApp/Hikvision integrations. Documentation depth varies by module; source code, routes, and tests remain authoritative where a dedicated guide is not yet available.

## Related project files

| File | Purpose |
|------|---------|
| [README.md](../README.md) | Setup, stack, quick reference |
| [AI_GUIDE.md](../AI_GUIDE.md) | Concise repository-wide architecture (load only when needed) |
| [AGENTS.md](../AGENTS.md) | Laravel Boost agent rules (package versions, skills) |

## Documentation standards

- Routes are listed as paths; run `php artisan route:list --path=organization` for named routes.
- Permissions are seeded in `database/seeders/PermissionsSeeder.php`.
- Frontend pages live under `resources/js/pages/`; feature modules under `resources/js/features/`.

## Last reviewed

Entry points reviewed on **2026-08-25**. Guides were reconciled with CrewAssignment + P0–P6 (EmployeeDeployment removed), current authorization/policy usage, Golden Files, Cursor rules, and CI change classification. Topic guides still vary in depth; implementation remains authoritative.
