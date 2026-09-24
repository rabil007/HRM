# OMS-HRM Documentation Index

Human-friendly catalog of OMS-HRM documentation. Agents should normally start from [README.md](./README.md), which is intentionally kept as a compact context router.

Current code, routes, migrations, tests, and `database/seeders/PermissionsSeeder.php` remain authoritative when documentation differs from implementation.

## Product and workflow guides

| Guide | Audience | Topics |
| --- | --- | --- |
| [Dashboard](./dashboard.md) | HR, developers | Analytics, charts, document health, workforce trends |
| [Document management](./document-management.md) | HR, developers | Overview, Library, document types, folders, employee browse, upload, expiry, compliance, Company/Branch documents, templates and related document workflows |
| [Document search](./document-search.md) | HR, developers | Documents index search UX, result modes, backend queries |
| [Document sharing](./document-sharing.md) | HR, developers | Share links, WhatsApp, bulk actions |
| [Document compliance Web Push](./document-compliance-web-push.md) | Admins, developers | Browser push for daily document expiry summary |
| [Payroll](./payroll.md) | Payroll users, developers | Periods, salary inputs, timesheets, payslips, WPS, state transitions |
| [Crew Payroll / Crew Timesheet preparation](./architecture/crew-payroll-timeline-preparation.md) | Payroll, operations, developers | Prepare, review, approve and apply Crew Timesheets from Crew Assignment actuals |
| [Crew Movement Phases](./architecture/crew-movement-phases.md) | Operations, developers | `CrewAssignment` source of truth, P0-P6, planning sync, sea service, manning, alerts |
| [Crew Movement Corrections](./architecture/crew-movement-corrections.md) | Operations, developers | Request/approve workflow for in-place movement corrections |
| [Crew Movement History](./reports/crew-movement-history.md) | Operations, management, developers | One-row assignment history, phase mapping, durations, exports |
| [Leave Report](./reports/leave-report.md) | HR, management, developers | Historical leave requests, approval progress, leave-type cards, exports |
| [Leave Balance Report](./reports/leave-balance-report.md) | HR, management, developers | Persisted leave balance ledger, leave-type cards, department tree, exports |
| [Crew operational alerts email](./crew-operational-alerts-email.md) | Admins, developers | Privacy-safe Crew alert email delivery, ledger, SMTP, retries |
| [Crew operational alerts Web Push](./crew-operational-alerts-web-push.md) | Admins, developers | Unified bell, recipient/read state, privacy-safe Crew browser push |
| [Announcements](./announcements.md) | HR, developers | Channels, publish flow, Send test to me |
| [Announcement Web Push](./announcements-web-push.md) | Admins, developers | Browser push as an extension of in-app announcements |
| [Global search](./global-search.md) | HR, developers | Cmd/Ctrl+K omnibox, commands, permission-aware record search |
| [Navigation favorites](./navigation-favorites.md) | HR, developers | Personal pinned navigation destinations |
| [Recent items](./recent-items.md) | HR, developers | Recently viewed business records by user/company |
| [Saved views](./saved-views.md) | HR, developers | Personal named list-filter combinations |
| [Mobile operational lists](./mobile-operational-lists.md) | Developers | Compact phone cards for selected operational indexes |

## Administration, security and integrations

| Guide | Audience | Topics |
| --- | --- | --- |
| [Permissions](./permissions.md) | Admins, developers | Spatie permissions, tenancy, activity audit, imports, user account status and identity |
| [Privileged two-factor](./privileged-2fa.md) | Admins, developers | Fortify 2FA enrollment for high-trust actions |
| [HTTP security headers](./security-headers.md) | Admins, developers | CSP, HSTS, framing, Referrer-Policy, production session-cookie settings |
| [Email configuration](./email-configuration.md) | Admins, developers | SMTP settings and test email |
| [WhatsApp integration](./whatsapp-integration.md) | Admins, developers | Meta Cloud API settings, webhook verification, signed callbacks |
| [Hikvision integration](./hikvision-integration.md) | Admins, developers | Company settings, webhooks, scheduled syncs |
| [AI settings](./ai-settings.md) | Admins, developers | OpenAI/OpenRouter credentials, Smart Employee Search |
| [CI quality gates](./ci.md) | Developers | Change classifier, Pint, frontend static/build, sharded Pest, quality-gate aggregation |

## Architecture and agent navigation

| Guide | Audience | Topics |
| --- | --- | --- |
| [Documentation router](./README.md) | Developers, agents | Smallest-context task routing across the full OMS-HRM platform |
| [Context map](./architecture/context-map.md) | Developers, agents | Compact directory-level backend/frontend/test pointers by domain |
| [Architecture overview](./architecture/project-analysis.md) | Developers | Application structure, stack, conventions |
| [Domain map](./architecture/domains.md) | Product, developers | Detailed cross-domain business relationships and implementation map |
| [Golden files](./architecture/golden-files.md) | Developers | Preferred implementation references |
| [Active employee visibility](./architecture/active-employee-visibility.md) | Product, developers | Operational vs historical employee-status filtering |
| [Documentation audit](./DOCUMENTATION_AUDIT.md) | Maintainers | Historical documentation audits and follow-ups |

## Platform coverage

OMS-HRM covers the complete organization and HR lifecycle, including:

- dashboard and analytics;
- organization, tenancy, companies and branches;
- employees, profile templates, contracts, banking, education, experience, training, vaccination, languages and sea service;
- master data;
- documents, document compliance, PDF templates, generation, sharing and e-signing;
- attendance, leave requests, My Leave balances, Approvals queue, policies (including Sync Pending Requests), Leave Report and Leave Balance Report;
- payroll, Crew Timesheets, salary inputs, records, payslips and WPS;
- Crew Operations, including Crew Assignments, repeatable P0-P6 phases, planning, vessel manning, movement corrections/history, readiness and sea-service synchronization;
- reports and exports;
- users, roles, permissions, tenant authorization, privileged 2FA and activity audit;
- announcements, notifications and Web Push;
- SMTP, WhatsApp, Hikvision and AI integrations;
- settings and operational master data;
- global search, favorites, recent items and saved views;
- mobile operational UX and CI/deployment quality gates.

Crew Operations is one domain within OMS-HRM, not the definition of the product.

## Documentation maintenance

- Update an existing domain guide instead of creating overlapping documentation.
- Keep [README.md](./README.md) focused on routing; put detailed domain knowledge in the relevant guide.
- Use [architecture/golden-files.md](./architecture/golden-files.md) only for preferred implementation examples.
- Routes are paths/names from current route definitions; permissions are authoritative in `database/seeders/PermissionsSeeder.php`.
- Frontend pages live under `resources/js/pages/`; reusable feature modules live under `resources/js/features/`.
- Do not claim a guide is current unless the touched area was checked against current implementation.

## Related repository files

| File | Purpose |
| --- | --- |
| [../README.md](../README.md) | Setup, stack and repository quick reference |
| [../AI_GUIDE.md](../AI_GUIDE.md) | Broader repository architecture; load only when needed |
| [../AGENTS.md](../AGENTS.md) | Compact OMS-HRM agent bootstrap and Laravel Boost entry points |

## Review note

This catalog was split from the agent router on **2026-09-16** to keep routine Cursor context small. Topic guides vary in depth; current implementation remains authoritative.
