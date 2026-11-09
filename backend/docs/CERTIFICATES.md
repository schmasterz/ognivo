# Ognivo — Host Onboarding Guide

## Overview
This platform manages Incus hypervisor nodes securely using mutual TLS (mTLS) with dedicated panel client certificates and TLS pinning. The browser never communicates directly with Incus; all operations are securely proxied and verified by the Laravel backend.

---

## Panel Client Certificate Management

The panel automatically generates and manages an elliptic-curve self-signed X.509 client certificate (`secp384r1`, `sha384`, CN `incus-panel`, Extended Key Usage: `clientAuth`).
- **Private Key Security:** Stored encrypted in the database (`encrypted` Eloquent cast), hidden from model serialization, and never returned in API responses or logs.
- **Rotation:** When rotating via `POST /api/admin/incus/certificate/rotate` or `php artisan incus:generate-cert --force`, the existing certificate transitions to `retiring` status and all hosts are flagged with `needs_retrust = true`.
- **Revocation Safety:** Retiring certificates cannot be revoked until all hosts are confirmed re-trusted (`pending_retrust_hosts_count === 0`).
- **Expiry Monitoring:** `WarnExpiringPanelCertificateJob` runs on a daily schedule and logs warnings when the certificate is within 30 days of expiration.

---

## Adding an Incus Compute Node

### 1. Primary Action: Connect with a Single-Use Trust Token

This is the recommended and fastest onboarding path. The trust token contains connection addresses, server certificate fingerprint, and a single-use secret.

#### Step 1: Generate Trust Token on Hypervisor Host
SSH into your server and execute:
- **On Incus:**
  ```bash
  incus config trust add-token <node-name-or-panel>
  ```
- **On LXD:**
  ```bash
  lxc config trust add --name <node-name-or-panel>
  ```
*Example Output:*
```
Client panel has been added to the trust store.
Token: eyJjbGllbnRfbmFtZSI6InBhbmVsIiwiZmluZ2VycHJpbnQiOiI5YjkzNWY5...
```

#### Step 2: Onboard Node in Web UI
1. Open **Manage Compute Nodes** (`/admin/hosts`).
2. Click **Create Node** and select the **Connect with token** tab.
3. Enter the Node Name, paste the generated token into the Trust Token field, and set resource capacities.
4. Click **Connect with token**.

#### Backend Security Flow:
1. Validates base64 structure and JSON fields (`client_name`, `fingerprint`, `addresses`, `secret`, `expires_at`).
2. Connects to the first reachable address, extracts the remote peer certificate during the TLS handshake, and computes its SHA-256 fingerprint.
3. Compares peer fingerprint with the token's fingerprint. If there is a mismatch, execution is immediately aborted without sending credentials (Man-in-the-Middle protection).
4. Submits the panel's public certificate to `POST /1.0/certificates` with `trust_token: <secret>`.
5. Pins the verified server certificate in the database for all future mTLS communications.
6. The token is never stored in the database.

---

### 2. Secondary Action: Add with Pre-Trusted Certificate

Use this path if manual trust management or firewall policies restrict token onboarding.

#### Step 1: Download Panel Public Certificate
In the Web UI under **Manage Compute Nodes**, click **Download .crt** (or fetch `GET /api/admin/incus/certificate/download`).

#### Step 2: Trust the Certificate on the Host
Copy `incus-panel.crt` to your server and add it to the trust store:
- **On Incus:**
  ```bash
  incus config trust add-certificate incus-panel.crt
  ```
- **On LXD:**
  ```bash
  lxc config trust add incus-panel.crt
  ```

#### Step 3: Find Host Server Fingerprint
Run on the Incus server:
```bash
incus info
```
Copy the 64-character SHA-256 certificate fingerprint.

#### Step 4: Add Node in Web UI
1. In **Create Node**, select the **Add with certificate** tab.
2. Enter the Node Name, Endpoint URL (e.g. `https://192.168.1.100:8443`), and Expected Fingerprint.
3. Click **Verify connection** to test reachability, fingerprint matching, and mTLS trust.
4. Click **Add with certificate**.

---

## Connection Verification & States

The **Verify connection** action evaluates connection reachability, server identity, and mutual trust:
- **`ok`**: Connected, peer certificate matches pinned fingerprint, and mTLS client is trusted (`auth: "trusted"`).
- **`unreachable`**: Host timed out or connection refused.
- **`fingerprint_mismatch`**: Remote server certificate changed or does not match pinned fingerprint (Security Warning: potential MITM or server reinstallation).
- **`not_trusted`**: Server reached, but panel certificate is not in the server's trust list (`auth: "untrusted"`).
- **`error`**: Unexpected error with safe, sanitized error message.
