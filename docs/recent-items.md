# Recent items

Users can reopen **recently viewed business records** from Cmd/Ctrl+K. Recent Items are personal UX state for the authenticated user and active company. They are **not** navigation Favorites, **not** audit history, and **not** a permission grant.

Favorites remain explicit module/destination pins. Recent Items remember show pages the user actually opened. Named list filters are [Saved views](./saved-views.md), a separate control on selected index pages.

## Supported record types

| Type | Show page | View permission |
|------|-----------|-----------------|
| Employee | Employee show | `employees.view` |
| Document | Employee document show | `documents.view` |
| Crew assignment | Crew assignment show | `crew_operations.assignments.view` |
| Vessel | Vessel show | `crew_operations.vessels.view` |
| Payroll period | Payroll period show | `payroll.periods.view` **or** `payroll.crew_timesheets.view` |

Only these catalog values are stored (`employee`, `document`, `crew_assignment`, `vessel`, `payroll_period`). Clients cannot submit a model class name, href, or extra record type.

Departments, positions, settings, list pages, search hits, edit sheets, and exports are not tracked.

## Persistence

Rows live on `recent_items`: `user_id`, `company_id`, `record_type`, `record_id`, `last_viewed_at`. Unique per user + company + type + record. Maximum **25** rows per user per company; opening another record drops the oldest for that pair.

Display fields (names, document titles, payroll amounts, statuses) are **not** snapshotted. Cmd+K resolves current labels from live records using the same safe fields as [global search](./global-search.md).

Reopening the same record updates `last_viewed_at` and does not create a duplicate. Tracking is not written to the activity log.

## Tracking

A row is written only after a **successful authorized show**: tenant checks passed, the user can view the record, and the response is the show page (not a 403/404, not a payroll payslip poll partial, not a list).

## Tenancy and permissions

History is scoped to the authenticated user and `current_company_id` from `SetCurrentCompany`. Switching companies changes which recents appear; it does not delete the other company's history.

Stored recents never grant access. The list endpoint hides a row when:

- the active company does not match
- the matching view permission is missing
- the record is gone / soft-deleted / no longer in the company

Missing permission hides the row without deleting it, so restoring the permission can show it again. Missing records are omitted and the stale row is deleted so Cmd+K never offers a broken link.

`platform_access` does not reveal tenant recents.

## Cmd/Ctrl+K

Recent Items are loaded once when the palette opens (`GET /recent-items`), not on every keystroke.

| Query | Surface |
|-------|---------|
| Empty | Favorites, Recent, Commands |
| 1 character | Favorites, Commands |
| 2+ characters | Matching favorites, matching recents, live record search, matching commands |

An empty recent list does not render a Recent heading. Mobile uses the same command dialog.

Recent Items are **not** added to the sidebar.

## Routes

| Method | Path | Name |
|--------|------|------|
| GET | `/recent-items` | `recent-items` |

Authenticated + verified. `user_id`, `company_id`, `record_type`, and `record_id` query parameters are prohibited.
