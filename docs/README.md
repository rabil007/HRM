# OMS-HRM Documentation

Product and developer documentation for the Herd OMS-HRM application. These guides describe **implemented** behavior in this repository—verify against code when in doubt.

## Index

| Guide | Audience | Topics |
|-------|----------|--------|
| [Dashboard](./dashboard.md) | HR, developers | Analytics, charts, document health, workforce trends |
| [Document management](./document-management.md) | HR, developers | Folders, employee browse, upload, expiry, compliance |
| [Document search](./document-search.md) | HR, developers | Global search UX, result modes, backend queries |
| [Document sharing](./document-sharing.md) | HR, developers | Share links, WhatsApp, bulk actions |
| [Permissions](./permissions.md) | Admins, developers | Spatie permissions, documents, imports |
| [Email configuration](./email-configuration.md) | Admins, developers | SMTP settings, test email |
| [Payroll](./payroll.md) | Payroll users, developers | Periods, salary inputs, timesheets, payslips, WPS, state transitions |
| [Architecture overview](./architecture/project-analysis.md) | Developers | Application structure, stack, risks, conventions |
| [Domain map](./architecture/domains.md) | Product, developers | Core HR, documents, attendance, payroll, crew operations |
| [Golden files](./architecture/golden-files.md) | Developers | Preferred implementation references |
| [Documentation audit](./DOCUMENTATION_AUDIT.md) | Maintainers | Historical May audit and July 2026 follow-up |

## Implemented module coverage

The application currently includes core organization and employee management, employee profile templates, documents and e-signing, attendance and leave, payroll, training, crew deployments and planning, users and roles, activity logging, bulk documents, and SMTP/WhatsApp/Hikvision integrations. Documentation depth varies by module; source code, routes, and tests remain authoritative where a dedicated guide is not yet available.

## Related project files

| File | Purpose |
|------|---------|
| [README.md](../README.md) | Setup, stack, quick reference |
| [AI_GUIDE.md](../AI_GUIDE.md) | Preferred patterns for contributors and AI agents |
| [AGENTS.md](../AGENTS.md) | Laravel Boost agent rules (package versions, skills) |

## Agent context routing

| Task | Read first |
|------|------------|
| General architecture | `architecture/project-analysis.md`, then `architecture/golden-files.md` |
| Tenant access or permissions | `permissions.md` and `.cursor/rules/permissions.mdc` |
| Documents, sharing, or search | The matching document guide above |
| Payroll | `payroll.md` |
| Laravel backend | `.cursor/rules/backend.mdc`; use the `laravel-best-practices` skill |
| Inertia React UI | The matching scoped UI rule; use the `inertia-react-development` skill |
| End-to-end change | Use the `implement-oms-change` skill |
| Security review | Use the `review-oms-security` skill |

## Documentation standards

- Routes are listed as paths; run `php artisan route:list --path=organization` for named routes.
- Permissions are seeded in `database/seeders/PermissionsSeeder.php`.
- Frontend pages live under `resources/js/pages/`; feature modules under `resources/js/features/`.

## Last reviewed

Entry points reviewed on **2026-07-14**. The topic guides are being reconciled with the larger attendance, payroll, crew operations, templates, e-signing, and integration surface added since the original May audit.
