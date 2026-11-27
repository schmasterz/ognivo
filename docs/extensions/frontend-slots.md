# Frontend Slots, Navigation & Theming

Ognivo's Next.js frontend provides three client-side extension registries under `frontend/src/lib/` so extensions can inject React components, sidebar navigation entries, custom brand logos, and CSS design tokens without editing core UI components.

---

## Slot Registry (`slotRegistry.ts`)

Module: `frontend/src/lib/registry/slotRegistry.ts`

### Available Slot IDs

`SlotId` defines five standard extension points plus two `@experimental` VPS plan slots (while accepting custom string IDs for extension-to-extension composition):

| Slot ID | Status | Intended Surface | Notes |
| --- | --- | --- | --- |
| `instance.detail.tabs` | Stable | Tabs inside `VmDetailsModal` | Bridged into `vmRegistry.ts` (`getVmTabs`). Supports `metadata.label`, `metadata.icon`, `metadata.badge`, and `metadata.visible`. |
| `instance.detail.actions` | Stable | Action buttons inside `VmDetailsModal` | Bridged into `vmRegistry.ts` (`getVmActions`). Supports `metadata.label`, `metadata.icon`, `metadata.variant`, `metadata.disabled`, `metadata.visible`, and `metadata.onClick`. |
| `settings.sections` | Stable | Additional sections in platform settings | Standard `SlotId` reserved for settings UI panels. |
| `dashboard.widgets` | Stable | Panels above the main dashboard workspace | Rendered directly in `DashboardShell.tsx` above page `{children}`. |
| `login.providers` | Stable | Alternative authentication options on login | Standard `SlotId` reserved for external sign-in providers. |
| `vps.plan.card` | `@experimental` | Footer/extra content inside each VPS plan card (`VpsPlanCard.tsx`) | Receives `{ plan: VpsPlanCatalogItem }`. |
| `vps.plan.change.preview` | `@experimental` | Preview step inside `ChangePlanModal.tsx` | Receives `{ vm: VirtualMachine; preview: VpsPlanChangePreview }`. |

### Experimental VPS Plan Slots (`@experimental`)

> [!NOTE]
> **Status: Developer Preview (`@experimental`)**  
> `vps.plan.card` and `vps.plan.change.preview` are in Developer Preview and may evolve in minor `1.x` releases per the [Stability Policy](./stability-policy.md).

- **`vps.plan.card`** (`{ plan: VpsPlanCatalogItem }`): Rendered inside `VpsPlanCard` (`frontend/src/components/plans/VpsPlanCard.tsx`) to display pricing, billing cycle badges, or custom plan metadata.
- **`vps.plan.change.preview`** (`{ vm: VirtualMachine; preview: VpsPlanChangePreview }`): Rendered in the preview step of `ChangePlanModal` (`frontend/src/components/vms/ChangePlanModal.tsx`) below the resource comparison and warnings list to display proration, invoice summaries, or upgrade terms.

### Registering & Unregistering Slot Components

```ts
import {
  registerSlotComponent,
  getSlotComponents,
  unregisterSlotComponent,
} from "@/lib/registry/slotRegistry";

const unregister = registerSlotComponent("dashboard.widgets", {
  id: "acme-cluster-banner",
  priority: 50, // default is 100; lower numbers render first
  component: ClusterHealthBanner,
  metadata: {
    source: "acme/observability",
  },
});

// Later cleanup (or call unregisterSlotComponent("dashboard.widgets", "acme-cluster-banner"))
unregister();
```

#### `SlotItem<TProps>` Shape

| Property | Type | Required | Description |
| --- | --- | --- | --- |
| `id` | `string` | Yes | Unique item ID within the slot. Re-registering the same `id` replaces the previous item. |
| `priority` | `number` | No | Sort order (default `100`). Items are sorted ascending—lower numbers render first. |
| `component` | `React.ComponentType<TProps>` | Yes | React component rendered for the slot. |
| `metadata` | `Record<string, unknown>` | No | Arbitrary key-value metadata read by slot consumers. |

### Instance Detail Slots (`vmRegistry.ts`)

For `instance.detail.tabs` and `instance.detail.actions`, `frontend/src/lib/registry/vmRegistry.ts` automatically merges components registered via `registerSlotComponent` with built-in tabs (`overview`, `metrics`, `snapshots`) and actions (`reinstall`).

- Built-in IDs take precedence if an extension registers a duplicate `id`.
- `getVmTabs(context)` and `getVmActions(context)` filter items using `metadata.visible(context)` (when provided) and sort ascending by `priority` (default `100`).
- Reactive hooks `useVmTabs(context)` and `useVmActions(context)` subscribe via `useSyncExternalStore`.

Example of registering an instance detail tab via `registerSlotComponent`:

```tsx
import { registerSlotComponent } from "@/lib/registry/slotRegistry";
import type { VmTabComponentProps, VmTabContext } from "@/lib/registry/vmRegistry";

function BackupsTab({ vm }: VmTabComponentProps) {
  return <div>External backups for {vm.name}</div>;
}

registerSlotComponent<VmTabComponentProps>("instance.detail.tabs", {
  id: "acme-backups",
  priority: 30,
  component: BackupsTab,
  metadata: {
    label: "Backups",
    visible: ({ vm }: VmTabContext) => vm.status !== "deleted",
  },
});
```

---

## Navigation Registry (`navigationRegistry.ts`)

Module: `frontend/src/lib/registry/navigationRegistry.ts`

`Sidebar.tsx` subscribes to `navigationRegistry` using `useSyncExternalStore` and renders an **Extensions** section whenever one or more items are visible to the current user's roles.

### Registering Sidebar Links

```ts
import { ServerCog } from "lucide-react";
import { registerNavigationItem } from "@/lib/registry/navigationRegistry";

const unregisterNav = registerNavigationItem({
  id: "acme-billing-overview",
  label: "Usage & Billing",
  href: "/extensions/billing",
  priority: 40, // default 100, lower rendered first
  icon: ServerCog,
  requiredRole: "admin", // omit to show to all authenticated users
  badge: "Beta", // string, number, or () => string | number
  target: "_self", // "_self" or "_blank"
});
```

#### `NavigationItem` Properties

| Property | Type | Required | Description |
| --- | --- | --- | --- |
| `id` | `string` | Yes | Unique navigation item ID. |
| `label` | `string` | Yes | Display text in the sidebar. |
| `href` | `string` | Yes | Route path or external URL. |
| `priority` | `number` | No | Sort order (default `100`; lower renders higher). |
| `icon` | `React.ComponentType<{ className?: string }>` | No | Optional icon component. |
| `requiredRole` | `string` | No | Role name required for visibility (e.g. `"admin"`). |
| `badge` | `string \| number \| (() => string \| number)` | No | Optional pill badge rendered on the right side of the link. |
| `target` | `"_blank" \| "_self"` | No | Link target. `_blank` links include `rel="noopener noreferrer"`. |

Helper APIs exported from `navigationRegistry.ts`:
- `getNavigationItems(userRoles?: string[]): NavigationItem[]`
- `unregisterNavigationItem(id: string): boolean`
- `clearNavigationRegistry(): void`
- `subscribeNavigationRegistry(callback: () => void): () => void`

---

## Theming Registry (`themeRegistry.ts`)

Module: `frontend/src/lib/theming/themeRegistry.ts`

The theming registry lets extensions override brand CSS custom properties and replace the default `OgnivoLogo` component at runtime.

### Runtime Color & Radius Tokens

`ThemeContext.tsx` subscribes to `themeRegistry` and synchronizes registered tokens onto `document.documentElement.style`:

| `ThemeTokens` Key | CSS Custom Property | Example Value |
| --- | --- | --- |
| `primaryColor` | `--brand-primary` | `"#2563eb"` |
| `accentColor` | `--brand-accent` | `"#0ea5e9"` |
| `borderRadius` | `--brand-radius` | `"0.5rem"` |

```ts
import { setThemeTokens, getThemeTokens, clearThemeRegistry } from "@/lib/theming/themeRegistry";

// Merge partial token overrides at runtime
setThemeTokens({
  primaryColor: "#0f766e",
  accentColor: "#14b8a6",
  borderRadius: "0.75rem",
});

// Read active tokens
const active = getThemeTokens();

// Restore defaults (clears all tokens and custom brand logo)
clearThemeRegistry();
```

### Custom Brand Logo Slot

`frontend/src/components/ui/OgnivoLogo.tsx` subscribes to `getBrandLogo()` via `useSyncExternalStore`. When a custom component is registered, `OgnivoLogo` renders it with `{ className }` instead of the default Ognivo SVG:

```tsx
import { setBrandLogo, type BrandLogoComponent } from "@/lib/theming/themeRegistry";

const CustomBrandLogo: BrandLogoComponent = ({ className }) => (
  <img src="/custom-logo.svg" alt="Acme Cloud" className={className} />
);

// Activate custom logo
setBrandLogo(CustomBrandLogo);

// Restore default Ognivo logo
setBrandLogo(null);
```
