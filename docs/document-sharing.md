# Document sharing

HR can share employee documents via time-limited links and WhatsApp-friendly messages when permitted.

## Permissions

| Permission | Capability |
|------------|------------|
| `documents.view` | Browse and preview |
| `documents.share` | Generate share links (bulk on employee browse) |
| `documents.download` | Download single file, folder ZIP, bulk ZIP |

Sharing does not grant upload or delete.

## Share link flow

1. On employee document browse, select one or more files.
2. Use WhatsApp/share action (requires `documents.share`).
3. Frontend calls `POST /organization/documents/employees/{employee}/files/share-links` with document IDs.
4. Backend: `DocumentBulkShareLinksController` + `DocumentShareLinkService` creates signed/temporary access.
5. Public route (no auth): `GET /organization/documents/share/{document}` — `DocumentShareController`

## WhatsApp

- Feature module: `resources/js/features/organization/documents/whatsapp-share/`
- Builds a message with links returned from the share-links API
- Used from employee browse toolbar (`employee.tsx`)

## Other bulk actions (same page)

| Action | Route | Permission |
|--------|-------|------------|
| Download ZIP (selected files) | `POST .../files/bulk-download` | `documents.download` |
| Download employee folder | `GET .../employees/{id}/download` | `documents.download` |
| Bulk delete | `DELETE .../files/bulk` | `documents.delete` |
| Merge PDF | `POST .../files/merge-pdf` | `documents.download` |
| Email documents | `POST .../files/email` | `documents.view` (see implementation) |

## Upload and edit

Profile and browse upload flows use `EmployeeDocumentController` and FormRequests under `App\Http\Requests\Organization\EmployeeDocument/`.

Document number, issue date, and expiry can be set on upload/edit when configured in onboarding or forms.

## Tests

`tests/Feature/Organization/DocumentShareTest.php`
