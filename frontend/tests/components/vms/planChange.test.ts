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
import { ApiError } from "../../../src/lib/api/client.ts";
import { vmsApi } from "../../../src/lib/api/vms.ts";
import { vpsPlansApi } from "../../../src/lib/api/vpsPlans.ts";
import {
  clearSlotRegistry,
  getSlotComponents,
  registerSlotComponent,
  type SlotId,
} from "../../../src/lib/registry/slotRegistry.ts";
import type {
  AdminVpsPlan,
  AdminVpsPlanChange,
  VirtualMachine,
  VpsPlanChange,
  VpsPlanChangeBlockingReason,
  VpsPlanChangePreview,
} from "../../../src/lib/api/types.ts";

const SPEC_9_2_CODES = [
  "VM_STATE_NOT_SUPPORTED",
  "ANOTHER_CHANGE_IN_PROGRESS",
  "PLAN_NOT_AVAILABLE",
  "VERSION_NOT_AVAILABLE",
  "NO_CHANGE",
  "INSTANCE_TYPE_MISMATCH",
  "DISK_SHRINK_NOT_SUPPORTED",
  "QUOTA_EXCEEDED",
  "HOST_UNREACHABLE",
  "HOST_CAPACITY_EXCEEDED",
  "NETWORK_LIMIT_NOT_SUPPORTED_MODE",
  "REQUIRES_SHUTDOWN_NOT_SUPPORTED",
  "NETWORK_LIMIT_NO_NIC",
  "DRIVER_OPERATION_UNSUPPORTED",
  "EXTENSION_AUTHORIZATION_DENIED",
  "EXTENSION_AUTHORIZATION_UNAVAILABLE",
  "RESOURCE_OVERRIDE_NOT_ALLOWED",
  "PLAN_CHANGE_BLOCKED",
  "PLAN_CHANGE_CANNOT_BE_CANCELLED",
  "VM_NOT_FOUND",
  "AUTHORIZATION_TIMEOUT",
  "APPLY_JOB_FAILED",
  "APPLY_TIMEOUT",
  "EMPTY_DRIVER_RESULT",
  "DRIVER_UNAVAILABLE",
  "HYPERVISOR_EXCEPTION",
  "HYPERVISOR_OPERATION_FAILED",
  "HYPERVISOR_ERROR",
  "RESOURCE_UPDATE_REJECTED",
  "RESOURCE_UPDATE_FAILED",
  "CONCURRENT_MODIFICATION",
  "PROJECT_LIMIT_EXCEEDED",
  "INVALID_RESOURCE_SPEC",
  "INSTANCE_FETCH_FAILED",
  "CONNECTION_ERROR",
  "INVALID_OPERATION_ID",
  "OPERATION_QUERY_ERROR",
  "OPERATION_NOT_FOUND",
  "PARTIAL_APPLY",
  "STORAGE_QUOTA_UNSUPPORTED",
  "NIC_TYPE_UNSUPPORTED",
  "DISK_SHRINK",
  "VERIFY_HOST_UNREACHABLE",
  "VERIFY_RESOURCE_MISMATCH",
  "RECONCILE_RESOURCE_MISMATCH",
  "NOT_APPLIED",
  "RECONCILED_FAILED",
] as const;

function transpileAndLoad(
  relativePath: string,
  customRequire: (specifier: string) => unknown
): Record<string, unknown> {
  const fullPath = fileURLToPath(new URL(relativePath, import.meta.url));
  const sourceText = fs.readFileSync(fullPath, "utf8");
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

  const wrapper = vm.runInThisContext(
    `(function(exports, require, module) {\n${transpiled.outputText}\n})`
  ) as (
    exportsArg: Record<string, unknown>,
    requireArg: (specifier: string) => unknown,
    moduleArg: { exports: unknown }
  ) => void;

  wrapper(exportsObj, customRequire, moduleObj);
  return moduleObj.exports as Record<string, unknown>;
}

const formatPlanRamStub = (ramMb: number) => {
  const gib = ramMb / 1024;
  const gibText = Number.isInteger(gib)
    ? String(gib)
    : Number(gib.toFixed(2)).toString();
  return `${gibText} GiB (${ramMb} MiB)`;
};

const formatNetworkLimitStub = (
  mbps: number | null | undefined,
  unlimitedLabel: string,
  formatMbps: (value: number) => string
) => (mbps === null || mbps === undefined ? unlimitedLabel : formatMbps(mbps));

const i18nEnStub = {
  useI18n: () => ({
    locale: "en",
    preference: "en",
    setPreference: () => {},
    t: (key: string, params?: Record<string, string | number>) =>
      interpolate((en as Record<string, string>)[key] || key, params),
  }),
};

const toastStub = {
  useToast: () => ({
    toast: {
      success: () => {},
      error: () => {},
      info: () => {},
      warning: () => {},
    },
  }),
};

interface ChangePlanModalModule {
  ChangePlanModal: React.ComponentType<{
    isOpen: boolean;
    onClose: () => void;
    vm: VirtualMachine;
    initialPlanChange?: VpsPlanChange | null;
    onSuccess?: (planChange: VpsPlanChange) => void;
    onVmUpdated?: (vm: VirtualMachine) => void;
  }>;
  PLAN_CHANGE_CODE_KEYS: Record<string, string>;
  translatePlanChangeErrorCode: (
    code: string | null | undefined,
    t: (key: string, params?: Record<string, string | number>) => string,
    kind?: string | null
  ) => string;
  translatePlanChangeBlockingReason: (
    reason: VpsPlanChangeBlockingReason,
    t: (key: string, params?: Record<string, string | number>) => string
  ) => string;
  translatePlanChangeWarning: (
    code: string,
    t: (key: string, params?: Record<string, string | number>) => string
  ) => string;
  translatePlanChangeStatus: (
    status: string,
    t: (key: string, params?: Record<string, string | number>) => string
  ) => string;
  translatePlanChangeStage: (
    stage: string | null | undefined,
    t: (key: string, params?: Record<string, string | number>) => string
  ) => string;
  translatePlanChangeDirection: (
    direction: string | undefined,
    t: (key: string, params?: Record<string, string | number>) => string
  ) => string;
  isPlanChangePollingActive: (status: string | undefined) => boolean;
  isPlanChangeCancellableStatus: (status: string | undefined) => boolean;
  isPlanChangeActiveStatus: (status: string | undefined) => boolean;
  generatePlanChangeIdempotencyKey: () => string;
  extractPlanChangeApiErrorMessage: (
    err: unknown,
    t: (key: string, params?: Record<string, string | number>) => string,
    fallback: string
  ) => string;
}

function loadChangePlanModalModule(): ChangePlanModalModule {
  return transpileAndLoad("../../../src/components/vms/ChangePlanModal.tsx", (specifier) => {
    if (specifier === "react") return React;
    if (specifier === "lucide-react") return lucideReact;
    if (specifier === "@/lib/api") return { ApiError, vmsApi };
    if (specifier === "@/lib/i18n/I18nContext") return i18nEnStub;
    if (specifier === "@/lib/toast/ToastContext") return toastStub;
    if (specifier === "@/lib/hooks/useModalEscape") return { useModalEscape: () => {} };
    if (specifier === "@/lib/registry/slotRegistry") return { getSlotComponents };
    if (specifier === "@/components/plans/VpsPlanCard") {
      return {
        formatPlanRam: formatPlanRamStub,
        formatNetworkLimit: formatNetworkLimitStub,
      };
    }
    throw new Error(`Unexpected import in ChangePlanModal.tsx: ${specifier}`);
  }) as unknown as ChangePlanModalModule;
}

const changePlanModalMod = loadChangePlanModalModule();

interface VmPlanTabModule {
  VmPlanTab: React.ComponentType<{
    vm: VirtualMachine;
    user: null;
    isAdmin: boolean;
    onVmUpdated?: (vm: VirtualMachine) => void;
  }>;
}

function loadVmPlanTabModule(): VmPlanTabModule {
  return transpileAndLoad("../../../src/components/vms/VmPlanTab.tsx", (specifier) => {
    if (specifier === "react") return React;
    if (specifier === "lucide-react") return lucideReact;
    if (specifier === "@/lib/api") return { vmsApi };
    if (specifier === "@/lib/api/client") {
      return {
        extractPaginationMeta: () => ({
          currentPage: 1,
          lastPage: 1,
          perPage: 10,
          total: 0,
        }),
      };
    }
    if (specifier === "@/lib/i18n/I18nContext") return i18nEnStub;
    if (specifier === "@/lib/toast/ToastContext") return toastStub;
    if (specifier === "@/components/ui/ConfirmModal") {
      return { ConfirmModal: () => null };
    }
    if (specifier === "@/components/ui/PaginationBar") {
      return { PaginationBar: () => null };
    }
    if (specifier === "@/components/plans/VpsPlanCard") {
      return {
        formatPlanRam: formatPlanRamStub,
        formatNetworkLimit: formatNetworkLimitStub,
      };
    }
    if (specifier === "./ChangePlanModal") return changePlanModalMod;
    throw new Error(`Unexpected import in VmPlanTab.tsx: ${specifier}`);
  }) as unknown as VmPlanTabModule;
}

const { VmPlanTab } = loadVmPlanTabModule();

interface PlanChangesModalModule {
  PlanChangesModal: React.ComponentType<{ plan: AdminVpsPlan; onClose: () => void }>;
  parsePositiveIntFilter: (value: string) => number | null;
  resolvePlanChangeDirection: (
    change: Pick<AdminVpsPlanChange, "from_version" | "to_version">,
    planId: number
  ) => "incoming" | "outgoing" | "internal";
}

function loadPlanChangesModalModule(): PlanChangesModalModule {
  return transpileAndLoad(
    "../../../src/components/plans/PlanChangesModal.tsx",
    (specifier) => {
      if (specifier === "react") return React;
      if (specifier === "lucide-react") return lucideReact;
      if (specifier === "@/lib/api") return { vpsPlansApi };
      if (specifier === "@/lib/api/client") {
        return {
          extractPaginationMeta: () => ({
            currentPage: 1,
            lastPage: 1,
            perPage: 20,
            total: 0,
          }),
        };
      }
      if (specifier === "@/components/ui/PaginationBar") {
        return { PaginationBar: () => null };
      }
      if (specifier === "@/components/ui/rowActions/RowActions") {
        return { RowActions: () => null };
      }
      if (specifier === "@/components/ui/rowActions/StickyActionsCell") {
        return { StickyActionsTh: () => null, StickyActionsTd: () => null };
      }
      if (specifier === "@/components/ui/rowActions/types") return {};
      if (specifier === "@/lib/i18n/I18nContext") return i18nEnStub;
      if (specifier === "@/lib/toast/ToastContext") return toastStub;
      if (specifier === "@/lib/hooks/useModalEscape") return { useModalEscape: () => {} };
      if (specifier === "@/components/vms/ChangePlanModal") return changePlanModalMod;
      if (specifier === "./VpsPlanCard") {
        return {
          formatPlanRam: formatPlanRamStub,
          formatNetworkLimit: formatNetworkLimitStub,
        };
      }
      throw new Error(`Unexpected import in PlanChangesModal.tsx: ${specifier}`);
    }
  ) as unknown as PlanChangesModalModule;
}

const { PlanChangesModal, parsePositiveIntFilter, resolvePlanChangeDirection } =
  loadPlanChangesModalModule();

const sampleVm: VirtualMachine = {
  id: 42,
  user_id: 7,
  host_id: 3,
  name: "app-node-01",
  incus_project: "tenant-7",
  instance_type: "virtual-machine",
  status: "running",
  cpu_cores: 2,
  ram_mb: 2048,
  disk_gb: 25,
  vps_plan: {
    id: 1,
    name: "Starter",
    slug: "starter",
    version_id: 10,
    version: 2,
    is_current_version: true,
  },
  network_limits: {
    download_mbps: 500,
    upload_mbps: 250,
    applicable: true,
  },
  os_image: "ubuntu/24.04",
  ipv4_address: "10.0.0.15",
  ipv6_address: null,
  created_at: "2026-01-01T00:00:00Z",
  updated_at: "2026-01-01T00:00:00Z",
};

describe("VPS Plan Change Frontend Tests", () => {
  beforeEach(() => {
    clearSlotRegistry();
  });

  afterEach(() => {
    clearSlotRegistry();
  });

  describe("1. vpsPlans i18n parity & Spec 9.2 codes", () => {
    it("has 100% key and placeholder parity between en and bg for all plan-change strings", () => {
      const enKeys = Object.keys(enVpsPlans).sort();
      const bgKeys = Object.keys(bgVpsPlans).sort();
      assert.deepStrictEqual(bgKeys, enKeys);

      const enDict = enVpsPlans as Record<string, string>;
      const bgDict = bgVpsPlans as Record<string, string>;

      for (const key of enKeys) {
        assert.ok(enDict[key].trim().length > 0, `Empty EN string for ${key}`);
        assert.ok(bgDict[key].trim().length > 0, `Empty BG string for ${key}`);
        assert.notEqual(enDict[key], key);
        assert.notEqual(bgDict[key], key);

        const enPlaceholders = (enDict[key].match(/:[a-zA-Z0-9_]+/g) ?? []).sort();
        const bgPlaceholders = (bgDict[key].match(/:[a-zA-Z0-9_]+/g) ?? []).sort();
        assert.deepStrictEqual(bgPlaceholders, enPlaceholders, `Placeholder mismatch on ${key}`);
      }

      assert.equal(
        (en as Record<string, string>).vpsPlanViewChanges,
        enDict.vpsPlanViewChanges
      );
      assert.equal(
        (bg as Record<string, string>).vpsPlanViewChanges,
        bgDict.vpsPlanViewChanges
      );
    });

    it("translates every Spec 9.2 blocking/error code without exposing raw codes", () => {
      const enDict = enVpsPlans as Record<string, string>;
      const bgDict = bgVpsPlans as Record<string, string>;
      const tEn = (key: string, params?: Record<string, string | number>) =>
        interpolate(enDict[key] || key, params);
      const tBg = (key: string, params?: Record<string, string | number>) =>
        interpolate(bgDict[key] || key, params);

      for (const code of SPEC_9_2_CODES) {
        const key = changePlanModalMod.PLAN_CHANGE_CODE_KEYS[code];
        assert.ok(key, `Missing PLAN_CHANGE_CODE_KEYS mapping for ${code}`);
        assert.ok(enDict[key], `Missing EN translation for ${code} (${key})`);
        assert.ok(bgDict[key], `Missing BG translation for ${code} (${key})`);

        const translatedEn = changePlanModalMod.translatePlanChangeErrorCode(code, tEn);
        const translatedBg = changePlanModalMod.translatePlanChangeErrorCode(code, tBg);
        assert.equal(translatedEn.includes(code), false, `EN exposes raw code ${code}`);
        assert.equal(translatedBg.includes(code), false, `BG exposes raw code ${code}`);
      }

      // Unknown codes fall back gracefully without exposing the raw code
      const unknownEn = changePlanModalMod.translatePlanChangeErrorCode(
        "SOME_UNEXPECTED_INTERNAL_CODE",
        tEn
      );
      assert.equal(unknownEn, enDict.vpsPlanChangeCodeFallback);
      assert.equal(unknownEn.includes("SOME_UNEXPECTED_INTERNAL_CODE"), false);

      // Unknown warning code falls back gracefully without exposing raw code
      const warnKnown = changePlanModalMod.translatePlanChangeWarning(
        "DISK_GUEST_RESIZE_MAY_BE_REQUIRED",
        tEn
      );
      assert.equal(warnKnown, enDict.vpsPlanChangeWarningDiskGuestResize);
      assert.equal(warnKnown.includes("DISK_GUEST_RESIZE_MAY_BE_REQUIRED"), false);

      const warnUnknown = changePlanModalMod.translatePlanChangeWarning(
        "INTERNAL_WARN_CODE",
        tEn
      );
      assert.equal(warnUnknown, enDict.vpsPlanChangeWarningGeneric);
      assert.equal(warnUnknown.includes("INTERNAL_WARN_CODE"), false);
    });

    it("translates all 7 statuses, stages, and 5 resource comparison directions", () => {
      const tEn = (key: string, params?: Record<string, string | number>) =>
        interpolate((enVpsPlans as Record<string, string>)[key] || key, params);

      const statuses = [
        "pending",
        "awaiting_authorization",
        "applying",
        "completed",
        "failed",
        "cancelled",
        "reconciliation_required",
      ];
      for (const s of statuses) {
        const label = changePlanModalMod.translatePlanChangeStatus(s, tEn);
        assert.notEqual(label, s);
        assert.ok(label.length > 0);
      }

      const stages = ["authorization", "validating", "applying", "verifying", "committing"];
      for (const st of stages) {
        const label = changePlanModalMod.translatePlanChangeStage(st, tEn);
        assert.ok(label.length > 0);
      }

      const dirs = ["unchanged", "increase", "decrease", "set", "remove"];
      for (const d of dirs) {
        const label = changePlanModalMod.translatePlanChangeDirection(d, tEn);
        assert.ok(label.length > 0);
      }
    });
  });

  describe("2. vmsApi & vpsPlansApi plan-change methods + Idempotency-Key", () => {
    it("calls expected VM plan-change endpoints and sends Idempotency-Key header on requestPlanChange", async () => {
      const originalFetch = globalThis.fetch;
      const calls: Array<{
        url: string;
        method: string;
        headers: Record<string, string>;
        body: string | null;
      }> = [];

      globalThis.fetch = (async (input: string | URL | Request, init?: RequestInit) => {
        const url = typeof input === "string" ? input : input.toString();
        const method = (init?.method ?? "GET").toUpperCase();
        const headersObj: Record<string, string> = {};
        new Headers(init?.headers).forEach((v, k) => {
          headersObj[k.toLowerCase()] = v;
        });

        calls.push({
          url,
          method,
          headers: headersObj,
          body: typeof init?.body === "string" ? init.body : null,
        });

        if (url.endsWith("/sanctum/csrf-cookie")) {
          return new Response(null, { status: 204 });
        }

        if (url.endsWith("/vms/42/plan-change/targets")) {
          return new Response(
            JSON.stringify({ data: [{ id: 2, name: "Pro", slug: "pro", selectable: true }] }),
            { status: 200, headers: { "Content-Type": "application/json" } }
          );
        }

        if (url.endsWith("/vms/42/plan-change/preview")) {
          return new Response(
            JSON.stringify({
              data: {
                can_submit: true,
                requires_shutdown: false,
                changes: { cpu: "increase", ram: "increase", disk: "increase", net_download: "unchanged", net_upload: "unchanged" },
                warnings: ["DISK_GUEST_RESIZE_MAY_BE_REQUIRED"],
                blocking_reasons: [],
              },
            }),
            { status: 200, headers: { "Content-Type": "application/json" } }
          );
        }

        if (url.endsWith("/vms/42/plan-change")) {
          return new Response(
            JSON.stringify({
              data: { public_id: "01jtestulid", virtual_machine_id: 42, status: "pending" },
            }),
            { status: 202, headers: { "Content-Type": "application/json" } }
          );
        }

        if (url.includes("/vms/42/plan-changes/01jtestulid/cancel")) {
          return new Response(
            JSON.stringify({
              data: { public_id: "01jtestulid", virtual_machine_id: 42, status: "cancelled" },
            }),
            { status: 200, headers: { "Content-Type": "application/json" } }
          );
        }

        if (url.endsWith("/vms/42/plan-changes/01jtestulid")) {
          return new Response(
            JSON.stringify({
              data: { public_id: "01jtestulid", virtual_machine_id: 42, status: "applying" },
            }),
            { status: 200, headers: { "Content-Type": "application/json" } }
          );
        }

        if (url.includes("/vms/42/plan-changes")) {
          return new Response(
            JSON.stringify({
              data: [{ public_id: "01jtestulid", virtual_machine_id: 42, status: "pending" }],
              meta: { current_page: 1, last_page: 1, per_page: 15, total: 1 },
            }),
            { status: 200, headers: { "Content-Type": "application/json" } }
          );
        }

        if (url.includes("/admin/vps/plan-changes/01jtestulid/resolve")) {
          return new Response(
            JSON.stringify({
              data: { id: 9, public_id: "01jtestulid", status: "completed" },
            }),
            { status: 200, headers: { "Content-Type": "application/json" } }
          );
        }

        if (url.endsWith("/admin/vps/plan-changes/01jtestulid")) {
          return new Response(
            JSON.stringify({
              data: { id: 9, public_id: "01jtestulid", status: "reconciliation_required" },
            }),
            { status: 200, headers: { "Content-Type": "application/json" } }
          );
        }

        if (url.includes("/admin/vps/plan-changes")) {
          return new Response(
            JSON.stringify({
              data: [{ id: 9, public_id: "01jtestulid", status: "reconciliation_required" }],
              meta: { current_page: 1, last_page: 1, per_page: 20, total: 1 },
            }),
            { status: 200, headers: { "Content-Type": "application/json" } }
          );
        }

        return new Response(JSON.stringify({ data: {} }), {
          status: 200,
          headers: { "Content-Type": "application/json" },
        });
      }) as typeof fetch;

      try {
        const targets = await vmsApi.getPlanChangeTargets(42);
        assert.equal(targets.length, 1);
        assert.equal(targets[0].slug, "pro");

        const preview = await vmsApi.previewPlanChange(42, { vps_plan_id: 2 });
        assert.equal(preview.can_submit, true);

        const idemKey = "550e8400-e29b-41d4-a716-446655440000";
        const created = await vmsApi.requestPlanChange(
          42,
          { vps_plan_id: 2 },
          { idempotencyKey: idemKey }
        );
        assert.equal(created.public_id, "01jtestulid");
        assert.equal(created.status, "pending");

        const reqCall = calls.find(
          (c) => c.method === "POST" && c.url.endsWith("/vms/42/plan-change")
        );
        assert.ok(reqCall, "POST /vms/42/plan-change should be called");
        assert.equal(reqCall.headers["idempotency-key"], idemKey);

        const history = await vmsApi.getPlanChanges(42, { page: 1, per_page: 10 });
        assert.equal(history.data.length, 1);

        const detail = await vmsApi.getPlanChange(42, "01jtestulid");
        assert.equal(detail.status, "applying");

        const cancelled = await vmsApi.cancelPlanChange(42, "01jtestulid");
        assert.equal(cancelled.status, "cancelled");

        const adminList = await vpsPlansApi.listAdminPlanChanges({
          plan_id: 5,
          status: "reconciliation_required",
          user_id: 7,
          vm_id: 42,
        });
        assert.equal(adminList.data.length, 1);
        assert.ok(
          calls.some(
            (c) =>
              c.method === "GET" &&
              c.url.includes("/admin/vps/plan-changes?") &&
              c.url.includes("plan_id=5") &&
              c.url.includes("status=reconciliation_required") &&
              c.url.includes("user_id=7") &&
              c.url.includes("vm_id=42")
          )
        );

        const adminDetail = await vpsPlansApi.getAdminPlanChange("01jtestulid");
        assert.equal(adminDetail.status, "reconciliation_required");

        const resolved = await vpsPlansApi.resolveAdminPlanChange("01jtestulid", {
          action: "complete",
          note: "Verified on host",
        });
        assert.equal(resolved.status, "completed");
      } finally {
        globalThis.fetch = originalFetch;
      }
    });
  });

  describe("3. vps.plan.change.preview slot registration & rendering", () => {
    it("registers components in vps.plan.change.preview sorted by priority", () => {
      const slotId: SlotId = "vps.plan.change.preview";

      registerSlotComponent<{ vm: VirtualMachine; preview: VpsPlanChangePreview }>(
        slotId,
        {
          id: "billing-note-low",
          priority: 50,
          component: ({ vm, preview }) =>
            React.createElement(
              "div",
              { "data-slot": "billing-low" },
              `Prorated:${vm.id}:${preview.to?.plan_slug}`
            ),
        }
      );

      registerSlotComponent<{ vm: VirtualMachine; preview: VpsPlanChangePreview }>(
        slotId,
        {
          id: "billing-note-high",
          priority: 10,
          component: ({ vm, preview }) =>
            React.createElement(
              "div",
              { "data-slot": "billing-high" },
              `Estimate:${vm.name}:${preview.changes.cpu}`
            ),
        }
      );

      const items = getSlotComponents<{
        vm: VirtualMachine;
        preview: VpsPlanChangePreview;
      }>("vps.plan.change.preview");
      assert.deepStrictEqual(
        items.map((item) => item.id),
        ["billing-note-high", "billing-note-low"]
      );
    });
  });

  describe("4. ChangePlanModal rendering & status helpers", () => {
    it("identifies active, polling, and cancellable statuses accurately", () => {
      assert.equal(changePlanModalMod.isPlanChangePollingActive("pending"), true);
      assert.equal(
        changePlanModalMod.isPlanChangePollingActive("awaiting_authorization"),
        true
      );
      assert.equal(changePlanModalMod.isPlanChangePollingActive("applying"), true);
      assert.equal(
        changePlanModalMod.isPlanChangePollingActive("reconciliation_required"),
        false
      );
      assert.equal(changePlanModalMod.isPlanChangePollingActive("completed"), false);
      assert.equal(changePlanModalMod.isPlanChangePollingActive("failed"), false);
      assert.equal(changePlanModalMod.isPlanChangePollingActive("cancelled"), false);

      assert.equal(changePlanModalMod.isPlanChangeCancellableStatus("pending"), true);
      assert.equal(
        changePlanModalMod.isPlanChangeCancellableStatus("awaiting_authorization"),
        true
      );
      assert.equal(changePlanModalMod.isPlanChangeCancellableStatus("applying"), false);

      assert.equal(
        changePlanModalMod.isPlanChangeActiveStatus("reconciliation_required"),
        true
      );
      assert.equal(changePlanModalMod.isPlanChangeActiveStatus("completed"), false);

      const uuid = changePlanModalMod.generatePlanChangeIdempotencyKey();
      assert.ok(uuid.length >= 16);
    });

    it("renders Step 4 failed status with sanitized error_message and hides raw error_code", () => {
      const failedChange: VpsPlanChange = {
        public_id: "01jfailtest0000000000000000",
        virtual_machine_id: 42,
        status: "failed",
        stage: "verifying",
        from_version_id: 10,
        from: {
          vps_plan_id: 1,
          vps_plan_version_id: 10,
          plan_name: "Starter",
          plan_slug: "starter",
          version: 2,
        },
        to_version_id: 11,
        to: {
          vps_plan_id: 2,
          vps_plan_version_id: 11,
          plan_name: "Pro",
          plan_slug: "pro",
          version: 1,
        },
        old_resources: {
          cpu_cores: 2,
          ram_mb: 2048,
          disk_gb: 25,
          net_download_mbps: 500,
          net_upload_mbps: 250,
        },
        new_resources: {
          cpu_cores: 4,
          ram_mb: 4096,
          disk_gb: 50,
          net_download_mbps: 1000,
          net_upload_mbps: 500,
        },
        error_code: "VERIFY_HOST_UNREACHABLE",
        error_message: "Unable to verify resources on compute host [redacted].",
        requested_at: "2026-01-01T10:00:00Z",
        started_at: "2026-01-01T10:00:02Z",
        completed_at: null,
        failed_at: "2026-01-01T10:00:10Z",
        created_at: "2026-01-01T10:00:00Z",
      };

      const html = renderToStaticMarkup(
        React.createElement(changePlanModalMod.ChangePlanModal, {
          isOpen: true,
          onClose: () => {},
          vm: sampleVm,
          initialPlanChange: failedChange,
        })
      );

      assert.match(html, /Failed/);
      assert.match(html, /Verifying/);
      assert.match(html, /Unable to verify resources on compute host \[redacted\]\./);
      assert.equal(html.includes("VERIFY_HOST_UNREACHABLE"), false);
    });

    it("renders Step 4 reconciliation_required with clear contact support notice", () => {
      const reconChange: VpsPlanChange = {
        public_id: "01jrecon000000000000000000",
        virtual_machine_id: 42,
        status: "reconciliation_required",
        stage: "applying",
        from_version_id: 10,
        from: null,
        to_version_id: 11,
        to: {
          vps_plan_id: 2,
          vps_plan_version_id: 11,
          plan_name: "Pro",
          plan_slug: "pro",
          version: 1,
        },
        old_resources: {
          cpu_cores: 2,
          ram_mb: 2048,
          disk_gb: 25,
          net_download_mbps: null,
          net_upload_mbps: null,
        },
        new_resources: {
          cpu_cores: 4,
          ram_mb: 4096,
          disk_gb: 50,
          net_download_mbps: null,
          net_upload_mbps: null,
        },
        error_code: "RECONCILE_RESOURCE_MISMATCH",
        error_message: null,
        requested_at: "2026-01-01T10:00:00Z",
        started_at: "2026-01-01T10:00:02Z",
        completed_at: null,
        failed_at: null,
        created_at: "2026-01-01T10:00:00Z",
      };

      const html = renderToStaticMarkup(
        React.createElement(changePlanModalMod.ChangePlanModal, {
          isOpen: true,
          onClose: () => {},
          vm: sampleVm,
          initialPlanChange: reconChange,
        })
      );

      assert.match(html, /Reconciliation Required/);
      assert.match(html, /contact support/i);
      assert.match(html, /01jrecon000000000000000000/);
      assert.equal(html.includes("RECONCILE_RESOURCE_MISMATCH"), false);
    });
  });

  describe("5. VmPlanTab rendering & PlanChangesModal", () => {
    it("renders current plan, version, network_limits, and Change Plan button", () => {
      const html = renderToStaticMarkup(
        React.createElement(VmPlanTab, {
          vm: sampleVm,
          user: null,
          isAdmin: false,
        })
      );

      assert.match(html, /Starter/);
      assert.match(html, /v2/);
      assert.match(html, /500 Mbps/);
      assert.match(html, /250 Mbps/);
      assert.match(html, /Change Plan/);
    });

    it("renders No plan assigned and Not applicable when vps_plan is null and network_limits.applicable is false", () => {
      const customManualVm: VirtualMachine = {
        ...sampleVm,
        vps_plan: null,
        network_limits: {
          download_mbps: null,
          upload_mbps: null,
          applicable: false,
        },
      };

      const html = renderToStaticMarkup(
        React.createElement(VmPlanTab, {
          vm: customManualVm,
          user: null,
          isAdmin: false,
        })
      );

      assert.match(html, /No plan assigned/);
      assert.match(html, /Not applicable/);
      assert.match(html, /Change Plan/);
    });

    it("renders PlanChangesModal filters and validates positive int filter & direction helpers", () => {
      assert.equal(parsePositiveIntFilter(""), null);
      assert.equal(parsePositiveIntFilter("0"), null);
      assert.equal(parsePositiveIntFilter("-3"), null);
      assert.equal(parsePositiveIntFilter("abc"), null);
      assert.equal(parsePositiveIntFilter("42"), 42);

      const modalPlan: AdminVpsPlan = {
        id: 2,
        name: "Pro",
        slug: "pro",
        description: "Pro plan",
        status: "published",
        sort_order: 1,
        current_version_id: 11,
        current_version: null,
        draft_version: null,
        created_by: 1,
        updated_by: 1,
        created_at: "2026-01-01T00:00:00Z",
        updated_at: "2026-01-01T00:00:00Z",
      };

      const html = renderToStaticMarkup(
        React.createElement(PlanChangesModal, { plan: modalPlan, onClose: () => {} })
      );
      assert.match(html, /Plan Changes: Pro/);
      assert.match(html, /pro/);
      assert.match(html, /All statuses/);
      assert.match(html, /User ID/);
      assert.match(html, /VM ID/);

      // Ensure AdminVpsPlanChange type compatibility & direction resolution
      const sampleAdminOp: AdminVpsPlanChange = {
        id: 1,
        public_id: "01jadmin000000000000000000",
        virtual_machine_id: 42,
        user_id: 7,
        initiated_by: 7,
        from_version_id: 10,
        to_version_id: 11,
        old_resources: { cpu_cores: 2, ram_mb: 2048, disk_gb: 25, net_download_mbps: null, net_upload_mbps: null },
        new_resources: { cpu_cores: 4, ram_mb: 4096, disk_gb: 50, net_download_mbps: null, net_upload_mbps: null },
        status: "reconciliation_required",
        stage: "verifying",
        authorization_ref: null,
        driver_name: "incus",
        driver_operation_id: "op-1",
        error_code: "VERIFY_RESOURCE_MISMATCH",
        error_message: "Mismatch observed",
        requested_at: "2026-01-01T10:00:00Z",
        started_at: "2026-01-01T10:00:01Z",
        completed_at: null,
        failed_at: null,
        apply_deadline: null,
        created_at: "2026-01-01T10:00:00Z",
        updated_at: "2026-01-01T10:00:02Z",
        virtual_machine: { id: 42, name: "app-node-01", incus_project: "tenant-7", status: "running", user_id: 7 },
        user: { id: 7, name: "Alice", email: "alice@example.com" },
        initiator: { id: 7, name: "Alice", email: "alice@example.com" },
        from_version: { id: 10, vps_plan_id: 1, version: 2, plan_name: "Starter", plan_slug: "starter" },
        to_version: { id: 11, vps_plan_id: 2, version: 1, plan_name: "Pro", plan_slug: "pro" },
      };
      assert.equal(sampleAdminOp.status, "reconciliation_required");
      assert.equal(resolvePlanChangeDirection(sampleAdminOp, 2), "incoming");
      assert.equal(resolvePlanChangeDirection(sampleAdminOp, 1), "outgoing");
      assert.equal(
        resolvePlanChangeDirection(
          {
            from_version: { id: 11, vps_plan_id: 2, version: 1, plan_name: "Pro", plan_slug: "pro" },
            to_version: { id: 12, vps_plan_id: 2, version: 2, plan_name: "Pro", plan_slug: "pro" },
          },
          2
        ),
        "internal"
      );
    });

    it("registers the built-in plan tab in registerVmBuiltins.ts", () => {
      const builtinsPath = fileURLToPath(
        new URL("../../../src/components/vms/registerVmBuiltins.ts", import.meta.url)
      );
      const source = fs.readFileSync(builtinsPath, "utf8");
      assert.match(source, /id:\s*"plan"/);
      assert.match(source, /VmPlanTab/);
    });
  });
});
