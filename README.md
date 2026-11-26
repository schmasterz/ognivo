# Ognivo

**Open-source, multi-tenant control plane for KVM virtual machines and LXC system containers on Incus.**

[![CI](https://img.shields.io/github/actions/workflow/status/schmasterz/ognivo/ci.yml?label=CI)](https://github.com/schmasterz/ognivo/actions)
[![Release](https://img.shields.io/github/v/release/schmasterz/ognivo?include_prereleases)](CHANGELOG.md)
[![License: MIT](https://img.shields.io/badge/License-MIT-emerald.svg)](LICENSE)
[![PHP 8.5](https://img.shields.io/badge/PHP-8.5-777BB4?logo=php&logoColor=white)](https://www.php.net/)
[![Laravel 13](https://img.shields.io/badge/Laravel-13-FF2D20?logo=laravel&logoColor=white)](https://laravel.com/)
[![Next.js 16](https://img.shields.io/badge/Next.js-16-000000?logo=next.js&logoColor=white)](https://nextjs.org/)

> [!WARNING]
> **Status: alpha (`1.0.0-alpha.1`).** Ognivo is not production-ready yet. APIs, configuration keys and the database schema may change without migration paths until 1.0. Keep external backups of anything you care about. See [Upgrading and backups](#upgrading-and-backups).


Ognivo lets you offer VPS-style hosting on your own **Incus** hypervisors. Customers get a self-service dashboard. Administrators get node, image, network and quota management. Everything is built around tenant isolation and a tamper-evident audit trail.

*Ognivo* (Огниво) is the Bulgarian word for a flint-and-steel fire starter.

![Ognivo Dashboard Overview](docs/assets/dashboard-overview.png)

## Contents

- [Why Ognivo?](#why-ognivo)
- [Features](#features)
- [Architecture](#architecture)
- [Installation](#installation)
- [Connect your first Incus node](#connect-your-first-incus-node)
- [Supported versions](#supported-versions)
- [Documentation](#documentation)
- [Known limitations](#known-limitations)
- [Roadmap](#roadmap)
- [Upgrading and backups](#upgrading-and-backups)
- [Security](#security)
- [Contributing](#contributing)
- [Technology stack](#technology-stack)
- [License](#license)

## Why Ognivo?

Ognivo is aimed at small hosting providers, labs and self-hosters who run Incus and want a multi-tenant panel with quotas, IP management and a web terminal, without adopting a full virtualization platform.

It is an open-source alternative to hosting panels such as SolusVM and Virtualizor, focused on Incus. It is not a replacement for general-purpose platforms like Proxmox VE and does not aim to be one.

## Features

| Area                  | What you get                                                                                                                                         | Status       |
|:--------------------- |:---------------------------------------------------------------------------------------------------------------------------------------------------- |:------------ |
| Hypervisor onboarding | mTLS with `secp384r1` client certificates, one-time trust tokens, server certificate fingerprint and public-key (SPKI) pinning, certificate rotation | Alpha        |
| Web terminal          | `xterm.js` through an isolated `console-proxy`; exec-based shell with a serial-console fallback; single-use Redis tickets (60 s by default)          | Alpha        |
| Images                | Admin-managed image catalog, per-node image selection                                                                                                | Alpha        |
| Snapshots             | Async crash-consistent disk snapshots, distributed locks, per-tier quotas, pluggable `BackupTargetInterface`                                         | Alpha        |
| Instance metrics      | Per-instance historical CPU, RAM, root disk, disk I/O, and network traffic (`1h`–`30d`) behind `METRICS_ENABLED`                                    | Alpha        |
| Networking / IPAM     | IPv4 pools (Routed, Bridged, Macvlan, and Manual modes), pool address/free-IP inspector, IP cooldown quarantine, hypervisor preflight checks | Alpha        |
| Quotas                | Reusable plans and tiers, race-free provisioning with row locks and Redis mutexes                                                                    | Alpha        |
| Audit log             | Separate audit DB connection, `INSERT`/`SELECT`-only grants in production, SHA-256 hash chain with HMAC seals, PII pseudonymisation                  | Alpha        |
| Real-time UI          | Laravel Reverb WebSockets, drift detection, English and Bulgarian UI, light/dark/system themes                                                       | Alpha        |
| Extensions & Integrations | 17 typed domain events, pluggable service contracts (DNS, billing, notification, backup), webhooks with HMAC-SHA256, service tokens, UI slot/nav registry, and idempotency keys | Alpha        |
| LXD driver            | Best-effort compatibility, see [Supported versions](#supported-versions)                                                                             | Experimental |

Details for each area live in [`docs/`](docs/README.md).

### Screenshots

**Instance provisioning**

![Ognivo Instance Provisioning](docs/assets/provision-instance-modal.png)

**Compute nodes & mTLS onboarding**

![Ognivo Compute Nodes Management](docs/assets/compute-nodes-mtls.png)

**IPAM pool address inspector**

![Ognivo IPAM Pool Address Inspector](docs/assets/ipam-pool-inspector.png)

### Tenant privacy

Administrators are blocked by panel policy from opening customer guest terminals, including while impersonating a user. This protects tenants from the panel's own staff, not from someone with root access to the hypervisor itself. See the [threat model](docs/architecture/security-and-audit.md) for what Ognivo does and does not protect against.

## Architecture

```mermaid
flowchart TB
  Browser["Browser<br/>(Next.js 16 / React 19 / Echo / xterm.js)"]

  subgraph Docker["Docker network (app-network)"]
    Nginx["Nginx reverse proxy<br/>(:80 / :443)"]
    Frontend["Next.js 16 app server<br/>(:3000)"]
    Backend["Laravel 13 API (PHP 8.5-FPM)<br/>(:9000)"]
    Reverb["Laravel Reverb WS<br/>(:8080)"]
    ConsoleProxy["Console proxy (Node.js)<br/>(:8085)"]
    Workers["Background daemons<br/>(queue-worker, queue-worker-heavy, scheduler, incus-events)"]
    MySQL[("MySQL 8.4 LTS<br/>(app and audit grants)")]
    Redis[("Redis 7<br/>(queues [high, metrics, default, heavy], locks, terminal tickets)")]
  end

  Incus["Incus / LXD hypervisor<br/>(mTLS REST and WSS API :8443)"]

  Browser -->|"HTTPS / HTTP"| Nginx
  Nginx -->|"/"| Frontend
  Nginx -->|"/api/v1, /sanctum, /docs"| Backend
  Nginx -->|"/app/{key} (WSS)"| Reverb
  Nginx -->|"/console?ticket=... (WSS)"| ConsoleProxy

  Backend --> MySQL
  Backend --> Redis
  Backend -->|"Broadcast events"| Reverb
  Workers --> MySQL
  Workers --> Redis

  ConsoleProxy -->|"Atomic GETDEL ticket"| Redis
  Backend -->|"mTLS REST (:8443)"| Incus
  Workers -->|"mTLS REST / events (:8443)"| Incus
  ConsoleProxy -->|"WSS (secret + SPKI pinning :8443)"| Incus
```

Full walkthrough: [System Architecture](docs/architecture/overview.md).

## Installation

Ognivo runs as a Docker Compose stack. There is nothing to install on the host except Docker, Make and OpenSSL. PHP, Node.js and the databases all run in containers.

### Requirements

| Requirement             | Version / note                                                                                                                                                              |
|:----------------------- |:--------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Operating system        | Linux (recommended). macOS works for development. On Windows use WSL2 with Docker Desktop's WSL integration, because the scripts need Bash                                  |
| Docker Engine           | 24.0 or newer                                                                                                                                                               |
| Docker Compose          | v2, 2.20 or newer (`docker compose`, not `docker-compose`)                                                                                                                  |
| GNU Make, Bash, OpenSSL | Any recent version (used by `make secrets`)                                                                                                                                 |
| Git                     | To clone the repository                                                                                                                                                     |
| Resources               | 2 CPU cores and 4 GB RAM are enough for a development stack. The first build downloads several images and runs `composer install` and `npm install`, so allow a few minutes |
| Free host ports         | Development: `80`, `3000`, `8080`, `3306` (MySQL, localhost only). Production: `80`, `443`                                                                                  |
| An Incus node           | Only needed to manage real instances. The development stack seeds demo data with a fake driver, so you can explore the UI without one                                       |

Check your tools:

```bash
docker --version && docker compose version && make --version && openssl version && git --version
```

### 1. Clone the repository

```bash
git clone https://github.com/schmasterz/ognivo.git
cd ognivo
```

Prefer a release over the moving `main` branch? List the tags and check one out:

```bash
git tag --list
git checkout v1.0.0-alpha.1
```

Downloaded a ZIP instead (for example `ognivo-main.zip`)? Unpack it and `cd` into the folder. The folder name does not matter, because the Compose project name is fixed to `ognivo`.

### 2a. Development install (hot reload, demo data)

```bash
make secrets   # creates .env.development and .env.production with random secrets
make up-dev    # builds images, starts all containers, runs migrations, seeds demo data
```

`make up-dev` creates the env files for you if they do not exist, so `make secrets` is optional on a fresh clone. It does not overwrite existing values.

The first start takes a few minutes. Watch progress and wait until the containers report `healthy`:

```bash
make ps
make logs-dev      # Ctrl+C to stop following
```

Then open:

| What                        | URL                         |
|:--------------------------- |:--------------------------- |
| Web panel                   | `http://localhost`          |
| API documentation (OpenAPI) | `http://localhost/docs/api` |
| Health check                | `http://localhost/up`       |

| Role     | Email                   | Password   |
|:-------- |:----------------------- |:---------- |
| Admin    | `admin@ognivo.local`    | `password` |
| Customer | `customer@ognivo.local` | `password` |

> [!CAUTION]
> **Local development only.** The development stack runs with debug tooling and well-known demo credentials, and some ports are published on all network interfaces. Never run it on a server reachable from the internet. Use the production install below.

### 2b. Production install (hardened, HTTPS on port 443)

Run these on the server that will host the panel.

**1. Generate secrets.**

```bash
make secrets
```

**2. Set your domain** in `.env.production`. Frontend configuration is runtime-driven (Node.js reads container environment variables upon request and feeds `<ConfigProvider>` and `GET /api/v1/config`), so changing variables later only requires restarting or recreating the containers (`make up-prod` or `docker compose restart frontend`), without `make build-prod`.

```ini
APP_URL=https://panel.example.com
REVERB_ALLOWED_ORIGINS=https://panel.example.com
CONSOLE_ALLOWED_ORIGINS=https://panel.example.com
SANCTUM_STATEFUL_DOMAINS=panel.example.com
SESSION_COOKIE=__Host-ognivo_session
SESSION_SAME_SITE=strict
SESSION_ABSOLUTE_LIFETIME=1440
SCRAMBLE_ALLOW_PUBLIC=false
SCRAMBLE_TRY_IT_ENABLED=false
```

> [!NOTE]
> When upgrading an existing installation to cookie-based browser sessions, add the keys above (`SESSION_COOKIE`, `SESSION_SAME_SITE`, `SESSION_ABSOLUTE_LIFETIME`, `CONSOLE_ALLOWED_ORIGINS`, `SANCTUM_STATEFUL_DOMAINS`, `SCRAMBLE_ALLOW_PUBLIC`, `SCRAMBLE_TRY_IT_ENABLED`) to `.env.production`. All users will need to sign in again, and operators should run `docker exec ognivo-backend php artisan auth:purge-legacy-tokens` to purge legacy personal access tokens.

**3. Allow private node addresses if needed.** By default production refuses to add Incus nodes on private (RFC 1918) or loopback addresses (an SSRF protection). If your nodes are on a LAN, add:

```ini
HYPERVISOR_ALLOW_PRIVATE_ENDPOINTS=true
```

**4. Build and start.**

```bash
make build-prod
make up-prod
make ps            # wait until every container with a healthcheck is "healthy"
```

If the configuration is unsafe (placeholder passwords, `APP_DEBUG=true`, wildcard origins and so on), the backend refuses to start and says why in `make logs-prod`.

**5. Create the first administrator.** Seeders are disabled in production.

```bash
docker exec -it ognivo-backend php artisan admin:create admin@yourdomain.com --name="Super Admin"
```

Without `--password`, a strong password is generated and printed once.

**6. Open the panel** at your `APP_URL` (for a local test: `https://localhost`). The health check is `/up`.

**7. Install a real TLS certificate.** On first start a self-signed certificate is created in `./docker/certs/`, so browsers will warn. Replace it and reload Nginx:

```bash
cp /path/to/fullchain.pem docker/certs/server.crt
cp /path/to/privkey.pem   docker/certs/server.key
chmod 600 docker/certs/server.key
docker exec ognivo-nginx nginx -s reload
```

> [!IMPORTANT]
> Back up `.env.production`, especially `APP_KEY` (and any active `APP_PREVIOUS_KEYS`) and `AUDIT_HMAC_KEY`. Losing `APP_KEY` makes encrypted data (panel certificate key, node tokens, SMTP password) permanently unrecoverable, and database restores require the matching `APP_KEY` (and `APP_PREVIOUS_KEYS` if unmigrated). See [Upgrading and backups](#upgrading-and-backups) and [docs/operations/configuration.md#6-app_key-rotation-procedure](docs/operations/configuration.md#6-app_key-rotation-procedure).

Firewall: expose only `80` and `443` to the internet. The panel host must be able to reach each Incus node on port `8443`.

### Day-to-day commands

| Action                                         | Development      | Production        |
|:---------------------------------------------- |:---------------- |:----------------- |
| Start (builds missing images)                  | `make up-dev`    | `make up-prod`    |
| Rebuild after Dockerfile or dependency changes | `make build-dev` | `make build-prod` |
| Status and health                              | `make ps`        | `make ps`         |
| Follow logs                                    | `make logs-dev`  | `make logs-prod`  |
| Stop                                           | `make down-dev`  | `make down-prod`  |
| Reset the dev database                         | `make reset-dev` | not applicable    |

Starting one environment stops the other, so they never fight over ports. After editing `.env.development` or `.env.production`, run `make up-dev` or `make up-prod` so the containers are recreated with the new values. More in the [Commands reference](docs/operations/commands.md).

### Uninstall

```bash
make down-dev            # or make down-prod
# Optional: also delete the data volumes (irreversible)
docker volume ls | grep ognivo
docker volume rm <volume-name>
```

Full walkthrough with explanations: [Installation guide](docs/getting-started/installation.md) and [Quickstart](docs/getting-started/quickstart.md). Problems? See [Troubleshooting](docs/operations/troubleshooting.md).

## Connect your first Incus node

Ognivo is only useful once it manages a hypervisor.

👉 *For a complete end-to-end host preparation guide (including mandatory packages like `genisoimage`, NAT/firewall persistence, and storage pool sizing), see the [Compute Node Setup Guide](docs/getting-started/compute-node-setup.md).*

**On the Incus host** (Incus 6.0 LTS or newer, with KVM support; nested virtualization if the host is itself a VM):

```bash
# Make the Incus API reachable by the panel (default is local-only)
incus config set core.https_address <host-ip>:8443

# Create a one-time trust token for the panel
incus config trust add ognivo-panel
```

**In the Ognivo admin UI:**

1. Open **Compute Nodes**, click **Create Node**, choose **Connect with token**.
2. Enter a name, paste the token and set the CPU, RAM and storage capacity.
3. Click **Connect with token**. Ognivo verifies the server fingerprint from the token before sending any secret, then pins it.
4. Select the images this node should offer, then create an IP pool for it under **Networking & IPAM**.

Notes:

- If the Incus host and the panel run on the same machine, the panel containers must reach Incus on a host address that is routable from the Docker network. `localhost` inside a container is the container itself.
- On a production panel, nodes on private addresses need `HYPERVISOR_ALLOW_PRIVATE_ENDPOINTS=true` (see the production install).
- For Routed networking, the node also needs IP forwarding and Proxy ARP, see [Networking & IPAM](docs/features/networking.md).

Alternative flow with a pre-trusted certificate and full details: [Host Onboarding & mTLS](docs/features/host-onboarding.md).

## Supported versions

- **Incus 6.0+ LTS** is the officially supported platform. Container and VM orchestration, snapshots and terminal streaming are tested against it.
- **LXD** is experimental and best-effort. Core operations share API compatibility, but production deployments should use Incus.

## Documentation

| Section                   | Guides                                                                                                                                                                                                                                                                                                                                                                   |
|:------------------------- |:------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Getting started           | [Installation](docs/getting-started/installation.md), [Quickstart](docs/getting-started/quickstart.md), [Development environment](docs/getting-started/development.md)                                                                                                                                                                                                   |
| Architecture and security | [System architecture](docs/architecture/overview.md), [Security and audit log](docs/architecture/security-and-audit.md)                                                                                                                                                                                                                                                  |
| Features                  | [Host onboarding & mTLS](docs/features/host-onboarding.md), [Images](docs/features/images.md), [Networking & IPAM](docs/features/networking.md), [Web terminal](docs/features/console.md), [Snapshots](docs/features/snapshots.md), [Instance metrics](docs/features/metrics.md)                                                                                                                                      |
| Operations                | [Production deployment](docs/operations/production.md), [Configuration reference](docs/operations/configuration.md), [Commands](docs/operations/commands.md), [Scheduled tasks](docs/operations/commands.md#34-scheduled-tasks), [Troubleshooting](docs/operations/troubleshooting.md) |
| Reference                 | [Interactive OpenAPI & Manual QA](docs/testing/manual-qa.md#25-interactive-openapi-documentation) (interactive OpenAPI at `/docs/api` on a running panel)                                                                                                                                                                                                                                                                                     |
| Testing                   | [Automated testing](docs/testing/automated-testing.md), [Manual QA & API scenarios](docs/testing/manual-qa.md), [Manual Incus network tests](docs/testing/manual-incus-network-tests.md) |
| Extensions                | [Extension architecture & manifest](docs/extensions/index.md), [Domain events](docs/extensions/events-catalog.md), [Service contracts](docs/extensions/service-contracts.md), [Frontend slots & theming](docs/extensions/frontend-slots.md), [Webhooks & service tokens](docs/extensions/webhooks-and-tokens.md), [Idempotency](docs/extensions/idempotency.md), [Stability policy](docs/extensions/stability-policy.md) |

## Known limitations

- **IPv4 only.** IPAM supports IPv4 pools and allocation. IPv6 and dual-stack guests are planned for 1.1.
- **Standalone nodes.** Each Incus endpoint is one compute node. Cross-cluster migration, cluster-wide storage pools, SSO/OIDC and object storage buckets are out of scope for 1.0.
- **No plans or products yet.** Tenants pick CPU, RAM and disk within their quota. Overcommit is a global setting in `.env`.
- **First-party web interface only.** Browser sessions use `httpOnly`, `Secure` (in production), `SameSite=Strict` cookies (`__Host-ognivo_session` in production) with CSRF and same-origin verification. There is no public Bearer token authentication for external clients yet (Bearer tokens are rejected with `401`).

## Roadmap

Planned work is tracked [`CHANGELOG.md`](CHANGELOG.md). Current priorities: finishing 1.0 (instance access provisioning, VM metrics), IPv6 IPAM, storage pool selection and more backup targets.

### Planned, not scheduled

- **API access for external clients:** personal API tokens (Bearer) created by the user in the UI, scopes, per-token rate limiting, token revocation, audit log entries, and public API documentation. The browser session uses `httpOnly` cookies; token authentication would be an additional, separate mechanism. Not scheduled.

## Upgrading and backups

- **Alpha releases:** versions before the first beta may need a fresh install. Each release's notes in [`CHANGELOG.md`](CHANGELOG.md) say whether an in-place upgrade is supported. See [Installation — Update](docs/getting-started/installation.md#update).

- **Typical upgrade of an existing install** (when supported):
  
  ```bash
  git fetch --tags && git checkout <new-tag>
  make build-prod
  make up-prod                       # runs migrations on start
  docker exec ognivo-backend php artisan audit:grants --force   # only if the release notes ask for it
  ```

- **MySQL grants:** the users `ognivo_user`, `audit_writer` and `audit_maintenance` are created at first install and not modified on upgrade. When a release changes privileges, re-apply them with `php artisan audit:grants --force`.

- **What to back up:** the MySQL volume, `.env.production`, `docker/certs/`. Database restores require the matching `APP_KEY` (and `APP_PREVIOUS_KEYS` if secrets are unmigrated); for key rotation, see [docs/operations/configuration.md#6-app_key-rotation-procedure](docs/operations/configuration.md#6-app_key-rotation-procedure). Details in [Installation — Back up what cannot be regenerated](docs/getting-started/installation.md#back-up-what-cannot-be-regenerated).

- **Excluding telemetry from `mysqldump`:** the consumable metrics tables (`vm_metric_samples`, `vm_metric_rollups_5m`, `vm_metric_rollups_1h`, `vm_metric_totals`) can be excluded from logical `mysqldump` backups via `--ignore-table`. See [Instance metrics](docs/features/metrics.md#7-excluding-metrics-from-database-backups).

## Security

Please report vulnerabilities privately, not through public issues. See [`SECURITY.md`](SECURITY.md) for the contact address and disclosure process. The security model and threat model are described in [Security and audit log](docs/architecture/security-and-audit.md).

## Contributing

Contributions are welcome, including translations (the UI is available in English and Bulgarian). Read [`CONTRIBUTING.md`](CONTRIBUTING.md) and [`CODE_OF_CONDUCT.md`](CODE_OF_CONDUCT.md) first. Tests and static analysis (Pest, Pint, PHPStan level 10 and Psalm level 1 with baselines, ESLint, Node test runner) are described in [Automated testing](docs/testing/automated-testing.md).

## Technology stack

| Layer     | Technologies                                                                                                |
|:--------- |:----------------------------------------------------------------------------------------------------------- |
| Backend   | PHP 8.5 (FPM Alpine), Laravel 13, Sanctum 4, Spatie Permission and Model States, Dedoc Scramble (OpenAPI 3) |
| Frontend  | Next.js 16 (App Router, Turbopack), React 19, strict TypeScript, Tailwind CSS 4, Lucide, `xterm.js`         |
| Real-time | Laravel Reverb 1.12, Laravel Echo, Node.js 26 `console-proxy`                                               |
| Data      | MySQL 8.4 LTS, Redis 7 (`predis`), Nginx                                                                    |
| Quality   | Pest 5 / PHPUnit 13, Pint, Larastan (level 10), Psalm (level 1), ESLint 9, Node test runner                 |

## License

Distributed under the [MIT License](LICENSE). Release history: [`CHANGELOG.md`](CHANGELOG.md).