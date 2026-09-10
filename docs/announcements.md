# Announcements

Company-scoped announcements can be delivered through:

- In-app (notification bell / inbox)
- Email
- WhatsApp

Browser Web Push remains an automatic extension of the **In-app** channel. See [Announcement Web Push](./announcements-web-push.md).

## Permissions

| Permission | Use |
|------------|-----|
| `announcements.view` | Browse / show |
| `announcements.create` | Create drafts and recipient preview |
| `announcements.update` | Edit draft/scheduled, manage attachments |
| `announcements.publish` | Publish, and **Send test to me** |
| `announcements.cancel` | Cancel scheduled |
| `announcements.retry` | Retry failed deliveries |
| `announcements.download_attachments` | Download publisher attachments |

Frontend `can` flags are UX only. Routes enforce Spatie permissions with the active company team.

## Send test to me

Publishers with `announcements.publish` can send a **test** of the current draft/scheduled (or unsaved create-form) content to their own company-linked contact details before publishing.

### Purpose

Verify the **real** Email and WhatsApp output employees will receive, without publishing.

### Allowed test channels

- Email
- WhatsApp

In-app and Web Push are **not** part of Test Send.

### Route

| Method | Path | Middleware |
|--------|------|------------|
| POST | `/organization/announcements/send-test` | `can:announcements.publish`, `throttle:5,1` |

Name: `organization.announcements.send-test`

### Self-recipient resolution

The browser must **not** submit destination addresses. The server resolves destinations from:

1. Authenticated user
2. `current_company_id` from trusted request attributes

Lookup the Employee in the active company where `user_id` matches the authenticated user.

| Channel | Destination |
|---------|-------------|
| Email | `ResolveEmployeeAnnouncementEmail` (work → personal → linked user email). If no company employee exists, falls back to the authenticated `User.email`. |
| WhatsApp | `Employee.phone` normalized with `WhatsAppService::normalizePhone()` — no arbitrary numbers |

Frontend receives only masked values (`m***@company.com`, `+971******67`). Raw contacts are never returned.

### Production renderers

Test Send reuses production builders — no separate fake templates:

| Channel | Shared path |
|---------|-------------|
| Email | `BuildAnnouncementEmailContent` + `resources/views/mail/announcement.blade.php` (subject prefixed with `[TEST]`) |
| WhatsApp | `BuildAnnouncementWhatsAppTemplatePayload` + enabled Meta template slug `announcement` via `WhatsAppService::sendTemplate()` |

Preview, Test Send, and production delivery share `ResolveAnnouncementWhatsAppTemplate` (enabled `announcement` slug only — no General-template fallback).

Body HTML is sanitized with `SanitizeAnnouncementHtml` before Email Test Send.

### State / data integrity

Test Send must **not**:

- Publish the announcement or set `published_at` / `published_by`
- Change `scheduled_at` or status
- Create `AnnouncementRecipient` or `AnnouncementDelivery` rows
- Trigger Web Push or appear in the employee inbox
- Affect delivery analytics

Draft remains draft; scheduled remains scheduled.

Unsaved create forms can test without persisting an announcement. Persisted draft/scheduled attachments are represented the same way as Email preview (`#` placeholder links in V1).

### `announcement_id` and `channels`

| Input | Behavior |
|-------|----------|
| `announcement_id` omitted / `null` | Unsaved create-form Test Send (transient content only) |
| Same-company **Draft** or **Scheduled** id | Allowed; may reuse that announcement’s persisted attachment context |
| Missing id, or id belonging to another company | Rejected with **404** (no existence leak) |
| Same-company Published / Cancelled / otherwise non-editable id | Rejected with **422** validation (`announcement_id`) — never treated as unsaved |

`channels` must be an array of `email` and/or `whatsapp`. Malformed values (string, `null`, object) return **422** validation errors; they must not produce a 500. Duplicate channel values are normalized before send.

### Audit

Activity log event: `announcement_test_sent` (log `announcements`), company-scoped.

Properties include announcement id (when known), channels requested, and per-channel attempted/success flags. Secrets, raw provider responses, and full contact values are not logged. Viewing activity still requires `audit.view`.

### UI

On create/edit, **Send test to me** appears when Email and/or WhatsApp are selected and the user has `announcements.publish`. A dialog shows masked destinations, per-channel availability, and send results. The request is disabled while in flight.

## Related

- [Announcement Web Push](./announcements-web-push.md)
- [Email configuration](./email-configuration.md)
- [WhatsApp integration](./whatsapp-integration.md)
