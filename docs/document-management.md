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

## Backend services

| Class | Role |
|-------|------|
| `DocumentBrowseQuery` | Folders, compliance list, search results, summaries |
| `StoresEmployeeDocument` | Create/update storage |
| `DocumentPagePermissions` | Maps `documents.*` to Inertia `can` props |

## Tests

- `tests/Feature/Organization/DocumentBrowseTest.php`
- `tests/Feature/Organization/EmployeeDocumentsTest.php`
- `tests/Feature/Organization/DocumentShareTest.php`

See [Document search](./document-search.md) and [Document sharing](./document-sharing.md) for specialized flows.
