# Ognivo Extension Developer Guide

Ognivo Core is built around a **thin core, modular extensions** architecture. Core focuses on multi-tenant workload orchestration on Incus/LXD, RBAC, IPAM, mTLS node security, and tamper-evident auditing, while integrations—such as DNS automation, usage metering, external notification channels, remote backup targets, and custom UI modules—live in decoupled extensions.

Starting with **Ognivo 1.0.0**, all backend contracts, domain events, manifest schemas, webhook wire formats, and frontend extension registries are governed by a strict [Stability & SemVer Policy](./stability-policy.md).

---

## Architecture Principles

1. **Thin Core:** Core never embeds vendor-specific logic for DNS providers, billing systems, chat/incident tools, or offsite storage backends.
2. **Contract-Driven:** Extensions implement stable PHP interfaces (`App\Contracts\*`) and subscribe to structured domain events (`OgnivoEventInterface`) instead of patching core services.
3. **Zero Core Modifications:** Extensions register themselves via Composer autoloading and `ognivo-extension.json` manifests, binding into Laravel's service container and Next.js runtime registries without altering core files.
4. **Neutrality:** Core extension APIs are completely neutral and make no assumptions about deployment model, licensing, or external tooling.

---

## Extension Lifecycle & Discovery

Backend extensions are discovered at runtime by `App\Services\Extension\ExtensionRegistry` (`backend/app/Services/Extension/ExtensionRegistry.php`).

### Discovery Locations

`ExtensionRegistry::discover()` scans for `ognivo-extension.json` manifests in two locations:

1. **Composer vendor packages:**
   - Paths listed in `vendor/composer/installed.json` (`install-path` entries).
   - Direct package paths matching `vendor/*/*/ognivo-extension.json`.
2. **Local extensions directory:**
   - Directories matching `extensions/*/ognivo-extension.json` relative to the Laravel application base path (`backend/extensions/`).

Duplicate manifest paths (for example, symlinked path repositories) are deduplicated by canonical `realpath` and indexed by the manifest's `name`.

### Manifest Format (`ognivo-extension.json`)

Every extension must provide an `ognivo-extension.json` file in its root directory, parsed into `App\Services\Extension\ExtensionMetadata`:

```json
{
  "name": "acme/dns-sync",
  "version": "1.0.0",
  "description": "Synchronizes instance DNS records with an external DNS zone.",
  "author": "Acme Infrastructure",
  "ognivo_version_constraint": "^1.0",
  "providers": [
    "Acme\\DnsSync\\DnsSyncServiceProvider"
  ],
  "event_subscribers": [
    "Acme\\DnsSync\\Listeners\\InstanceDnsSubscriber"
  ]
}
```

| Field | Required | Type | Validation / Notes |
| --- | --- | --- | --- |
| `name` | Yes | `string` | Non-empty unique identifier (typically `vendor/package`). |
| `version` | Yes | `string` | Valid Composer SemVer version (`Composer\Semver\VersionParser::normalize`). |
| `ognivo_version_constraint` | Yes | `string` | Valid Composer SemVer constraint (e.g. `"^1.0"`). |
| `description` | No | `string` | Human-readable summary (defaults to `""`). |
| `author` | No | `string` | Maintainer name or organization (defaults to `""`). |
| `providers` | No | `string[]` | List of Laravel `ServiceProvider` FQCNs to register when compatible. |
| `event_subscribers` | No | `string[]` | List of event subscriber FQCNs (camelCase `eventSubscribers` is also accepted). |

### Composer PSR-4 Autoloading

Distribute backend extensions as standard Composer packages (via Packagist, VCS, or a local Composer `path` repository) so PHP classes resolve automatically via PSR-4:

```json
{
  "name": "acme/dns-sync",
  "type": "library",
  "require": {
    "php": "^8.5"
  },
  "autoload": {
    "psr-4": {
      "Acme\\DnsSync\\": "src/"
    }
  }
}
```

### Compatibility Check & Provider Registration

When `ExtensionRegistry::registerProviders()` runs:

1. It resolves the running core version from `config('app.version')` (defaulting to `'1.0.0'` if unset).
2. Each discovered extension is evaluated via `ExtensionMetadata::isCompatibleWith($coreVersion)` using `Composer\Semver\Semver::satisfies()`.
3. Incompatible extensions are skipped without breaking application boot.
4. For compatible extensions, each class listed in `providers` is registered once via `$this->app->register($providerClass)`.

### Inspecting Discovered Extensions via API

Administrators can inspect discovered extensions and their compatibility status through `ExtensionController` (`/api/v1/extensions`, protected by `auth:sanctum`, `active`, and `admin` middleware):

- `GET /api/v1/extensions` — Lists all discovered extensions and their `compatible` status.
- `GET /api/v1/extensions/{name}` — Returns detailed metadata for a single extension (e.g. `/api/v1/extensions/acme/dns-sync`), or `404` if not discovered.

---

## Map of Extension Points

| Extension Area | Purpose | Documentation |
| --- | --- | --- |
| **Domain Events** | React to 17 post-commit lifecycle, host, storage, and access events. | [Domain Events Catalog](./events-catalog.md) |
| **Service Contracts** | Swap or implement DNS, notification, billing, and backup providers. | [Service Contracts](./service-contracts.md) |
| **Frontend Slots & Theming** | Inject UI tabs, actions, widgets, sidebar links, brand logos, and CSS tokens. | [Frontend Slots & Theming](./frontend-slots.md) |
| **Webhooks & Service Tokens** | Subscribe external systems over signed HTTP POST and authenticate with `srv_` tokens. | [Webhooks & Service Tokens](./webhooks-and-tokens.md) |
| **Idempotency** | Safely retry mutating API requests with `Idempotency-Key` deduplication. | [API Idempotency](./idempotency.md) |
| **Stability & Versioning** | Review SemVer 1.0.0 guarantees, breaking-change rules, and deprecation timelines. | [Stability & SemVer Policy](./stability-policy.md) |
