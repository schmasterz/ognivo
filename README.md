# Ognivo

**Open-source, multi-tenant control plane for KVM virtual machines and LXC system containers on Incus.**


> [!WARNING]
> **Pre-Alpha Software — Early Development**
>
> This project is currently in the **Pre-Alpha** stage and is under active development.
>
> Features may be incomplete, unstable, or subject to significant changes. APIs, configurations, and internal implementations may change without notice.
>
> **Not recommended for production use.**
>
> Use at your own risk. The project has not yet reached its first official Alpha release.
Ognivo lets you offer VPS-style hosting on your own **Incus** hypervisors: customers get a self-service dashboard, administrators get node, network and quota management, and everything is built around tenant isolation and an auditable trail of actions.

*Ognivo* (Огниво) is the Bulgarian word for a flint-and-steel fire starter.

## Contents

- [Why Ognivo?](#why-ognivo)
- [Features](#features)
- [Architecture](#architecture)
- [Quick Start](#quick-start)
- [Connect Your First Incus Node](#connect-your-first-incus-node)
- [Supported Versions](#supported-versions)
- [Documentation](#documentation)
- [Known Limitations](#known-limitations)
- [Roadmap](#roadmap)
- [Upgrade Notes](#upgrade-notes)
- [Security](#security)
- [Contributing](#contributing)
- [Technology Stack](#technology-stack)
- [License](#license)

## Why Ognivo?

Ognivo is aimed at small hosting providers, labs and self-hosters who run Incus and want a multi-tenant panel with quotas, IP management and a web console, without adopting a full virtualization platform.

It is an open-source alternative to hosting panels such as SolusVM and Virtualizor, focused on Incus. It is not a replacement for general-purpose platforms like Proxmox VE, and it does not aim to be one.

## Features

| Area | What you get | Status |
| :--- | :--- | :--- |
| Hypervisor onboarding | mTLS with `secp384r1` client certificates, one-time trust tokens, server fingerprint pinning, certificate rotation | Alpha |
| Web console | `xterm.js` terminal through an isolated `console-proxy`, single-use 60-second Redis tickets, SPKI-pinned upstream connection | Alpha |
| Snapshots | Async stateful and stateless snapshots, distributed locks, per-tier quotas, pluggable `BackupTargetInterface` | Alpha |
| Networking / IPAM | IPv4 pools, Routed and Manual modes, IP cooldown quarantine, hypervisor preflight checks | Alpha |
| Quotas | Reusable plans and tiers, race-free provisioning with row locks and Redis mutexes | Alpha |
| Audit log | Separate audit DB connection, `INSERT`/`SELECT`-only grants in production, SHA-256 hash chain with HMAC seals, PII pseudonymisation | Alpha |
| Real-time UI | Laravel Reverb WebSockets, drift detection, English and Bulgarian UI, light/dark/system themes | Alpha |
| LXD driver | Best-effort compatibility, see [Supported Versions](#supported-versions) | Experimental |

Details for each area live in [`docs/`](docs/README.md).

### Tenant privacy

Administrators are blocked by panel policy from opening customer guest consoles. This protects tenants from the panel's own staff, not from someone with root access to the hypervisor itself. See the [threat model](docs/architecture/security-and-audit.md) for what Ognivo does and does not protect against.

## Architecture

```mermaid
flowchart TB
    Browser["Browser<br/>(Next.js 16 / React 19 / Echo / xterm.js)"]

    subgraph Docker["Docker network (app-network)"]
        Nginx["Nginx reverse proxy<br/>(:80 / :443)"]
        Frontend["Next.js 16 app server<br/>(:3000)"]
        Backend["Laravel 13 API (PHP 8.5-FPM)<br/>(:9000)"]
        Reverb["Laravel Reverb WS<br/>(:8080)"]
        ConsoleProxy["Console proxy (Node 26)<br/>(:8085)"]
        Workers["Background daemons<br/>(queue-worker, scheduler, incus-events)"]
        MySQL[("MySQL 8.4 LTS<br/>(app and audit grants)")]
        Redis[("Redis 7<br/>(queues, locks, console tickets)")]
    end

    Incus["Incus / LXD hypervisor<br/>(mTLS REST and WSS API :8443)"]

    Browser -->|"HTTPS / HTTP"| Nginx
    Nginx -->|"/"| Frontend
    Nginx -->|"/api, /sanctum, /docs"| Backend
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

## Quick Start

**Prerequisites:** Docker Engine 24.0+, Docker Compose v2 (2.20+), GNU Make, Bash and OpenSSL. Free host ports: `80`, `3000`, `8080`, `3306` (development) or `80`, `443` (production).

### Development (hot reload, demo data)

```bash
make secrets   # generates random secrets in .env.development and .env.production
make up-dev    # builds images, starts all containers, runs migrations, seeds demo data
```

Open `http://localhost`. Health check: `http://localhost/up`.

| Role | Email | Password |
| :--- | :--- | :--- |
| Admin | `admin@ognivo.local` | `password` |
| Customer | `customer@ognivo.local` | `password` |

> [!CAUTION]
> **Local development only.** The development stack runs with debug tooling and well-known demo credentials. Never expose it to the internet. Use `make up-prod` for any public server.

### Production (hardened HTTPS on port 443)

```bash
make secrets   # edit .env.production afterwards if you use a custom domain
make build-prod
make up-prod

# Seeders are disabled in production, so create the first administrator:
docker exec -it ognivo-backend php artisan admin:create admin@yourdomain.com --name="Super Admin"
```

Open `https://localhost`. Health check: `https://localhost/up`.

On first start a self-signed certificate is generated in `./docker/certs/` (`server.crt`, `server.key`). To use your own certificate, replace both files and run:

```bash
docker exec ognivo-nginx nginx -s reload
```

> [!IMPORTANT]
> Back up `.env.production`, especially `APP_KEY`. Losing it makes encrypted data unreadable. `make secrets` writes both `.env.development` and `.env.production`.

> [!TIP]
> - `make up-dev` and `make up-prod` generate missing `.env.*` files, build missing images and (in development) install missing Composer dependencies. Use `make build-dev` / `make build-prod` to rebuild after changing a `Dockerfile` or package manifest.
> - Starting one environment stops the other to avoid port conflicts. Run `make ps` to inspect container health.
> - Full walkthrough: [Quickstart Guide](docs/getting-started/quickstart.md).

## Connect Your First Incus Node

Ognivo is only useful once it manages a hypervisor. In short:

1. Install **Incus 6.0+ LTS** on a host with KVM support (nested virtualization if the host is itself a VM) and expose the API on `:8443`.
2. In the admin UI, add a host and generate a one-time trust token.
3. Add the panel's client certificate to the node's Incus trust store, using the command shown in the UI.
4. Verify the server fingerprint in the UI to pin it.

<!-- TODO: put the exact commands here (e.g. the `incus config trust add` step) once finalized -->

Full guide: [Host Onboarding & mTLS](docs/features/host-onboarding.md). For Routed networking you also need host `sysctl` and Proxy ARP settings, see [Networking & IPAM](docs/features/networking.md).

## Supported Versions

- **Incus 6.0+ LTS** is the officially supported platform. Container and VM orchestration, snapshots and console streaming are tested against it.
- **LXD** is experimental and best-effort. Core operations share API compatibility, but production deployments should use Incus.

## Documentation

| Section | Guides |
| :--- | :--- |
| Getting started | [Quickstart](docs/getting-started/quickstart.md), [Development environment](docs/getting-started/development.md) |
| Architecture and security | [System architecture](docs/architecture/overview.md), [Security and audit log](docs/architecture/security-and-audit.md) |
| Features | [Host onboarding & mTLS](docs/features/host-onboarding.md), [Networking & IPAM](docs/features/networking.md), [Web console](docs/features/console.md), [Snapshots](docs/features/snapshots.md) |
| Operations | [Production deployment](docs/operations/production.md), [Configuration reference](docs/operations/configuration.md), [Commands cheat sheet](docs/operations/commands.md), [Troubleshooting](docs/operations/troubleshooting.md) |
| Testing | [Automated testing](docs/testing/automated-testing.md), [Manual QA & API scenarios](docs/testing/manual-qa.md) |

## Known Limitations

- **IPv4 only.** IPAM supports IPv4 pools and allocation. IPv6 and dual-stack guests are planned.
- **Single-node scope.** Ognivo manages standalone hypervisor nodes. Incus/LXD clustering (cross-cluster migration, cluster-wide storage pools), SSO/OIDC and object storage buckets are out of scope for 1.0.
- **Bearer tokens in `localStorage`.** API tokens are stored in the browser and mitigated by strict CSP and XSS hygiene. Migration to `httpOnly` SameSite cookies is planned (tracked in [issues](https://github.com/OWNER/ognivo/issues)).

## Roadmap

Planned work is tracked in [GitHub milestones](https://github.com/OWNER/ognivo/milestones) and [`CHANGELOG.md`](CHANGELOG.md). Current priorities: `httpOnly` cookie authentication, IPv6 IPAM, and further backup targets.

## Upgrade Notes

- **From pre-alpha or early `alpha.1`:** do a fresh install. Breaking schema and security-model changes cannot be upgraded in place.
- **MySQL grants:** existing users (`ognivo_user`, `audit_writer`, `audit_maintenance`) are set up at initial install and are not modified on upgrade. When a release adds table grants or changes privileges, re-apply them manually:

  ```bash
  php artisan audit:grants --force
  ```

## Security

Please report vulnerabilities privately, not through public issues. See [`SECURITY.md`](SECURITY.md) for the contact address and disclosure process. The security model and threat model are described in [Security and Audit Log](docs/architecture/security-and-audit.md).

## Contributing

Contributions are welcome, including translations (the UI is available in English and Bulgarian). Read [`CONTRIBUTING.md`](CONTRIBUTING.md) and [`CODE_OF_CONDUCT.md`](CODE_OF_CONDUCT.md) first. Tests and static analysis (Pest, PHPStan level 10, Psalm, ESLint) are described in [Automated testing](docs/testing/automated-testing.md).

## Technology Stack

| Layer | Technologies |
| :--- | :--- |
| Backend | PHP 8.5 (FPM Alpine), Laravel 13, Sanctum 4, Spatie Permission and Model States, Dedoc Scramble (OpenAPI 3) |
| Frontend | Next.js 16 (App Router, Turbopack), React 19, strict TypeScript, Tailwind CSS 4, Lucide, `xterm.js` |
| Real-time | Laravel Reverb 1.12, Laravel Echo, Node.js 26 LTS `console-proxy` |
| Data | MySQL 8.4 LTS, Redis 7 (`predis`), Nginx 1.27 |
| Quality | Pest 5 / PHPUnit 13, Larastan (level 10), Psalm (level 1), ESLint 9, Node test runner |

## License

Distributed under the [MIT License](LICENSE). Release history: [`CHANGELOG.md`](CHANGELOG.md).