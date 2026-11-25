# Webhooks & Service Tokens

Ognivo Core provides signed outbound webhooks for event-driven external integrations and scoped service tokens (`srv_`) for machine-to-machine API authentication with automatic audit attribution.

---

## Outbound Webhooks

All 17 [Domain Events](./events-catalog.md) are automatically forwarded to matching active webhook endpoints by `App\Listeners\WebhookEventSubscriber` via queued `App\Jobs\DeliverWebhookJob` jobs. Because domain events implement `ShouldDispatchAfterCommit`, webhooks are only queued once the originating database transaction commits.

### Subscription Matching

Each `WebhookEndpoint` stores a JSON array of patterns in `subscribed_events`. Matching rules in `WebhookEndpoint::isSubscribedTo(string $eventName)`:

| Pattern | Example | Behavior |
| --- | --- | --- |
| `*` | `"*"` | Matches every domain event emitted by Ognivo Core. |
| Prefix wildcard (`<prefix>.*`) | `"instance.*"` | Matches any event whose name starts with `"instance."` (e.g. `instance.created`, `instance.stopped`). |
| Exact event name | `"host.registered"` | Matches only the exact event name. |

Only endpoints with `is_active = true` receive deliveries.

### Administrative Webhook API

All webhook management endpoints live under `/api/v1/webhooks` (`App\Http\Controllers\Api\V1\WebhookController`) and require `auth:sanctum`, `active`, and `admin` middleware:

| Method & Path | Description |
| --- | --- |
| `GET /api/v1/webhooks` | Lists configured endpoints (`id`, `url`, `subscribed_events`, `is_active`, `failure_count`, timestamps). Signing `secret` is hidden. |
| `POST /api/v1/webhooks` | Creates an endpoint (`url`: required URL max 500; `subscribed_events`: non-empty string array max 100 chars each). Returns `201` with `data` and the one-time 64-hex-character `secret`. |
| `DELETE /api/v1/webhooks/{id}` | Deletes an endpoint and cascades its `webhook_deliveries` records. |
| `GET /api/v1/webhooks/{id}/deliveries` | Paginated delivery log (`per_page` 1–100, default 25) ordered newest first. |
| `POST /api/v1/webhooks/{id}/test` | Queues an asynchronous `ping` test delivery (`202 Accepted`, returns `delivery_id`). |

### Delivery Semantics & Headers

`DeliverWebhookJob` dispatches an HTTP `POST` request with a **10-second timeout**, **up to 4 attempts**, and exponential backoff intervals of **`60s`, `300s`, `900s`, and `3600s`**.

Each delivery includes:

| Header | Value |
| --- | --- |
| `Content-Type` | `application/json` |
| `X-Ognivo-Event` | Event name (e.g. `instance.created`, or `ping` for test deliveries) |
| `X-Ognivo-Signature` | `sha256=<hex_hmac>` computed over the raw JSON request body using the endpoint's `secret` |
| `X-Ognivo-Delivery` | Numeric ID of the `WebhookDelivery` record as a string |

### HMAC-SHA256 Signature Verification

Always verify `X-Ognivo-Signature` against the **exact raw request body bytes** (do not re-encode parsed JSON) using a **constant-time comparison**.

#### PHP

```php
<?php

declare(strict_types=1);

function verifyOgnivoWebhook(string $rawBody, string $signatureHeader, string $secret): bool
{
    if (! str_starts_with($signatureHeader, 'sha256=')) {
        return false;
    }

    $expected = 'sha256=' . hash_hmac('sha256', $rawBody, $secret);

    return hash_equals($expected, $signatureHeader);
}

$rawBody = file_get_contents('php://input') ?: '';
$signatureHeader = $_SERVER['HTTP_X_OGNIVO_SIGNATURE'] ?? '';
$eventName = $_SERVER['HTTP_X_OGNIVO_EVENT'] ?? '';
$deliveryId = $_SERVER['HTTP_X_OGNIVO_DELIVERY'] ?? '';

if (! verifyOgnivoWebhook($rawBody, $signatureHeader, getenv('OGNIVO_WEBHOOK_SECRET') ?: '')) {
    http_response_code(401);
    exit('Invalid signature');
}

$payload = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
http_response_code(200);
```

#### Python

```python
import hashlib
import hmac
import json


def verify_ognivo_webhook(raw_body: bytes, signature_header: str, secret: str) -> bool:
    if not signature_header.startswith("sha256="):
        return False

    digest = hmac.new(
        secret.encode("utf-8"),
        raw_body,
        hashlib.sha256,
    ).hexdigest()
    expected = f"sha256={digest}"

    return hmac.compare_digest(expected, signature_header)


# Example usage inside a request handler:
# raw_body = request.body  # raw bytes
# signature_header = request.headers.get("X-Ognivo-Signature", "")
# if not verify_ognivo_webhook(raw_body, signature_header, WEBHOOK_SECRET):
#     return Response(status_code=401)
# payload = json.loads(raw_body.decode("utf-8"))
```

#### Node.js

```js
import crypto from "node:crypto";

export function verifyOgnivoWebhook(rawBody, signatureHeader, secret) {
  if (typeof signatureHeader !== "string" || !signatureHeader.startsWith("sha256=")) {
    return false;
  }

  const bodyBuffer = Buffer.isBuffer(rawBody)
    ? rawBody
    : Buffer.from(rawBody, "utf8");

  const digest = crypto
    .createHmac("sha256", secret)
    .update(bodyBuffer)
    .digest("hex");

  const expected = Buffer.from(`sha256=${digest}`, "utf8");
  const actual = Buffer.from(signatureHeader, "utf8");

  if (expected.length !== actual.length) {
    return false;
  }

  return crypto.timingSafeEqual(expected, actual);
}
```

---

## Service Tokens (`srv_`)

Service tokens allow external daemons, CI pipelines, and extensions to authenticate to Ognivo APIs without tying credentials to a human user account.

### Token Format & Storage

When `App\Models\ServiceToken::generate()` creates a token:

1. It generates 20 cryptographically random bytes encoded as 40 hex characters (`bin2hex(random_bytes(20))`).
2. The plain-text token returned **once** to the caller has the format `srv_<40-hex-characters>`.
3. Only the SHA-256 hash of the `<secret>` portion (without the `srv_` prefix) is persisted in `service_tokens.token_hash` (`VARCHAR(64)` unique).

### Abilities

Each service token stores a JSON array of string abilities (`abilities`), evaluated via `ServiceToken::hasAbility(string $ability)`:

- Specific ability strings (e.g. `"vms:read"`, `"snapshots:create"`).
- Wildcard `"*"` grants all abilities.

### Administrative Service Token API

Managed via `App\Http\Controllers\Api\V1\ServiceTokenController` under `/api/v1/service-tokens` (`auth:sanctum`, `active`, `admin` middleware):

| Method & Path | Description |
| --- | --- |
| `GET /api/v1/service-tokens` | Lists all service tokens (`id`, `name`, `abilities`, `last_used_at`, `expires_at`, `created_at`, `created_by_user_id`). `token_hash` is never returned. |
| `POST /api/v1/service-tokens` | Creates a token (`name`: required string max 100; `abilities`: required non-empty array of strings max 50 chars each; `expires_at`: optional future date). Returns `201` with `token` and one-time `plain_text_token`. |
| `DELETE /api/v1/service-tokens/{id}` | Revokes and deletes the token and writes a `service_token.revoked` audit record. |

Creating a service token also emits the [`access.service_token_created`](./events-catalog.md) domain event and records a `service_token.created` audit log entry.

### Authentication & Automatic Audit Attribution

`App\Http\Middleware\AuthenticateServiceToken` authenticates requests bearing `Authorization: Bearer srv_<secret>`:

1. Extracts `<secret>` after `srv_` and computes `hash('sha256', $secret)`.
2. Looks up the matching `ServiceToken` by `token_hash` and checks `isValid()` (`expires_at === null || expires_at->isFuture()`).
3. Returns `401 Unauthenticated.` if missing, malformed, unknown, or expired.
4. Updates `last_used_at` silently, attaches the model to `$request->attributes->set('service_token', $token)` and `app()->instance(ServiceToken::class, $token)`.
5. Configures `App\Audit\AuditContext::forServiceToken($token)`:
   - `actor_type = 'system'`
   - `actor_id = (int) $token->id`
   - `actor_label = 'service_token:' . $token->name`
   - `impersonator_id = null`

As a result, every audit log entry and every `OgnivoEventInterface` event emitted during a service-token request automatically records `actor_type = 'system'` and identifies the exact service token by ID and name.
