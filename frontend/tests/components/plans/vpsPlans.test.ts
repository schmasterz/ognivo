import { describe, it, beforeEach, afterEach } from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import vm from "node:vm";
import { fileURLToPath } from "node:url";
import React from "react";
import { renderToStaticMarkup } from "react-dom/server";
import ts from "typescript";
import * as lucideReact from "lucide-react";

import en from "../../../locale/en/index.ts";
import bg from "../../../locale/bg/index.ts";
import enVpsPlans from "../../../locale/en/vpsPlans.ts";
import bgVpsPlans from "../../../locale/bg/vpsPlans.ts";
import { interpolate } from "../../../src/lib/i18n/interpolate.ts";
import {
  clearSlotRegistry,
  getSlotComponents,
  registerSlotComponent,
  type SlotId,
} from "../../../src/lib/registry/slotRegistry.ts";
import { vpsPlansApi } from "../../../src/lib/api/vpsPlans.ts";
import { splitRowActions } from "../../../src/components/ui/rowActions/splitRowActions.ts";
import type { RowAction } from "../../../src/components/ui/rowActions/types.ts";
import type { AdminVpsPlan, VpsPlanCatalogItem } from "../../../src/lib/api/types.ts";

interface VpsPlanCardModule {
  VpsPlanCard: React.ComponentType<{ plan: VpsPlanCatalogItem }>;
  formatPlanRam: (ramMb: number) => string;
  formatNetworkLimit: (
    mbps: number | null | undefined,
    unlimitedLabel: string,
    formatMbps: (value: number) => string
  ) => string;
}

function loadVpsPlanCardModule(): VpsPlanCardModule {
  const cardPath = fileURLToPath(
    new URL("../../../src/components/plans/VpsPlanCard.tsx", import.meta.url)
  );
  const sourceText = fs.readFileSync(cardPath, "utf8");
  const transpiled = ts.transpileModule(sourceText, {
    compilerOptions: {
      module: ts.ModuleKind.CommonJS,
      target: ts.ScriptTarget.ES2020,
      jsx: ts.JsxEmit.React,
      esModuleInterop: true,
    },
  });

  const exportsObj: Record<string, unknown> = {};
  const moduleObj: { exports: unknown } = { exports: exportsObj };

  const customRequire = (specifier: string) => {
    if (specifier === "react") return React;
    if (specifier === "lucide-react") return lucideReact;
    if (specifier === "@/lib/i18n/I18nContext") {
      return {
        useI18n: () => ({
          locale: "en",
          preference: "en",
          setPreference: () => {},
          t: (key: string, params?: Record<string, string | number>) =>
            interpolate((en as Record<string, string>)[key] || key, params),
        }),
      };
    }
    if (specifier === "@/lib/registry/slotRegistry") {
      return {
        getSlotComponents,
        registerSlotComponent,
        clearSlotRegistry,
      };
    }
    throw new Error(`Unexpected import in VpsPlanCard.tsx: ${specifier}`);
  };

  const wrapper = vm.runInThisContext(
    `(function(exports, require, module) {\n${transpiled.outputText}\n})`
  ) as (
    exportsArg: Record<string, unknown>,
    requireArg: (specifier: string) => unknown,
    moduleArg: { exports: unknown }
  ) => void;

  wrapper(exportsObj, customRequire, moduleObj);
  return moduleObj.exports as VpsPlanCardModule;
}

const { VpsPlanCard, formatPlanRam, formatNetworkLimit } = loadVpsPlanCardModule();

interface AdminPlansViewModule {
  buildAdminPlanRowActions: (
    plan: AdminVpsPlan,
    t: (key: string, params?: Record<string, string | number>) => string,
    busyActionId: string | null,
    callbacks: {
      onEdit: (plan: AdminVpsPlan) => void;
      onOpenPlanChanges: (plan: AdminVpsPlan) => void;
      onPublish: (plan: AdminVpsPlan) => void;
      onPublishDraft: (plan: AdminVpsPlan) => void;
      onUnarchive: (plan: AdminVpsPlan) => void;
      onUnpublish: (plan: AdminVpsPlan) => void;
      onArchive: (plan: AdminVpsPlan) => void;
      onDiscardDraft: (plan: AdminVpsPlan) => void;
    }
  ) => RowAction[];
}

function loadAdminPlansViewModule(): AdminPlansViewModule {
  const viewPath = fileURLToPath(
    new URL("../../../src/components/plans/AdminPlansView.tsx", import.meta.url)
  );
  const sourceText = fs.readFileSync(viewPath, "utf8");
  const transpiled = ts.transpileModule(sourceText, {
    compilerOptions: {
      module: ts.ModuleKind.CommonJS,
      target: ts.ScriptTarget.ES2020,
      jsx: ts.JsxEmit.React,
      esModuleInterop: true,
    },
  });

  const exportsObj: Record<string, unknown> = {};
  const moduleObj: { exports: unknown } = { exports: exportsObj };

  const customRequire = (specifier: string) => {
    if (specifier === "react") return React;
    if (specifier === "lucide-react") return lucideReact;
    if (specifier === "@/lib/api") return { vpsPlansApi };
    if (specifier === "@/lib/api/client") {
      return {
        extractPaginationMeta: () => ({ currentPage: 1, lastPage: 1, perPage: 20, total: 0 }),
      };
    }
    if (specifier === "@/components/ui/ConfirmModal") return { ConfirmModal: () => null };
    if (specifier === "@/components/ui/PaginationBar") return { PaginationBar: () => null };
    if (specifier === "@/components/ui/rowActions/RowActions") return { RowActions: () => null };
    if (specifier === "@/components/ui/rowActions/StickyActionsCell") {
      return { StickyActionsTh: () => null, StickyActionsTd: () => null };
    }
    if (specifier === "@/components/ui/rowActions/types") return {};
    if (specifier === "@/lib/hooks/useConfirmDialog") {
      return {
        useConfirmDialog: () => ({
          confirmDialog: { isOpen: false, title: "", message: "", onConfirm: () => {} },
          requestConfirm: () => {},
          closeConfirmDialog: () => {},
        }),
      };
    }
    if (specifier === "@/lib/i18n/I18nContext") {
      return {
        useI18n: () => ({
          locale: "en",
          preference: "en",
          setPreference: () => {},
          t: (key: string, params?: Record<string, string | number>) =>
            interpolate((en as Record<string, string>)[key] || key, params),
        }),
      };
    }
    if (specifier === "@/lib/toast/ToastContext") {
      return { useToast: () => ({ toast: { success: () => {}, error: () => {}, info: () => {} } }) };
    }
    if (specifier === "./PlanChangesModal") return { PlanChangesModal: () => null };
    if (specifier === "./PlanEditorModal") {
      return {
        PlanEditorModal: () => null,
        extractPlanErrorMessage: (_e: unknown, fb: string) => fb,
        getPlanStatusBadgeStyle: () => "",
        getPlanStatusTranslationKey: () => "vpsPlanPublishedBadge",
      };
    }
    if (specifier === "./VpsPlanCard") {
      return { formatPlanRam, formatNetworkLimit };
    }
    throw new Error(`Unexpected import in AdminPlansView.tsx: ${specifier}`);
  };

  const wrapper = vm.runInThisContext(
    `(function(exports, require, module) {\n${transpiled.outputText}\n})`
  ) as (
    exportsArg: Record<string, unknown>,
    requireArg: (specifier: string) => unknown,
    moduleArg: { exports: unknown }
  ) => void;

  wrapper(exportsObj, customRequire, moduleObj);
  return moduleObj.exports as AdminPlansViewModule;
}

const { buildAdminPlanRowActions } = loadAdminPlansViewModule();

describe("VPS Plans Frontend Tests", () => {
  beforeEach(() => {
    clearSlotRegistry();
  });

  afterEach(() => {
    clearSlotRegistry();
  });

  describe("1. vps-plans i18n key parity (en & bg)", () => {
    it("keeps identical key sets and non-empty localized values between en and bg", () => {
      const enKeys = Object.keys(enVpsPlans).sort();
      const bgKeys = Object.keys(bgVpsPlans).sort();
      assert.deepStrictEqual(bgKeys, enKeys);

      const enDict = enVpsPlans as Record<string, string>;
      const bgDict = bgVpsPlans as Record<string, string>;

      for (const key of enKeys) {
        assert.ok(enDict[key].trim().length > 0, `Empty EN translation for ${key}`);
        assert.ok(bgDict[key].trim().length > 0, `Empty BG translation for ${key}`);
        assert.notEqual(enDict[key], key, `EN key ${key} must have a human label`);
        assert.notEqual(bgDict[key], key, `BG key ${key} must have a human label`);

        // Placeholder parity (:version, :count, :gb, :mbps)
        const enPlaceholders = (enDict[key].match(/:[a-zA-Z0-9_]+/g) ?? []).sort();
        const bgPlaceholders = (bgDict[key].match(/:[a-zA-Z0-9_]+/g) ?? []).sort();
        assert.deepStrictEqual(
          bgPlaceholders,
          enPlaceholders,
          `Placeholder mismatch for key "${key}"`
        );
      }

      // Also verify merged dictionaries expose the keys
      assert.equal((en as Record<string, string>).menuVpsPlans, enDict.menuVpsPlans);
      assert.equal((bg as Record<string, string>).menuVpsPlans, bgDict.menuVpsPlans);
    });
  });

  describe("2. VpsPlanCard rendering & formatting", () => {
    it("formats RAM in GiB and MiB and formats network limits with Unlimited fallback", () => {
      assert.equal(formatPlanRam(512), "0.5 GiB (512 MiB)");
      assert.equal(formatPlanRam(2048), "2 GiB (2048 MiB)");
      assert.equal(formatPlanRam(1536), "1.5 GiB (1536 MiB)");

      assert.equal(
        formatNetworkLimit(null, "Unlimited", (v) => `${v} Mbps`),
        "Unlimited"
      );
      assert.equal(
        formatNetworkLimit(1000, "Unlimited", (v) => `${v} Mbps`),
        "1000 Mbps"
      );
    });

    it("renders plan name, CPU, RAM, disk, instance_type, network limits/Unlimited, description, and version", () => {
      const limitedPlan: VpsPlanCatalogItem = {
        id: 10,
        name: "Compute Pro",
        slug: "compute-pro",
        description: "Balanced compute tier for web workloads.",
        sort_order: 1,
        current_version: {
          id: 101,
          version: 3,
          cpu_cores: 4,
          ram_mb: 4096,
          disk_gb: 80,
          instance_type: "virtual-machine",
          net_download_mbps: 1000,
          net_upload_mbps: null,
        },
      };

      const html = renderToStaticMarkup(
        React.createElement(VpsPlanCard, { plan: limitedPlan })
      );

      assert.match(html, /Compute Pro/);
      assert.match(html, /compute-pro/);
      assert.match(html, /Balanced compute tier for web workloads\./);
      assert.match(html, /v3/);
      assert.match(html, /Virtual Machine/);
      assert.match(html, /4 vCPU/);
      assert.match(html, /4 GiB \(4096 MiB\)/);
      assert.match(html, /80 GiB/);
      assert.match(html, /1000 Mbps/);
      assert.match(html, /Unlimited/);
    });

    it("renders container badge when instance_type is container", () => {
      const containerPlan: VpsPlanCatalogItem = {
        id: 11,
        name: "Micro LXC",
        slug: "micro-lxc",
        description: null,
        sort_order: 2,
        current_version: {
          id: 102,
          version: 1,
          cpu_cores: 1,
          ram_mb: 512,
          disk_gb: 10,
          instance_type: "container",
          net_download_mbps: null,
          net_upload_mbps: null,
        },
      };

      const html = renderToStaticMarkup(
        React.createElement(VpsPlanCard, { plan: containerPlan })
      );

      assert.match(html, /Micro LXC/);
      assert.match(html, /Container/);
      assert.match(html, /0\.5 GiB \(512 MiB\)/);
      assert.match(html, /10 GiB/);
      assert.match(html, /Unlimited/);
    });
  });

  describe("3. vps.plan.card experimental slot rendering", () => {
    it("renders components registered in vps.plan.card in priority order with { plan }", () => {
      const slotId: SlotId = "vps.plan.card";

      registerSlotComponent<{ plan: VpsPlanCatalogItem }>(slotId, {
        id: "second-badge",
        priority: 20,
        component: ({ plan }) =>
          React.createElement("span", { "data-slot": "second" }, `Order:${plan.slug}`),
      });

      registerSlotComponent<{ plan: VpsPlanCatalogItem }>(slotId, {
        id: "first-badge",
        priority: 10,
        component: ({ plan }) =>
          React.createElement(
            "span",
            { "data-slot": "first" },
            `Select:${plan.name}:v${plan.current_version?.version}`
          ),
      });

      const plan: VpsPlanCatalogItem = {
        id: 22,
        name: "Starter",
        slug: "starter",
        description: "Starter plan",
        sort_order: 0,
        current_version: {
          id: 201,
          version: 2,
          cpu_cores: 2,
          ram_mb: 2048,
          disk_gb: 25,
          instance_type: "virtual-machine",
          net_download_mbps: 500,
          net_upload_mbps: 250,
        },
      };

      const html = renderToStaticMarkup(React.createElement(VpsPlanCard, { plan }));
      const firstIndex = html.indexOf("Select:Starter:v2");
      const secondIndex = html.indexOf("Order:starter");

      assert.ok(firstIndex !== -1, "First slot component should render with plan prop");
      assert.ok(secondIndex !== -1, "Second slot component should render with plan prop");
      assert.ok(firstIndex < secondIndex, "Slot components must render sorted by priority");
    });

    it("omits slot wrapper when no components are registered in vps.plan.card", () => {
      const plan: VpsPlanCatalogItem = {
        id: 23,
        name: "Bare",
        slug: "bare",
        description: null,
        sort_order: 0,
        current_version: {
          id: 202,
          version: 1,
          cpu_cores: 1,
          ram_mb: 1024,
          disk_gb: 15,
          instance_type: "virtual-machine",
          net_download_mbps: null,
          net_upload_mbps: null,
        },
      };

      const html = renderToStaticMarkup(React.createElement(VpsPlanCard, { plan }));
      assert.equal(html.includes('data-testid="vps-plan-card-slot"'), false);
    });
  });

  describe("4. vpsPlansApi client methods", () => {
    it("unwraps { data } responses and hits expected customer & admin endpoints", async () => {
      const originalFetch = globalThis.fetch;
      const recorded: Array<{ url: string; method: string }> = [];

      globalThis.fetch = (async (input: string | URL | Request, init?: RequestInit) => {
        const url = typeof input === "string" ? input : input.toString();
        const method = (init?.method ?? "GET").toUpperCase();
        recorded.push({ url, method });

        if (url.endsWith("/sanctum/csrf-cookie")) {
          return new Response(null, { status: 204 });
        }

        if (url.endsWith("/vps/plans")) {
          return new Response(JSON.stringify({ data: [{ id: 1, name: "P1", slug: "p1" }] }), {
            status: 200,
            headers: { "Content-Type": "application/json" },
          });
        }

        if (url.endsWith("/vps/plans/p1")) {
          return new Response(JSON.stringify({ data: { id: 1, name: "P1", slug: "p1" } }), {
            status: 200,
            headers: { "Content-Type": "application/json" },
          });
        }

        if (url.endsWith("/admin/vps/plans/1/usage")) {
          return new Response(
            JSON.stringify({
              data: {
                vps_plan_id: 1,
                total_active_vms: 4,
                versions: [{ vps_plan_version_id: 9, version: 1, status: "published", active_vms_count: 4 }],
              },
            }),
            { status: 200, headers: { "Content-Type": "application/json" } }
          );
        }

        return new Response(JSON.stringify({ data: { ok: true } }), {
          status: 200,
          headers: { "Content-Type": "application/json" },
        });
      }) as typeof fetch;

      try {
        const catalog = await vpsPlansApi.listCatalog();
        assert.equal(catalog.length, 1);
        assert.equal(catalog[0].slug, "p1");

        const single = await vpsPlansApi.getCatalogPlan("p1");
        assert.equal(single.id, 1);

        const usage = await vpsPlansApi.getPlanUsage(1);
        assert.equal(usage.total_active_vms, 4);
        assert.equal(usage.versions[0].active_vms_count, 4);

        assert.ok(recorded.some((r) => r.method === "GET" && r.url.endsWith("/vps/plans")));
        assert.ok(recorded.some((r) => r.method === "GET" && r.url.endsWith("/vps/plans/p1")));
        assert.ok(
          recorded.some((r) => r.method === "GET" && r.url.endsWith("/admin/vps/plans/1/usage"))
        );
      } finally {
        globalThis.fetch = originalFetch;
      }
    });
  });

  describe("5. AdminPlansView rowActions & Plan Changes trigger", () => {
    it("places Edit and Plan Changes inline and splits lifecycle/critical actions into the overflow menu", () => {
      const tEn = (key: string, params?: Record<string, string | number>) =>
        interpolate((en as Record<string, string>)[key] || key, params);

      const openedIds: number[] = [];
      const noop = () => {};

      const publishedWithDraft: AdminVpsPlan = {
        id: 7,
        name: "Business 4vCPU",
        slug: "business-4vcpu",
        description: null,
        status: "published",
        sort_order: 2,
        current_version_id: 70,
        current_version: {
          id: 70,
          vps_plan_id: 7,
          version: 1,
          status: "published",
          cpu_cores: 4,
          ram_mb: 8192,
          disk_gb: 80,
          instance_type: "virtual-machine",
          net_download_mbps: 1000,
          net_upload_mbps: 1000,
          change_summary: null,
          created_by: 1,
          published_by: 1,
          created_at: "2026-01-01T00:00:00Z",
          published_at: "2026-01-01T00:00:00Z",
        },
        draft_version: {
          id: 71,
          vps_plan_id: 7,
          version: null,
          status: "draft",
          cpu_cores: 4,
          ram_mb: 16384,
          disk_gb: 100,
          instance_type: "virtual-machine",
          net_download_mbps: 2000,
          net_upload_mbps: 2000,
          change_summary: "RAM upgrade",
          created_by: 1,
          published_by: null,
          created_at: "2026-01-02T00:00:00Z",
          published_at: null,
        },
        created_by: 1,
        updated_by: 1,
        created_at: "2026-01-01T00:00:00Z",
        updated_at: "2026-01-02T00:00:00Z",
      };

      const actions = buildAdminPlanRowActions(publishedWithDraft, tEn, null, {
        onEdit: noop,
        onOpenPlanChanges: (p) => {
          openedIds.push(p.id);
        },
        onPublish: noop,
        onPublishDraft: noop,
        onUnarchive: noop,
        onUnpublish: noop,
        onArchive: noop,
        onDiscardDraft: noop,
      });

      const split = splitRowActions(actions, 2);
      assert.deepStrictEqual(
        split.inline.map((a) => a.id),
        ["edit", "planChanges"]
      );
      assert.deepStrictEqual(
        split.menu.map((a) => a.id),
        ["publishDraft", "unpublish", "archive", "discardDraft"]
      );
      assert.equal(split.dividerBeforeId, "discardDraft");

      const planChangesAction = split.inline.find((a) => a.id === "planChanges");
      assert.ok(planChangesAction);
      assert.equal(planChangesAction.label, "Plan Changes");
      planChangesAction.onSelect();
      assert.deepStrictEqual(openedIds, [7]);
    });
  });
});
