---
name: review-oms-security
description: Review OMS-HRM code for tenant isolation, backend authorization, credential exposure, unsafe secret updates, mass assignment, and sensitive logging. Use for security reviews and any change involving company-owned data, SMTP, WhatsApp, Hikvision, signing credentials, share links, imports, or exports.
---

# Review OMS-HRM Security

1. Trace the request from route middleware through Form Request or controller, domain query/action, model access, and Inertia or download response.
2. Verify every tenant-owned lookup and mutation is constrained to the active `current_company_id`; test cross-company identifiers explicitly.
3. Verify backend authorization for every endpoint. Treat frontend visibility and `can` props as UX only.
4. Inspect all response paths: Inertia props, JSON, redirects, validation errors, logs, jobs, notifications, exports, and exception messages.
5. Never return decrypted credentials. Return a fixed masked placeholder only when useful and an explicit `has_*` boolean.
6. On update, interpret an empty credential field as “preserve the stored value.” Replace a secret only when a non-empty validated value is submitted.
7. Check temporary links, signatures, tokens, uploaded files, and exports for expiry, tenant binding, permission checks, and accidental disclosure.
8. Add targeted Pest coverage for authorized use, missing permission, cross-company access, masked response values, and empty-secret preservation as applicable.
9. Report findings by severity with exact file and line references; do not claim a control exists without tracing the executable path.

Read `docs/permissions.md` for the current permission model and the matching integration guide for credential-sensitive settings.
