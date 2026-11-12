# Ognivo Documentation Hub

Welcome to the **Ognivo** technical documentation. All guides are organized into five logical categories:

---

## 1. 🏁 Getting Started (`docs/getting-started/`)
- **[Quickstart Guide](getting-started/quickstart.md)** — Prerequisites, generating `.env` secrets, and starting Development or Production in minutes.
- **[Development Environment](getting-started/development.md)** — Docker service layout, hot reloading, database seeders, demo credentials, and local ports.

## 2. 🏛️ Architecture & Security (`docs/architecture/`)
- **[System Architecture Overview](architecture/overview.md)** — High-level topology, Backend Eloquent models & services, Next.js 16 App Router architecture, API endpoint map, Reverb WebSockets, and lifecycle sequence diagrams.
- **[Security & Tamper-Evident Audit Log](architecture/security-and-audit.md)** — Defense-in-depth controls, mTLS key protection, strict MySQL privilege separation, SHA-256 hash-chain + HMAC sealing, PII pseudonymisation, and HMAC key rotation.

## 3. 🧩 Features & Subsystems (`docs/features/`)
- **[Host Onboarding & mTLS Certificates](features/host-onboarding.md)** — Panel X.509 client certificate lifecycle, single-use Incus/LXD trust tokens, pre-trusted certificate setup, and connection verification states.
- **[Network Modes & Host IPAM](features/networking.md)** — Routed (`routed`) vs. Manual (`manual`) subnet pools, required hypervisor `sysctl` / Proxy ARP configuration, and preflight pool verification.
- **[Interactive Web Console](features/console.md)** — Real-time `xterm.js` terminal sessions, `console-proxy` microservice, atomic 60s Redis tickets, and strict tenant privacy isolation.
- **[Virtual Machine Snapshots](features/snapshots.md)** — Point-in-time VM/container snapshots, state machine, concurrency locks, quota enforcement, REST API, and `BackupTargetInterface` extension points.

## 4. 🛠️ Operations & Reference (`docs/operations/`)
- **[Production Deployment](operations/production.md)** — Building self-contained images, startup safety enforcement, creating the initial administrator, and installing real TLS certificates.
- **[Configuration Reference](operations/configuration.md)** — Complete environment variable dictionary, Reverb WebSocket split flow, healthchecks, and `APP_KEY` rotation procedure.
- **[Commands Reference](operations/commands.md)** — Makefile targets, native Docker Compose commands, Laravel Artisan CLI, and MySQL/Redis container inspection.
- **[Troubleshooting](operations/troubleshooting.md)** — Solutions for common configuration, port binding, database credential, and Nginx reverse-proxy issues.

## 5. 🧪 Testing & Quality Assurance (`docs/testing/`)
- **[Automated Testing & Static Analysis](testing/automated-testing.md)** — Running SQLite unit tests, MySQL 8.4 + Redis 7 integration tests, PHPStan Level 10, Psalm ErrorLevel 1, and frontend checks.
- **[Manual QA & API Verification](testing/manual-qa.md)** — End-to-end browser verification scenarios and ready-to-use `curl` API commands.
