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
- Optionally set a model name for each provider (leave blank to use the Laravel AI SDK / provider default)
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

Interpretation happens only on explicit submit (Interpret or Enter). There is no request on page load, while typing, or from a debounce. The user previews resolved labels, unresolved values, and unsupported terms. Filters are not applied until **Apply Filters**.

Apply Filters reuses the existing Employee Directory query/filter pipeline (`useServerPaginationFilters`). Smart Search overwrites only supported fields it resolved (`status`, `department_id`, `position_id`, `nationality_id`, `rank_id`, `crew_status`). Unrelated existing manual filters and the normal text search remain preserved. Department and Position are dependency-aware: changing Department via Smart Search clears a stale Position unless a new Position was also resolved, and resolving Position without Department clears a stale Department constraint. Pagination resets through that existing hook. The prompt itself is not stored in the database or localStorage, and it is not included in Employee Directory URL parameters or Saved Views.

When Smart Employee Search is off in this UI, `POST /organization/employees/smart-search/interpret` reports that the feature is not enabled and does not call the provider.

When it is on, Laravel reads the selected provider, optional model, and decrypted API key from these settings, then interprets the user’s short prompt into existing Employee Directory filters. The provider receives only:

- fixed interpreter instructions
- the user’s short search prompt

It does **not** receive employee rows, salaries, payroll, banking, passport/ID data, documents, current Employee Directory filters, `company_id`, or database credentials.

The interpreter still does not search employees. It only returns existing directory filter values for the Employee Directory UI to preview and apply.

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
