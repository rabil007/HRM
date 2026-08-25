# Document management

Employee documents are stored per company and linked to employees. HR can browse by folder, manage files on the employee profile, and track expiry for compliance.

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

## Index page (folders)

**Default view:** grid of employee folders (only employees who have at least one document).

Each folder shows:

- Employee name and number
- File count badge
- Link to employee document browse
- Optional bulk ZIP download (`documents.download`)

**Expiry summary cards** (top of page):

- Total documents
- Expired
- Expiring in 30 / 15 / 7 days

These operational counts, the folder grid, compliance table, and global search include **active employees only**. Per-employee browse and the profile Documents tab remain available for inactive/terminated employees. See [Active employee visibility](./architecture/active-employee-visibility.md).

Clicking a card switches to a **compliance table** filtered by that bucket (server-side, paginated).

## Employee browse page

Path: `/organization/documents/employees/{id}`

- Breadcrumb: Documents → Employee name
- Same expiry summary cards (scoped to that employee)
- File table: name, type, document number, issue/expiry, size, status, uploaded by
- Client-side filter by file search and expiry on the loaded set
- Bulk actions (when permitted): download ZIP, merge PDF, email, WhatsApp share links, delete

## Employee profile (Documents tab)

Path: `/organization/employees/{id}#documents`

- Table with type, title, **number**, issue, expiry, status
- Upload dialog and edit dialog (including document number)
- Version history endpoint for replacements

## Employee profile templates and create employee

Employee profile templates can require documents with optional metadata:

- `ask_issue_date`
- `ask_expiry_date`
- `ask_document_number`

Templates are managed at `/organization/templates/employee-profile`. The create employee flow
(`/organization/employees/create`) persists uploads through the same document storage pipeline.

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

## Backend services

| Class | Role |
|-------|------|
| `DocumentBrowseQuery` | Folders, compliance list, search results, summaries |
| `StoresEmployeeDocument` | Create/replace on the private disk |
| `EmployeePrivateFile` | Private-disk store/resolve with public fallback |
| `DocumentPagePermissions` | Maps `documents.*` to Inertia `can` props |

## Tests

- `tests/Feature/Organization/DocumentBrowseTest.php`
- `tests/Feature/Organization/EmployeeDocumentsTest.php`
- `tests/Feature/Organization/EmployeePrivateFileStorageTest.php`
- `tests/Feature/Organization/MigrateEmployeeFilesToPrivateCommandTest.php`
- `tests/Feature/Organization/DocumentShareTest.php`

See [Document search](./document-search.md) and [Document sharing](./document-sharing.md) for specialized flows.
