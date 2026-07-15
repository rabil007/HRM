# Document sharing

HR can share employee documents and folders via persisted, time-limited portal links.

## Permissions

| Permission | Capability |
|------------|------------|
| `documents.view` | Browse and preview |
| `documents.share` | Create file/folder share links |
| `documents.download` | Download single file, folder ZIP, bulk ZIP |

Sharing does not grant HR upload or delete. Guest upload is an explicit flag on a **folder** share only.

## Persisted shares (`document_shares`)

Each share is one DB row with a unique `token`:

| Field | Notes |
|-------|--------|
| `scope` | `folder` (all employee files) or `files` (selected IDs) |
| `employee_document_ids` | JSON list for `files`; null/empty for `folder` |
| `password_hash` | Optional bcrypt |
| `expires_at` | Default 24 hours |
| `can_download` | Default true |
| `can_upload` | Folder only; default false |
| `revoked_at` | Soft revoke |

## HR flows

1. **Selected files** (employee browse): Share links → one portal URL for all selected docs.  
   `POST /organization/documents/employees/{employee}/files/share-links`
2. **Folders** (documents index or Share folder on browse): password, expiry, download/upload toggles.  
   `POST /organization/documents/folders/share-links`

## Guest portal

Public signed routes (`signed` + throttle):

- `GET /documents/shared/{token}` — Inertia `shared/show`
- `POST /documents/shared/{token}/unlock`
- `GET /documents/shared/{token}/files/{document}/download|preview`
- `POST /documents/shared/{token}/upload` (folder + `can_upload`)

Guests see the allowed file list and can only use enabled actions. Uploads become normal `employee_documents` for that employee.

## Legacy file download links

Ephemeral signed URLs to `organization/documents/share/{document}` (optional `pwd_hash`) remain for already-issued links and WhatsApp template attachments via `DocumentShareLinkService`.

## WhatsApp

Feature module: `resources/js/features/organization/documents/whatsapp-share/`  
Builds messages from the new one-link portal URLs (files) or folder share URLs.

## Tests

`tests/Feature/Organization/DocumentShareTest.php`
