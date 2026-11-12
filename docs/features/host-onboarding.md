# Host Onboarding & mTLS Certificate Guide

Ognivo manages **Incus** and **LXD** hypervisor nodes using mutual TLS (**mTLS**) with dedicated panel client certificates and strict server TLS fingerprint pinning. The browser never communicates directly with hypervisors; all operations are authenticated and proxied by the backend and `console-proxy`.

---

## 1. Panel Client Certificate Management

The panel automatically generates and manages a self-signed elliptic-curve X.509 client certificate (`secp384r1`, `sha384`, CN `incus-panel`, Extended Key Usage: `clientAuth`).

- **Private Key Security:** Stored encrypted in the database (`encrypted` Eloquent cast using AES-256-CBC), hidden from model serialization, and persisted locally with `0600` permissions in `storage/app/lxd-certs`.
- **Zero-Downtime Rotation:** When rotating via `POST /api/admin/incus/certificate/rotate` or `php artisan incus:generate-cert --force`, the previous certificate transitions to `retiring` status and all existing hosts are flagged with `needs_retrust = true`.
- **Revocation Safety Guard:** Retiring certificates cannot be revoked (`POST /api/admin/incus/certificate/revoke-old`) until all hosts have confirmed re-trust (`pending_retrust_hosts_count === 0`).
- **Expiry Monitoring:** `WarnExpiringPanelCertificateJob` runs daily and logs warnings when the active certificate is within 30 days of expiration.

---

## 2. Adding an Incus / LXD Compute Node

### Path A: Connect with a Single-Use Trust Token (Recommended)

This is the fastest and safest onboarding flow. The trust token encodes connection addresses, the server certificate fingerprint, and a one-time secret.

#### Step 1: Generate Trust Token on the Hypervisor Host
SSH into your hypervisor server and run:

- **On Incus:**
  ```bash
  incus config trust add-token <node-name-or-panel>
  ```
- **On LXD:**
  ```bash
  lxc config trust add --name <node-name-or-panel>
  ```

*Example Output:*
```text
Client panel has been added to the trust store.
Token: eyJjbGllbnRfbmFtZSI6InBhbmVsIiwiZmluZ2VycHJpbnQiOiI5YjkzNWY5...
```

#### Step 2: Onboard Node in the Web UI
1. Open **Host Nodes & Regions** in the Admin sidebar.
2. Click **Create Node** and select the **Connect with token** tab.
3. Enter the Node Name, paste the generated token into the **Trust Token** field, and configure CPU/RAM/Storage capacity.
4. Click **Connect with token**.

#### Backend Cryptographic Flow
1. Decodes and validates the Base64 token structure (`client_name`, `fingerprint`, `addresses`, `secret`, `expires_at`).
2. Connects to the first reachable address, extracts the remote peer certificate during the TLS handshake (`TlsPeerCertificateFetcher`), and computes its SHA-256 fingerprint.
3. Compares the live peer fingerprint with the token's expected fingerprint. On mismatch, onboarding is aborted immediately before transmitting any secrets (MITM protection).
4. Submits the panel's public certificate to `POST /1.0/certificates` with `trust_token: <secret>`.
5. Pins the verified server certificate fingerprint in the database for all future mTLS connections.
6. Discards the one-time token (never stored in the database).

---

### Path B: Add with a Pre-Trusted Certificate

Use this path when manual trust store management or firewall rules require out-of-band certificate registration.

#### Step 1: Download the Panel Public Certificate
In the Admin UI under **Host Nodes & Regions**, click **Download .crt** (or call `GET /api/admin/incus/certificate/download`).

#### Step 2: Trust the Certificate on the Hypervisor Host
Copy `incus-panel.crt` to your server and register it in the trust store:

- **On Incus:**
  ```bash
  incus config trust add-certificate incus-panel.crt
  ```
- **On LXD:**
  ```bash
  lxc config trust add incus-panel.crt
  ```

#### Step 3: Retrieve the Host Server Fingerprint
Run on the hypervisor host:
```bash
incus info
```
Copy the 64-character SHA-256 `server_certificate` fingerprint.

#### Step 4: Register the Node in the Web UI
1. In **Create Node**, select the **Add with certificate** tab.
2. Enter the Node Name, Endpoint URL (e.g., `https://192.168.1.100:8443`), and the Expected Fingerprint.
3. Click **Verify connection** to test reachability, fingerprint matching, and mTLS client authentication.
4. Click **Add with certificate**.

---

## 3. Connection Verification States

The **Verify connection** action (`POST /api/admin/hosts/verify-connection` or `POST /api/admin/hosts/{host}/verify-connection`) returns one of the following states:

| State | Meaning |
| :--- | :--- |
| `ok` | Connected, peer certificate matches pinned SHA-256 fingerprint, and mTLS client is trusted (`auth: "trusted"`). |
| `unreachable` | Host timed out or connection was refused. |
| `fingerprint_mismatch` | Remote server certificate changed or does not match the pinned fingerprint (potential MITM or host reinstallation). |
| `not_trusted` | Server was reached and fingerprint matched, but the panel certificate is not in the server's trust store (`auth: "untrusted"`). |
| `error` | Unexpected transport or protocol error (returns a sanitized error summary). |
