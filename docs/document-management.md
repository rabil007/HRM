# Document management

Employee documents are stored per company and linked to employees. HR can browse by folder, manage files on the employee profile, track expiry, and measure **required-document compliance** (valid, expiring, expired, missing).

## Unified Documents module

Documents is one sidebar group with these destinations:

| Path | Section | Reuses | Permission |
|------|---------|--------|------------|
| `/organization/documents` | Overview | Compact summary of existing document counts | `documents.view` |
| `/organization/documents/library` | Library | Canonical browse / search / compliance workspace | `documents.view` |
| `/organization/documents/generate` | Generate & Send | Current Bulk Documents roster | `bulk_documents.view` |
| `/organization/documents/requests` | Requests | Current bulk signature-request view | `bulk_documents.view` |
| `/organization/documents/templates` | Templates | Company custom and system generation templates | Any of `documents.templates.view`, `bulk_documents.view`, `settings.master-data.document-types.view`, or platform view |
| `/organization/documents/activity` | Activity | Current bulk generation history | `bulk_documents.view` |

**Overview** is a lightweight operational dashboard. It shows expiry and required-document counts, needs-attention actions, and permission-aware shortcuts. It does not render the document table, folder grid, search, or Saved Views. Summary cards and attention actions open Library with the matching supported filter (`expiry=expired`, `expiry=expiring_7` / `expiring_15` / `expiring_30`, `requirement_status=missing`). Overview never applies a default Documents Saved View.

**Library** is the canonical browsing workspace: employee folders, global search, expiry / required-document / department filters, Saved Views, pagination, tables, and bulk file/folder actions. `DocumentsFolderIndexController` serves Library only. Saved Views keep the `documents` page key and apply on Library (`organization.documents.library`). Applying or defaulting a Documents Saved View from Library stays on Library.

Opening a document from Library uses `from=library` so **Back to Library** restores supported list state: `search`, `expiry`, `requirement_status`, `department_id`, and `page` (validated with the current filter rules). Overview document links use `from=index` (**Back to documents**). Employee-folder back stays **Back to files**. Employee-profile back stays **Back to employee profile**. Query `company_id` is ignored for ownership; list/controller tenant scoping remains authoritative. Employee browse URLs (`/organization/documents/employees/{employee}` and nested files) resolve to the Library favorites destination, not Overview.

Old filtered Overview bookmarks such as `/organization/documents?search=`, `?expiry=`, `?requirement_status=`, `?department_id=`, and `?page=` redirect to the equivalent Library URL with those supported keys preserved. Unknown parameters are not redirected and are not copied. Plain `/organization/documents` stays Overview.

Generate, Requests, and Activity share `BulkDocumentsController`. Explicit module routes set a `module_view` route default (`roster` / `signatures` / `history`). That value is resolved before the legacy `view` query string. Those flows, and the Templates bridge, are unchanged in this phase. Protected Salary Declaration / Salary Certificate PDF layout, Browsershot, Puppeteer, FPDI stamping, and the public e-sign workflow are unchanged.

### Legacy Bulk URLs

These remain valid GET bookmarks and keep their existing route names. POST/PUT/DELETE bulk action routes are unchanged.

| Legacy URL | Active module section |
|------------|------------------------|
| `/organization/documents/bulk` | Generate & Send |
| `/organization/documents/bulk?view=signatures` | Requests |
| `/organization/documents/bulk?view=history` | Activity |

The standalone **Bulk generate** sidebar item is removed. Favorites key `documents.bulk` now points at Generate & Send.

### Document Templates

Documents → Templates serves as the centralized company custom document template management area while preserving protected system generation templates:

1. **Company Custom Templates** (`document_generation_templates`):
   - Scoped to the active company.
   - Lifecycle statuses: `draft`, `active`, `inactive`.
   - Permissions: `documents.templates.view`, `documents.templates.create`, `documents.templates.update`, `documents.templates.delete`.
   - Optional association to `document_types` for categorization.
   - Managed via right-side form sheet with interactive merge field insertion.
   - Duplication creates a company-scoped copy starting in `draft` with a unique `(Copy)` suffix.
   - Preview system renders safe in-memory HTML preview with sample data only (real-employee preview is intentionally deferred). No database side-effects or workflow triggers.
   - **Allowed Merge Fields**: Strict allowlist catalog (`App\Support\Documents\DocumentTemplateMergeFields`) covering:
     - *Employee*: `{{employee_name}}`, `{{employee_no}}`, `{{first_name}}`, `{{last_name}}`, `{{email}}`, `{{phone}}`, `{{gender}}`, `{{joining_date}}`
     - *Organization*: `{{company_name}}`, `{{department_name}}`, `{{position_name}}`, `{{branch_name}}`
     - *System*: `{{today}}`, `{{current_year}}`
     - Sensitive fields (bank/IBAN, salary, passport number, Emirates ID, credentials) are strictly forbidden. Content with unsupported placeholders is rejected at validation.

2. **System generation templates** from `BulkDocumentTypeRegistry` (Salary Declaration, Salary Certificate):
   - Protected application renderers used by Generate & Send.
   - Layout is code-owned and not editable from this UI.

3. **Configuration shortcuts**:
   - Link to **Settings → Master Data → Document Types** when user has `settings.master-data.document-types.view`.
   - Link to **Settings → Application → signature placement** when user has platform view.

## Routes

| Path | Purpose | Permission |
|------|---------|------------|
| `/organization/documents` | Documents Overview (summary dashboard) | `documents.view` |
| `/organization/documents/library` | Documents Library (browse / search / compliance) | `documents.view` |
| `/organization/documents/generate` | Generate & Send (bulk roster) | `bulk_documents.view` |
| `/organization/documents/requests` | Signature requests | `bulk_documents.view` |
| `/organization/documents/templates` | Custom and System Document Templates | `documents.templates.view` \| `bulk_documents.view` \| `settings.master-data.document-types.view` \| platform view |
| `/organization/documents/templates` (POST) | Store custom document template | `documents.templates.create` |
| `/organization/documents/templates/preview-draft` (POST) | Render preview for unsaved draft | `documents.templates.create` \| `documents.templates.update` |
| `/organization/documents/templates/{template}/preview` (GET) | Render preview for saved template | `documents.templates.view` |
| `/organization/documents/templates/{template}` (PUT) | Update custom document template | `documents.templates.update` |
| `/organization/documents/templates/{template}/duplicate` (POST) | Duplicate custom template in company | `documents.templates.update` |
| `/organization/documents/templates/{template}` (DELETE) | Delete custom template | `documents.templates.delete` |
| `/organization/documents/activity` | Bulk generation history | `bulk_documents.view` |
| `/organization/documents/bulk` | Legacy Bulk Documents index | `bulk_documents.view` |
| `/organization/documents/employees/{employee}` | Employee document browse | `documents.view` |
| `/organization/employees/{employee}` (Documents tab) | Upload, edit, versions on profile | `documents.view` / `documents.upload` / `documents.delete` |

Upload and CRUD on the profile use `organization.employees.documents.*` routes.

## Data model

- Table: `employee_documents`
- Model: `App\Models\EmployeeDocument`
- Key fields: `document_type_id`, `title`, `original_filename`, `file_path`, `issue_date`, `expiry_date`, `document_number`, `status`, `mime_type`, `size_bytes`
- Document type labels come from `document_types` or legacy `document_type` / `type` fields
- Company requirement policy: `document_requirements` plus `document_requirement_department` / `_position` / `_rank` / `_project` (see [Document requirement policy](#document-requirement-policy))

## Library (folders)

**Default view:** grid of employee folders (only employees who have at least one document). Path: `/organization/documents/library`.

Each folder shows:

- Employee name and number
- File count badge
- Link to employee document browse
- Optional bulk ZIP download (`documents.download`)

**Summary cards** (one row on Library; Overview uses the same counts as links into Library):

- Total documents
- Expired
- Expiring in 30 / 15 / 7 days
- Missing (required-document compliance)

These operational counts, the folder grid, compliance table, and global search include **active employees only**. Per-employee browse and the profile Documents tab remain available for inactive/terminated employees. See [Active employee visibility](./architecture/active-employee-visibility.md).

Clicking an expiry card switches to a **compliance table** filtered by that bucket (server-side, paginated). Missing is calculated from the active company's document requirement policies (see [Document requirement policy](#document-requirement-policy)). Clicking it opens employee × required document type rows that are currently missing. Active employees with **zero uploaded files** still appear here when they are missing a required document. Missing is calculated state — there is no `employee_documents` row for it. The employee browse page keeps the five expiry cards only.

Saved views on Documents belong to Library and may include `requirement_status` (`required`, `valid`, `expiring`, `expired`, `missing`) alongside `search`, `expiry`, and `department_id`. Valid / expiring / expired / required remain available as URL or saved-view filters; they are not shown as cards.

## Employee browse page

Path: `/organization/documents/employees/{id}`

- Breadcrumb: Documents (Library) → Employee name
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
| `DocumentsOverviewQuery` | Overview counts and needs-attention items from existing browse/compliance summaries |
| `DocumentsLibraryQueryState` | Sanitize supported Library query keys for redirects, back-navigation, and Library |
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
- `tests/Feature/Organization/DocumentsModuleTest.php`
- `tests/Unit/Support/DocumentsModuleAccessTest.php`
- `tests/Feature/Organization/DocumentBrowseTest.php`
- `tests/Feature/Organization/EmployeeDocumentsTest.php`
- `tests/Feature/Organization/EmployeePrivateFileStorageTest.php`
- `tests/Feature/Organization/MigrateEmployeeFilesToPrivateCommandTest.php`
- `tests/Feature/Organization/DocumentShareTest.php`

See [Document search](./document-search.md) and [Document sharing](./document-sharing.md) for specialized flows.
