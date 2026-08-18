# Saved views

Users can save **named filter combinations** on selected server-driven list pages and restore them later. Saved Views are personal productivity state for the authenticated user and active company. They are **not** a permission grant, **not** navigation Favorites, and **not** Recent Items.

Favorites remain module shortcuts. Recent Items remember show pages. Saved Views remember list filters.

This phase does **not** store arbitrary URLs, add Saved Views to Cmd/Ctrl+K, or share views across users.

## Supported pages

| Page key | List | View permission | Filter keys |
|----------|------|-----------------|-------------|
| `employees` | Employees | `employees.view` | `search`, `status`, `department_id`, `position_id`, `manager_id`, `gender_id`, `nationality_id`, `visa_type_id`, `company_visa_type_id`, `rank_id`, `approval_location_id`, `sssa_option_id`, `crew_status`, `role_id` |
| `documents` | Documents | `documents.view` | `search`, `expiry`, `department_id` |
| `crew` | Crew Assignments | `crew_operations.assignments.view` | `search`, `phase`, `status`, `vessel_id`, `rank_id`, `client_id`, `employee_id`, date ranges, tour/relief flags, optional `view` |
| `leave` | Leave requests | `attendance.leave-requests.view` | `search`, `status`, `employee_id`, `leave_type_id`, `scope` |
| `payroll` | Payroll periods hub | `payroll.periods.view` **or** `payroll.crew_timesheets.view` | `search`, `category`, `status`, `date_from`, `date_to` |

Keys come from the current index query parameters. `branch_id` exists on the employee directory object but is not in the Employees UI, so it is not saved. Crew `sort` / `direction` / `per_page` / `page` are not saved.

Empty values and page defaults are omitted (`documents` `expiry=all`, `leave` `scope=my`, `crew` `view=crew`, false booleans).

## Persistence

Rows live on `saved_views`: `user_id`, `company_id`, `page_key`, `name`, `filters` JSON, `is_default`. Unique per user + company + page + name. Maximum **20** views per user per company per page. Names are at most **60** characters.

`user_id` and `company_id` always come from the authenticated user and `current_company_id`. Clients cannot submit them. Creating, renaming, or deleting a view is not written to the activity log.

## Applying a view

The control converts stored filters into the page’s normal query parameters and visits the existing index route. The list controller then runs its usual validation, permission, tenancy, pagination, and filtering. There is no second query engine.

Unknown historical keys are ignored when listing or applying so product changes cannot 500. Deleted related records use the list’s existing behavior (usually no matching rows).

## Default view

At most one default is kept per user + company + page. Visiting the base list with **no catalog query keys** redirects once to the same named route with the default filters. An explicit URL such as `/organization/employees?status=inactive` wins and is not overwritten.

## Tenancy and permissions

Views are scoped to the user and active company. Switching companies changes which views appear; it does not delete the other company’s rows. `platform_access` does not reveal tenant views.

A Saved View never grants the list permission. If that permission is later removed, the list (and therefore the control) is inaccessible; the row stays stored.

Tenant-owned filter IDs (`department_id`, `position_id`, `employee_id`, `manager_id`, `vessel_id`, `leave_type_id`, `role_id`) must belong to the active company when saving. Master/global IDs are existence-checked without treating them as tenant records.

## UI

A compact **Views** control sits next to Filters on the five pages (desktop SearchBar and the same Phase 3C mobile lists). Users can apply, save current, rename, delete, and set/clear default. There is no Saved Views navigation page and no sidebar list.

## Routes

| Method | Path | Name |
|--------|------|------|
| POST | `/saved-views` | `saved-views.store` |
| PUT | `/saved-views/{savedView}` | `saved-views.update` |
| DELETE | `/saved-views/{savedView}` | `saved-views.destroy` |

Authenticated + verified. `user_id`, `company_id`, `url`, `href`, `path`, and `query` are prohibited. Lists are passed as page-specific `saved_views` props, not shared on every Inertia request.
