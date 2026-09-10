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

## WhatsApp templates

OMS-HRM supports **multiple** Announcement-compatible WhatsApp Meta templates.

- Templates are platform-global (`whatsapp_templates`), not company-owned.
- Only enabled templates with category `announcement` and an explicit `payload_profile` appear in the Announcement composer.
- Document/Payroll templates are never offered for Announcements.
- The composer shows a business-friendly **label** first (for example `Promotion Announcement`), with Meta name/language as secondary text.
- Selected template is stored on the announcement as nullable `whatsapp_template_id`.
- Submitted template IDs are validated against the enabled Announcement-template set. Invalid IDs are rejected; there is no silent fallback to an unrelated template.
- Announcements without a selected template keep backward-compatible resolution of the legacy enabled `announcement` slug.

### Payload profiles

OMS-HRM does **not** map arbitrary Meta templates at send time. Profile is stored on the template row:

| Profile | Contract |
|---------|----------|
| `announcement_legacy_v1` | Body variables: company, title, summary, priority, view link |
| `announcement_title_body_v2` | Header `{{1}}` = title; Body `{{1}}` = resolved WhatsApp message |

New Announcement templates should use **Title + Message** (`announcement_title_body_v2`).

Resolved WhatsApp message:

1. `whatsapp_message` when present
2. otherwise plain text derived from canonical `body_html`
3. optional `whatsapp_link` is **appended** to the body value for v2 (not a separate Meta variable)
4. blank link does **not** send `N/A` for v2

Canonical Announcement content remains `title` + `body_html`. Priority remains in the module for in-app/email/reporting, but is **not** included in the v2 WhatsApp payload.

Pending Meta review templates must stay disabled until an administrator enables them after Meta approval. Shipping this code does not auto-activate pending templates.

### Shared builder parity

Preview, Test Send, and production WhatsApp delivery all use `BuildAnnouncementWhatsAppContent` (via `BuildAnnouncementWhatsAppTemplatePayload` for send call sites).

```text
Announcement
        ↓
selected template / legacy fallback
        ↓
BuildAnnouncementWhatsAppContent
     ↙        ↓          ↘
Preview   Test Send   Production
```

Composer channel preview is loaded from `POST /organization/announcements/preview-channels`, which also reuses `BuildAnnouncementEmailContent` + the Blade email renderer for Email exact preview.

## AI Assist

Optional content assistance reuses Application AI (`AiSettingsService` / Laravel AI). It does **not** depend on the Smart Employee Search enable toggle.

| Method | Path | Notes |
|--------|------|-------|
| POST | `/organization/announcements/ai-assist` | create **or** update permission; throttled `20,1` |

Capabilities: generate/improve/make professional/friendly/shorten/fix grammar/create WhatsApp version/suggest template purpose.

Structured output is validated server-side. `template_purpose` is a closed enum. Laravel maps purpose to a trusted enabled Announcement template. The model never selects database IDs or Meta names. The user must explicitly confirm a suggested template.

AI never publishes or sends. Provider requests include only writing instructions and authored content (title/body/optional WhatsApp message, optional company display name). No employee/recipient/payroll/document/credentials data.

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
| WhatsApp | `BuildAnnouncementWhatsAppContent` + selected/enabled Announcement template via `WhatsAppService::sendTemplate()` |

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
- [AI settings](./ai-settings.md)
