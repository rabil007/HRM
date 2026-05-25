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

## Related project files

| File | Purpose |
|------|---------|
| [README.md](../README.md) | Setup, stack, quick reference |
| [AI_GUIDE.md](../AI_GUIDE.md) | Preferred patterns for contributors and AI agents |
| [AGENTS.md](../AGENTS.md) | Laravel Boost agent rules (package versions, skills) |

## Documentation standards

- Routes are listed as paths; run `php artisan route:list --path=organization` for named routes.
- Permissions are seeded in `database/seeders/PermissionsSeeder.php`.
- Frontend pages live under `resources/js/pages/`; feature modules under `resources/js/features/`.

## Last reviewed

Documentation aligned with codebase through document search redesign, dashboard analytics, document sharing, SMTP test mail, and granular document permissions.
