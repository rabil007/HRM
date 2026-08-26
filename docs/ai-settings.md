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

When the feature is enabled, **Smart Search Beta** appears on **Organization → Employees**. It is hidden entirely when the setting is off; the Employee Directory does not show a disabled AI panel.

Smart Search is a fast, automatic search box. Interpretation runs after a short debounce (~450ms) while typing, or immediately when the user presses Enter. There is no Interpret button and no Apply Filters confirmation. The request is not made on page load, on every keystroke, or for an empty value. Prompts of two or more characters (for example `AB`) remain valid.

Resolved filters apply automatically through the existing Employee Directory query/filter pipeline (`useServerPaginationFilters`). Compact chips communicate what was applied. Unresolved values and unsupported terms remain visible and do not block applied filters.

Smart Search overwrites supported fields it resolved (`status`, `department_id`, `position_id`, `nationality_id`, `rank_id`, `crew_status`, `emirates_id_presence`). Previous AI-owned filters are replaced as the query changes: a field omitted by a new interpretation is cleared only when the current value still equals the value Smart Search last applied. Manual filters, including values the user changed after Smart Search applied them, are preserved. Unrelated existing manual filters and the normal Employee text search remain preserved.

Department and Position are dependency-aware: changing Department via Smart Search clears a stale Position unless a new Position was also resolved, and resolving Position without Department clears a stale Department constraint.

Clearing the Smart Search input cancels pending work, does not call the provider, and removes still-AI-owned filters while preserving manual filters and normal text search.

Normal Employee search remains a separate deterministic field for name, employee number, email, and phone. It is not sent to the AI provider.

Emirates ID completeness is a real directory filter (`emirates_id_presence=missing|present`). It is available in Employee Filters as Missing/Present, may appear in the URL and Saved Views, and treats NULL, empty, and whitespace-only Emirates ID values as missing. Smart Search can request that presence filter from natural language. Actual Emirates ID numbers are not sent to the provider and cannot become a lookup filter.

Pagination resets through the existing hook. The prompt itself is not stored in the database, localStorage, sessionStorage, URL parameters, or Saved Views. In-memory interpretation cache is only for the current mounted page session.

When no model is explicitly configured, Smart Search uses a fast provider-specific default (`gpt-5.6-luna` for OpenAI, `openai/gpt-5.6-luna` for OpenRouter). An administrator’s stored model still wins. Employee Directory users do not see provider or model details.

When Smart Employee Search is off in this UI, `POST /organization/employees/smart-search/interpret` reports that the feature is not enabled and does not call the provider. The interpretation route is rate limited (`throttle:30,1`).

When it is on, Laravel reads the selected provider, optional stored model or fast default, and decrypted API key from these settings, then interprets the user’s short prompt into existing Employee Directory filters. The provider receives only:

- fixed interpreter instructions
- the user’s short search prompt

It does **not** receive employee rows, salaries, payroll, banking, passport/ID data, documents, current Employee Directory filters, `company_id`, or database credentials.

The interpreter still does not search employees. It only returns existing directory filter values, which the Employee Directory UI applies automatically.

Malformed, empty, or unstructured provider output fails closed with HTTP 503 and `Employee smart search is temporarily unavailable.` It is never treated as a successful empty-filter result. Extra model fields are ignored and cannot become filters, database IDs, or SQL.

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
