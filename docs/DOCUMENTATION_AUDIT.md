# Documentation audit report (historical)

**Date:** 2026-05-22  
**Scope:** All repository `.md` files vs current codebase (through document search redesign, dashboard analytics, sharing, SMTP, permissions).

> This report records the state of the repository and the work completed on 2026-05-22. Its file counts, coverage estimates, and “current” statements are historical and must not be used as a description of the present application. See the 2026-07-14 follow-up below.

---

## Follow-up — 2026-07-14

The product surface expanded substantially after the original audit. The documentation entry points now recognize employee profile templates (which replaced the removed onboarding-template workflow), attendance and leave, payroll, training, crew operations, bulk documents, e-signing, and WhatsApp/Hikvision integrations.

Corrections made during this follow-up:

- Removed recruitment and browser-test claims that were not supported by active routes or test directories.
- Replaced `/onboarding/templates` and `onboarding.templates.*` with `/organization/templates/employee-profile` and `employee_profile_templates.*`.
- Corrected employee-import requirements: `employee_no` and `name` are the base required fields; selected profile templates may require more.
- Removed the unsafe implication that every authenticated organization endpoint automatically has `can:` middleware. Authorization must be verified per endpoint and tested on both allowed and forbidden paths.
- Expanded the documentation index and added the current payroll guide.

The May 2026 “~80%” coverage estimate is no longer a meaningful current score. Dedicated guides remain uneven: documents and dashboard have detailed guides, payroll now has a dedicated guide, while attendance/leave, crew operations, training, bulk documents/e-signing, Hikvision, and WhatsApp still need deeper operational documentation. The route definitions, permission seeder, tests, and implementation are authoritative until those guides are completed.

---

## Phase 1 — Discovery

| File path | Purpose | Last major feature referenced (before audit) | Outdated? | Confidence |
|-----------|---------|---------------------------------------------|-----------|------------|
| `README.md` | Project overview, setup, permissions cheatsheet | Employee import; no documents module | **Yes** | High |
| `AI_GUIDE.md` | Contributor / AI patterns | Onboarding, list pages; no documents/dashboard | **Yes** | High |
| `AGENTS.md` | Laravel Boost agent rules | Package versions (accurate) | Partial | Medium |
| `GEMINI.md` | Duplicate Boost guidelines | Same as AGENTS | Partial | Medium |
| `docs/*` | Product guides | **Did not exist** | N/A | — |
| `.agents/skills/**` | Framework skills (Laravel, Inertia, Pest, …) | Generic best practices | No (vendor-style) | High |
| `.cursor/skills/**` | Cursor copy of `.agents/skills` | Same | No | High |

**Count:** 52 `.md` files total; **4** project docs + **48** skill/rule mirrors.

---

## Phase 2 — Repository analysis (features vs docs)

| Feature area | Implemented in code | Was documented before audit |
|--------------|---------------------|----------------------------|
| Dashboard analytics & charts | `DashboardAnalytics`, `dashboard.tsx` | No |
| Document folders index | `DocumentsFolderIndexController` | No |
| Global document search + result modes | `DocumentBrowseQuery`, `use-documents-index-search-mode` | No |
| Document number on browse/profile | `EmployeeDocument::toBrowseArray` | No |
| Document permissions | `documents.view/download/share/upload/delete` | No |
| Document sharing / WhatsApp | `DocumentShareController`, share-links API | No |
| Compliance expiry filters | Summary cards + `documentsForCompliance` | No |
| SMTP + test email | `ApplicationSettingsController` | No |
| User ↔ employee link + avatar | `SyncUserEmployeeLink`, user form | No |
| Last login on users | `RecordUserLastLogin` | No |
| Granular import permissions | `employees.*.import` | Partial (`employees.import` only) |
| ADNOC CV print | `EmployeeCvPrintController` | No |

---

## Phase 3 — Gap analysis & update plan

| Gap | Action taken |
|-----|----------------|
| No `docs/` tree | Created `docs/README.md` + 6 topic guides |
| README missing documents, dashboard, routes | Rewrote highlights, routes, permissions, structure |
| AI_GUIDE missing documents/dashboard/users | Added sections + link to docs |
| Permissions cheatsheet incomplete | Expanded in README; full detail in `docs/permissions.md` |
| Search UX undocumented | `docs/document-search.md` |
| Active filter / chip behavior | Documented (search not a chip on index) |
| `.agents`/`.cursor` skills | **No change** — maintained by Laravel Boost, not product docs |

---

## Phase 4 — Updates completed

See Phase 6 tables below.

---

## Phase 5 — New files

| File | Reason |
|------|--------|
| `docs/README.md` | Documentation index |
| `docs/dashboard.md` | Dashboard metrics and architecture |
| `docs/document-management.md` | Folders, browse, profile, expiry |
| `docs/document-search.md` | Search modes and API props |
| `docs/document-sharing.md` | Share links, WhatsApp, bulk actions |
| `docs/permissions.md` | Spatie + document/import permissions |
| `docs/email-configuration.md` | SMTP and test mail |
| `docs/DOCUMENTATION_AUDIT.md` | This audit trail |

---

## Phase 6 — Validation

### Updated files

| Path | Summary of changes |
|------|-------------------|
| `README.md` | Added docs index link; dashboard & documents highlights; updated permissions, routes, structure; PHP 8.3+ |
| `AI_GUIDE.md` | Docs link; Documents, Dashboard, Users sections |
| `AGENTS.md` | One-line pointer to `docs/README.md` |

### New files

Listed in Phase 5.

### Remaining gaps (manual / business input)

- Production deployment runbook (servers, queues, backups).
- HR policy definitions (retention, mandatory document types per jurisdiction).
- Screenshot or video walkthroughs (none referenced; intentionally omitted).
- Recruitment / attendance / payroll modules (routes may exist; not fully documented until product-ready).
- Exact permission names for every `settings.*` key (verify in `routes/settings.php` when documenting ops).

### Documentation coverage score at the time (historical estimate)

| Area | Before | After |
|------|--------|-------|
| Setup & dev | 85% | 90% |
| Core HR (employees, org) | 70% | 75% |
| Documents module | 10% | 85% |
| Dashboard | 0% | 80% |
| Permissions | 50% | 85% |
| Email / SMTP | 0% | 75% |
| **Overall product docs** | **~35%** | **~80%** |

Skill files (`.agents`/`.cursor`) remain framework guidance, not product documentation.
