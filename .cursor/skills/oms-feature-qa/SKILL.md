---
name: oms-feature-qa
description: Independently verify a completed OMS-HRM feature against acceptance criteria before human review. Use when the user asks for feature QA, acceptance testing, pre-review verification, end-to-end feature checks, or to confirm a change is ready for review. Covers requirement coverage, backend/frontend behavior, focused Pest and frontend tests, optional browser checks, regression risk, and a final QA report.
---

# OMS Feature QA

Verify a **completed** feature independently before human review. Do not re-implement the feature unless QA finds an in-scope defect.

This skill is the **acceptance / QA control plane**. Do not restate:

- Implementation steps → `implement-oms-change`
- Security deep-dive → `review-oms-security`
- Changed-file CI ladder → `.cursor/rules/pre-push-verification.mdc`
- Delivery / retry / draft PR → `.cursor/rules/autonomous-workflow.mdc`
- Tenant/auth invariants → `.cursor/rules/project-rules.mdc`

## When to use

- User asks to QA, acceptance-test, or verify a finished feature
- Autonomous delivery self-check before asking for human approval
- Confirming requirement coverage after a multi-file change

Skip for pure advice, research-only, or when the user limits scope to a single check.

## Safety (hard stops)

- No production data, merges, deploys, or destructive app-DB commands (`migrate:fresh` / `refresh` / `db:wipe`)
- No credential exposure in reports, screenshots, logs, or PR text
- No unrelated module edits; no new dependencies for QA
- Do not weaken or rewrite tests only to hide failures
- Never claim a check passed unless it ran successfully

## Workflow

Copy and track:

```text
QA Progress:
- [ ] 1. Requirement verification
- [ ] 2. Backend verification
- [ ] 3. Frontend verification
- [ ] 4. Browser testing (required UI | not required | unavailable)
- [ ] 5. Automated testing (run or reuse same-commit successes)
- [ ] 6. Regression review
- [ ] 7. Failure handling (if any)
- [ ] 8. Final QA report
```

### 1. Requirement verification

1. Restate the requested feature in one short paragraph.
2. Extract **acceptance criteria** as a checklist (happy path, authz, validation, UI wiring, edge cases the user named). Mark which are **critical** vs nice-to-have.
3. Diff the branch / changed files against that checklist.
4. Mark each criterion with exactly one status:
   - **verified** — evidence confirms the behavior (code review + tests and/or browser as applicable)
   - **failed** — implemented but broken or incorrect
   - **missing** — requested behavior not present in the change
   - **unverified** — not yet proven (check not run, tools unavailable, or evidence insufficient)
   - **out of scope** — explicitly excluded by the user
5. Stop and report **missing** or **failed** requested behavior before calling the feature QA-complete.
6. **Verdict rule:** never report overall **PASS** (or **PASS WITH LIMITATIONS**) when any **critical** criterion is **failed**, **missing**, or **unverified**. Those force **FAIL** (or keep the criterion unverified and the verdict non-passing).

### 2. Backend verification

Inspect only the routes, controllers, Form Requests, Support/Services, and policies the feature touches:

- Authorization is enforced on the server (not only frontend `can` flags)
- Tenant-owned reads/writes use trusted `current_company_id`
- Validation and error responses match the intended UX
- No secrets in responses, logs, or activity payloads

If the change is security-sensitive, run `review-oms-security` instead of duplicating that checklist here.

### 3. Frontend verification

Inspect the Inertia/React pages and feature components the change touches:

- Buttons, forms, modals, links, and Wayfinder actions wire to the correct backend endpoints
- Loading, empty, and error states are handled
- Permission-gated UI matches backend authorization (UX only; backend remains authoritative)
- Displayed behavior matches server validation and flash/error contracts

### 4. Browser testing

**When to run:** only if acceptance criteria involve user-facing UI (pages, forms, buttons, modals, navigation, visible states). Skip browser testing for backend-only / non-UI changes (Support/Services, pure Pest coverage, jobs, seeders with no UI). Record **Browser verification: not required** with a one-line reason.

When UI criteria apply and browser / computer-use tools are available (typically Cloud Agents):

1. Use `http://127.0.0.1:8000` (Herd is not in Cloud VMs).
2. Prefer built assets (`npm run build`) over `npm run dev` when Vite HMR targets `oms-hrm.test`.
3. Seeded login when needed: `admin@example.com` / `password` — do not put credentials in the QA report body beyond noting “seeded admin login”.
4. Exercise the acceptance-criteria interactions only.
5. Capture screenshots or short recordings under `/opt/cursor/artifacts/` when they prove a criterion.

If UI criteria apply but browser tools are unavailable (Local without GUI tooling, missing executor, app not serving): state **Browser verification: unavailable** with the reason, mark affected UI criteria **unverified**, and do **not** invent browser evidence.

### 5. Automated testing

Follow `.cursor/rules/pre-push-verification.mdc` on the feature’s changed files — do not invent a parallel suite.

**Reuse successful results** from the same unchanged commit when trustworthy: same commit SHA, same command/scope, exit 0 already recorded in this session (or clearly attributable CI for that SHA). Cite the prior result in the QA report instead of re-running. Re-run when the commit changed, the command/scope differs, the prior run failed or was flaky, or the failure-handling loop needs confirmation.

Minimum for a typical feature QA (run or reuse as above):

- Focused Pest: `php artisan test --compact <matching-test>`
- Matching frontend unit tests when present: `node --experimental-strip-types --test path/to/*.test.ts`
- PHP: `vendor/bin/pint --dirty --format agent` if PHP was fixed during QA
- Frontend: Prettier + ESLint on changed TS/TSX, then `npm run types:check`
- Wayfinder inputs changed → regenerate before types check

Escalate to `npm run verify:quick` / `verify:push` only when the change warrants it (same rule as pre-push verification).

### 6. Regression testing

Identify **adjacent** risk from the diff — do not scan the whole product:

- Related Pest / frontend tests for shared helpers the feature calls
- Edge cases implied by the domain (empty lists, unauthorized user, cross-company id)

**Do not modify** unrelated payroll calculations, crew movement scheduling, hotel history, permissions catalogs, or date-counting behavior unless the feature under QA explicitly owns that change. Protected crew scheduling invariant remains as in `AGENTS.md`.

### 7. Failure handling

On failure:

1. Identify root cause (not symptom-only patches).
2. Fix only within the approved feature scope.
3. Rerun the **same** focused check.
4. Cap retries per distinct failure signature at **three** (see `autonomous-workflow.mdc`).
5. If still blocked, report the exact error, attempts, and remaining gap — do not claim QA pass.

### 8. Final QA report

End with this template (fill every section; use `n/a` when genuinely not applicable):

```markdown
## Feature QA report

### Scope
- Feature:
- Branch / files reviewed:

### Acceptance criteria
- [ ] Criterion (critical|nice-to-have) — verified|failed|missing|unverified|out of scope — evidence

### Automated checks
| Check | Command | Result |
| --- | --- | --- |
| Pest | | pass/fail/reused (commit + prior evidence)/not run |
| Frontend unit | | pass/fail/reused (commit + prior evidence)/not run |
| Types / lint | | pass/fail/reused (commit + prior evidence)/not run |

### Browser verification
- Status: completed | not required | unavailable
- Evidence: paths, skip reason, or unavailable reason

### Bugs found and fixed
- …

### Remaining limitations
- …

### Regression risks
- …

### Verdict
- **PASS** — every critical criterion is **verified**; ready for human review
- **PASS WITH LIMITATIONS** — all critical criteria **verified**; only non-critical gaps/limitations remain
- **FAIL** — any critical criterion is **failed**, **missing**, or **unverified**
```

## Complements

| Concern | Source of truth |
| --- | --- |
| Building the feature | `implement-oms-change` |
| Security review | `review-oms-security` |
| What commands to run | `pre-push-verification.mdc` |
| Retry budget / draft PR / no merge | `autonomous-workflow.mdc` |
| Acceptance proof + report | **this skill** |
