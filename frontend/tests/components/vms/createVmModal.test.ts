import { describe, it } from "node:test";
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
import { vmsApi, type CreateVmPayload } from "../../../src/lib/api/vms.ts";
import type { HostNode, IpPool, VpsPlanCatalogItem } from "../../../src/lib/api/types.ts";

interface CreateVmModalModule {
  CreateVmModal: React.ComponentType<{
    isOpen: boolean;
    onClose: () => void;
    quotaData: null;
    systemSettings?: { allow_virtual_machines?: boolean; allow_containers?: boolean } | null;
    onSuccess: () => void;
  }>;
  planHasNetworkLimits: (plan: VpsPlanCatalogItem) => boolean;
  computeHostManualOnlyMap: (pools: IpPool[]) => Record<number, boolean>;
  isHostSelectionManualOnly: (
    selectedHostId: number | null,
    hosts: HostNode[],
    hostHasFreeMap: Record<number, boolean>,
    hostManualOnlyMap: Record<number, boolean>
  ) => boolean;
  isNetworkLimitNotSupportedError: (err: unknown) => boolean;
  buildCreateVmPayload: (input: {
    isAdmin: boolean;
    useCustomResources: boolean;
    name: string;
    osImage: string;
    selectedPlanId: number | null;
    selectedHostId: number | null;
    instanceType: "virtual-machine" | "container";
    cpuCores: number;
    ramGb: number;
    diskGb: number;
  }) => CreateVmPayload;
}

function loadCreateVmModalModule(options: { isAdmin: boolean }): CreateVmModalModule {
  const modalPath = fileURLToPath(
    new URL("../../../src/components/vms/CreateVmModal.tsx", import.meta.url)
  );
  const sourceText = fs.readFileSync(modalPath, "utf8");
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
    if (specifier === "@/lib/api") {
      return {
        ApiError,
        api: {
          getHosts: async () => ({ data: [] }),
          getIpPools: async () => ({ data: [], meta: { current_page: 1, last_page: 1, per_page: 100, total: 0 } }),
          getImages: async () => ({ data: [] }),
          createVm: vmsApi.createVm,
        },
        vpsPlansApi: {
          listCatalog: async () => [],
        },
      };
    }
    if (specifier === "@/lib/api/client") {
      return {
        extractPaginationMeta: () => ({ currentPage: 1, lastPage: 1, perPage: 100, total: 0 }),
      };
    }
    if (specifier === "@/lib/auth/AuthContext") {
      return {
        useAuth: () => ({
          isAdmin: options.isAdmin,
          user: { id: 1, name: "Test", email: "test@example.com", roles: options.isAdmin ? [{ name: "admin" }] : [] },
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
    if (specifier === "@/lib/hooks/useModalEscape") {
      return {
        useModalEscape: () => {},
      };
    }
    if (specifier === "../plans/VpsPlanCard") {
      return {
        formatPlanRam: (ramMb: number) => `${ramMb / 1024} GiB (${ramMb} MiB)`,
        formatNetworkLimit: (
          mbps: number | null | undefined,
          unlimitedLabel: string,
          formatMbps: (v: number) => string
        ) => (mbps === null || mbps === undefined ? unlimitedLabel : formatMbps(mbps)),
      };
    }
    throw new Error(`Unexpected import in CreateVmModal.tsx: ${specifier}`);
  };

  const wrapper = vm.runInThisContext(
    `(function(exports, require, module) {\n${transpiled.outputText}\n})`
  ) as (
    exportsArg: Record<string, unknown>,
    requireArg: (specifier: string) => unknown,
    moduleArg: { exports: unknown }
  ) => void;

  wrapper(exportsObj, customRequire, moduleObj);
  return moduleObj.exports as CreateVmModalModule;
}

const modalHelpers = loadCreateVmModalModule({ isAdmin: false });

describe("CreateVmModal & Plan-based Provisioning Tests", () => {
  describe("1. vmsApi.createVm payload and Idempotency-Key header", () => {
    it("sends plan-based payload without resource fields and includes Idempotency-Key header", async () => {
      const originalFetch = globalThis.fetch;
      const recorded: Array<{ url: string; method: string; headers: Headers; body: string }> = [];

      globalThis.fetch = (async (input: string | URL | Request, init?: RequestInit) => {
        const url = typeof input === "string" ? input : input.toString();
        const method = (init?.method ?? "GET").toUpperCase();
        const headers = new Headers(init?.headers);
        const body = typeof init?.body === "string" ? init.body : "";
        recorded.push({ url, method, headers, body });

        if (url.endsWith("/sanctum/csrf-cookie")) {
          return new Response(null, { status: 204 });
        }

        return new Response(
          JSON.stringify({
            message: "Provisioning initiated",
            virtual_machine: {
              id: 42,
              user_id: 7,
              host_id: 2,
              name: "web-01",
              incus_project: "tenant-7",
              instance_type: "virtual-machine",
              status: "provisioning",
              cpu_cores: 2,
              ram_mb: 2048,
              disk_gb: 25,
              os_image: "ubuntu/24.04",
              ipv4_address: null,
              ipv6_address: null,
              created_at: "2026-11-27T00:00:00Z",
              updated_at: "2026-11-27T00:00:00Z",
              vps_plan: {
                id: 5,
                name: "Standard",
                slug: "standard",
                version_id: 12,
                version: 1,
                is_current_version: true,
              },
              network_limits: {
                download_mbps: 500,
                upload_mbps: 250,
                applicable: true,
              },
            },
          }),
          { status: 202, headers: { "Content-Type": "application/json" } }
        );
      }) as typeof fetch;

      try {
        const res = await vmsApi.createVm(
          {
            name: "web-01",
            os_image: "ubuntu/24.04",
            vps_plan_id: 5,
            host_id: 2,
          },
          { idempotencyKey: "11111111-2222-4333-8444-555555555555" }
        );

        assert.equal(res.virtual_machine.vps_plan?.slug, "standard");
        assert.equal(res.virtual_machine.network_limits?.download_mbps, 500);

        const postCall = recorded.find((r) => r.method === "POST" && r.url.endsWith("/vms"));
        assert.ok(postCall, "Expected POST /vms call");
        assert.equal(
          postCall.headers.get("Idempotency-Key"),
          "11111111-2222-4333-8444-555555555555"
        );

        const parsedBody = JSON.parse(postCall.body) as Record<string, unknown>;
        assert.deepStrictEqual(parsedBody, {
          name: "web-01",
          os_image: "ubuntu/24.04",
          vps_plan_id: 5,
          host_id: 2,
        });
        assert.equal("cpu_cores" in parsedBody, false);
        assert.equal("ram_mb" in parsedBody, false);
        assert.equal("disk_gb" in parsedBody, false);
        assert.equal("instance_type" in parsedBody, false);
      } finally {
        globalThis.fetch = originalFetch;
      }
    });
  });

  describe("2. buildCreateVmPayload role & mode enforcement", () => {
    it("strips all resource fields for non-admin users even if useCustomResources is true", () => {
      const payload = modalHelpers.buildCreateVmPayload({
        isAdmin: false,
        useCustomResources: true,
        name: "app-01",
        osImage: "ubuntu/24.04",
        selectedPlanId: 9,
        selectedHostId: null,
        instanceType: "container",
        cpuCores: 8,
        ramGb: 16,
        diskGb: 100,
      }) as unknown as Record<string, unknown>;

      assert.deepStrictEqual(payload, {
        name: "app-01",
        os_image: "ubuntu/24.04",
        vps_plan_id: 9,
      });
      assert.equal("cpu_cores" in payload, false);
      assert.equal("ram_mb" in payload, false);
      assert.equal("disk_gb" in payload, false);
      assert.equal("instance_type" in payload, false);
      assert.equal("host_id" in payload, false);
    });

    it("includes optional host_id for plan-based provisioning when a host is selected", () => {
      const payload = modalHelpers.buildCreateVmPayload({
        isAdmin: false,
        useCustomResources: false,
        name: "app-02",
        osImage: "debian/12",
        selectedPlanId: 4,
        selectedHostId: 3,
        instanceType: "virtual-machine",
        cpuCores: 1,
        ramGb: 2,
        diskGb: 20,
      }) as unknown as Record<string, unknown>;

      assert.deepStrictEqual(payload, {
        name: "app-02",
        os_image: "debian/12",
        vps_plan_id: 4,
        host_id: 3,
      });
    });

    it("builds custom resource payload for admins when custom mode is enabled", () => {
      const payload = modalHelpers.buildCreateVmPayload({
        isAdmin: true,
        useCustomResources: true,
        name: "admin-custom",
        osImage: "ubuntu/24.04",
        selectedPlanId: 4,
        selectedHostId: 1,
        instanceType: "container",
        cpuCores: 4,
        ramGb: 8,
        diskGb: 60,
      }) as unknown as Record<string, unknown>;

      assert.deepStrictEqual(payload, {
        name: "admin-custom",
        instance_type: "container",
        os_image: "ubuntu/24.04",
        cpu_cores: 4,
        ram_mb: 8192,
        disk_gb: 60,
        host_id: 1,
      });
      assert.equal("vps_plan_id" in payload, false);
    });
  });

  describe("3. Admin custom resources toggle & non-admin UI isolation", () => {
    it("hides the custom resources toggle and resource inputs for non-admin users", () => {
      const { CreateVmModal } = loadCreateVmModalModule({ isAdmin: false });
      const html = renderToStaticMarkup(
        React.createElement(CreateVmModal, {
          isOpen: true,
          onClose: () => {},
          quotaData: null,
          onSuccess: () => {},
        })
      );

      assert.equal(html.includes('data-testid="create-vm-mode-toggle"'), false);
      assert.equal(html.includes(enVpsPlans.vpsPlanProvisionModeCustom), false);
      assert.ok(html.includes(enVpsPlans.vpsPlanSelectLabel));
      assert.equal(html.includes("CPU Cores"), false);
    });

    it("renders the Plan / Custom resources toggle for admin users", () => {
      const { CreateVmModal } = loadCreateVmModalModule({ isAdmin: true });
      const html = renderToStaticMarkup(
        React.createElement(CreateVmModal, {
          isOpen: true,
          onClose: () => {},
          quotaData: null,
          onSuccess: () => {},
        })
      );

      assert.ok(html.includes('data-testid="create-vm-mode-toggle"'));
      assert.ok(html.includes('data-testid="create-vm-mode-plan"'));
      assert.ok(html.includes('data-testid="create-vm-mode-custom"'));
      assert.ok(html.includes(enVpsPlans.vpsPlanProvisionModeCustom));
    });
  });

  describe("4. Manual-only IP pool detection & NETWORK_LIMIT_NOT_SUPPORTED_MODE handling", () => {
    it("identifies plans with network limits and hosts with manual-only active free pools", () => {
      const limitedPlan: VpsPlanCatalogItem = {
        id: 1,
        name: "Limited",
        slug: "limited",
        description: null,
        sort_order: 1,
        current_version: {
          id: 10,
          version: 1,
          cpu_cores: 2,
          ram_mb: 2048,
          disk_gb: 20,
          instance_type: "virtual-machine",
          net_download_mbps: 100,
          net_upload_mbps: null,
        },
      };
      const unlimitedPlan: VpsPlanCatalogItem = {
        id: 2,
        name: "Unlimited",
        slug: "unlimited",
        description: null,
        sort_order: 2,
        current_version: {
          id: 11,
          version: 1,
          cpu_cores: 2,
          ram_mb: 2048,
          disk_gb: 20,
          instance_type: "virtual-machine",
          net_download_mbps: null,
          net_upload_mbps: null,
        },
      };

      assert.equal(modalHelpers.planHasNetworkLimits(limitedPlan), true);
      assert.equal(modalHelpers.planHasNetworkLimits(unlimitedPlan), false);

      const pools: IpPool[] = [
        { id: 1, name: "H1 Manual", host_id: 1, active: true, network_mode: "manual", free_ips: 5 },
        { id: 2, name: "H2 Manual", host_id: 2, active: true, network_mode: "manual", free_ips: 5 },
        { id: 3, name: "H2 Routed", host_id: 2, active: true, network_mode: "routed", free_ips: 3 },
        { id: 4, name: "H3 Routed Exhausted", host_id: 3, active: true, network_mode: "routed", free_ips: 0 },
        { id: 5, name: "H3 Manual", host_id: 3, active: true, network_mode: "manual", free_ips: 2 },
      ];

      const manualMap = modalHelpers.computeHostManualOnlyMap(pools);
      assert.equal(manualMap[1], true);
      assert.equal(manualMap[2], false);
      assert.equal(manualMap[3], true);

      const hosts: HostNode[] = [
        { id: 1, name: "node-1", driver: "incus", is_active: true, total_cpu_cores: 8, total_ram_mb: 16384, total_storage_gb: 200 },
        { id: 2, name: "node-2", driver: "incus", is_active: true, total_cpu_cores: 8, total_ram_mb: 16384, total_storage_gb: 200 },
      ];

      assert.equal(modalHelpers.isHostSelectionManualOnly(1, hosts, { 1: true, 2: true }, manualMap), true);
      assert.equal(modalHelpers.isHostSelectionManualOnly(2, hosts, { 1: true, 2: true }, manualMap), false);
      assert.equal(modalHelpers.isHostSelectionManualOnly(null, hosts, { 1: true, 2: true }, manualMap), false);
      assert.equal(modalHelpers.isHostSelectionManualOnly(null, [hosts[0]], { 1: true }, manualMap), true);
    });

    it("detects NETWORK_LIMIT_NOT_SUPPORTED_MODE API errors cleanly", () => {
      const apiErr = new ApiError(
        422,
        "Network bandwidth limits are not supported by manual-mode IP pools.",
        { ip_pool: ["Network bandwidth limits are not supported by manual-mode IP pools."] }
      );
      assert.equal(modalHelpers.isNetworkLimitNotSupportedError(apiErr), true);

      const codeErr = { code: "NETWORK_LIMIT_NOT_SUPPORTED_MODE" };
      assert.equal(modalHelpers.isNetworkLimitNotSupportedError(codeErr), true);

      const unrelatedErr = new ApiError(422, "Name is already taken.", { name: ["Taken"] });
      assert.equal(modalHelpers.isNetworkLimitNotSupportedError(unrelatedErr), false);
    });
  });

  describe("5. vpsPlans i18n parity for provisioning keys (en & bg)", () => {
    it("contains identical keys and non-empty translations in en and bg", () => {
      const enKeys = Object.keys(enVpsPlans).sort();
      const bgKeys = Object.keys(bgVpsPlans).sort();
      assert.deepStrictEqual(bgKeys, enKeys);

      const requiredNewKeys = [
        "vpsPlanProvisionModePlan",
        "vpsPlanProvisionModeCustom",
        "vpsPlanSelectLabel",
        "vpsPlanSelectedBadge",
        "vpsPlanManualPoolNotice",
        "vpsPlanManualPoolDisabledBadge",
        "vpsPlanManualPoolSubmitError",
        "vpsPlanNoCompatiblePlan",
      ];

      const enDict = enVpsPlans as Record<string, string>;
      const bgDict = bgVpsPlans as Record<string, string>;

      for (const key of requiredNewKeys) {
        assert.ok(enDict[key]?.trim().length > 0, `Missing EN key ${key}`);
        assert.ok(bgDict[key]?.trim().length > 0, `Missing BG key ${key}`);
        assert.equal((en as Record<string, string>)[key], enDict[key]);
        assert.equal((bg as Record<string, string>)[key], bgDict[key]);
      }
    });
  });
});
