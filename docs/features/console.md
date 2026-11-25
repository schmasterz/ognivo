# Interactive Web Terminal

Ognivo gives the owner of a container or virtual machine an interactive terminal in the browser. The terminal is rendered by `xterm.js` and streamed through the dedicated `console-proxy` service. The proxy talks to Incus over mutually authenticated, pinned TLS, so the browser never sees the hypervisor address, a client key or an Incus operation secret.

The terminal is opened through one of two **drivers**. Each driver maps to a different Incus endpoint:

| Driver | Incus endpoint | What the user gets | Used for |
| :--- | :--- | :--- | :--- |
| `exec` | `POST /1.0/instances/{name}/exec` | A new login shell (`bash -l`, falling back to `sh -l`) running as `root` in `/root`, with `TERM=xterm-256color` | **Default.** All containers, and virtual machines that run the Incus guest agent |
| `console` | `POST /1.0/instances/{name}/console` | The instance's serial console (`/dev/console`, `ttyS0`) | Fallback for virtual machines without a running guest agent, and an explicit choice through the API |

Alongside the interactive terminal there is a separate, read-only **boot log** (see [Boot log](#7-boot-log)). It does not depend on either driver.

---

## 1. Architecture Overview

```mermaid
sequenceDiagram
    participant Browser as Browser (xterm.js)
    participant Nginx as Nginx (:80/:443)
    participant API as Laravel Backend
    participant Redis as Redis 7
    participant Proxy as console-proxy (:8085)
    participant Incus as Incus / LXD Host (:8443)

    Browser->>Nginx: POST /api/v1/vms/{id}/console { type, cols, rows }
    Nginx->>API: Authorize owner (deny admins and impersonators)
    API->>Incus: mTLS POST /1.0/instances/{name}/exec (or /console)
    Incus-->>API: Operation ID + websocket secrets (data, control)
    API->>Redis: SETEX console:ticket:{ticket} (60 s TTL by default)
    API->>Redis: HSET console:vm_sessions:{vmId} {sessionId}
    API-->>Browser: 200 { ticket, session_id, websocket_path: "/console", driver, ... }
    Browser->>Nginx: WSS /console (Sec-WebSocket-Protocol: ognivo-terminal, ticket.{ticket})
    Nginx->>Proxy: Upgrade WebSocket (:8085)
    Proxy->>Redis: Atomic GETDEL console:ticket:{ticket}
    Redis-->>Proxy: Host endpoint + SPKI pin + operation paths + panel client certificate
    Proxy->>Incus: Outbound WSS to /1.0/operations/{id}/websocket (pinned SPKI, mTLS)
    Proxy<<->>Browser: Duplex terminal stream (+ resize through the Incus control socket)
    Browser->>API: DELETE /api/v1/vms/{id}/console/{sessionId} (on close)
    API->>Incus: DELETE /1.0/operations/{operationId}
```

### Ticket lifecycle

1. The authenticated owner calls `POST /api/v1/vms/{id}/console`.
2. `ConsoleTicketService` checks, in order: the request is not an admin impersonation session, the `viewConsole` policy allows the user, and the instance is `running`.
3. It chooses a driver (see [Driver selection](#2-driver-selection)) and asks Incus to start an exec or console operation. Incus returns an operation ID and per-socket secrets.
4. The service stores a **single-use ticket** in Redis under `console:ticket:{ticket}` (64 random characters) with a TTL of `TERMINAL_TICKET_TTL_SECONDS` (default `60`, minimum `10`). It also records the session in `console:vm_sessions:{vmId}` and `console:session_index` so concurrency limits and closing work.
5. The API returns only the ticket and session metadata. Hypervisor addresses, pins, client keys and operation secrets are **never** part of the response.
6. The browser opens `wss://<host>/console`, sending the ticket in the `Sec-WebSocket-Protocol` header.
7. `console-proxy` redeems the ticket with an atomic `GETDEL`. If it is missing, expired or already used, the upgrade fails. Otherwise the proxy opens the outbound WSS connection to Incus, verifies the server certificate's SPKI hash against the pin stored with the host, and bridges the two sockets.

> [!IMPORTANT]
> The Redis ticket payload contains the panel's mTLS client certificate **and private key** (for at most the ticket TTL) so the proxy can authenticate to Incus. Redis must therefore stay on the internal network and be password-protected. Both are the default in the supplied Compose files.

---

## 2. Driver Selection

The browser UI always requests `type: "console"` without a `driver`, which means **automatic** selection:

| Instance | Requested `driver` | Result |
| :--- | :--- | :--- |
| Container | `auto` / omitted | `exec` |
| Virtual machine | `auto` / omitted | `exec` first. If the guest agent is unavailable (error text mentions `agent`, `vsock`, `not running`, `connection refused`, `timed out` or `disconnected`), falls back to `console` and returns `fallback_reason: "agent_unavailable"` |
| Any | `exec` | `exec` only, no fallback |
| Any | `console` | `console` (serial) only |
| Virtual machine | any, with `type: "vga"` | `console` driver with the VGA stream (see the note below) |

Details:

- **Exec on virtual machines** uses short timeouts (3 s to connect, 5 s overall) so that a missing guest agent fails fast and the fallback can kick in.
- A host **certificate pin mismatch** (`FingerprintMismatchException`) is never treated as "agent unavailable". It aborts the request with no fallback.
- The `exec` driver rejects `type: "vga"`.
- Containers do not support `vga`. The API returns `422`.

> [!NOTE]
> **VGA is not usable from the UI today.** The API accepts `type: "vga"` for virtual machines and opens the Incus VGA console operation, but Ognivo ships no graphical (noVNC/RFB) client, and the web panel never requests it. Treat `vga` as experimental until a client is added.

### Guest agent and serial console requirements

- `exec` on a virtual machine needs the Incus agent running inside the guest (images with the `/agent` variant, or the agent installed manually).
- The `console` driver shows whatever the guest writes to its serial console. The guest must have a login `getty` on `ttyS0` and, in most cases, a password set. A guest with no login prompt on the serial line shows nothing useful. Use the [boot log](#7-boot-log) to inspect what the guest printed at boot.

---

## 3. WebSocket Protocol (`console-proxy`)

**Endpoint:** `wss://<panel-host>/console` (Nginx forwards `/console` to `console-proxy:8085`; read and send timeouts are 3600 s).

### Authentication

The ticket is read in this order:

1. `Sec-WebSocket-Protocol: ognivo-terminal, ticket.<ticket>` (**used by the web panel**; keeps the ticket out of URLs and access logs).
2. `Authorization: Bearer <ticket>` header.
3. `?ticket=<ticket>` query string (legacy; avoid it, because URLs end up in logs and browser history).

The proxy answers the subprotocol handshake with `ognivo-terminal`.

### Handshake failures

| HTTP status | Meaning |
| :--- | :--- |
| `404` | Path is not `/console` |
| `401` | No ticket supplied |
| `400` | Ticket is not 16 to 128 characters of `A-Z a-z 0-9 _ -` |
| `403` | Ticket unknown, expired or already used |
| `500` | Unexpected error while redeeming the ticket |

### Frames

| Direction | Frame | Meaning |
| :--- | :--- | :--- |
| Browser to proxy | Binary | Keystrokes, forwarded to Incus unchanged |
| Browser to proxy | Text JSON `{"type":"resize","cols":120,"rows":40}` | Window resize (`width` / `height` are accepted aliases). Forwarded as an Incus `window-resize` control message. Not available on connections without a control socket |
| Proxy to browser | Binary or text | Terminal output |

Messages larger than the session's `max_message_bytes` (default 64 KiB) close the socket with code `1009`.

### Close codes

| Code | Meaning |
| :--- | :--- |
| `4000` | Idle timeout exceeded (the proxy sends a notice line first) |
| `1009` | Message too big |
| `1011` | Upstream connection to the hypervisor failed |
| `1000` / other | Normal close, or Incus ended the session (for example, the user typed `exit`) |

When a session ends because of an idle timeout, an oversized message or an explicit close, the proxy sends `SIGHUP` and `SIGTERM` on the Incus control socket (exec sessions) and cancels the Incus operation, so no orphaned shell stays behind.

---

## 4. Sessions, Limits and Idle Timeout

Every ticket belongs to a **session** with a UUID `session_id`.

| Limit | Default | Environment variable | Behavior when exceeded |
| :--- | :--- | :--- | :--- |
| Concurrent `exec` sessions per instance | `5` | `TERMINAL_MAX_EXEC_SESSIONS` | `429` |
| Concurrent `console` sessions per instance | `1` | `TERMINAL_MAX_CONSOLE_SESSIONS` | `409` |
| Ticket lifetime | `60 s` | `TERMINAL_TICKET_TTL_SECONDS` | Ticket expires, request a new one |
| Idle timeout | `900 s` | `TERMINAL_IDLE_TIMEOUT_SECONDS` | Socket closed with `4000` |
| Maximum WebSocket message | `65536` bytes | `TERMINAL_MAX_MESSAGE_BYTES` | Socket closed with `1009` |
| Ticket and boot-log requests | `6` per minute | (route throttle) | `429` |

Values are clamped to sane minimums (ticket TTL at least 10 s, idle timeout at least 30 s, session limits at least 1, message size at least 1024 bytes). Terminal dimensions are clamped to 20 to 500 columns and 5 to 200 rows.

The idle timeout and message limit are copied into the ticket, so they apply to that session even if the proxy's own defaults differ.

> [!NOTE]
> **Activity tracking and unredeemed tickets.** When the browser opens the WebSocket connection, `console-proxy` marks the session as redeemed (`redeemed_at`) in Redis and refreshes `last_activity_at` (along with the Redis key TTL) approximately every 30 seconds while traffic flows. Active sessions therefore retain their concurrency slot as long as the terminal is in use, and unredeemed tickets automatically release their concurrency slot once `TERMINAL_TICKET_TTL_SECONDS` expires.

### Closing a session

The web panel calls `DELETE /api/v1/vms/{id}/console/{sessionId}` when the modal is closed or the terminal reconnects. This removes the session from the concurrency count and cancels the Incus operation. If the browser disappears without calling it, the proxy cleans up the Redis records itself when the socket closes.

---

## 5. Privacy & Security Model (Strict Administrative Denial)

Ognivo enforces **tenant privacy isolation** on guest terminals:

- **Owner-only access.** Only the user who owns the workload can issue terminal tickets, read the boot log or close sessions.
- **Explicit admin denial.** `VirtualMachinePolicy::viewConsole` denies every user with the `admin` role. The message is: *"Console access is restricted to the workload owner only. Administrators are denied guest access."*
- **Impersonation guard.** While an administrator impersonates a user, ticket issuance is refused with `403` and recorded as `vm.action.denied` (`action: console.ticket`, `reason: forbidden_while_impersonating`).
- **Single-use, short-lived tickets** redeemed with an atomic `GETDEL`.
- **No secrets in the browser.** Responses go through `ConsoleSessionDTO`, which only contains session metadata.
- **Pinned upstream TLS.** The proxy checks the Incus server's SubjectPublicKeyInfo (SPKI) hash (`sha256//...`) against `hosts.server_public_key_pin` and refuses the connection on mismatch.

This policy protects tenants from the panel's staff. It does not protect them from someone with root access to the hypervisor itself.

### Audit events (tenant-visible)

| Action | Written when |
| :--- | :--- |
| `console.ticket.issued` | A ticket is issued (context: `console_type`, `driver`, `session_id`) |
| `terminal.session.opened` | A session is recorded (adds `fallback_reason`) |
| `terminal.session.closed` | A session is closed (adds `duration_seconds` and `reason`, for example `client_disconnect`, `client_closed`, `reconnect`, `modal_closed`, `idle_timeout`, `ticket_expired`) |
| `vm.action.denied` | Impersonation or policy denial (`action: console.ticket`, `reason: forbidden_while_impersonating` or `policy_denial`) |
| `vm.action.requested` | Boot log viewed (`action: console_log.viewed`, `bytes_returned`) |

---

## 6. REST API Reference

All endpoints require a Sanctum bearer token and are under `/api`. Interactive documentation is available at `/docs/api`.

### Issue a terminal session

```http
POST /api/v1/vms/{id}/console
```

**Body parameters (all optional):**

| Field | Values | Default | Notes |
| :--- | :--- | :--- | :--- |
| `type` | `console`, `vga` | `console` | `vga` is for virtual machines only and is not supported by the web panel |
| `driver` | `auto`, `exec`, `console` | `auto` | See [Driver selection](#2-driver-selection) |
| `cols` (alias `width`) | integer | `80` | Clamped to 20 to 500 |
| `rows` (alias `height`) | integer | `24` | Clamped to 5 to 200 |

**Response `200 OK`:**

```json
{
  "virtual_machine_id": 42,
  "session_id": "0b7f6a52-3f4e-4c0e-9d52-0c1f3a8a9c11",
  "ticket": "c8a4e3...",
  "websocket_path": "/console",
  "expires_at": "2026-11-16T13:20:00+00:00",
  "type": "console",
  "driver": "exec",
  "fallback_reason": null,
  "idle_timeout": 900
}
```

`websocket_path` is a path without a query string. Open `wss://<host><websocket_path>` and pass the `ticket` in the `Sec-WebSocket-Protocol` header as described in [WebSocket Protocol](#3-websocket-protocol-console-proxy). `driver` is the driver actually used and `fallback_reason` is `agent_unavailable` when a virtual machine fell back to the serial console.

**Status codes:**

| Code | Reason |
| :--- | :--- |
| `200` | Ticket issued |
| `403` | Administrator account, non-owner, or active admin impersonation session |
| `409` | Instance is not `running`; or a serial console session is already active; or the guest agent is unavailable and the serial console is already in use |
| `422` | Invalid `type` or `driver`, or `vga` requested for a container |
| `429` | Too many concurrent `exec` sessions on the instance, or the `6 per minute` route throttle |
| `5xx` | The hypervisor refused to create the session (for example, no shell found in the guest, host unreachable) |

### Close a terminal session

```http
DELETE /api/v1/vms/{id}/console/{sessionId}
```

Optional body field `reason` (default `client_disconnect`) is stored in the audit log. Returns `{ "closed": true }` if the session existed and was closed, otherwise `{ "closed": false }`. Requires the same owner-only permission as issuing a ticket.

### Boot log

```http
GET /api/v1/vms/{id}/console-log
```

See [Boot log](#7-boot-log).

### Legacy endpoints

| Endpoint | Behavior |
| :--- | :--- |
| `POST /api/v1/vms/{id}/console-ticket` | **Deprecated.** Issues an automatic-driver `console` ticket and returns only `{ "virtual_machine_id", "ticket" }`. Without a `session_id` the session cannot be closed explicitly |
| `GET /api/v1/vms/{id}/console` | Always `404`. Terminals are only issued with `POST` |

---

## 7. Boot Log

```http
GET /api/v1/vms/{id}/console-log
```

Returns the text that the guest has written to its console buffer (`GET /1.0/instances/{name}/console?buffer=true` on Incus), for example to see why a guest failed to boot or which IP it received.

```json
{ "virtual_machine_id": 42, "name": "vm-example", "output": "..." }
```

- **Read-only**, independent of the exec/console drivers and of the guest agent. It works for stopped instances too, as long as Incus still holds a buffer.
- Capped to the **last 50 KB**.
- Same owner-only policy and `6 per minute` throttle as terminal tickets. Each view is audited.
- If Incus cannot return the buffer, the endpoint still answers `200` with an explanatory message in `output` (for example, `Unable to fetch console log from hypervisor. Instance status: ...`, or `Console buffer is empty.`).

In the web panel this is the **Boot log** tab next to the **Terminal** tab.

---

## 8. Configuration

### Backend (`config/hypervisor.php`, `terminal`)

| Variable | Default | Description |
| :--- | :--- | :--- |
| `TERMINAL_TICKET_TTL_SECONDS` | `60` | Ticket lifetime (minimum 10) |
| `TERMINAL_IDLE_TIMEOUT_SECONDS` | `900` | Idle timeout copied into each ticket (minimum 30) |
| `TERMINAL_MAX_EXEC_SESSIONS` | `5` | Concurrent `exec` sessions per instance |
| `TERMINAL_MAX_CONSOLE_SESSIONS` | `1` | Concurrent `console` sessions per instance |
| `TERMINAL_MAX_MESSAGE_BYTES` | `65536` | Largest accepted WebSocket message (minimum 1024) |

### `console-proxy` service

| Variable | Default | Description |
| :--- | :--- | :--- |
| `PORT` | `8085` | Listening port |
| `REDIS_HOST` / `REDIS_PORT` / `REDIS_PASSWORD` | `127.0.0.1` / `6379` / none | Redis used to redeem tickets and clean up sessions. Must be the same Redis as the backend |
| `REDIS_PREFIX` | none | Laravel Redis key prefix. If you changed the backend's Redis prefix, set it here too, otherwise tickets are not found. Common default prefixes are tried automatically |
| `CLIENT_CERT_PATH` / `CLIENT_KEY_PATH` | none | Fallback client certificate and key files, used only when the ticket carries none |
| `MAX_MESSAGE_BYTES` | `65536` | Default maximum message size (the ticket value wins) |
| `IDLE_TIMEOUT_SECONDS` | `900` | Default idle timeout (the ticket value wins) |

Health check: `GET /health` on the proxy returns `{"status":"ok","service":"console-proxy"}`.

---

## 9. Development with the Fake Driver

When the host driver is `fake` (the seeded demo nodes), the proxy does not contact Incus. It answers with a simulated shell: `exec` sessions print `root@fake-instance:~#` and echo input, `console` sessions print a `login:` prompt. This is for UI development only. Real exec and serial behavior needs a real Incus node.

---

## 10. Troubleshooting

| Symptom | Likely cause | What to check |
| :--- | :--- | :--- |
| `409` "only available while the instance is running" | Instance is stopped, starting or in error | Start it, or use the boot log |
| `409` "serial console session is already active" | Only one serial session per instance is allowed | Close the other session, or wait for the idle timeout |
| `429` "Maximum number of concurrent terminal sessions" | 5 `exec` sessions already open | Close old sessions, or raise `TERMINAL_MAX_EXEC_SESSIONS` |
| VM terminal opens a serial login prompt instead of a shell | Guest agent not running, so the fallback was used | `fallback_reason` is `agent_unavailable`; use an agent-enabled image or install the agent in the guest |
| Serial console is blank | No `getty` on `ttyS0`, or the guest has no password | Check the boot log; configure a serial login |
| Socket fails with HTTP `403` | Ticket expired (over 60 s between the POST and the WebSocket), already used, or the proxy uses a different Redis or prefix | Request a new ticket; check `REDIS_*` and `REDIS_PREFIX` on `console-proxy` |
| Socket closes with `1011` | The proxy cannot reach Incus, or the SPKI pin does not match | Check the node status and the host's stored pin (`hosts:backfill-pins`); see [Host Onboarding](host-onboarding.md) |
| Terminal closes after 15 minutes of silence | Idle timeout (`4000`) | Raise `TERMINAL_IDLE_TIMEOUT_SECONDS` |
| Admin cannot open a tenant's terminal | By design | Ask the tenant, or use the hypervisor directly |