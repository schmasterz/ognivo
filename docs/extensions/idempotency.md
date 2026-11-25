# API Idempotency

Mutating API integrations (such as provisioning workflows, billing hooks, or automated retries after network timeouts) can supply the `Idempotency-Key` HTTP header handled by `App\Http\Middleware\IdempotencyMiddleware` (`backend/app/Http/Middleware/IdempotencyMiddleware.php`).

---

## Request Header

```http
Idempotency-Key: 7d8f3c2e-9b1a-4f0c-8e12-1a2b3c4d5e6f
```

- Maximum length: **128 characters** (enforced by the `idempotency_keys.key` column).
- If the `Idempotency-Key` header is absent or empty, `IdempotencyMiddleware` passes the request through normally without reading or writing `idempotency_keys`.

---

## Request Fingerprinting

When `Idempotency-Key` is present, the middleware computes a SHA-256 checksum over the HTTP method, request path, and raw request body:

```php
$checksum = hash(
    'sha256',
    $request->getMethod() . '|' . $request->path() . '|' . $request->getContent()
);
```

This binds the idempotency key to both the target endpoint and the exact request payload.

---

## Response Behavior Matrix

| Scenario | HTTP Status | Response & Headers |
| --- | --- | --- |
| **First request with key** | Handler status | Executes the route handler, stores status, headers, and body in `idempotency_keys`, clears `locked_at`, and returns the live response. |
| **Identical completed request** | Cached status | Replays the exact cached `response_body`, `response_status`, and `response_headers`, adding `X-Idempotent-Replay: true` without re-executing the handler. |
| **Concurrent in-flight request** | `409 Conflict` | Returned while another request with the same key holds an active lock (`response_status === null` and `locked_at` within the last 30 seconds). Body: `{"message": "A request with this idempotency key is currently being processed."}` |
| **Same key, different method/path/payload** | `422 Unprocessable Entity` | Returned when constant-time comparison (`hash_equals`) of `request_hash` fails. Body: `{"message": "Idempotency key payload mismatch"}` |

---

## Lock Lifecycle & Expiration

- **Active Lock Window:** When a request starts, `locked_at` is set to `now()`. `IdempotencyKey::isLocked()` returns `true` when `response_status === null`, `locked_at !== null`, and `locked_at` is within the last **30 seconds**.
- **Stale Lock Recovery:** If a prior in-flight request failed or crashed before recording a response and more than 30 seconds have elapsed, a retry with the same key and matching checksum re-acquires `locked_at`, refreshes `expires_at`, and executes the handler.
- **Expiration TTL:** `expires_at` is set to **24 hours** (`now()->addHours(24)`) when the record is created or re-locked. `IdempotencyKey::isExpired()` checks `$this->expires_at->isPast()`.

---

## Database Schema (`idempotency_keys`)

Defined in `backend/database/migrations/2026_11_25_000003_create_idempotency_keys_table.php` and modeled by `App\Models\IdempotencyKey`:

| Column | Type | Nullable | Notes |
| --- | --- | --- | --- |
| `id` | `BIGINT UNSIGNED` | No | Primary key (`bigIncrements`). |
| `key` | `VARCHAR(128)` | No | Unique index; stores the `Idempotency-Key` header value. |
| `request_hash` | `VARCHAR(64)` | No | Hex SHA-256 checksum of `METHOD\|path\|body`. |
| `response_status` | `SMALLINT UNSIGNED` | Yes | Cached HTTP status code (`null` while in-flight). |
| `response_headers` | `JSON` | Yes | Cached response headers map. |
| `response_body` | `LONGTEXT` | Yes | Cached raw response body (or `null` if empty). |
| `locked_at` | `TIMESTAMP` | Yes | In-flight lock acquisition timestamp (`null` once completed). |
| `expires_at` | `TIMESTAMP` | No | Expiration timestamp (`now() + 24 hours`). |
| `created_at` / `updated_at` | `TIMESTAMP` | Yes | Standard Eloquent timestamps. |
