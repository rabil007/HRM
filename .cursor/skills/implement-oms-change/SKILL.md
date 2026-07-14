---
name: implement-oms-change
description: Implement or refactor an end-to-end OMS-HRM feature across Laravel, Inertia React, routes, permissions, and Pest tests. Use for multi-file application changes, new workflows, CRUD behavior, or changes spanning backend and frontend.
---

# Implement an OMS-HRM Change

1. Read `.cursor/rules/project-rules.mdc` and use `docs/README.md` to select only the relevant domain documentation.
2. Inspect the nearest existing controller, Support or Service class, Form Request, page or feature component, and Pest test before designing the change.
3. Confirm tenant ownership, backend authorization, validation, and credential handling before editing.
4. Keep controllers focused on HTTP/Inertia concerns. Put domain queries and actions in `app/Support/`; put external integrations in `app/Services/`.
5. Keep Inertia pages thin, reuse `resources/js/features/` and shared components, and use Wayfinder instead of hardcoded application URLs.
6. Add or update the smallest targeted Pest test. Cover unauthorized and cross-company behavior when the change touches tenant-owned data.
7. Run the targeted tests, `vendor/bin/pint --dirty --format agent` after PHP edits, and the narrowest relevant TypeScript lint or type check after frontend edits.
8. Review the final diff for unrelated changes, generated files, exposed secrets, and stale documentation.

For broad architecture decisions, read `AI_GUIDE.md`. For preferred examples, read `docs/architecture/golden-files.md`.
