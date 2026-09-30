# AI settings

Platform AI providers and Smart Employee Search are configured in **Settings → Application → AI**.

This is installation-wide configuration. The API account and billing belong to OMS-HRM, not to a company. Per-company AI credentials are not supported. Changes are recorded as platform activity with `company_id` null and do **not** appear in a tenant Activity Log merely because a company was selected when the settings were saved.

## Routes

| Method | Path | Name | Notes |
|--------|------|------|-------|
| PUT | `/settings/application/ai` | `application.ai.update` | `platform:manage` + `privileged.2fa` |
| POST | `/settings/application/ai/test` | `application.ai.test` | `platform:manage`, throttled `6,1` |

Controller: `App\Http\Controllers\Settings\ApplicationSettingsController`

## What administrators can manage

- Enable or disable Smart Employee Search
- Select **OpenAI** or **OpenRouter**
- Store an encrypted API key for each provider
- Optionally set a model name for each provider (leave blank to use the fast Smart Search default)
- Test the **currently saved** selected provider (not unsaved form values)

Normal administration does **not** require editing `.env` or redeploying. Stored Application Settings are authoritative. `EMPLOYEE_SMART_SEARCH_ENABLED`, `OPENAI_API_KEY`, and `OPENROUTER_API_KEY` remain optional bootstrap fallbacks only when no stored value exists. Database settings always win. PHP never writes to `.env`.

An unsupported value stored in `ai_provider` does not fall back to OpenAI. Smart Employee Search and the connection test fail closed without calling a provider.

## Credentials

- API keys are encrypted at rest (`ai_openai_api_key`, `ai_openrouter_api_key`)
- Inertia props expose `has_api_key` flags and models only — never decrypted or masked keys
- Blank key fields preserve the stored secret
- Switching the selected provider keeps both providers’ stored credentials
- Credential updates require `platform:manage` and `privileged.2fa`
- One save is one transaction: all AI setting writes and the platform activity entry commit together, or none do

## Smart Employee Search

**Smart Search Beta** appears on **Organization → Employees** only when the feature is enabled **and** the selected provider has usable runtime credentials (`smart_search_available`). Employees never see provider, model, key, or “why it is missing” details. A temporary provider outage still returns a generic HTTP 503.

Smart Search is a fast, automatic filter box. Interpretation runs after a short debounce (~450ms) while typing, or immediately when the user presses Enter. There is no Interpret button and no Apply Filters confirmation. The request is not made on page load, on every keystroke, or for an empty value. Prompts of two or more characters (for example `AB`) remain valid. Enter cancels a pending debounce. Duplicate in-flight prompts are skipped. The latest request wins; an aborted or stale browser response cannot overwrite a newer interpretation.

Announcement **AI Assist** is a separate product capability. It reuses the same Application AI provider credentials via `AiSettingsService::isProviderConfigured()` / `applySelectedProviderToRuntime()`, and does **not** require Smart Employee Search to be enabled. See [Announcements](./announcements.md#ai-assist).

**Document AI** reuses these installation-wide provider credentials independently of the Smart Employee Search enable switch. Each company owns only its Document AI mode (`off`, `optional`, or `automatic`) in `document_ai_settings`; provider credentials and models remain platform-global. `off` is the default and preserves the existing manual Documents workflow. `documents.ai.manage` changes the company mode (with `privileged.2fa`), while `documents.ai.use` permits single-file and bulk extraction in the existing employee document upload dialog. Supported categories are Passport, Emirates ID, and UAE Visa only; extraction never creates an `EmployeeDocument`, updates employee master data, or replaces the final user review/save step. Uploaded files are sent through private request/temporary storage paths and provider failures fall back to manual upload.

The upload experience depends on company mode:

- **Off:** normal manual document workflow with no AI provider calls.
- **Optional:** the user chooses **Extract with AI** (single) or **Extract all with AI** (multi-file), reviews or edits suggestions and warnings, then uses the existing Upload action.
- **Automatic:** extraction starts for eligible files after the employee context is known; the review panel appears, but the user still reviews and presses Upload. Automatic means auto-start extraction, never auto-save.

AI-filled document number and date fields are visibly marked until the user edits them. Existing user values are never overwritten; conflicting suggestions remain available through **Use suggestion**. Confidence is review guidance only, and low-confidence or document-type mismatch warnings do not silently change the selected type or block manual upload. AI suggestions can be incorrect. Document AI does not update employee master data or automatically save/upload documents.

For multi-file uploads, Optional mode exposes **Extract all with AI** and Automatic mode starts eligible extraction automatically. Each file is copied to private temporary storage (`local` disk, not public `/storage`) and processed by its own bounded background job on the application queue (default `database`). The dialog polls for per-file progress while the batch is active, keeps completed suggestions independently reviewable, and allows failed items to be retried or completed manually without affecting successful files. Cancelling AI extraction does not remove upload drafts or block the existing Upload action. Late results after cancellation do not reactivate the batch.

Bulk extraction batches belong to the active company, initiating user, and employee (validated with `DocumentAccess` / `EmployeeVisibilityScope`). Temporary files and normalized results expire after **24 hours** (`expires_at`). The hourly `documents:cleanup-ai-batches` task:

- purges expired terminal batches
- skips expired batches that still have **recent** `processing` items (`started_at` within job timeout 45s + 120s grace)
- terminalizes **stale** `processing` items past that window, then purges
- sweeps orphan `document-ai-temp/{companyId}/{batchId}/` directories left by DB cascade deletes, but only after a **15-minute** grace window so in-flight batch creation (uncommitted DB row + written temp files) is not raced

Cleanup is idempotent. Provider credentials, raw responses, OCR text, and document PII are never stored in queued payloads or operational logs. Jobs resolve provider credentials server-side at execution time; if the provider or company mode is unavailable then, extraction fails safely. Bulk create accepts a client `batch_request_id` UUID for idempotency scoped to `company_id` + `user_id` + `employee_id` + `batch_request_id`. Ambiguous network retries should reuse the same UUID so the server returns the existing batch instead of spawning duplicates. Changing employee, clearing drafts, or abandoning the dialog resets that UUID because those are a new logical batch. Switching employee also clears still-AI-owned draft metadata (`ai_filled_fields`) so one employee's extracted values cannot be uploaded under another; manually edited values keep their AI marker cleared and remain. Item `attempts` increments on every claimed execution (including provider-unavailable checks) and is capped at 3 for both queue and manual Retry.

### Document AI operational notes

- **Deployment order:** deploy code → run migrations → restart queue workers so they load new job classes/tables → ensure the existing app scheduler continues to run (cleanup is registered in `routes/console.php`).
- **Queue:** uses the default queue connection (typically `database` on Hostinger). Workers must be running for bulk extraction. Timing: provider timeout 30s < job timeout 45s < database queue `retry_after` (default 660s).
- **Safe error categories** stored on items (never raw provider bodies): `provider_timeout`, `provider_rate_limited`, `provider_unavailable`, `invalid_output`, `unsupported_file`, `temporary_file_missing`, `cancelled`, `company_ai_disabled`, `extraction_failed`. Transient provider failures are classified from the exception chain via `DocumentAiProviderException` and use bounded backoff retries.
- **Throttles:** single extract `20/min`, bulk create `10/min`, retry `20/min`.
- **Retention:** 24 hours after batch creation; stale processing cannot retain private temp files indefinitely.
- **Cancellation:** closing the upload dialog, clearing files, or changing employee cancels/purges an active backend batch when possible. Late results for removed drafts are ignored.

Architecture:

```text
natural language
    → privacy guard (before any provider call)
    → bounded semantic criteria (AI language only)
    → Laravel validates the closed schema
    → trusted tenant-scoped master-data resolution
    → deterministic Employee Directory filters
```

The AI never defines a database column, relation, query expression, company ID, record ID, SQL, or arbitrary filter. Concepts and operators come from `EmployeeSmartSearchConceptRegistry`. Structured output is strict: every object property is required, `additionalProperties` is false, and semantically optional values use `null` rather than omitted keys. Top-level `criteria`, `ambiguous_terms`, and `unsupported_terms` always exist (`[]` when empty). Nested criterion objects always include `concept`, `operator`, and `value`. Laravel’s decoder enforces the same contract and fails closed on malformed output.

Resolved filters apply automatically through the existing Employee Directory query/filter pipeline (`useServerPaginationFilters` with a partial Inertia reload of list/tree props). Compact **Results filtered** chips show Smart Search-owned conditions. **Directory scope** shows the default Active HR-status constraint when the user did not ask for a status. Unresolved, ambiguous, and unsupported terms remain visible and do not block applied filters.

If nothing supported resolves (for example “who has Ford cars”), Smart Search does not re-apply directory filters merely to appear responsive. The UI states that no Smart Search filters were applied and the current list was not changed, then shows `Not supported yet · …`.

### Closed concepts

Exact-value concepts (equals): HR status, branch, department, position, nationality, rank, gender, visa type, sponsor, role, approval location, SSSA option, crew status.

Missing/present concepts: Emirates ID, nationality, date of birth, passport number, work email, personal email, composite **email**, phone, home-country phone, branch, department, position, rank, gender, visa type, sponsor, nearest airport, emergency contact, emergency phone, hire date, place of birth.

**Email** is composite and server-owned: missing means both work and personal email are absent; present means at least one exists. Display is `Email · Missing` unless the prompt asked for work or personal email specifically.

Generic completeness is stored as canonical `missing_fields` / `present_fields` CSV keys (never raw AI/user field names). Unknown keys fail closed and never broaden results. The Emirates ID Missing/Present dropdown is a convenience control mapped onto those keys. Missing a value is **not** the same as an incomplete profile; “incomplete employee profiles” is unsupported.

Intentionally excluded from Smart Search: salary, allowances, payroll, bank/IBAN, passwords, credentials, `company_id`, audit fields, arbitrary IDs, and **manager-by-person-name** (privacy: do not send person names to the provider; use the manual Manager filter). Phrases such as “employees under Ahmed”, “managed by Ahmed”, “reporting to Ahmed”, “employee named ahmed”, and “name is mohammed” are blocked **before** the provider is called. Category phrasing such as “employees under 30”, “employees without manager”, or “employees under Crewing department” is not treated as a named-person lookup.

Approval Location and SSSA option are multi-valued directory filters. When Smart Search resolves more than one trusted value, IDs accumulate into the existing CSV (`approval_location_id=1,2`) with unique IDs and stable numeric order. Single-valued concepts such as nationality, department, and rank still reject conflicting equals values.

### Language and trusted resolution

The model may normalize language (Indian employees, Indian country employees, employees from India → nationality India; Filipino → Philippines; department codes such as HR/CRW; rank abbreviations such as AB / Able Seaman). Laravel then resolves only against trusted active master data: exact name, exact code, or a small server-owned alias list. Numeric IDs from the model are ignored. Fuzzy similarity never auto-applies a candidate. Ambiguous matches are not guessed. “Working in UAE” is not silently mapped to nationality.

Crew status uses `EmployeeCrewStatusFilter` selectable labels (onboard, available, at home, join standby, and so on). Legacy travel in and ready to join are not normal Smart Search targets. The word “crew” by itself is not a crew status.

Contradictions (equals + missing, missing + present, two different equals on a single-valued concept) are not applied. Unsupported OR/negation is not guessed (`not active` is not `inactive`; `Indian or Filipino` is not reduced to one nationality).

### HR status: Active default vs All

A blank directory status still means **Active employees**. The Filters UI labels that option **Active (default)**, never **All**. `status=all` omits the HR-status predicate (All statuses). Explicit `active` / `inactive` / `on_leave` / `terminated` remain. Smart Search maps “all employees” / “regardless of status” to `all`, and does not treat “active and inactive” as `all`.

### Privacy guard

Before any provider call, `EmployeeSmartSearchPromptGuard` blocks obvious specific-person / identifier lookups (Emirates ID-looking values, email addresses, phone lookups, passport numbers after passport wording, employee-number wording, named/called person phrasing regardless of casing, and manager/reporting-to person phrasing). Category language such as “employees without email”, “missing Emirates ID”, “employees without manager”, or “employees under Crewing department” is not blocked. Blocked prompts return a validation message directing the user to regular Employee search; they are not logged or stored, and the AI fake/provider is never called.

Normal Employee search remains a separate deterministic field for name, employee number, email, and phone. It is not sent to the AI provider. Smart Search help text tells users which box to use.

### Ownership, reset, and Saved Views

Smart Search overwrites supported fields it resolved. Previous AI-owned filters are replaced as the query changes: a field omitted by a new interpretation is cleared only when the current value still equals the value Smart Search last applied. Manual overrides (Filters sheet, department tree, position, completeness chips) immediately drop Smart Search ownership for that key so stale chips disappear and are not reapplied while the prompt remains.

Reset Filters cancels debounce/in-flight work, clears the prompt, ownership, chips, warnings, and last-successful-prompt cache entry, and restores the default directory. Applying a Saved View does the same for Smart Search state; the Saved View becomes authoritative. The natural-language prompt is never stored in Saved Views, the URL, localStorage, or sessionStorage.

A working filter snapshot is updated **before** Inertia navigation so a second interpretation cannot be computed from stale server props.

In-memory interpretation cache is mounted-page-only, bounded (~20), and case/whitespace-normalized. Blocked privacy-sensitive prompts are not cached. Client AbortController does not cancel an already-running provider request; debounce, cache, and duplicate suppression remain the cost controls.

Pagination resets through the existing hook. Filter/search visits reload list, pagination, search, filters, and department-tree props; static option collections are lazy closures and are not rebuilt on every partial visit. Department-tree counts stay filter-dependent.

When no model is explicitly configured, Smart Search uses a fast provider-specific default (`gpt-5.6-luna` for OpenAI, `openai/gpt-5.6-luna` for OpenRouter). An administrator’s stored model still wins.

When Smart Employee Search is off, `POST /organization/employees/smart-search/interpret` reports that the feature is not enabled and does not call the provider. The interpretation route is rate limited (`throttle:30,1`). HTTP 429 shows a friendly message and does not auto-retry; a clean Retry-After cooldown suppresses further auto-search/Enter storms.

When it is on, Laravel reads the selected provider, optional stored model or fast default, and decrypted API key from these settings. After the privacy guard, the provider receives only:

- fixed interpreter instructions
- the user’s short search prompt

It does **not** receive employee rows, salaries, payroll, banking, passport/ID data, documents, current Employee Directory filters, `company_id`, or database credentials.

The interpreter still does not search employees. It only returns closed semantic criteria, which Laravel resolves into existing directory filters.

Malformed, empty, unstructured, or structurally incomplete provider output fails closed with HTTP 503 and `Employee smart search is temporarily unavailable.` It is never treated as a successful empty-filter result. Extra model fields are ignored and cannot become filters, database IDs, or SQL. The frontend also rejects malformed HTTP 200 payloads without changing filters.

## Test connection

`POST /settings/application/ai/test` probes the last saved selected provider with a tiny structured “OK” request. It does not accept a provider or API key from the client. Failures return a generic validation error without provider payloads or secrets. The route is rate limited (`throttle:6,1`).

## Permissions

| Action | Authority |
|--------|-----------|
| View AI settings | `platform:view` |
| Update AI settings / credentials | `platform:manage` + `privileged.2fa` |
| Test selected provider | `platform:manage` |
| Use Smart Employee Search | `employees.view` (existing) |

Frontend `can` flags are UX only. Backend middleware is authoritative.

Platform AI activity uses `log_name` `platform` and `scope` `platform`. It is not tenant-owned. `audit.view` still gates the company Activity Log; that page only lists rows for the active `company_id`.
