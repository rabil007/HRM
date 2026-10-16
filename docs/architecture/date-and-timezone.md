# Date format and timezone

How OMS-HRM displays dates and resolves timezones without breaking company-local attendance, crew, or payroll logic.

## Hierarchy

| Concern | Source of truth |
| --- | --- |
| Date-only display format | Platform setting `SettingKey::DateFormat` → Inertia `settings.platform.default_date_format` |
| Org / tenant operational timezone | `companies.timezone` first |
| Platform fallback timezone | `SettingKey::Timezone` when company timezone is missing or invalid |
| Scheduler / `config('app.timezone')` | Infrastructure default; not a substitute for company timezone in tenant UI |

Shared wiring: `SharedSettingsPresenter`, `CompanyTimezone` (PHP), `useCompanyTimezone()` / `useFormatDate()` (JS).

## Allowed helpers

### Frontend

| Use case | Helper |
| --- | --- |
| React date-only display | `useFormatDate()` / `useDateFormat()` from `resources/js/lib/format-date.ts` |
| Pure / non-React formatting | `formatDisplayDate(value, format?)` — pass format; keep pure |
| Layout cache sync | `PlatformDateFormatSync` + `syncPlatformDateFormatCache` |
| Absolute ISO datetime (org screens) | `formatDisplayDateTime*InTimezone(..., useCompanyTimezone())` |
| Crew wall-clock local inputs | Existing company-timezone helpers in `resources/js/lib/company-timezone.ts` |
| Date inputs (when already used) | `AppDateField` — value remains ISO `Y-m-d` |

### Backend

| Use case | Helper |
| --- | --- |
| Inertia `*_formatted`, email, PDF, export | `App\Support\Settings\PlatformDateFormatter` |
| Company “today” / business boundaries | `App\Support\Settings\CompanyTimezone` |
| Storage / import / API / validation | ISO `Y-m-d` (and normal Carbon casting) |

## Anti-patterns

- Hardcoded `d-m-Y`, `d M Y`, or `toLocaleDateString()` for operational calendar dates
- Bare `formatDisplayDate(value)` in React components without going through `useFormatDate` / explicit format
- Browser-local `formatDisplayDateTime` for company attendance, leave, or activity ISO instants
- Forcing platform timezone onto companies that already set `companies.timezone`
- Changing storage, import templates, or `<input type="date">` values to platform display format
- Rewiring crew movement wall-clock, payroll period boundaries, or leave rollover year logic for display-only consistency

## Examples

- Leave request cards: `useFormatDate()` for date-only labels
- Attendance clock times: company-timezone datetime helpers with platform date portion
- Recruitment / dashboard / search presenters: `PlatformDateFormatter::make()->date()` / `dateTime()`
- Crew movement phases: follow [crew-movement-phases.md](./crew-movement-phases.md); do not double-convert wall-clock timestamps

## Cursor / AI reuse

Rule: [`.cursor/rules/date-timezone.mdc`](../../.cursor/rules/date-timezone.mdc)

Golden helpers row: [golden-files.md](./golden-files.md) (date / timezone helpers).
