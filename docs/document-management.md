# Document management

Employee documents are stored per company and linked to employees. HR can browse by folder, manage files on the employee profile, track expiry, and measure **required-document compliance** (valid, expiring, expired, missing).

## Routes

| Path | Purpose | Permission |
|------|---------|------------|
| `/organization/documents` | Folder index + global search | `documents.view` |
| `/organization/documents/employees/{employee}` | Employee document browse | `documents.view` |
| `/organization/employees/{employee}` (Documents tab) | Upload, edit, versions on profile | `documents.view` / `documents.upload` / `documents.delete` |

Upload and CRUD on the profile use `organization.employees.documents.*` routes.

## Data model

- Table: `employee_documents`
- Model: `App\Models\EmployeeDocument`
- Key fields: `document_type_id`, `title`, `original_filename`, `file_path`, `issue_date`, `expiry_date`, `document_number`, `status`, `mime_type`, `size_bytes`
- Document type labels come from `document_types` or legacy `document_type` / `type` fields
- Company requirement policy: `document_requirements` plus `document_requirement_department` / `_position` / `_rank` / `_project` (see [Document requirement policy](#document-requirement-policy))

## Index page (folders)

**Default view:** grid of employee folders (only employees who have at least one document).

Each folder shows:

- Employee name and number
- File count badge
- Link to employee document browse
- Optional bulk ZIP download (`documents.download`)

**Summary cards** (one row on the Documents index):

- Total documents
- Expired
- Expiring in 30 / 15 / 7 days
- Missing (required-document compliance)

These operational counts, the folder grid, compliance table, and global search include **active employees only**. Per-employee browse and the profile Documents tab remain available for inactive/terminated employees. See [Active employee visibility](./architecture/active-employee-visibility.md).

Clicking an expiry card switches to a **compliance table** filtered by that bucket (server-side, paginated). Missing is calculated from the active company's document requirement policies (see [Document requirement policy](#document-requirement-policy)). Clicking it opens employee × required document type rows that are currently missing. Active employees with **zero uploaded files** still appear here when they are missing a required document. Missing is calculated state — there is no `employee_documents` row for it. The employee browse page keeps the five expiry cards only.

Saved views on Documents may include `requirement_status` (`required`, `valid`, `expiring`, `expired`, `missing`) alongside `search`, `expiry`, and `department_id`. Valid / expiring / expired / required remain available as URL or saved-view filters; they are not shown as cards.

## Employee browse page

Path: `/organization/documents/employees/{id}`

- Breadcrumb: Documents → Employee name
- Same expiry summary cards (scoped to that employee)
- File table: name, type, document number, issue/expiry, size, status, uploaded by
- Client-side filter by file search and expiry on the loaded set
- Bulk actions (when permitted): download ZIP, merge PDF, email, WhatsApp share links, delete

## Employee profile (Documents tab)

Path: `/organization/employees/{id}#documents`

- **Required Documents** block when the employee currently matches at least one active company requirement: type, status (valid / expiring / expired / missing), and actions (Upload for missing, View, Replace for expired)
- Missing Upload reuses the existing upload dialog and preselects that document type
- Table of uploaded files with type, title, **number**, issue, expiry, status
- Upload dialog and edit dialog (including document number)
- Version history endpoint for replacements
- Historical uploaded files remain even if the type is no longer required for this employee

## Employee profile templates and create employee

Employee profile templates can require documents with optional metadata:

- `ask_issue_date`
- `ask_expiry_date`
- `ask_document_number`

Templates are managed at `/organization/templates/employee-profile`. The create employee flow
(`/organization/employees/create`) persists uploads through the same document storage pipeline.

Template field requirements control **form fields** on create/edit. They are not the same as ongoing document compliance (see [Template fields vs document compliance](#template-fields-vs-document-compliance)). Required-for-compliance does **not** block employee creation.

## Expiry and status

- `App\Support\EmployeeDocuments\DocumentExpiry` resolves display status: valid, expiring windows, expired, or no expiry
- Persisted `status` on the model is derived when saving

## Private file storage

Employee documents, document versions, training certificates, and certificate versions are stored on Laravel's private `local` disk (`storage/app/private`). They are not written with `storePublicly()` and Inertia props do not expose `/storage/...` URLs.

Downloads and previews go through authenticated application routes (`organization.documents.files.*` and `organization.employees.training.certificate*`). Those routes check the active `company_id` and the matching document or training permission. Legacy files that still exist on the public disk remain readable **only** through those same controllers until they are migrated.

Move existing public files without a database migration:

```bash
php artisan employee-files:migrate-to-private --dry-run
php artisan employee-files:migrate-to-private
```

The command copies each company-prefixed path to private storage, verifies the destination, then deletes the public copy. It is idempotent and does not print filenames.

Exit code is non-zero when a copy fails, a skipped local row still has a public leftover, or unreferenced files remain under `employee-documents/{companyId}/` or `employees/{companyId}/training-certificates/`. Remote URLs and empty paths are safe skips. Invalid prefixes are reported and never deleted. Orphan public files in those prefixes are reported for manual review and are not deleted.

## Document requirement policy

HR configures compulsory document types on the existing **Settings → Master Data → Document Types** sheet. There is no separate Document Requirements menu.

`DocumentType` remains global master data (title + active). Requirement **policy** is company-scoped (`document_requirements`): one policy per company per document type.

A type may be:

- Optional (no active policy, or an inactive stored policy)
- Required for all employees in the active company
- Required for selected groups: departments, positions, ranks, and/or projects

Implemented scopes are **all employees**, **department**, **position**, **rank**, and **project**. Client, nationality, visa type, branch, vessel, and per-employee exceptions are not implemented.

### Matching

Selected organizational scopes use **AND** matching **between** categories and **OR** matching **within** a category.

For every scope category that contains one or more selected values, the employee must match at least one value from that category. Categories with no selections are ignored.

Example:

- Department: Crew
- Rank: Captain, Chief Engineer
- Project: ADNOC, ARAMCO

means:

Department = Crew
**AND**
Rank = Captain **OR** Chief Engineer
**AND**
Project = ADNOC **OR** ARAMCO

An employee in Crew with rank Captain assigned to Project XYZ is **not** required. Empty Position (and any other unselected category) imposes no restriction.

A selected category does not match when the employee value is `NULL` (for example, Project = ADNOC and `employee.project_id` is empty).

`required_for_all` applies to every operational (active) employee in the company, regardless of department, position, rank, or project. It bypasses selected-group matching.

Requirements are resolved dynamically from the employee’s current company, department, position, rank, and `project_id`. Changing those attributes changes currently applicable requirements. Policies are not snapshotted onto the employee. Historical uploads are never deleted when requirements change.

Projects are **global** master data in the current schema (`projects` has no `company_id`). Project scope uses the employee’s current `project_id` and the `document_requirement_project` pivot, but the DocumentRequirement **policy** remains company-scoped. Company B employees assigned to the same global Project do not inherit Company A’s policy.

Inactive document types are ignored. Soft-deleting a document type does not delete employee files or destroy the stored company policy; reactivating the type restores the previous policy unless it was edited. The list Active toggle and CSV import update only `title` / `is_active` and do not erase requirement configuration.

### Requirement metadata

Each company policy may flag:

- Require issue date
- Require expiry date
- Require document number

V1 uses these as stored policy metadata. Compliance status does **not** treat missing issue date / number as `missing`. Upload/create forms continue to follow employee profile template field configuration.

### Authorization and tenancy

Requirement configuration reuses document type permissions:

- `settings.master-data.document-types.view`
- `settings.master-data.document-types.create`
- `settings.master-data.document-types.update`
- `settings.master-data.document-types.delete`

Company ID always comes from trusted `current_company_id`. Department and position IDs must belong to the active company. Ranks and Projects remain global master data. Company A cannot attach Company B organization IDs or overwrite Company B’s policy.

Documents index / profile compliance viewing still requires `documents.view`. Upload and replace still require `documents.upload`. Frontend `can` flags are UX only.

Meaningful policy changes are activity-logged with a single company-aware phrase such as `Passport: Optional → Required for all employees`. Pivot (department / position / rank / project) changes are included in that phrase. Metadata-only edits use `required information updated`. Generic Spatie attribute dumps are not written for the same policy mutation. Audit rows are visible only with `audit.view`.

## Compliance calculation

For each required document type and employee, exactly one status is calculated:

| Status | Meaning |
|--------|---------|
| `missing` | No current `EmployeeDocument` exists for that `document_type_id` |
| `expired` | Latest upload exists and `DocumentExpiry` resolves to expired |
| `expiring` | Latest upload exists and is in the existing 30 / 15 / 7-day window |
| `valid` | Latest upload exists and is neither expired nor currently expiring (including no expiry date) |

The canonical latest upload is **`created_at DESC`, then `id DESC`** (the same rule as `EmployeeDocument::latestUpload()`). Documents index bulk compliance and the employee profile Required Documents block both use `LatestEmployeeDocumentQuery` so they cannot disagree when IDs and timestamps are out of order (imports, restores, backfills). Equal `created_at` values are broken by the highest `id`. An older superseded file does not satisfy the requirement.

`MAX(id)` is not the latest-document rule.

Optional types are never reported as missing.

Operational compliance (Documents index counts and tables) includes **active employees only**. Inactive and terminated people remain reachable from their employee record / per-employee document browse. See [Active employee visibility](./architecture/active-employee-visibility.md).

### Unmapped legacy `document_type_id`

Older `employee_documents` rows may still have `document_type_id = NULL` when a historical string `document_type` could not be matched to a `DocumentType`. Compliance only recognizes `employee_documents.document_type_id =` the required type ID. Those unmapped rows **do not** satisfy a requirement (the pair stays `missing`) until they are deterministically mapped. Runtime compliance does not fuzzy-match titles or slugs.

`DocumentType` currently has a title (no slug). A leftover slug-like string such as `passport-copy` is unmatched unless it exactly equals a current title after trim/case normalization.

Inspect and repair with:

```bash
php artisan employee-documents:audit-unmapped-types
php artisan employee-documents:audit-unmapped-types --company=1
php artisan employee-documents:backfill-document-types --dry-run
php artisan employee-documents:backfill-document-types --dry-run --company=1
php artisan employee-documents:backfill-document-types
php artisan employee-documents:backfill-document-types --company=1
```

Rules:

- The audit command is read-only. It prints counts by company and distinct legacy `document_type` values, plus whether a deterministic match exists. It does not print file paths, notes, or file contents.
- Backfill maps a row only when there is **one** exact normalized match (`mb_strtolower(trim(...))`) against `DocumentType.title`, and against `slug` only if that column still exists.
- `--dry-run` reports what would change and writes nothing.
- Ambiguous keys (two types that normalize to the same title, or a title/slug collision) and unmatched values are left unchanged.
- Rows that already have `document_type_id` are not selected. Re-running the command is safe.
- `--company=` must be an existing company ID. Unknown or non-numeric values fail without writing.
- Do not treat this as a substitute for mapping; compliance will keep reporting `missing` for leftover NULL `document_type_id` rows.

## Template fields vs document compliance

| | Employee profile template | Document requirement policy |
|--|---------------------------|-----------------------------|
| Purpose | Which fields appear / are required on employee forms and the Documents tab uploader | Which document types an employee must currently hold |
| Scope | Template configuration | Active company + department / position / rank / project |
| Blocks employee create? | Template required fields on the create form | No |
| Missing file | N/A (form field) | Compliance status `missing` |

Do not merge the two. If both mention issue date / expiry / document number, the **template** still drives the upload form in V1. Requirement metadata is stored for policy and future enforcement.

## V1 exclusions

Not implemented: a separate requirements page, individual exceptions/waivers, approval of exceptions, client/branch/nationality/visa/vessel/crew-assignment scopes, automatic email / WhatsApp / Web Push reminders for missing required documents, employee self-service upload, blocking employee creation / payroll / crew mobilisation, OCR, or AI classification.

## Backend services

| Class | Role |
|-------|------|
| `DocumentBrowseQuery` | Folders, expiry compliance list, search results, summaries |
| `DocumentRequirementResolver` | Which active company policies apply to an employee (AND between selected categories; OR within a category) |
| `DocumentComplianceQuery` | Required / valid / expiring / expired / missing pairs without N+1 |
| `LatestEmployeeDocumentQuery` | Canonical latest upload per employee + type (`created_at DESC`, `id DESC`) |
| `UnmappedEmployeeDocumentTypeMatcher` | Deterministic audit/backfill of NULL `document_type_id` rows |
| `SyncDocumentRequirement` | Transactional company policy create/update + one human-readable audit event |
| `StoresEmployeeDocument` | Create/replace on the private disk |
| `EmployeePrivateFile` | Private-disk store/resolve with public fallback |
| `DocumentPagePermissions` | Maps `documents.*` to Inertia `can` props |

## Tests

- `tests/Feature/Settings/MasterData/DocumentRequirementTest.php`
- `tests/Feature/Organization/DocumentRequirementComplianceTest.php`
- `tests/Feature/Organization/UnmappedEmployeeDocumentTypeCommandTest.php`
- `tests/Unit/Support/DocumentRequirementResolverTest.php`
- `tests/Unit/Support/LatestEmployeeDocumentQueryTest.php`
- `tests/Feature/Organization/DocumentBrowseTest.php`
- `tests/Feature/Organization/EmployeeDocumentsTest.php`
- `tests/Feature/Organization/EmployeePrivateFileStorageTest.php`
- `tests/Feature/Organization/MigrateEmployeeFilesToPrivateCommandTest.php`
- `tests/Feature/Organization/DocumentShareTest.php`

See [Document search](./document-search.md) and [Document sharing](./document-sharing.md) for specialized flows.
