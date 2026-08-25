# AI settings

Platform AI providers and Smart Employee Search are configured in **Settings → Application → AI**.

This is installation-wide configuration. The API account and billing belong to OMS-HRM, not to a company. Per-company AI credentials are not supported.

## Routes

| Method | Path | Name |
|--------|------|------|
| PUT | `/settings/application/ai` | `application.ai.update` |
| POST | `/settings/application/ai/test` | `application.ai.test` |

Controller: `App\Http\Controllers\Settings\ApplicationSettingsController`

## What administrators can manage

- Enable or disable Smart Employee Search
- Select **OpenAI** or **OpenRouter**
- Store an encrypted API key for each provider
- Optionally set a model name for each provider (leave blank to use the Laravel AI SDK / provider default)
- Test the **currently saved** selected provider

Normal administration does **not** require editing `.env` or redeploying. Stored Application Settings are authoritative. `EMPLOYEE_SMART_SEARCH_ENABLED`, `OPENAI_API_KEY`, and `OPENROUTER_API_KEY` remain optional bootstrap fallbacks only when no stored value exists. Database settings always win. PHP never writes to `.env`.

## Credentials

- API keys are encrypted at rest (`ai_openai_api_key`, `ai_openrouter_api_key`)
- Inertia props expose `has_api_key` flags and models only — never decrypted or masked keys
- Blank key fields preserve the stored secret
- Switching the selected provider keeps both providers’ stored credentials
- Credential updates require `platform:manage` and `privileged.2fa`

## Smart Employee Search

When Smart Employee Search is off in this UI, `POST /organization/employees/smart-search/interpret` reports that the feature is not enabled and does not call the provider.

When it is on, Laravel reads the selected provider, optional model, and decrypted API key from these settings, then interprets the user’s short prompt into existing Employee Directory filters. The provider receives only:

- fixed interpreter instructions
- the user’s short search prompt

It does **not** receive employee rows, salaries, payroll, banking, passport/ID data, documents, `company_id`, or database credentials.

The interpreter still does not search employees; it only returns filters for a later Employee Directory UI.

## Test connection

`POST /settings/application/ai/test` probes the last saved selected provider with a tiny structured “OK” request. It does not accept a provider or API key from the client. Failures return a generic validation error without provider payloads or secrets.

## Permissions

| Action | Authority |
|--------|-----------|
| View AI settings | `platform:view` |
| Update AI settings / credentials | `platform:manage` + `privileged.2fa` |
| Test selected provider | `platform:manage` |
| Use Smart Employee Search | `employees.view` (existing) |

Frontend `can` flags are UX only. Backend middleware is authoritative.
