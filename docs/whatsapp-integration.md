# WhatsApp Integration

OMS-HRM integrates with the **Meta WhatsApp Cloud API** through the Graph API. The implementation uses Meta business-account IDs, phone-number IDs, access tokens, App IDs, App Secrets, and `messaging_product: whatsapp` payloads.

## Configuration

Application Settings stores a single platform WhatsApp integration in `whatsapp_settings`.

- `access_token` and `app_secret` use Laravel encrypted casts.
- Secret values are hidden on the model and are returned to Inertia as empty strings plus `has_*` flags.
- Blank secret submissions preserve the stored value.
- Settings changes are recorded in the company-scoped activity log using the trusted `current_company_id`. The audit entry contains public identifiers, enabled state, secret-presence flags, and the names of rotated credentials; it never contains credential values.

## Webhook routes

Both callback paths remain supported:

- `/whatsapp/webhook` — current callback URL
- `/webhooks/whatsapp` — legacy callback URL

Both callbacks are stateless and explicitly exclude the browser-oriented `web` middleware group. This prevents session persistence or Inertia shared-data caching before webhook authentication.

Authenticated POST callbacks are limited to 120 requests per minute per client. The limiter runs after signature authentication because rate-limit storage may be database-backed; rejected requests therefore cannot mutate rate-limit storage before authentication.

### GET subscription verification

GET requests preserve Meta's existing subscription-verification flow:

1. Require `hub.mode=subscribe` and a non-empty `hub.challenge`.
2. Compare `hub.verify_token` with the stored webhook verify token using `hash_equals`.
3. Return the plain-text challenge when valid; otherwise return `403`.

The underscore aliases (`hub_mode`, `hub_verify_token`, `hub_challenge`) remain accepted for compatibility.

### POST payload authentication

Meta signs webhook event payloads in `X-Hub-Signature-256`. OMS-HRM follows the [Meta webhook validation mechanism](https://developers.facebook.com/docs/graph-api/webhooks/getting-started):

1. Read the untouched raw HTTP request body.
2. Calculate `sha256=` plus the hexadecimal HMAC-SHA256 digest using the configured App Secret.
3. Compare the complete expected and supplied signatures with `hash_equals`.
4. Reject missing, malformed, or mismatched signatures with `403` before JSON parsing, delivery lookup, or any database mutation.

The POST path uses a read-only lookup for the active settings row. It does not call `WhatsAppSetting::current()`, because that method may create or restore the singleton row.

## Integration and tenant isolation

The WhatsApp integration is currently platform-global rather than company-owned. Signed status events are processed only when:

- the payload object is `whatsapp_business_account` and its messaging product is `whatsapp`;
- `entry.id` matches the configured Meta business-account ID;
- `metadata.phone_number_id` matches the configured phone-number ID;
- the provider message reference identifies exactly one WhatsApp delivery; and
- the delivery, recipient, and announcement all have the same positive `company_id`.

Ambiguous provider references, mismatched integration identifiers, malformed ownership chains, unknown statuses, and unknown messages are acknowledged without mutation. This prevents a public webhook from selecting a company using request-controlled tenant data.

Exact retries are idempotent and do not rewrite delivery timestamps. Provider progress is monotonic (`sent` → `delivered` → `read`), successful terminal states cannot be changed to `failed`, and terminal failures cannot be reopened by a replay. Meta's signature proves authenticity but does not itself carry a freshness guarantee, so these transition rules prevent an older valid callback from regressing stored state.

## Delivery status updates

Authenticated matching events map Meta statuses to announcement delivery states:

- `sent`
- `delivered`
- `read`
- `failed`

After a valid update, the announcement delivery summary is refreshed through the existing announcement Support action.
