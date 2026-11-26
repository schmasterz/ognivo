# Frontend Agent Guide

## 1. Tech Stack & Runtime
- **Framework:** Next.js 16 (App Router, Turbopack, `cacheComponents: true`, `partialPrefetching: true`)
- **UI & Styling:** React 19, TypeScript (strict), Tailwind CSS v4, `lucide-react` icons, `xterm.js` for the web terminal
- **Real-Time:** Laravel Echo (`pusher-js` / Reverb WebSocket client in `src/lib/echo.ts`)
- **Testing:** Native Node.js test runner (`node --test`) with TypeScript strip-only mode

---

## 2. Directory & Routing Architecture (`src/app/`)

Routes are organized using **Next.js Route Groups** so URL paths remain flat while layouts are cleanly separated:

```text
src/app/
├── layout.tsx                        # Root providers: Theme -> I18n -> Toast -> Auth -> GlobalFilters
├── globals.css                       # Global Tailwind & theme styles
├── (auth)/                           # Public unauthenticated routes (no dashboard chrome)
│   ├── reset-password/page.tsx       # -> /reset-password
│   └── verify-email/page.tsx         # -> /verify-email
└── (dashboard)/                      # Authenticated routes sharing DashboardProvider & DashboardShell
    ├── layout.tsx                    # Mounts DashboardProvider + DashboardShell (Sidebar, Navbar, Quotas, Modals)
    ├── page.tsx                      # -> / (renders InstancesPage)
    ├── instances/page.tsx            # -> /instances (VmsTab)
    ├── snapshots/page.tsx            # -> /snapshots (SnapshotsTab)
    ├── networking/page.tsx           # -> /networking (NetworkingTab)
    ├── users/page.tsx                # -> /users (UsersTab, admin-only: non-admins are redirected to /instances)
    ├── nodes/page.tsx                # -> /nodes (NodesTab, admin-only: non-admins are redirected to /instances)
    ├── audit/page.tsx                # -> /audit (AuditLogTab, admin-only: non-admins are redirected to /instances)
    └── activity/page.tsx             # -> /activity (AuditLogTab for tenants; redirects admins to /audit)
```

### Routing Rules
- **Never** create stub pages that do `export { default } from "../page"`. Every `page.tsx` in `(dashboard)` must be a focused route component consuming `useDashboard()`.
- Legacy paths (`/workload`, `/workloads`) are redirected to `/instances` in `next.config.ts`.
- Use `useRouter()` / `usePathname()` (via `useDashboardNavigation`) for tab transitions—do not use raw `window.history.pushState` for route changes.
- Keep `src/app/(dashboard)/layout.tsx` as a Server Component (without `"use client"`) exporting `export const instant = false;`, and keep `experimental.instantInsights.validationLevel: "manual-warning"` in `next.config.ts`, since `DashboardShell` gates `{children}` behind client-side authentication (`authLoading` / `user`).

---

## 3. Component & State Organization (`src/components/` & `src/lib/`)

```text
src/
├── components/
│   ├── index.ts                      # Barrel exports for top-level domain components
│   ├── layout/
│   │   ├── DashboardContext.tsx      # Shared dashboard state, domain hooks, and action handlers
│   │   ├── DashboardShell.tsx        # Persistent layout chrome (Sidebar, Navbar, QuotaSection, FilterToolbar, Modals)
│   │   ├── DashboardModals.tsx       # Centralized modal mounting point
│   │   ├── Sidebar.tsx, Navbar.tsx, FilterToolbar.tsx, LanguageToggle.tsx, ThemeToggle.tsx, WsStatusBadge.tsx
│   │   ├── ImpersonationBanner.tsx   # Sticky banner shown during admin tenant impersonation
│   │   ├── useDashboardData.ts       # Static config (hosts, quotaGroups, settings) & quota/stats fetching
│   │   ├── useDashboardRealtime.ts   # Echo subscriptions (users.{id}, admin.workloads), reconnect resync, polling
│   │   ├── useDashboardNavigation.ts # Route-to-tab resolution & ?vm=<id>&vm_tab=<tab> deep-link sync
│   │   ├── useDashboardModals.ts     # Consolidated modal open/target state
│   │   └── useDashboardConfirmActions.ts # Domain confirmation dialog handlers
│   ├── auth/                         # AuthView (login, register, forgot password), ProfileModal, ResetPasswordView, VerifyEmailView
│   ├── vms/                          # VM table, CreateVmModal, VmDetailsModal, ConsoleModal + XtermViewer (web terminal), per-VM snapshots, useVms hook
│   ├── snapshots/                    # Global SnapshotsTab
│   ├── networking/                   # IP allocations, IP pools, PoolDetailModal, PreflightModal, coolingRefresh.ts, ipStatusFilter.ts, useNetworking hook
│   ├── users/                        # Admin user management table, modals, useUsers hook
│   ├── quotas/                       # QuotaOverview, DashboardQuotaSection, QuotaLimitsFormSection, quota group modals
│   ├── hosts/                        # Compute node tab & table (NodesTab, NodesTable, CreateHostModal, EditHostModal) and per-node image selection (HostImagesSection, ImageSelectionDrawer)
│   ├── images/                       # OS Images catalog modals (ImagesModal, CreateImageModal)
│   ├── audit/                        # Unified AuditLogTab, keyset cursor pagination, AuditDetailDrawer
│   ├── settings/                     # Platform SettingsModal
│   └── ui/                           # Reusable primitives (ConfirmModal, PaginationBar, SearchableUserSelect)
└── lib/
    ├── api/                          # Typed HTTP client (client.ts, ApiError) and domain API modules (vms, hosts, images, networking, quotas, snapshots, audit, admin, auth)
    ├── auth/                         # AuthContext.tsx (session state, login/logout, tenant impersonation) and impersonationStorage.ts
    ├── filters/GlobalFilterContext.tsx # Global tenant/host/status/search/perPage filters with debounce
    ├── hooks/
    │   ├── useCachedFetch.ts         # Session-scoped TTL cache with AbortController & force-refetch
    │   ├── useConfirmDialog.ts       # Reusable confirmation dialog state machine
    │   └── useModalEscape.ts         # Escape-key handling for modals
    ├── images/imageSelection.ts      # Pure helpers for the per-node image selection payload
    ├── registry/vmRegistry.ts        # Registry of VM details tabs
    ├── i18n/                         # I18nContext.tsx, loaders.ts, and useI18n() hook (translation data in frontend/locale/ via @locale/*)
    ├── theme/                        # Dark/Light/System theme provider
    ├── toast/                        # Toast notifications (useToast())
    └── echo.ts                       # Laravel Echo / Reverb client setup

tests/
├── components/
│   ├── audit/audit.test.ts
│   └── networking/
│       ├── coolingRefresh.test.ts
│       └── ipStatusFilter.test.ts
└── lib/
    ├── auth/impersonationStorage.test.ts
    ├── hooks/
    │   ├── useCachedFetch.test.ts
    │   ├── useConfirmDialog.test.ts
    │   └── useModalEscape.test.ts
    └── images/imageSelection.test.ts
```

---

## 4. Coding Conventions & Strict Guardrails

1. **Language:** All code, identifiers, comments, commit messages, and documentation MUST be in **English**. All user-facing UI strings must go through `useI18n()` (translation sections in `frontend/locale/en/` and `frontend/locale/bg/` imported via `@locale/*`).
2. **React 19 Strict Hook Compliance:**
   - **Never** read or mutate `.current` of a `useRef` during render (`react-hooks/refs`). Sync refs inside `useEffect`.
   - **Never** use `any` (`@typescript-eslint/no-explicit-any`). Use `unknown` in `catch (err: unknown)` blocks and narrow with `err instanceof Error` or `err instanceof ApiError`.
3. **No Native Browser Dialogs:**
   - Never use `window.alert()` or `window.confirm()`. Always use `useToast()` (`toast.success`, `toast.error`, `toast.info`) and `useConfirmDialog()` / `<ConfirmModal />`.
4. **Avoid Circular Dependencies:**
   - Components inside `src/components/**` must import sibling components via direct paths (e.g., `@/components/ui/ConfirmModal`), **not** from the root `@/components` barrel (`src/components/index.ts`).
5. **Cache & Session Isolation:**
   - Domain hooks using `useCachedFetch` must include `sessionUserId` (`user?.id`) in their cache key.
   - Explicit `refetch()` calls bypass TTL cache (`force: true`). Session transitions (login, logout, impersonation) must call `clearAllCachedFetchEntries()`.
6. **Unit Test Compatibility (`node --test`):**
   - Pure logic and state helpers tested by `tests/**/*.test.ts` files (placed under `frontend/tests/` mirroring the `src/` directory structure) are executed directly by Node's native TypeScript runner (`node --test`). Avoid TypeScript parameter properties (`constructor(public x: number)`) and path aliases (`@/`) inside files imported directly by `.test.ts` files, and use `import { type Foo }` for type-only imports.
7. **Web Terminal:**
   - The terminal WebSocket (`XtermViewer`) connects to the `websocket_path` returned by `POST /api/vms/{id}/console` and passes the single-use ticket through the subprotocols `["ognivo-terminal", "ticket.<ticket>"]`. Never put the ticket in a URL or log it.
   - Close the session with `DELETE /api/vms/{id}/console/{sessionId}` when the terminal is closed or reconnects.
8. **Documentation:**
   - When you change user-visible behavior or add UI text, update the matching page in `docs/` and both languages in `frontend/locale/` (`@locale/*`). New translation sections go under `frontend/locale/{en,bg}/<section>.ts`.

---

## 5. Verification Commands (Must Pass Before Every Commit)

Run from `frontend/`:

```bash
# 1. Regenerate Next.js route types (when routes change) & run TypeScript check
npx next typegen && npx tsc --noEmit -p . --incremental false

# 2. Run ESLint (must report 0 errors)
npm run lint

# 3. Run unit tests via the native Node test runner (all tests/**/*.test.ts files)
npm test

# 4. Verify production build
npm run build
```

CI runs lint, tests and the build. The TypeScript check in step 1 is a local check, so run it before pushing.