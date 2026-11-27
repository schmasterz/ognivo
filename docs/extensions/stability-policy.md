# Stability & Semantic Versioning Policy

Ognivo adheres to [Semantic Versioning 2.0.0](https://semver.org/spec/v2.0.0.html) (`MAJOR.MINOR.PATCH`). Beginning with **Ognivo 1.0.0**, all extension contracts and wire formats are frozen for the entire `1.x` release line.

---

## 1.0.0 SemVer Freeze Commitment

The following surfaces constitute the **Ognivo 1.0.0 Public Extension Surface** and will not receive breaking changes in any `1.y.0` minor or `1.y.z` patch release:

1. **PHP Service Contracts (`App\Contracts\*`):**
   - `BackupTargetInterface` (stable core contract for snapshot/backup orchestration).
2. **Domain Event Contracts & Catalog:**
   - `OgnivoEventInterface`, `AbstractOgnivoEvent` (`getEventName()`, `getPayload()`, `getOccurredAt()`, `getActor()`, `ShouldDispatchAfterCommit`), and all 17 `1.0.0` event names and payload keys.
3. **Extension Discovery & Manifest Schema:**
   - `ognivo-extension.json` fields (`name`, `version`, `ognivo_version_constraint`, `providers`, `event_subscribers`), `ExtensionMetadata`, `ExtensionRegistry`, and `/api/v1/extensions` responses.
4. **Frontend Extension Registries:**
   - `slotRegistry.ts` (`SlotId` union, `SlotItem`, `registerSlotComponent`, `getSlotComponents`, `unregisterSlotComponent`).
   - `navigationRegistry.ts` (`NavigationItem`, `registerNavigationItem`, `getNavigationItems`, `unregisterNavigationItem`, `subscribeNavigationRegistry`).
   - `themeRegistry.ts` (`ThemeTokens`, `BrandLogoComponent`, `setThemeTokens`, `getThemeTokens`, `setBrandLogo`, `getBrandLogo`, `subscribeThemeRegistry`) and CSS variables `--brand-primary`, `--brand-accent`, `--brand-radius`.
   - `vmRegistry.ts` (`registerVmTab`, `registerVmAction`, `useVmTabs`, `useVmActions`, context types).
5. **External Integration & Wire Protocols:**
   - Webhook headers (`X-Ognivo-Signature`, `X-Ognivo-Event`, `X-Ognivo-Delivery`), HMAC-SHA256 signature calculation, and `/api/v1/webhooks` API.
   - Service token `srv_` format, SHA-256 storage, `actor_type = 'system'` audit attribution, and `/api/v1/service-tokens` API.
   - `Idempotency-Key` behavior (`X-Idempotent-Replay: true`, `409 Conflict`, `422 Unprocessable Entity`).

Extensions targeting `ognivo_version_constraint: "^1.0"` can rely on backward compatibility across all `1.x` releases.

---

## Developer Preview Contracts (`@experimental`)

The following extension contracts, authorization payloads, value objects, events, and frontend slots are currently in **Developer Preview (`@experimental`)**:

- **Service Interfaces (`App\Contracts\*`):**
  - `DnsProviderInterface`
  - `NotificationChannelInterface`
  - `BillingProviderInterface`
  - `VpsPlanChangeAuthorizerInterface`
  - `VpsProvisioningAuthorizerInterface`
- **Authorization Payloads, Status Enum & Hypervisor Resource Specs (`App\Contracts\Data\*`, `App\Enums\Vps\VpsAuthorizationStatus`, `App\ValueObjects\Hypervisor\*`):**
  - `App\Contracts\Data\*`: `VpsPlanChangeAuthorizationRequest`, `VpsPlanChangeAuthorizationResult`, `VpsProvisioningAuthorizationRequest`, `VpsProvisioningAuthorizationResult`, `VpsAuthorizationInitiator`
  - `App\Enums\Vps\VpsAuthorizationStatus`
  - `App\ValueObjects\Hypervisor\*`: `InstanceResourceSpec`, `NetworkLimitsSpec`
- **VPS Domain Events (`App\Events\Vps\*`):**
  - `vps.plan.*`: `vps.plan.created`, `vps.plan.updated`, `vps.plan.published`, `vps.plan.unpublished`, `vps.plan.archived`, `vps.plan.unarchived`
  - `vps.plan.version.*`: `vps.plan.version.created`, `vps.plan.version.published`
  - `vps.plan_change.*`: `vps.plan_change.requested`, `vps.plan_change.started`, `vps.plan_change.completed`, `vps.plan_change.failed`, `vps.plan_change.cancelled`, `vps.plan_change.reconciliation_required`
- **Frontend Slot IDs (`slotRegistry.ts`):**
  - `vps.plan.card`
  - `vps.plan.change.preview`

Unlike the frozen surfaces above, experimental contracts are provided as architectural scaffolding and may be refined in minor `1.y.0` releases based on real-world requirements from reference integrations. **Stabilization criteria:** an `@experimental` contract transitions into the frozen SemVer commitment only after it is proven by a real production implementation integrated with core and remains unchanged across at least one minor release (`1.y.0`).

> **Note on `instance.resized`:** The core `instance.resized` event gained optional payload keys (`operation_id`, `from_plan_version_id`, `to_plan_version_id`, and `net_download_mbps` / `net_upload_mbps` inside `old_specs` / `new_specs`). Adding optional keys to an existing event payload is a non-breaking change under this policy.

---

## Breaking vs. Non-Breaking Changes

### Breaking Changes (Deferred to `2.0.0` or Later)

- Removing or renaming any public interface, method, class, or slot ID in the frozen extension surface.
- Adding required parameters to an existing interface method or narrowing parameter types / widening return types incompatibly.
- Removing or renaming any of the 17 domain event names or removing/changing the type of existing payload keys.
- Removing or renaming required fields in `ognivo-extension.json`.
- Changing the webhook HMAC algorithm, signature header format (`sha256=<hex>`), or `srv_` token prefix.
- Changing HTTP status codes or error semantics of `Idempotency-Key` handling.

### Non-Breaking Changes (Allowed in `1.y.0` / `1.y.z`)

- Adding **new** domain events or **new** optional keys to existing event payloads.
- Adding **new** service interfaces under `App\Contracts\`.
- Adding **new** optional properties to `ognivo-extension.json`, `SlotItem.metadata`, `NavigationItem`, or `ThemeTokens`.
- Adding **new** `SlotId` values to `slotRegistry.ts`.
- Adding **new** optional fields to JSON API responses.
- Refactoring internal core classes (`App\Services\*`, controllers, jobs, private methods) that are not part of the frozen extension surface.

---

## Deprecation Timeline

When an extension API or contract member must be superseded:

1. **Deprecation Notice:** The symbol or field is marked `@deprecated` in source code, documented in `CHANGELOG.md`, and paired with a clear replacement path in the extension guides.
2. **Minimum Retention Window:** Deprecated extension surface APIs remain functional and supported for **at least 2 minor versions** (for example, deprecated in `1.2.0` -> retained through `1.3.0` and `1.4.0`, and never removed before `2.0.0`).
3. **Major Release Removal:** Actual removal of deprecated public extension contracts occurs only on a major version boundary (`2.0.0`).

---

## Neutral Core Contracts

Ognivo Core is open-source (MIT-licensed) and maintains strict neutrality across every extension contract:

- **No licensing or edition bias:** Core interfaces, domain events, UI registries, and manifest discovery contain no license checks, artificial capability locks, or edition-specific branches.
- **Equal extension surface:** Internal modules, community packages, and self-hosted integrations all consume the exact same public extension contracts, events, slots, and APIs.
- **Clean separation of concerns:** Core defines minimal, domain-neutral primitives (for example `BillingProviderInterface::recordUsage` or `DnsProviderInterface::createRecord`) so any external system can integrate on equal terms.
