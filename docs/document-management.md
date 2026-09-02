# Document management

Employee documents are stored per company and linked to employees. HR can browse by folder, manage files on the employee profile, track expiry, and measure **required-document compliance** (valid, expiring, expired, missing).

## Unified Documents module

Documents is one sidebar group with these destinations. Pages do not repeat that list as an in-page tab strip.

| Path | Section | Reuses | Permission |
|------|---------|--------|------------|
| `/organization/documents` | Overview | Operational attention dashboard | `documents.view` |
| `/organization/documents/library` | Library | Canonical browse / search / compliance workspace | `documents.view` |
| `/organization/documents/generate` | Generate & Send | Current Bulk Documents roster | `bulk_documents.view` |
| `/organization/documents/requests` | Requests | Unified Review & Approval + legacy Signature Requests | `documents.requests.view` or `bulk_documents.view` |
| `/organization/documents/templates` | Templates | Company custom and system generation templates | Any of `documents.templates.view`, `bulk_documents.view`, `settings.master-data.document-types.view`, or platform view |
| `/organization/documents/configuration` | Configuration | Document Types and employee requirement policy | `settings.master-data.document-types.view` |
| `/organization/documents/activity` | Activity | Current bulk generation history | `bulk_documents.view` |

**Overview** is an operational dashboard. It answers what needs attention, who is affected, and the next action. It does not render the document table, folder grid, search, or Saved Views. Zero-value warning cards are omitted; a healthy company sees **No urgent document issues** plus compact secondary totals.

Needs Attention items appear only when the count is greater than zero:

| Item | Source | Drill-down |
|------|--------|------------|
| Missing Required | Existing requirement/compliance engine | Library `requirement_status=missing` |
| Expiring Soon | Browse expiry summary, 7-day window only | Library `expiry=expiring_7` |
| Expired | Browse expiry summary | Library `expiry=expired` |
| Awaiting Your Action | Pending workflow tasks assigned to the current user | Requests `tab=review&status=pending&assigned_to_me=1` |
| Awaiting Signature | Company recipient requests awaiting action, plus legacy bulk signature requests when the user can view them | Requests `tab=recipient&status=awaiting_action` or `tab=signatures&signature_filter=awaiting_signature` |

Request and signature cards are omitted unless the user has the matching Requests permission. `documents.view` alone never grants request metrics or Configure actions.

**Document Compliance** lists only Document Types that currently have missing, expiring, or expired required rows, from one grouped compliance query. **View** opens Library filtered by that type and the primary problem (`requirement_status=missing`, `expiry=expired`, or `requirement_status=expiring`). **Configure** appears only with `settings.master-data.document-types.view` and opens `Documents → Configuration?edit={documentTypeId}`.

Library also accepts `document_type_id` as a supported filter (with Saved Views). Overview never applies a default Documents Saved View.

**Library** is the canonical browsing workspace: employee folders, global search, expiry / required-document / department filters, Saved Views, pagination, tables, and bulk file/folder actions. `DocumentsFolderIndexController` serves Library only. Saved Views keep the `documents` page key and apply on Library (`organization.documents.library`). Applying or defaulting a Documents Saved View from Library stays on Library.

Opening a document from Library uses `from=library` so **Back to Library** restores supported list state: `search`, `expiry`, `requirement_status`, `department_id`, `document_type_id`, and `page` (validated with the current filter rules). Overview document links use `from=index` (**Back to documents**). Employee-folder back stays **Back to files**. Employee-profile back stays **Back to employee profile**. Query `company_id` is ignored for ownership; list/controller tenant scoping remains authoritative. Employee browse URLs (`/organization/documents/employees/{employee}` and nested files) resolve to the Library favorites destination, not Overview.

Old filtered Overview bookmarks such as `/organization/documents?search=`, `?expiry=`, `?requirement_status=`, `?department_id=`, `?document_type_id=`, and `?page=` redirect to the equivalent Library URL with those supported keys preserved. Unknown parameters are not redirected and are not copied. Plain `/organization/documents` stays Overview.

Generate & Send and Activity are served by `BulkDocumentsController` (`/organization/documents/generate`, `/organization/documents/activity`, and legacy `/organization/documents/bulk`). **Requests** is served by `DocumentRequestsIndexController` at `/organization/documents/requests` (Review & Approval and Signature Requests tabs). Legacy `/organization/documents/bulk?view=signatures` remains a valid bookmark into the signature roster via `BulkDocumentsController`. Explicit module routes for Generate and Activity set a `module_view` route default (`roster` / `history`) resolved before the legacy `view` query string. Templates bridge now supports company-owned content and visual PDF overlay templates with controlled merge fields and Fabric.js visual placement; system templates bridge remains available. Protected Salary Declaration / Salary Certificate PDF layout, Browsershot, Puppeteer, FPDI stamping, and the public e-sign workflow are unchanged.

### Legacy Bulk URLs

These remain valid GET bookmarks and keep their existing route names. POST/PUT/DELETE bulk action routes are unchanged.

| Legacy URL | Active module section |
|------------|------------------------|
| `/organization/documents/bulk` | Generate & Send |
| `/organization/documents/bulk?view=signatures` | Requests |
| `/organization/documents/bulk?view=history` | Activity |

The standalone **Bulk generate** sidebar item is removed. Favorites key `documents.bulk` now points at Generate & Send.

### Documents → Activity UX

Activity answers: *What document operation happened, who started it, and how did it finish?*

Activity represents operational history (generation and email batch runs), separated from the employee roster and signature request workflows.

- **Workspace Header**:
  - Title: **Activity**
  - Description: *"Review document generation and email history."*
  - Document context switcher: Displays *"Generation and email history for {selectedTypeLabel}."* alongside a clean document selector scoped to the active document type.
- **Suppressed Roster Controls**:
  - Employee search, department tree picker, sponsor/visa type filter, email-status filter, generation summary cards, and employee row check-boxes are hidden in the Activity view.
- **Desktop Table Columns**:
  - **Operation**: Clear operation title (e.g. `Generated {Document}` or `Sent {Document}`) with category badge (`Generation` or `Email Delivery`). On email delivery rows, an interactive drill-down indicator (`ArrowUpRight`) opens the recipient delivery sheet.
  - **Result**: Compact, plain-English summary answering "What happened?" (e.g. `38 created · 2 replaced · 1 skipped` or `34 sent · 2 no email · 1 failed · Template: {Template Name}`).
  - **Triggered By**: Name of the user who initiated the run, falling back to `System`.
  - **Date**: 12-hour formatted timestamp (`formatDisplayDateTime12h`).
  - **Status**: Human status badges:
    - **Completed** (emerald): All targeted documents were processed without error or skip.
    - **Completed with issues** (amber): Completed with skipped records or failures.
    - **Running** / **Queued** (amber / secondary): In-progress asynchronous jobs.
    - **Failed** (destructive): Job or delivery failure.
- **Mobile Card View**:
  - Automatically renders via `MobileRecordList` and `MobileRecordCard` on mobile viewports (`< md`), hiding the desktop table.
  - Displays operation title, timestamp subtitle, result metrics, triggered-by actor, status badge, and a direct "View details" action button for email batch rows.
- **Email Batch Drill-Down**:
  - Clicking any email delivery row or card opens `BulkEmailBatchSendsSheet` displaying recipient-level dispatch logs, delivery status, and error details.

### Document Templates

Documents → Templates serves as the centralized company custom document template management area while preserving protected system generation templates:

1. **Company Templates** (`document_generation_templates`):
   - Scoped to the active company.
   - User-facing terminology: "Company Templates", "PDF Template".
   - **Company Templates are PDF-upload templates only in the user-facing product.**
   - **Flow**: `Templates → Upload PDF → Design → Workflow → Readiness → Save Draft → Publish`.
     - Templates list **Upload PDF** opens `/organization/documents/templates/create/pdf`.
     - PDF upload stores the template and creates Draft v1, then redirects directly to `/{template}/design` (Design Template).
     - `/organization/documents/templates/create` and `/create/content` redirect to `/create/pdf`.
     - List **Design Template** / **Open Template** deep-links to the unified visual designer, which is the place to configure design, review, signing, readiness, and publishing for that version.
     - Secondary actions on the list: Replace PDF, Activate/Deactivate, Duplicate, Delete. Publish and After generation are not list actions; publish stays on the Designer, and After generation is removed from the normal Templates UX. The backend publish and legacy automation endpoints remain.
     - Dedicated company **workflow preset** and **signing preset** management pages remain for reusable administration. The Designer selects (and, with existing preset-create permission, can create) those presets for the current template version.
     - Legacy `content` template records and underlying domain rendering support remain preserved in the database for historical compatibility, but are hidden from company template management and blocked from new creation or editing (`/{template}/edit` redirects to Templates).
     - **Opening the designer is side-effect free.** It does not create a draft. It displays the most relevant version (draft if present, otherwise published, otherwise latest archived). A 404 is returned if no versions exist.
   - **Formats**:
     - `pdf_overlay`: Branded uploaded PDF with visual merge field placement, static text boxes, and signature slots. (Legacy `content` templates remain in schema for backward compatibility).
   - **Template Identity & Immutability**:
     - The parent model `DocumentGenerationTemplate` manages company-level identity, metadata (`name`, `description`, `document_type_id`, `template_format`), lifecycle status (`draft`, `active`, `inactive`), and pointer to `published_version_id`.
     - Authoritative renderable data resides in `DocumentGenerationTemplateVersion` (`version`, `status`, `content`, `source_pdf_path`, `placement_config`, `signature_placement_config`, `document_workflow_mode`, `document_workflow_preset_id`, `document_signing_mode`, `document_signing_preset_id`, `published_at`).
     - **Workflow is version-owned.** Review and signing require explicit decisions on each draft:
       - `null` mode = not explicitly configured (blocks publish).
       - `none` = intentionally disabled for that stage (`preset_id` must be null).
       - `preset` = configured using a company workflow or signing preset.
       - Legacy rows with a preset id and null mode **read** as `preset`. Null id + null mode stays unconfigured. Historical published/archived versions are never rewritten. New Draft v1 starts unconfigured. Branching a draft from a legacy configured version normalizes **only the new draft** to `preset`.
     - **Strict Immutability**: Published and archived versions cannot be altered. Editing an active template branches a new single `draft` version (`version = max + 1`), preserving historical published versions and source files indefinitely.
     - Concurrency-safe draft branching (`BranchDocumentGenerationTemplateDraft`) guarantees at most one draft per template.
   - **Unified PDF Designer** (`/{template}/design`):
     - Single visual workspace combining merge field placements, static text boxes, and signature slot placements on one Fabric.js canvas.
     - **`placement_config`** and **`signature_placement_config`** remain separate persisted domain structures with independent validation and audit trails.
     - `isEditable = (version.status === 'draft')` gates all add/delete/drag controls, Workflow edits, Save Draft, and Publish. Historical versions remain selectable and fully read-only, including the Workflow tab (it shows the stored configuration for that version).
     - Right panel tabs: **Properties** (selected-element controls) and **Workflow** (review/approval, signing, execution-order summary, placement status). Workflow stays visible with no Fabric selection.
     - Workflow decisions use explicit selected/unselected radio cards (`none` vs `preset`). Approval and signing preset steps render in execution order. Signing steps show whether the matching PDF signature slot is placed (`Placement configured` or `Signature placement missing`). A configured signer locates and selects its canvas placement; a missing signer on a Draft uses the existing click-to-place signature mechanism (`Place on PDF`). Canvas signature selection can highlight the matching Workflow step. Left **Signatures** still manage physical slots; Workflow describes who signs and whether those slots exist.
     - **Template readiness** is evaluated server-side (`DocumentGenerationTemplateReadiness`). The Designer shows a readiness indicator that opens issue details. Fix actions use stable issue codes (not English message text) to jump to the relevant Workflow control, arm Place on PDF, or Save Draft. Local radio/preset/placement edits update the visible status immediately; the server remains authoritative after Save Draft and before Publish. Publish is disabled for unsaved or blocking issues. Backend publish still calls the evaluator — a disabled button is not enforcement.
     - Switching versions reloads that version's placements, signature slots, workflow/signing modes and presets, and readiness. Unsaved-change confirmation covers Workflow edits as well as canvas edits.
     - Normalized coordinates `[0.0, 1.0]` ensure resolution-independent placement across any viewer or print scale.
     - **Schema versioning**: `schema_version: 1` remains readable for compatibility (missing `type` continues to mean `field`; never auto-migrated on read). `schema_version: 2` requires an explicit placement `type` of `field` or `text`; missing, empty, or unknown types are rejected at save and at render-time validation. All saves write v2. Published and archived versions remain immutable.
     - **Static text boxes**: `type: 'text'` placements with `text_content` (1–500 chars). No `field` key stored. The designer uses the same default box (160×26 CSS px) and the same edit/preview chrome as merge fields.
     - **Text wrapping**: Merge fields and static text both wrap inside the drawn box (`white-space: pre-wrap`, `overflow-wrap: break-word`, `line-height: 1.2`, full-width inner span so left/center/right `text-align` still applies). Keep the box width inside its column and increase **height** for extra lines. Browsershot DOM measurement (`scrollWidth > clientWidth + 1` or `scrollHeight > clientHeight + 1`) is used for font-size preflight.
     - **Explicit draft creation**: Users with update permission see a "Create Draft" button when no draft exists. Clicking it branches a draft from the current published version using `BranchDocumentGenerationTemplateDraft` (at-most-one-draft invariant preserved). Opening the designer never creates a draft automatically.
     - **Version switcher**: Toolbar dropdown lists all versions newest → oldest. Switching from an unsaved draft prompts "Stay on Draft / Discard changes and switch". Historical versions show a Version Info panel (PDF metadata, placement counts, change summary from `VersionChangeSummary`). Summaries compare against the immediately previous version. The design page provides `initial_change_summary` for the initially selected version so v2+ shows that diff on first render without an extra request.
     - **Save Draft**: Single atomic endpoint (`PUT .../versions/{version}/design`). Persists `placement_config`, `signature_placement_config`, `document_workflow_mode`, `document_workflow_preset_id`, `document_signing_mode`, and `document_signing_preset_id` in one DB transaction. If any validation fails, nothing is persisted. Preset ids are resolved against `current_company_id`.
     - **Publish**: Server-gated by readiness plus existing lifecycle/signature validation. Unsaved Designer changes must be saved first; Save Draft and Publish stay explicit.
     - **Historical immutability**: Published and archived versions cannot be edited. Fetching a historical version via `showVersion` performs no DB writes, creates no activity log entries, and never migrates schema v1 configs. Version summaries compare against the immediately previous version and never rewrite stored configs.
     - **Click-to-place**: Adding a merge field, static text box, or signature slot arms a placement. The next click on empty canvas hangs a new field/text box so its **baseline** sits on the click (the printed underline). Signature slots still center on the click. Esc or a second click on the same add control cancels.
     - **Vertical alignment**: Each field/text placement stores `vertical_align` (`top` / `middle` / `baseline`). New boxes default to `baseline`. Existing placements without the key keep the previous look (`middle` for merge fields, `top` for static text). The designer paints merge text on the same box edges as generation (`align-items: flex-start|center|flex-end`). Baseline is the **bottom of the box**, not the surrounding PDF line. Click-to-place hangs the box above the click so that floor sits on the printed underline. Switching Top/Middle → Baseline on an existing box moves the value to the floor without moving the box.
     - **Font size**: Properties has a preset selector (8–48pt) plus − / + buttons that change size by 1pt. Save still rejects sizes outside 8–48.
     - **Overflow warning**: The drawn box is the max size. Generation may shrink the font (down to 8pt) to stay inside it — the designer does **not** warn for that. It only warns when wrapped text still cannot fit at 8pt (red outline + “too small for the text”). Name-like fields use a long probe when no employee is selected; employee Preview uses the real value. Values are never clipped.
     - **Nudge / undo**: Arrow keys move the selected box 1 CSS px (Shift = 10). ⌘/Ctrl+Z undoes; ⌘/Ctrl+Shift+Z or Ctrl+Y redoes. History covers add, delete, duplicate, drag, resize, font, and nudge.
     - **Alignment guides**: Dragging a field, text, or signature box shows a magenta **horizontal** snap line (Y axis: top / middle / baseline) against other boxes on the page and the page vertical center. Left/right is not snapped. Hold Alt/Option to move freely. Arrow-key nudge does not snap.
     - **Print preview**: The in-canvas Preview toggle hides placement chrome (boxes, signature slot labels) and shows overlay text in the saved color. Sample Jane Smith values are the default. Designers who also have `employees.view` can search active company employees and overlay allowlisted merge values (`GET .../design-employees`, `GET .../design-employees/{employee}`). Search returns `id`, `name`, and `employee_no` only; values are restricted to `DocumentTemplateMergeFields::allowedKeys()`.
     - Opening Preview cancels an armed placement. Preview does not persist and does not write placements.
   - **Employee Signature Placement**:
     - Managed in the unified designer's Signatures section (left panel) and right properties panel.
     - Stored as version-owned `signature_placement_config` (separate from `placement_config`). Independent validation and audit trail.
     - Subject slot cannot be deleted; manager and company signatory slots can be added (up to 7 each) and removed with automatic renumbering.
     - Drag/resize persists the geometric box (`left`/`top`/`width`/`height` with scale baked in), not Fabric `getBoundingRect()`, so the outline stroke does not shift saved coordinates. Field, text, and signature boxes all use that geometric rect.
     - Published/Archived versions remain immutable; viewing them in the designer is read-only.
     - Required for Phase 6A **Request Signature** eligibility on generated custom PDF Overlay documents.
   - **PDF Storage & Compensation**:
     - Stored on the `local` private disk under `document-generation-templates/{companyId}/{uuid}.pdf`.
     - Duplication physically copies the source PDF to a new private UUID path so mutable paths are never shared.
     - Replacement clears placements for that draft and removes the old file. Database rollback compensation cleans up orphaned files on failure.
   - **Explicit Lifecycle**:
     - `Publish`: Promotes draft version to published (`published_at = now()`), archives prior published versions, and sets parent template to `active`.
     - `Deactivate`: Changes parent template to `inactive` without modifying version history.
     - `Activate`: Re-enables an inactive template that has a published version.
   - **Allowed Merge Fields**: Strict allowlist catalog (`App\Support\Documents\DocumentTemplateMergeFields`) covering:
     - *Employee*: `{{employee_name}}`, `{{employee_no}}`, `{{first_name}}`, `{{last_name}}`, `{{email}}`, `{{phone}}`, `{{gender}}`, `{{joining_date}}`, `{{nationality}}`, `{{position_name}}`, `{{rank_name}}`
     - *Manager*: `{{manager_name}}` (employee's department effective manager)
     - *Organization*: `{{company_name}}`, `{{department_name}}`, `{{branch_name}}`
     - *System*: `{{today}}`, `{{current_year}}`
     - Sensitive and restricted fields are not unrestricted merge fields. This includes passport number, salary, bank/IBAN, Emirates ID, credentials, and similar identifiers. Content or placements that use unsupported placeholders such as `{{passport_number}}` are rejected at validation.

2. **Built-in Templates** from `BulkDocumentTypeRegistry` (Salary Declaration, Salary Certificate):
   - User-facing terminology: "Built-in Templates".
   - Protected application renderers used by Generate & Send.
   - Layout is code-owned and not editable from this UI.

3. **Configuration shortcuts**:
   - Link to **Documents → Configuration → Document Types** when user has `settings.master-data.document-types.view`.
   - Link to **Settings → Application → signature placement** when user has platform view.

## Routes

| Path | Purpose | Permission |
|------|---------|------------|
| `/organization/documents` | Documents Overview (summary dashboard) | `documents.view` |
| `/organization/documents/library` | Documents Library (browse / search / compliance) | `documents.view` |
| `/organization/documents/generate` | Generate & Send (bulk roster) | `bulk_documents.view` |
| `/organization/documents/requests` | Unified Review & Approval + Signature Requests workspace | `documents.requests.view` \| `bulk_documents.view` |
| `/organization/documents/requests/{workflowRequest}` | Internal review/approval request detail | `documents.requests.view` |
| `/organization/documents/requests/{workflowRequest}/version-preview` | Stream bound canonical `DocumentInstanceVersion` PDF inline | `documents.requests.view` |
| `/organization/documents/configuration` | Documents Configuration (Document Types) | `settings.master-data.document-types.view` |
| `/organization/documents/templates` | Custom and System Document Templates | `documents.templates.view` \| `bulk_documents.view` \| `settings.master-data.document-types.view` \| platform view |
| `/organization/documents/templates/create` | Redirects to PDF upload page (`/create/pdf`) | `documents.templates.create` |
| `/organization/documents/templates/create/content` | Redirects to PDF upload page (`/create/pdf`) | `documents.templates.create` |
| `/organization/documents/templates/create/pdf` | PDF upload create page | `documents.templates.create` |
| `/organization/documents/templates/{template}/edit` | Redirects to Templates list | `documents.templates.update` |
| `/organization/documents/templates/{template}/design` | Unified visual designer — side-effect free; shows draft > published > latest | `documents.templates.update` |
| `/organization/documents/templates/{template}/design-employees` | JSON search of active company employees for canvas preview | `documents.templates.update` + `employees.view` |
| `/organization/documents/templates/{template}/design-employees/{employee}` | Allowlisted merge-field values for one company employee | `documents.templates.update` + `employees.view` |
| `/organization/documents/templates` (POST) | Store custom PDF document template → redirects to design page | `documents.templates.create` |
| `/organization/documents/templates/preview-draft` (POST) | Render preview for unsaved draft | `documents.templates.create` \| `documents.templates.update` |
| `/organization/documents/templates/{template}/preview` (GET) | Render preview for saved template | `documents.templates.view` |
| `/organization/documents/templates/{template}` (PUT) | Update custom document template | `documents.templates.update` |
| `/organization/documents/templates/{template}/duplicate` (POST) | Duplicate custom template in company | `documents.templates.update` |
| `/organization/documents/templates/{template}` (DELETE) | Delete custom template | `documents.templates.delete` |
| `/organization/documents/templates/{template}/draft` (POST) | Get or branch editable draft version | `documents.templates.update` |
| `/organization/documents/templates/{template}/versions/{version}/source-pdf` (GET) | Stream private source PDF | `documents.templates.view` |
| `/organization/documents/templates/{template}/versions/{version}/placements` (PUT) | Save visual placements to draft (merge fields + static text; schema v2) | `documents.templates.update` |
| `/organization/documents/templates/{template}/versions/{version}/signature-placement` (PUT) | Save signature placements to draft (backward-compat endpoint) | `documents.templates.update` |
| `/organization/documents/templates/{template}/versions/{version}/design` (PUT) | Atomic save — both `placement_config` + `signature_placement_config` in one transaction | `documents.templates.update` |
| `/organization/documents/templates/{template}/versions/{version}` (GET) | Side-effect-free version detail + `change_summary` for version switcher | `documents.templates.view` |
| `/organization/documents/templates/{template}/versions/{version}/replace-pdf` (POST) | Replace PDF on draft version | `documents.templates.update` |
| `/organization/documents/templates/{template}/versions/{version}/publish` (POST) | Publish draft version | `documents.templates.update` |
| `/organization/documents/templates/{template}/activate` (POST) | Activate template | `documents.templates.update` |
| `/organization/documents/templates/{template}/deactivate` (POST) | Deactivate template | `documents.templates.update` |
| `/organization/documents/activity` | Bulk generation history | `bulk_documents.view` |
| `/organization/documents/bulk` | Legacy Bulk Documents index | `bulk_documents.view` |
| `/organization/documents/employees/{employee}` | Employee document browse | `documents.view` |
| `/organization/documents/employees/{employee}/files/{document}/manager-countersign-requests` (POST) | Create department-manager countersign request (manager resolved server-side) | `documents.recipient-requests.create` |
| `/organization/documents/employees/{employee}/files/{document}/signing-flows` (POST) | Start signing flow from an active signing preset | `documents.recipient-requests.create` |
| `/organization/documents/signing-flows/{signingFlow}/retry` (POST) | Retry blocked signing flow advancement | `documents.recipient-requests.create` |
| `/organization/documents/signing-flows/{signingFlow}/cancel` (POST) | Cancel active/blocked signing flow | `documents.recipient-requests.cancel` |
| `/organization/documents/signing-presets` | Signing presets CRUD index | `documents.signing-presets.view` |
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

HR configures document types and requirement rules under **Documents → Configuration → Document Types**. There is no separate Document Requirements menu.

### Document Types list and UX

The page answers one core question: *What kind of employee document is this, and who is required to have it?*

- **Purpose & description:** *"Define document categories, requirements, and who needs each document."*
- **Detail page:** Opening a Document Type name or row navigates to `/organization/documents/configuration/{documentType}` (**Documents → Document Types → {name}**). The detail page answers: what the type is, whether it is required, who it applies to, which details are tracked, and recent changes. **Edit** reuses the existing create/edit Sheet and stays on the detail page after a successful update (`redirect_to=show`). **Delete** is available under overflow actions when permitted and returns to the Document Types list. **Recent activity** renders only with `audit.view` and includes Document Type field changes plus company-scoped requirement policy phrases. When the type is required and the user has `documents.view`, compliance shortcuts open Library filtered by that type (`View missing employees`, `View documents`).
- **Table columns:**
  - **Document Type:** Name of the document type (e.g. Passport Copy, Sea Service Book). Clicking the name or row opens the detail page.
  - **Requirement:** Clear status badge indicating **Required** or **Optional**.
  - **Applies To:** Who must hold it when required (**All employees**, specific group summary like `Crew · Captain`, or `—` when optional).
  - **Expiry:** Shows **Tracked** when expiry tracking is active, or `—`.
  - **Status:** **Active** / **Inactive** badge with inline status switch for fast updates.
  - **Actions:** View, Edit, and Delete actions (permission-governed).
- **Responsive card view:** On mobile screens (`< md`), records render as streamlined cards showing title, requirement status, applies-to scope, expiry tracking, active badge, View primary action, and inline edit/delete overflow actions.
- **Empty state:** Clean empty state with direct **Add document type** action when the user has create permissions.
- **Create / Edit Sheet structure:**
  1. **Basics:** Document Type Name (`title`) and Active status switch (`is_active`).
  2. **Requirement:** *"Is this document required for employees?"* with **Optional** vs **Required document** radio options.
  3. **Who needs this document?** (visible when Required): Choice between **All employees** and **Selected groups**. When *Selected groups* is chosen, an explicit rule explanation clarifies: *"Employees must match every selected category (AND). Within a category, matching any selected value is enough (OR). Unselected categories impose no restriction."* Multi-selectors for Departments, Positions, Ranks, and Projects include compact badge summaries so selected items are immediately visible and dismissible.
  4. **Tracked document details:** Clarifies which details are relevant for the document type (Issue date, Expiry date, Document number) and honestly explains: *"These settings identify the details normally tracked for this document type. They do not currently make those fields mandatory during upload."* For Expiry date, the UI notes: *"Indicates that expiry date is a relevant detail for this document type."*

The previous Settings location remains a compatibility bookmark: `/settings/master-data/document-types` redirects to `/organization/documents/configuration` and preserves supported query keys such as `search`, `page`, and `edit`. Create, update, delete, and CSV import still use the existing Settings mutation routes and `settings.master-data.document-types.*` permissions.

Deep-linking a specific type is supported with `?edit={documentTypeId}` on the Configuration URL. Direct loads, Overview **Configure** visits, and same-page visits from one `edit` ID to another open or switch the Document Type Sheet. Invalid or unknown IDs are ignored and do not fail the page. Closing the Sheet replaces the URL without `edit` so the same ID can be opened again without an accidental reopen loop. The detail page Edit action uses the same Sheet component rather than a separate form implementation.

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
| `DocumentsOverviewQuery` | Overview attention items, request/signature counts, and Document Compliance-by-type from existing browse/compliance/request queries |
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

- `tests/Feature/Organization/DocumentsOverviewDashboardTest.php`
- `tests/Feature/Organization/DocumentsConfigurationTest.php`
- `tests/Feature/Organization/DocumentTypeShowTest.php`
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

## Phase 3B: Custom Document Generation Templates & PDF Placement

Custom document templates allow companies to author custom HR documents in two formats:
1. **Content templates** (`template_format = 'content'`) with controlled merge fields.
2. **PDF Overlay templates** (`template_format = 'pdf_overlay'`) with private source PDF storage and visual drag-and-drop merge field placement via Fabric.js.

### Template & Version Architecture

- **`DocumentGenerationTemplate`**: Company-owned template identity (`name`, `description`, `document_type_id`, `template_format`, `status`, `published_version_id`).
- **`DocumentGenerationTemplateVersion`**: Immutable renderable version (`version`, `status`, `content`, `source_pdf_path`, `placement_config`, `published_at`).

### Lifecycle Semantics

- **Version Lifecycle**: `Draft` -> `Published` -> `Archived`.
  - Versions start as `Draft`. Once published, they are frozen and immutable (`content`, `placement_config`, and PDF attachments cannot be modified).
  - Publishing a new version automatically moves the previously published version to `Archived`.
- **Publish vs Activate**:
  - **Publish** (`versions/{version}/publish`): Transitions a Draft version to `Published`, archives the previous version, points `published_version_id` to the published version, and sets parent template status to `Active`.
  - **Activate / Deactivate** (`templates/{template}/activate`, `templates/{template}/deactivate`): Controls company availability. Activation strictly requires a valid `published_version_id` belonging to the active company and template with status `Published`.
  - Normal create/update form submissions do **not** accept `status`. Templates always begin in `Draft` and can only be published through the explicit publish action.
- **Parent Content Semantics**:
  - `parent.content` continues representing the **current published content** for backwards compatibility with legacy callers.
  - When editing a new draft version, `parent.content` is not overwritten until that draft is explicitly published.
  - For templates that have never been published, `parent.content` syncs with draft edits.
  - The editor always resolves content in order: `draft_version.content ?? published_version.content ?? parent.content`.

### PDF Storage & Security Boundary

- All uploaded template PDFs are stored on Laravel's private `local` disk (`storage/app/private/document-generation-templates/{companyId}/{uuid}.pdf`).
- Previews and streaming enforce company authorization and tenant directory boundaries (`document-generation-templates/{companyId}/`).
- Files are deleted only after database deletion completes successfully.

### Visual Placement & Normalized Coordinates

- Placements use normalized percentages (`0.0` to `1.0`) for `x`, `y`, `width`, and `height` relative to page dimensions, guaranteeing crisp rendering across arbitrary display DPIs and print paper sizes.
- Placement `font_size` is stored in PDF points (8–48). The Fabric.js designer multiplies that size by the PDF.js viewport scale so 12pt on the canvas matches 12pt on the source page and in generated overlays. Merge fields and static text share the same default box size and edit/preview chrome.
- Supported text alignment options: `left`, `center`, `right`. Alignment is stored in the placement configuration and rendered visually in both Fabric.js canvas placement boxes and sample or employee preview.
- Designer canvas edits are click-to-place (new field/text boxes hang from the click baseline), arrow-key nudge, undo/redo, vertical align, numeric left/top/width/height, and drag alignment guides that snap on the Y axis (same row / baseline as other boxes or the page center). Preview text uses the same box edges as generation. Print preview hides chrome; optional employee preview uses company-scoped JSON endpoints and never accepts a submitted `company_id`. The designer only warns when a value cannot fit at 8pt; automatic shrink-to-fit stays silent.

---

## Phase 4A: Custom Template Generation, Document Instances & Library Provenance

Phase 4A turns custom templates into real, generated employee PDF documents while establishing permanent provenance and synchronizing with the Documents Library.

### Provenance Chain

```
DocumentGenerationTemplate (Company entity)
       ↓
DocumentGenerationTemplateVersion (Immutable published snapshot)
       ↓
Employee (Trusted DB records resolve merge fields)
       ↓
DocumentInstance (Identity, snapshots, audit, current_version pointer)
       ↓
DocumentInstanceVersion (v1, immutable canonical PDF bytes + SHA-256)
       ↓
EmployeeDocument (Documents Library representation)
```

### Key Architectural Invariants

1. **Canonical Artifact vs. Library Separation**:
   - Canonical artifacts are stored in `storage/app/private/document-instances/{companyId}/{uuid}.pdf`.
   - Library copies are created in `storage/app/private/employee-documents/{companyId}/{employeeId}/...`.
   - **Library Deletion Safety**: Deleting an `EmployeeDocument` via `DocumentDeletionService` purges the Library file copy, but leaves the canonical artifact in `document-instances/` untouched. The `document_instances.employee_document_id` pointer is set to `null`. Historical provenance is never destroyed. Generate & Send **Generated / Missing** counts follow the live Library PDF: an unlinked instance is **Missing**, and **Generate missing** may create a new instance for that published version.
2. **Template & Version Provenance Protection**:
   - Once any `DocumentInstance` or `DocumentGenerationRun` exists for a `DocumentGenerationTemplate`, deleting that template or its versions is strictly blocked with a user-friendly `ValidationException`, directing the user to deactivate the template instead.
   - Deletion is blocked even if a run failed or completed with zero instances, preventing database-level foreign key constraint violations.
   - Backed at the database level by foreign key `ON DELETE RESTRICT` constraints on `document_generation_template_id` and `document_generation_template_version_id` from both `document_instances` and `document_generation_runs`.
   - **Instance Version Deletion RESTRICT**: Foreign key from `DocumentInstanceVersion` to `DocumentInstance` is configured as `ON DELETE RESTRICT`, blocking direct database deletion of instances that possess versions.
3. **DocumentInstance & Version Identity Immutability**:
   - `DocumentInstance` immutable attributes (`company_id`, `employee_id`, `employee_name_snapshot`, `employee_no_snapshot`, `document_generation_template_id`, `document_generation_template_version_id`, `document_type_id`, `document_generation_run_id`, `template_name_snapshot`, `template_version_number`, `title_snapshot`, `generated_by`, `generated_at`) cannot be modified after creation.
   - `DocumentInstanceVersion` attributes (`file_path`, `checksum`, `size_bytes`, `version`, `stage`, `company_id`, `document_instance_id`, `original_filename`, `mime_type`, `created_by`) are strictly immutable.
   - Only lifecycle pointers (`status`, `current_version_id`, `employee_document_id`) on `DocumentInstance` may be updated.
   - Calling `$instance->delete()` or `$version->delete()` throws a `DomainException` to guarantee official document records cannot be deleted via Eloquent.
4. **Version Snapshotting & Archived Version Generation**:
   - Generation runs are permanently bound to the template version snapshotted at Run creation.
   - If a new version (v2) is published while a queued Run for v1 is in progress, v1 transitions to `Archived`. The queued worker executes successfully because `Archived` versions represent immutable historical snapshots safe to reproduce. Draft versions are never accepted by the worker.
5. **Atomic Generation Unit & Full File/DB Compensation**:
   - Storage of canonical and library PDF files occurs prior to database persistence, with paths recorded in memory.
   - Creation of `EmployeeDocument`, `DocumentInstance`, `DocumentInstanceVersion`, `RunItem` completion, and activity audit execute in a single database transaction.
   - If any database step fails, the transaction rolls back completely and both the canonical and library files are purged from storage, leaving no orphaned files, no partial database rows, and no false audit logs.
6. **Tenant-Scoped Explicit Employee Validation**:
   - Explicit `employee_ids` submitted to `GenerateCustomDocumentsRequest` are validated against `current_company_id` using `Rule::exists('employees', 'id')->where('company_id', $companyId)`. Cross-company employee submissions are rejected with validation errors before any Run or queue dispatch occurs.
   - Filter-based bulk generation relies strictly on server-side `current_company_id`.
7. **Repeat Generation & Cross-Run Deduplication**:
   - Non-repeat generation (`allowRepeatGeneration = false`) is strictly deduplicated across concurrent runs. Workers lock the targeted Employee row `FOR UPDATE` inside the final database transaction and perform an authoritative existence re-check against the exact template version **with a live Library `EmployeeDocument`**. If that library PDF still exists, the run item is marked `skipped` and any newly rendered canonical or library PDF files are immediately purged. An instance whose library pointer was cleared by delete is not treated as current.
   - Explicit employee selection (`allowRepeatGeneration = true`) bypasses this deduplication, intentionally generating a new `DocumentInstance` (force new copy) while preserving all prior historical instances.
8. **Content Template Rendering & Multilingual Bidi Safety**:
   - Server-side trusted merge fields are resolved via `DocumentTemplateMergeFields::valuesForEmployee()`.
   - HTML characters are safely escaped (`e()`).
   - Container has `dir="auto"` and `unicode-bidi: plaintext` with embedded DejaVu fonts (`BrowsershotEmbeddedFonts::dejaVuStyles()`), ensuring correct RTL alignment for Arabic paragraphs (`محمد رابيل`), LTR for English, clean inline mixed text, and multi-page flow.
9. **Idempotent Queue Ledger**:
   - Runs are recorded in `document_generation_runs` and individual employee tasks in `document_generation_run_items` (unique on `[document_generation_run_id, employee_id]`).
   - Workers claim items atomically (`pending` -> `processing`).
   - Run totals (`generated_count`, `skipped_count`, `failed_count`) are derived directly from database aggregate counts on `DocumentGenerationRunItem`.
10. **Wayfinder-Driven Generate & Send UI**:
    - Frontend dispatches generation via Wayfinder route action `GenerateCustomDocumentsController.url()`.
    - Document Show page renders a "Document Provenance" card displaying template name, version, generation timestamp, and generator.
    - Generate & Send **Delete** removes the Library `EmployeeDocument` for the selected custom template’s published version. Canonical `DocumentInstance` artifacts remain. After delete, the employee appears under **Missing** for that version.

### Template Format Availability

- **Content Templates**: Fully supported for real production PDF generation with full Unicode/Arabic font embedding, secure HTML escaping, and complete provenance tracking.
- **PDF Overlay Templates**: Fully supported for production generation. Generate & Send lists active company templates that have a current published version. Overlay templates additionally require a configured source PDF. Draft and inactive templates are not offered.

---

## Phase 4B: Production PDF Overlay Generation

Phase 4B turns published PDF Overlay template versions into generated employee PDFs using the same Phase 4A provenance chain.

### Architecture

```
Published PDF Overlay Version
        ↓
Employee trusted merge values
        ↓
layout preflight (Chromium + DejaVu)
        ↓
transparent Unicode text overlay (Browsershot)
        ↓
FPDI composition over the ORIGINAL source PDF
        ↓
DocumentInstance → DocumentInstanceVersion → EmployeeDocument
```

The uploaded source PDF is imported as PDF page content through FPDI. The whole source is never rasterized to PNG/JPG. Overlay text is a separate transparent PDF layered on top.

### Zero-placement overlays

A PDF Overlay template with no field placements is a supported production state. New drafts initialize `placement_config` as schema version 2 with an empty `placements` array. Replacing the draft source PDF resets placements to the same empty schema. Publishing validates that the configuration is structurally renderable.

At generation time, zero placements reproduce the original source PDF through the official pipeline (FPDI import only; no overlay pages are rendered). Legacy published versions that still store `placement_config = null` are treated as zero placements for backward compatibility. Malformed non-null configs are rejected.

### Coordinate mapping

Published `placement_config` uses schema version 1 or 2 with normalized coordinates (`0.0`–`1.0`). Schema v1 remains readable: a missing placement `type` still means `field`. Schema v2 requires an explicit `field` or `text` type. At render time:

- `x_mm = placement.x * page_width_mm`
- `y_mm = placement.y * page_height_mm`
- `width_mm = placement.width * page_width_mm`
- `height_mm = placement.height * page_height_mm`

Published placement configuration is not rewritten during generation.

### Unicode, Arabic, and wrapping

Overlay HTML prefers Times New Roman (serif) or Arial (sans) so merge text matches typical Word letters, then falls back to embedded DejaVu for Arabic and hosts without those system fonts. Text uses `dir="auto"` and `unicode-bidi: plaintext`. Physical `text_align` (`left` / `center` / `right`) is the HR-saved layout choice and is not flipped for Arabic. Merge fields and static text wrap inside the placement box (`white-space: pre-wrap`). Merge values are Blade-escaped; employee HTML is never executed.

### Font fit and overflow

Every non-empty placement is measured in Chromium after `document.fonts.ready`. Overflow is detected when `scrollWidth > clientWidth + 1` or `scrollHeight > clientHeight + 1`. If the requested size does not fit, the renderer shrinks by `0.25pt` down to `8pt` so the value stays inside the drawn box. If the value still overflows at `8pt`, generation is blocked with `DocumentTemplateLayoutException`. Values are not clipped, truncated, or ellipsized. Empty merge values render nothing. The designer treats the drawn box as the max size and only warns when a value cannot fit at 8pt. Name fields use a long probe when no employee is selected.

Preflight runs for all placements before any overlay PDF or canonical/Library file is written. Layout failure logs only `run_id`, `item_id`, `placement_id`, `field_key`, and `page`.

### Multi-page and mixed orientation

Every source page is copied in order. Pages with placements receive a transparent overlay; pages without placements are copied only. Portrait, landscape, and mixed-orientation sources keep their page count, dimensions, and ordering. The stored `source_pdf_page_count` must match the actual FPDI page count.

### Source tenancy

`DocumentTemplateStorage::absolutePath()` validates relative template paths, rejects traversal segments (`..`, `.`), absolute paths, and cross-company prefixes, then resolves the real filesystem path and ensures it is physically inside `storage/app/private/document-generation-templates/{companyId}/`. Cross-company paths are rejected. Private absolute paths are not returned to Inertia or user-facing errors. Missing or unreadable sources fail the RunItem with `TEMPLATE_SOURCE_UNAVAILABLE` and create no official instance or files.

### Provenance and current-version state

PDF Overlay output uses the same `DocumentInstance` / `DocumentInstanceVersion` / `EmployeeDocument` chain as Content templates. Runs remain bound to the snapshotted template version, including Archived versions that were published when the run was created. Generation state is per employee + exact published template version: publishing overlay v2 makes a v1 employee Missing for v2. Repeat generation still requires explicit employee selection.

### Error codes

| Code | Meaning |
|------|---------|
| `TEMPLATE_LAYOUT_OVERFLOW` | A merge value does not fit the configured placement even at 8pt. |
| `TEMPLATE_SOURCE_UNAVAILABLE` | Source PDF missing, unreadable, page-count mismatch, or outside the company boundary. |
| `GENERATION_FAILED` | Any other renderer or storage failure. |

File compensation from Phase 4A is unchanged. Custom overlay templates remain generation-only in Phase 4B. Phase 5A adds internal review/approval workflows for generated documents; Phase 5B adds reusable workflow presets with server-side dynamic routing. Signing, email delivery, and automatic template preset assignment remain later phases.

---

## Phase 5A: Internal Review / Approval Workflow

Phase 5A adds a runtime internal workflow engine for generated documents. Approval is separate from e-signing and does not mutate PDF bytes.

### Provenance binding

Every workflow request binds to an exact immutable chain:

```
DocumentInstance
        ↓
DocumentInstanceVersion (exact version at request time)
        ↓
DocumentWorkflowRequest
        ↓
DocumentWorkflowStage (sequential)
        ↓
DocumentWorkflowTask (internal assignees)
```

If `DocumentInstance.current_version_id` later changes, existing workflow history remains on the original version. Terminal requests (`approved`, `rejected`, `cancelled`) do not block a deliberate new request for a new version, but only one **pending** request may exist per `(document_instance_id, document_instance_version_id)`.

### Sequential stages

- Only one stage is **active** at a time; later stages start as **pending**.
- The **final** stage must be `approve`. Earlier stages may be `review`.
- Assignees are explicit internal company users validated against `current_company_id` membership (active pivot or legacy home-company rule).
- Duplicate assignees within the same stage are rejected. The request creator cannot be assigned as a reviewer or approver.
- The same user may appear in different stages when they hold the required permissions for each stage action.
- Review/approval previews always stream the bound `DocumentInstanceVersion` artifact via a protected tenant-scoped route. Library file replacement or deletion does not change the PDF under review.
- Stage assignees must hold the required company-scoped permission for their stage action (`documents.requests.review` or `documents.requests.approve`) and `documents.requests.view`.

### Completion rules

| Rule | Behavior |
|------|----------|
| **ALL** | Every task in the stage must complete positively. Any rejection rejects the stage and the request. |
| **ANY** | First positive completion completes the stage and skips remaining pending tasks. One rejection does not reject the stage while other pending tasks remain; if every task rejects, the stage and request reject. |

When the final approval stage completes, the request becomes **approved**. No PDF mutation occurs in Phase 5A.

### Permissions

| Permission | Capability |
|------------|------------|
| `documents.requests.view` | List and open review/approval requests |
| `documents.requests.create` | Request approval from a generated document show page |
| `documents.requests.review` | Complete/reject **review** tasks assigned to the actor |
| `documents.requests.approve` | Approve/reject **approval** tasks assigned to the actor |
| `documents.requests.cancel` | Cancel pending workflows |

Review permission does not grant approval actions. Task assignment is enforced in addition to capability checks.

### Requests workspace

**Documents → Requests** is a unified operational inbox that answers: what needs attention, who is responsible, what stage the document is at, and what action to take.

Three tabs:

- **Approvals** — Phase 5A internal workflow inbox (`tab=review`, default when permitted). Rows show: employee, document, waiting for (assignee names), human status (normalized from backend), requested timestamp.
- **Employee Signing** — Phase 6A recipient sign/acknowledge requests (`tab=recipient`). Rows show: employee, document, waiting for (recipient name + role), human status, requested timestamp.
- **Signature Requests** — Legacy `BulkDocumentSignatureRequest` UI (`tab=signatures`). Rows show employee, document, status, consistent with modern presentation.

**Human-Readable Status**: Workflow and recipient presenters normalize backend states into user-friendly sentences (Waiting for Review, Waiting for Approval, Waiting for Signature, Email delivery failed, Expired, Rejected, Cancelled, Completed).

**Waiting For**: Each row displays who needs to act next (assignee names for workflow, recipient name + role for signing).

**Settings** live in the Requests page header for the active tab (not in the filter row):
- Approvals tab: **Approval Flows** (backend: Workflow Presets)
- Employee Signing tab: **Signing Flows** (backend: Signing Presets) and **Reminder Settings**
- Status and stage filters use an explicit “all” value so the dropdown always shows a label.
- Approval Flows list routing as ordered stage chips so the summary cannot overflow the Updated column.

Legacy `/organization/documents/bulk?view=signatures` continues to work unchanged and redirects signature browsing through `BulkDocumentsController`; the unified Requests tab embeds the same signature workspace via `DocumentRequestsIndexController`.

### Review preview route

Workflow request detail previews the exact bound `DocumentInstanceVersion` bytes at `/organization/documents/requests/{workflowRequest}/version-preview`. This route is tenant-scoped, requires `documents.requests.view`, validates company ownership and path boundaries, and never exposes private storage paths to Inertia.

### Audit

Workflow tables retain authoritative decision history (actor, time, notes). Company-scoped activity events are also written for `workflow_created`, `review_completed`, `approval_completed`, `task_rejected`, `stage_completed`, `workflow_approved`, `workflow_rejected`, and `workflow_cancelled` with safe metadata only (IDs, status, action, sequence — no PDF paths, merge values, or duplicated decision free-text such as notes or cancel reasons). `RecentActivityCard` on request detail remains gated by `audit.view`.

### Explicitly not in Phase 5A

No employee/manager signing, public signing links, acknowledgement, email/WhatsApp delivery, or reminders.

---

## Phase 5B: Workflow Presets + Dynamic Routing

Phase 5B adds reusable company workflow presets that resolve to concrete Phase 5A task snapshots at request creation. Manual workflow configuration from Phase 5A remains available.

### Preset model

| Table | Purpose |
|-------|---------|
| `document_workflow_presets` | Company-owned preset name, description, active/inactive status |
| `document_workflow_preset_stages` | Ordered stages with review/approve action and ALL/ANY completion rule |
| `document_workflow_preset_targets` | Routing targets per stage |

`DocumentWorkflowRequest` also stores optional provenance: `document_workflow_preset_id`, `preset_name_snapshot`, and `routing_definition_snapshot` JSON. Resolved assignees remain authoritative in `DocumentWorkflowTask` rows.

### Supported target types

| Target | Resolution |
|--------|------------|
| `specific_user` | Fixed company user validated for stage permissions |
| `department_manager` | First actionable manager from `ResolveDepartmentManagementChain` for the **document subject employee** |
| `parent_manager` | Next distinct actionable manager in the department hierarchy |
| `company_role` | Active company members assigned the selected Spatie role in the current company team |

`Employee.manager_id` is **not** used. Department hierarchy via `Department.manager_id` remains authoritative.

Dynamic manager routing uses document workflow permissions (`documents.requests.view` plus review/approve as appropriate), not leave permissions or `CompanyLeaveApprovalSetting`.

### Runtime resolution

When HR creates a request with `workflow_preset_id`:

1. Validate the preset belongs to `current_company_id` (Form Request + controller scope)
2. Lock the active company-scoped preset row (`lockForUpdate()`), verify it is **active**, and resolve stage/target definitions atomically inside one transaction
3. Resolve each stage target server-side for the subject employee
4. Deduplicate assignees within a stage
5. Exclude the requester (self-approval block preserved)
6. Block creation when any target resolves to zero actionable users
7. Feed concrete stage assignee lists into existing `CreateDocumentWorkflowRequest`

`routing_definition_snapshot` stores sanitized target metadata only (for example, specific-user targets include user id/name but never role fields). Resolved assignees remain authoritative in `DocumentWorkflowTask` rows.

Preset edits, deactivation, department manager changes, and role membership changes after request creation do **not** alter existing tasks. Used presets cannot be deleted; deactivate them instead. Legacy company access (`users.company_id` without a pivot row) follows the same `ResolveCompanyAccess` rules as Phase 5A assignee validation.

### Permissions

| Permission | Purpose |
|------------|---------|
| `documents.workflow-presets.view` | List presets |
| `documents.workflow-presets.create` | Create presets |
| `documents.workflow-presets.update` | Edit / activate / deactivate presets |
| `documents.workflow-presets.delete` | Delete unused presets |
| `documents.requests.create` | Select an active preset while requesting approval |

Preset management permissions are not required merely to use an active preset.

### UI

- `/organization/documents/workflow-presets` — preset CRUD (linked from Documents → Requests)
- Request Approval dialog — Manual vs active preset selection with read-only preset summary

Activity events: `workflow_preset_created`, `workflow_preset_updated`, `workflow_preset_activated`, `workflow_preset_deactivated`, `workflow_preset_deleted`. `workflow_created` may include safe preset metadata.

### Explicitly not in Phase 5B

No signing, acknowledgement, email/reminders, automatic template→preset assignment, or candidate routing.

---

## Phase 6A: Unified Signing & Acknowledgement Foundation

Phase 6A adds a new unified recipient-request path for generated documents. It is separate from legacy `BulkDocumentSignatureRequest` and public `/esign/*` routes, which remain unchanged.

### Recipient requests

| Table | Purpose |
|-------|---------|
| `document_recipient_requests` | Subject-employee sign/acknowledge requests bound to an exact `DocumentInstanceVersion` |
| `document_recipient_request_events` | Domain evidence timeline (viewed, submitted, superseded, etc.) |

Supported in Phase 6A:

- **Recipient:** subject employee only (`recipient_type = subject_employee`)
- **Actions:** `sign`, `acknowledge`

### Sign vs acknowledge

| Action | PDF mutation | New `DocumentInstanceVersion` |
|--------|--------------|-------------------------------|
| **Sign** | Exact-byte FPDI overlay on canonical source | Yes — immutable signed version becomes `current_version_id` |
| **Acknowledge** | None | No — evidence stored on request + events |

Acknowledgement stores `acknowledgement_text_snapshot`, consent timestamp, IP, and user agent. It does not display as “Signed”.

### Exact version binding

Every request binds to `source_document_instance_version_id` and `source_checksum_sha256`. Public preview/download streams canonical instance bytes, not mutable Library files.

If `DocumentInstance.current_version_id` changes while a request is still `awaiting_action`, completion is rejected and the request becomes `superseded`.

### Token security

- Browser receives a raw URL-safe token once after internal creation/regeneration
- Database stores only `SHA-256` hash in `token_hash` (unique)
- Public routes: `/document-action/{token}`, `/document-action/{token}/document`, `/document-action/{token}/sign`, `/document-action/{token}/acknowledge`
- Legacy `/esign/*` and plain-text bulk tokens are unchanged

Regenerating a link replaces `token_hash`, invalidates the prior URL immediately, and records a `token_rotated` evidence event.

### Signature placement

`DocumentGenerationTemplateVersion.signature_placement_config` (schema v1) stores normalized subject signature placement for PDF Overlay templates. Draft versions are editable; published/archived versions remain immutable.

**Templates UI (Phase 6A usability):** Documents → Templates exposes **Signature placement** for company custom `pdf_overlay` templates. HR opens the editable Draft (branching a draft when needed), places a single **Employee Signature** box on the private source PDF via the visual editor (Fabric.js / PDF.js), and saves normalized coordinates to that draft version only. Publishing promotes the exact configured `signature_placement_config` with the version. Content templates do not offer this editor.

Phase 6A supports exactly one subject-employee signature (`type: signature`, `role: subject`). Manager/countersigning and multiple signers remain Phase 6B.

Signing is blocked when trusted placement cannot be resolved server-side. There is no bottom-of-page fallback, and the public signing browser never submits placement coordinates.

### Workflow gating

For a given exact version:

- No workflow → allowed
- Latest workflow **approved** → allowed
- Pending / rejected / cancelled workflow → blocked

Phase 5 workflow stages are not extended with sign/acknowledge actions in Phase 6A.

### Requests workspace

**Documents → Requests** tabs:

1. **Approvals** (Phase 5A/5B, user-facing label)
2. **Employee Signing** (Phase 6A, user-facing label)
3. **Signature Requests** (legacy, consistent with modern presentation)

### Permissions

| Permission | Capability |
|------------|------------|
| `documents.recipient-requests.view` | List/open recipient requests |
| `documents.recipient-requests.create` | Create requests and regenerate links |
| `documents.recipient-requests.cancel` | Cancel awaiting requests |

### Explicitly not in Phase 6A

No manager/countersigning, external recipients, email/reminders/WhatsApp/push, automatic workflow sign stages, candidate signing, or automatic template→preset routing. Legacy bulk signature requests remain the protected production path for Salary Declaration and related bulk flows.

## Phase 6B-1: Internal Company Countersigning

Phase 6B-1 adds a single authenticated company signatory step after the subject employee has signed. It extends the unified `DocumentRecipientRequest` domain — no separate countersignature table.

### Version chain

1. **v1 Generated** — immutable generated PDF
2. **v2 Employee signed** — subject employee completes Phase 6A sign request; becomes current
3. **v3 Company countersigned** — assigned company user signs in-app; stamped onto exact v2 bytes; becomes current

Earlier versions remain immutable. The Library representation syncs to the latest completed version (v3 when countersigned).

### Recipient model

| Field | Meaning |
|-------|---------|
| `employee_id` | Always the **subject employee** who owns the document |
| `recipient_user_id` | Internal company user assigned to countersign |
| `recipient_type` | `company_user` for countersign requests |
| `recipient_role` | `company_signatory` (existing subject requests use `subject`) |
| `recipient_name_snapshot` | Signatory display name at request creation |

Phase 6A subject-employee rows remain `recipient_type = subject_employee`, `recipient_role = subject`.

### Security

- **Subject employee** continues using public `/document-action/{token}` (Phase 6A unchanged).
- **Company signatory** must authenticate, belong to the active company, match `recipient_user_id`, and hold `documents.recipient-requests.respond`.
- Internal routes: `GET .../respond`, `GET .../document`, `POST .../sign`.
- A random `token_hash` may exist for schema compatibility but is **never exposed**; public document-action routes return 404 for `company_user` requests.

### Signature placement

`signature_placement_config` on published template versions supports up to one placement per role:

- `role: subject` — employee signature (Phase 6A)
- `role: company_signatory` — company countersignature (Phase 6B-1)

Templates → **Signature placement** editor configures both on draft PDF overlay versions. Subject-only configs continue to work for employee signing.

### Eligibility

HR may request company countersignature when:

- User has `documents.recipient-requests.create`
- Current version is the result of a completed subject sign request
- Company signatory placement exists on the bound template version
- No active duplicate countersign request for that source version
- Selected user has company access and `documents.recipient-requests.respond`

### Explicitly not in Phase 6B-1

Department manager resolution, automatic multi-stage chains, multiple company signatories, external recipients, email/WhatsApp/reminders, workflow sign stages, legacy bulk migration, and `/esign/*` changes remain Phase 6B-2 / Phase 7 / Phase 8.

## Phase 6B-2A: Department Manager Countersigning

Phase 6B-2A adds an optional **department manager** countersignature step between subject employee signing and company signatory signing. It continues to use the unified `DocumentRecipientRequest` domain.

### Supported signing orders

1. **Subject Employee → Company Signatory** (Phase 6B-1 unchanged)
2. **Subject Employee → Department Manager → Company Signatory** (new)

### Version chains

**Two-party:**

1. v1 Generated
2. v2 Employee signed
3. v3 Company countersigned

**Three-party:**

1. v1 Generated
2. v2 Employee signed
3. v3 Manager signed (stamped onto exact v2 bytes)
4. v4 Company countersigned (stamped onto exact v3 bytes)

Every version remains immutable. The Library representation syncs to each newly completed current version.

### Manager resolution

Manager recipients are resolved **server-side** via `ResolveDepartmentManagementChain::forEmployee(...)` — the same authoritative department hierarchy used by document workflow routing.

- `Employee.manager_id` is **not** used.
- The first actionable manager in hierarchy order is selected.
- Actionable means: active employee in the company, linked active User, active company membership, and `documents.recipient-requests.respond`.
- Workflow review/approve permissions and leave approval settings are **not** used for signing eligibility.
- If no eligible manager exists, creation is blocked with a clear validation message.
- Routing is snapshotted at request creation. Later hierarchy changes do not rewrite historical requests. HR may cancel and recreate if the org chart changed.

### Recipient model

| Field | Meaning |
|-------|---------|
| `employee_id` | Always the **subject employee** who owns the document |
| `recipient_user_id` | Resolved manager user (or selected company signatory) |
| `recipient_type` | `company_user` for manager and company signatory |
| `recipient_role` | `manager` or `company_signatory` |

### Signature placement

`signature_placement_config` supports up to one placement per role:

- `role: subject`
- `role: manager`
- `role: company_signatory`

Templates → **Signature placement** editor configures all three on draft PDF overlay versions. Subject signing continues to work when manager/company placements also exist. Each signing role resolves only its own placement — no fallback.

### Authorization

Manager signing uses the same authenticated internal routes and `documents.recipient-requests.respond` permission as company signatories. Public `/document-action/{token}` remains subject-employee only.

### Explicitly not in Phase 6B-2A

Automatic sequential request creation, signing flow/preset configuration, multiple internal stages, manager → director → CEO chains, parent-manager signing stage, parallel signing, email/WhatsApp/reminders, workflow `sign` stages, and legacy bulk migration remain Phase **6B-2B** / Phase 7 / Phase 8.

## Phase 6B-2B1: Signing Flow Presets + Automatic Advancement

Phase 6B-2B1 turns the manual recipient-signing chain into a configurable, single-start signing flow.

### Architecture

Review/approval and signing remain separate domains:

- Review/approval: `DocumentWorkflowPreset` → `DocumentWorkflowRequest` → `DocumentWorkflowTask`
- Signing: `DocumentSigningPreset` → `DocumentSigningFlow` → `DocumentRecipientRequest`

### Supported preset chains

1. Subject Employee
2. Subject Employee → Company Signatory
3. Subject Employee → Department Manager
4. Subject Employee → Department Manager → Company Signatory

Parent Manager / Director / CEO stages, repeated roles, parallel signing, and acknowledgement inside signing flows remain Phase **6B-2B2**.

### Routing snapshot

When HR starts a flow, recipients are resolved and snapshotted:

- Subject: employee id/name
- Manager: first actionable department manager via `DocumentRecipientManagerResolver` / `ResolveDepartmentManagementChain` (`Employee.manager_id` is not used)
- Company Signatory: preset-selected user

Later hierarchy or preset edits do **not** rewrite an active flow’s snapshot.

### Automatic advancement

After a flow-linked signature completes and commits (immutable PDF/version/library update), `AdvanceDocumentSigningFlow` runs in a **separate** transaction (`DB::afterCommit`). Signature completion is never rolled back if the next request cannot be created.

If the next signer is no longer eligible, the flow becomes `blocked` with a safe reason. HR may **Retry** (same snapshotted recipient) or **Cancel**.

### Manual requests

One-off Request Signature / Manager / Company Countersignature remain supported when no open (`active`/`blocked`) flow exists for the document instance.

### Permissions

- Manage presets: `documents.signing-presets.view|create|update|delete`
- Start / retry flows: `documents.recipient-requests.create`
- Cancel flows: `documents.recipient-requests.cancel`
- Sign as manager/company: `documents.recipient-requests.respond`

### Explicitly not in Phase 6B-2B1

Automatic template→preset assignment, auto-start after generation/approval, email/WhatsApp/push/reminders, scheduled expiry jobs, arbitrary multi-stage/repeated signer chains (Phase 6B-2B2), and legacy bulk `/esign` migration.

## Phase 6B-2B2: Advanced Sequential Multi-Stage Signing

Phase 6B-2B2 extends sequential signing flows with repeated manager and company-signatory stages, while keeping the same broad recipient roles (`subject`, `manager`, `company_signatory`). Organization-specific titles such as Director or CEO are **step labels**, not new enum roles.

### Signature slots

Recipient role answers “what kind of signer?”. Signature slot answers “which exact signature box?”.

Examples: `subject`, `manager_1`, `manager_2`, `company_signatory_1`, `company_signatory_2`.

Slots are derived server-side from step order. Clients never submit `signature_slot_key` on presets.

### Placement schema

- Schema **v1** remains readable forever: one placement per role, interpreted as default slots (`subject`, `manager_1`, `company_signatory_1`).
- Schema **v2** stores explicit `slot_key` values, unique ids/slots, contiguous occurrences, and supports repeated roles.
- Saving a draft through the placement editor normalizes to schema v2. Published/archived v1 configs stay immutable.

### Supported preset shape

- Max **8** sequential steps
- Subject required once, always sequence 1
- Then `0..N` manager stages, then `0..N` specific company-signatory stages
- No subject after managers, no manager after company signatory, no duplicate specific users

Example: Employee → Department Manager → Parent Manager → Director → CEO

### Management chain

`DocumentSigningManagementChainResolver` returns actionable unique managers in hierarchy order (deduped by User id). Manual one-manager resolution still returns the first actionable manager only.

Flow start fails before creating a flow/request when required managers or slots are missing.

### Routing snapshot v2

New flows store `schema_version: 2` with step labels, slot keys, management positions, and snapshotted recipients. Existing schema v1 snapshots continue to advance using default slots/labels.

### Advancement

`CreateDocumentSigningFlowStepRequest` creates internal next steps from the snapshot only (no hierarchy re-resolution). Manual manager/company countersign actions remain simple Subject→Manager→Company and are not relaxed for Manager 2+.

### Explicitly not in Phase 6B-2B2

Parallel / quorum / “any 2 of 3” signing, external recipients, delivery channels, reminders, scheduled expiry, auto-start after generation/approval, workflow sign stages, and legacy bulk `/esign` / Salary Declaration migration. Next roadmap phase is **Phase 7**.

## Phase 7A — Recipient Email Delivery

Phase 7A delivers recipient requests by **email** without changing the signing/acknowledgement state machine.

### Concepts

| Record | Role |
|--------|------|
| `DocumentRecipientRequest` | Authoritative requested action |
| `DocumentSigningFlow` | Orchestration |
| `DocumentRecipientRequestDelivery` | Delivery evidence / channel attempt ledger |

Email failure never rolls back request creation or flow advancement. Awaiting requests remain actionable from the Requests workspace even when SMTP fails.

### Delivery ledger

Table: `document_recipient_request_deliveries`

- Channel (7A): `email`
- Purpose (7A): `initial`, `manual_resend`
- Status: `queued`, `sent`, `failed`, `suppressed`
- Unique `(document_recipient_request_id, channel, delivery_sequence)`
- Unique nullable `access_token_hash`
- Snapshots destination, template slug, and optional subject at send time

### Automatic initial email

Every newly created awaiting recipient request queues an initial email delivery (subject sign/acknowledge, manager/company countersign, and advanced-flow step requests) through `QueueDocumentRecipientRequestEmail`, after the surrounding DB transaction commits.

### Subject vs internal links

- **Subject employee:** delivery-specific public access token (SHA-256 only in ledger). Action URL: `/document-action/{delivery_token}` via named routes. Raw request token remains for Copy/Regenerate link UX and is independent of email deliveries.
- **Internal company users (manager / company signatory):** authenticated respond URL only. No delivery access token. Public `/document-action/*` cannot resolve company-user requests.

### Tokens and regenerate

- Raw bearer tokens are never stored in domain tables or returned by resend.
- Subject email jobs implement Laravel `ShouldBeEncrypted` so the raw delivery token is encrypted in the queue payload.
- **Regenerate secure link** rotates `DocumentRecipientRequest.token_hash` and sets `revoked_at` on all active subject email delivery access tokens for that request (history retained). Queued (unsent) deliveries are also marked `suppressed` with `access_token_revoked` so they cannot be dispatched; historical `sent` rows stay `sent` with `revoked_at` recorded.
- **Manual resend** creates a **new** delivery sequence with a new delivery token; it does **not** rotate the request token and does **not** revoke earlier email links by default.
- Template resolution at request time uses the live (non-trashed) Email Template only — soft-deleted/missing/disabled templates suppress delivery without restoring or reseeding Settings content. `EmailTemplatesSeeder` remains the intentional restore path.
- Queue handoff failures after DB commit are swallowed and left for `documents:dispatch-recipient-emails`. After successful SMTP, Sent ledger persistence failures retry without a second Mail send; reconciliation can repair remembered SMTP handoffs.
- Dynamic placeholder values are HTML-escaped when substituted into HTML template bodies; subject lines use plain values.

### Template

Slug: `document_recipient_action_request` (Document category). Managed under Settings → Email Templates. Non-clobbering seeder. If disabled, delivery is `suppressed` (`email_template_disabled`); request creation still succeeds.

No PDF attachments — secure action link only.

### Queue / retry

- Job: `DeliverDocumentRecipientRequestEmailJob` (unique by delivery id, backoff `[30, 60, 120]`)
- Claim/dispatch + SMTP handoff memory mirror Crew operational-alert reliability patterns
- Scheduler: `documents:dispatch-recipient-emails` every minute (`withoutOverlapping`) reconciles queued rows that never completed queue handoff
- SMTP via `MailSettingsService`; never log credentials or raw transport exception text

### Permissions / tenancy

- Automatic queue: no extra permission
- Explicit Send / Resend: `documents.recipient-requests.create` (`POST …/recipient-requests/{id}/email`)
- All ledger rows and jobs are company-scoped; delivery `company_id` must match the recipient request

### Explicitly not in Phase 7A

Reminders / “remind after X days”, expiry schedulers, auto-start after generation/approval, template→preset auto-assignment, WhatsApp/Web Push/SMS, parallel signing, external recipients, legacy `/esign` / BulkDocumentSignatureRequest / Salary Declaration migration (Phases 7B / 7C+).

## Phase 7B — Automatic Reminders + Expiry Reconciliation

Phase 7B makes outstanding recipient requests operationally self-managing without changing the 14-day expiry duration.

### Company reminder policy

Table: `document_recipient_automation_settings` (unique `company_id`).

- Default when no row exists: `reminders_enabled = false`
- Suggested offsets when enabling: `[7, 3, 1]` (days before expiry)
- Validation: max 5 unique integer days in `1..13`, stored descending
- Permissions: `documents.recipient-automation.view|update` (settings only — not signing/resend/create)
- UI: Documents → Requests → **Reminder settings** sheet
- Policy changes apply to **new requests only**

### Per-request immutable snapshot + scheduling pointer

At create time, when reminders are **enabled**, every recipient-request path stores:

```json
{ "schema_version": 1, "enabled": true, "days_before_expiry": [7, 3, 1] }
```

When reminders are **disabled**, `reminder_policy_snapshot` is `NULL`.

| Field | Role |
|-------|------|
| `reminder_policy_snapshot` | Immutable policy evidence for that request |
| `next_reminder_at` | Mutable operational scheduling cursor (next due reminder, or `NULL`) |

Pre-7B / disabled rows keep `NULL` snapshot and `NULL` next_reminder_at → automatic expiry still runs; automatic reminders do not. Changing company settings later never rewrites existing snapshots.

### Automatic reminders

- Purpose: `reminder` (new delivery ledger row; never mutates Initial)
- Template slug: `document_recipient_action_reminder` (non-clobbering seeder)
- Idempotency: `automation_key` (e.g. `reminder:7d`) + unique `(request_id, channel, automation_key)`
- Scheduler selects only **due** rows: `AwaitingAction` + `next_reminder_at <= now()` + `expires_at > now()` (indexed), ordered by `next_reminder_at`, batch 100
- After each pass, `next_reminder_at` advances to the next future unconsumed slot or `NULL`
- Subject: delivery-specific bearer token (SHA-256 only); Internal: authenticated respond URL
- Missed-window rule after scheduler downtime: suppress older due slots as `reminder_window_missed`; queue only the closest-to-expiry due slot (at most one reminder email per reconcile pass)
- Reminder SMTP failure / template/email suppression still consumes that slot and advances the pointer
- Reminder SMTP failure never expires the request or blocks the flow

### Automatic expiry

Command: `documents:reconcile-recipient-requests` every five minutes (`withoutOverlapping`), optional `--company=`.

Order: (1) expire overdue, (2) repair expired active signing flows, (3) process due reminders.

- `AwaitingAction` + `expires_at <= now()` → `Expired` + clear `next_reminder_at` + `RequestExpired` event + activity `recipient_request_expired`
- Delivery cleanup: revoke subject access tokens; suppress queued deliveries with `request_expired`
- Flow-linked: after request transaction commits, `BlockDocumentSigningFlow` (no Request→Flow lock inversion); `can_retry = false`
- Durable safety net: if the immediate post-commit block fails, the same command later repairs `Expired` current-step requests whose flow is still `Active`
- Submit signature/acknowledgement recheck `expires_at <= now()` **inside** the locked transaction (stale in-memory models cannot complete after DB expiry)

`documents:dispatch-recipient-emails` remains delivery handoff / SMTP-ledger repair only.

### Explicitly not in Phase 7B

WhatsApp/Web Push/SMS, parallel signing, external recipients, expired-step reissue, extending expiry, legacy `/esign` / BulkDocumentSignatureRequest / Salary Declaration migration. Template→preset lifecycle automation is Phase 7C.

## Phase 7C — Document Lifecycle Automation

Phase 7C binds optional workflow and signing presets onto generation template versions so successful generation can automatically start review/approval and then signing without manual “start” clicks.

### Template bindings

- Columns on `document_generation_template_versions`: `document_workflow_preset_id`, `document_signing_preset_id` (nullable, company-scoped FKs)
- Draft saves accept inactive presets (same company); **publish** requires active presets and, for signing, signature placement covering every preset step
- Content templates may bind workflow only; PDF Overlay may bind workflow and/or signing
- Branch draft / duplicate copy automation bindings (signing only for PDF Overlay)
- Preset delete is blocked while referenced by any template version (deactivate instead)
- UI: Templates → **Automation** sheet; route `PUT organization/documents/templates/{template}/automation` (`documents.templates.update`)

### Per-document lifecycle row

Table: `document_lifecycle_automations` (unique `document_instance_id`).

| Field | Role |
|-------|------|
| `policy_snapshot` | Immutable workflow/signing preset ids + names at generation |
| `status` | `pending` → `active` → `completed` / `stopped` / `blocked` |
| `stage` | `review`, `signing`, or `done` |
| Linked request/flow ids | Provenance for the automated children |

**Atomic registration:** when a template version has lifecycle automation configured, `GenerateCustomDocumentsJob` creates the Pending `DocumentLifecycleAutomation` row **inside the same DB transaction** that creates `EmployeeDocument`, `DocumentInstance`, and version 1. A generated instance is never committed without its lifecycle registration. Registration write failure rolls back generation (existing file compensation still deletes canonical/library PDFs).

**Execution after commit:** starting workflow/signing runs only after that transaction commits. Downstream start/routing failure leaves generation completed, keeps the lifecycle row, and marks the lifecycle Blocked when possible — it does **not** delete generated PDFs.

### Runtime behavior

1. **Start** — after commit, create workflow from snapshotted preset when present; otherwise start signing; otherwise mark completed. Before creating a workflow or signing flow, the instance `current_version_id` must equal `source_document_instance_version_id` (`lifecycle_source_version_changed` otherwise).
2. **Advance** — on workflow **Approved**, after commit start snapshotted signing (or complete if signing was not configured). Exact source-version gate applies again before signing. An existing linked signing flow is synchronized from its real status (never blindly marked Active while the flow is Blocked).
3. **Stop** — workflow reject/cancel after commit stops lifecycle (`workflow_rejected` / `workflow_cancelled`); signing cancel syncs to stopped
4. **Sync** — signing flow Completed / Cancelled / Blocked (and retry → Active) mirrors into lifecycle via `SyncDocumentLifecycleFromSigningFlow`
5. **Manual guard** — while lifecycle is pending/active/blocked, manual workflow create and signing start are rejected (`DocumentLifecycleAutomationGuard`); lifecycle-started actions pass `skipLifecycleGuard: true`. Atomic registration closes the generation → manual-start race.
6. **Retry** — blocked lifecycles: `POST organization/documents/{document}/lifecycle-automation/retry` (`documents.recipient-requests.create`); document show card + Retry button. Retry follows existing linked workflow/signing state (no duplicate children). Exact source-version divergence (`lifecycle_source_version_changed`) and non-retryable expired signing steps are **not** retryable; lifecycle `can_retry` reflects that. Successful recovery alone emits `document_lifecycle_retried`.
7. **Reconciliation (crash recovery only)** — `documents:reconcile-lifecycle-automations` every 5 minutes recovers lost after-commit starts and stale Active/Blocked rows. Scheduler batches select only actionable mismatches (terminal workflows; signing-flow/lifecycle desync) so healthy Active or Pending rows cannot starve recovery. It does not replace immediate post-commit execution and does not resurrect Completed/Stopped lifecycles.

### Explicitly not in Phase 7C

WhatsApp/Web Push/SMS, parallel signing, external recipients, expired-step reissue, extending expiry, legacy `/esign` / BulkDocumentSignatureRequest / Salary Declaration migration (Phase 8+).

## Phase 8A — Production Hardening & Integrity

Phase 8A does not add a new document workflow. It adds a company-scoped integrity auditor so operators can detect (and, in a few cases, safely repair) contradictions before any legacy BulkDocumentSignatureRequest / `/esign` migration.

### Command

```bash
php artisan documents:audit-integrity
php artisan documents:audit-integrity --company=12
php artisan documents:audit-integrity --verify-files
php artisan documents:audit-integrity --company=12 --repair-safe
```

`--company` must be a positive integer (same validation as `documents:reconcile-lifecycle-automations`). Invalid values fail the command without scanning.

Default mode is **read-only**. It never mutates rows, never deletes files, and never sends email.

`--verify-files` checks **every** company-owned immutable `DocumentInstanceVersion` canonical artifact (not only the current pointer), plus linked `EmployeeDocument` projection files, including size/checksum metadata when present. Checksum re-hashing is not part of scheduled reconciliation. Missing canonical version bytes are **High**; missing library projection files remain **Warning**.

`--repair-safe` applies only deterministic repairs:

- Terminal recipient requests (`completed` / `expired` / `cancelled` / `superseded`) that still have `next_reminder_at` → clear the pointer
- Lifecycle Active/Review vs terminal workflow, or Active/Blocked signing vs signing-flow status → call the existing lifecycle advance / stop / `SyncDocumentLifecycleFromSigningFlow` actions

It does **not** repair missing PDFs, cross-company pointers, missing versions, version-number corruption, missing signatures/evidence, historical membership loss, or historical rewrite. Those are reported only.

Safe repairs stream across **every** repairable issue during the chunked scan (not only the retained diagnostic sample). Each successful repair writes one company-scoped activity row (`action`, `company_id`, `entity_type`, `entity_id`, `repair_code`). Raw tokens, paths, PDF bytes, merge values, email bodies, and credentials are not recorded. Read-only audits do not write one activity row per issue.

### Checks

The auditor (`App\Support\Documents\Integrity\DocumentIntegrityAudit`) inspects company-owned records in bounded chunks (100 rows). Aggregate severity / repairable / total counts stay exact. Only a bounded sample of issues is retained for CLI diagnostics (`RETAINED_ISSUE_LIMIT = 100`, table display `TABLE_LIMIT = 50`). Issue payloads never include tokens, signature images, private paths, merge values, PDF content, or email bodies.

| Area | Examples |
|------|----------|
| Document instance | Missing current version (High); current version on another instance or company (Critical); EmployeeDocument company/employee mismatch; generation template-version company mismatch |
| Version history | Company/instance mismatch, non-positive or duplicate version numbers. History is never rewritten. With `--verify-files`, every immutable canonical version is checked. |
| Workflow | Company/instance/version/preset tenancy; stage/task ownership; **actionable** Pending/Active/Pending tasks with a missing or inaccessible assignee (`workflow_task_assignee_unavailable`, High). Historical completed/rejected/skipped/cancelled task snapshots are not invalidated by a null assignee id or later membership loss. |
| Lifecycle | Source version / template / linked workflow or signing-flow tenancy; stale child state (Warning, repairable via existing reconciliation) |
| Signing flow | Starting version tenancy; preset provenance; invalid current step; duplicate active recipient requests on the current step |
| Recipient requests | SIGN completed without a later same-instance result version (High); ACK completed with a result version (High); subject employee binding mismatch (High); terminal reminder pointer (Warning, repairable); awaiting request missing expiry; **AwaitingAction** CompanyUser without current access (`recipient_internal_assignee_unavailable`, High). Terminal internal-signer provenance is not flagged for later membership loss. |
| Delivery ledger | Delivery/request company mismatch; queued reminder still attached to a terminal request (Warning; delivery repair stays with the existing dispatcher/reconciler) |

**Critical** = cross-company ownership or a current/result version that belongs to another instance/tenant. **High** = missing canonical evidence / actionable assignee unavailability / structural version gaps. **Warning** = recoverable operational drift or optional missing projection files.

Workflow and recipient routing rows are immutable snapshots. Later company membership deactivation, role changes, or permission loss do **not** rewrite history and do **not** create Critical “cross-company” corruption merely because a historical actor is no longer currently eligible. Current-access checks apply only to still-actionable rows.

`--company=A --repair-safe` never updates company B.

### Deletion / history protection

`DocumentDeletionService` keeps the established archival pattern: unlinking `DocumentInstance.employee_document_id` and removing the library projection **after** the database transaction commits. Canonical `DocumentInstance` / `DocumentInstanceVersion` rows, workflow, lifecycle, signing flow, recipient requests, evidence events, and delivery history are not cascade-deleted. Physical bytes are never deleted before the DB commit.

Template PDF replacement and signed-library sync keep the same compensation order (store new file → commit DB → delete old file; roll back deletes the new file).

### CLI output

The command prints aggregate counts only (`Critical`, `High`, `Warning`, `Repairable`, `Repaired`) plus a bounded table of `code`, `entity`, `id`, `severity`. It does not print employee private data, tokens, PDF paths, signature contents, merge values, or email addresses.

There is no HR browser repair console. Integrity audit is CLI/backend operational tooling.

### Explicitly not in Phase 8A

Legacy `BulkDocumentSignatureRequest` / `/esign/{token}` migration, Salary Declaration / Salary Certificate cutover, WhatsApp/SMS/Web Push recipient delivery, parallel/quorum signing, expiry extension, automatic expired-step reissue, Recruitment/Payroll/Crew/Attendance/Leave changes, and new REST APIs.
