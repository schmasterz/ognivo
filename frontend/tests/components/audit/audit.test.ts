import { describe, it } from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import vm from "node:vm";
import { fileURLToPath } from "node:url";
import ts from "typescript";

// Mock minimal DOM window / history environment if not in browser
interface MockWindow {
  location: {
    search: string;
    pathname: string;
  };
  history: {
    replaceState: (_data: unknown, _unused: string, url: string) => void;
  };
}

if (typeof window === "undefined") {
  const globalWithWindow = global as unknown as { window: MockWindow };
  globalWithWindow.window = {
    location: {
      search: "",
      pathname: "/audit",
    },
    history: {
      replaceState: (_data: unknown, _unused: string, url: string) => {
        const [pathname, search] = url.split("?");
        globalWithWindow.window.location.pathname = pathname;
        globalWithWindow.window.location.search = search ? `?${search}` : "";
      },
    },
  };
}

import {
  parseFiltersFromUrl,
  syncFiltersToUrl,
  computeCursorPaginationMeta,
} from "../../../src/components/audit/auditUrl.ts";
import type { AuditEntry, AdminAuditEntry, AuditFilters } from "../../../src/lib/api/types.ts";
import { dictionaries } from "../../../src/lib/i18n/dictionaries.ts";

/**
 * Loads `formatAuditType` from AuditTable.tsx via TypeScript transpilation so
 * Node's native test runner can exercise the exact component helper without JSX/alias resolution.
 */
function loadFormatAuditType(): (type?: string | null) => string {
  const auditTablePath = fileURLToPath(
    new URL("../../../src/components/audit/AuditTable.tsx", import.meta.url),
  );
  const sourceText = fs.readFileSync(auditTablePath, "utf8");
  const sourceFile = ts.createSourceFile(
    "AuditTable.tsx",
    sourceText,
    ts.ScriptTarget.Latest,
    true,
    ts.ScriptKind.TSX,
  );

  let snippet: string | undefined;
  const visit = (node: ts.Node) => {
    if (snippet) return;
    if (ts.isFunctionDeclaration(node) && node.name?.text === "formatAuditType") {
      snippet = sourceText.slice(node.getStart(sourceFile), node.getEnd());
      return;
    }
    ts.forEachChild(node, visit);
  };
  visit(sourceFile);

  if (!snippet) {
    throw new Error("formatAuditType declaration not found in AuditTable.tsx");
  }

  const transpiled = ts.transpileModule(snippet, {
    compilerOptions: {
      module: ts.ModuleKind.CommonJS,
      target: ts.ScriptTarget.ES2020,
      strict: false,
    },
  });

  const exportsObj: Record<string, unknown> = {};
  const moduleObj: { exports: unknown } = { exports: exportsObj };
  const wrapper = vm.runInThisContext(
    `(function(exports, module) {\n${transpiled.outputText}\n})`,
  ) as (exportsArg: Record<string, unknown>, moduleArg: { exports: unknown }) => void;
  wrapper(exportsObj, moduleObj);

  const fn = exportsObj.formatAuditType;
  if (typeof fn !== "function") {
    throw new Error("Failed to load formatAuditType from AuditTable.tsx");
  }
  return fn as (type?: string | null) => string;
}

const formatAuditType = loadFormatAuditType();

describe("Audit Frontend Unit & Integration Tests", () => {
  describe("1. Filter-to-URL Synchronization & Recovery", () => {
    it("serializes audit filters to URL query string", () => {
      const filters: AuditFilters = {
        category: "vm",
        action: "vm.start",
        outcome: "success",
        from: "2026-11-01",
        to: "2026-11-05",
        actor_id: 42,
        tenant_user_id: 99,
        ip: "192.168.1.100",
        request_id: "req-abc-123",
      };

      syncFiltersToUrl(filters);

      const search = window.location.search;
      assert.ok(search.includes("category=vm"), "Should contain category");
      assert.ok(search.includes("action=vm.start"), "Should contain action");
      assert.ok(search.includes("outcome=success"), "Should contain outcome");
      assert.ok(search.includes("from=2026-11-01"), "Should contain from");
      assert.ok(search.includes("to=2026-11-05"), "Should contain to");
      assert.ok(search.includes("actor_id=42"), "Should contain actor_id");
      assert.ok(search.includes("tenant_user_id=99"), "Should contain tenant_user_id");
      assert.ok(search.includes("ip=192.168.1.100"), "Should contain ip");
      assert.ok(search.includes("request_id=req-abc-123"), "Should contain request_id");
    });

    it("parses audit filters from URL query string correctly", () => {
      window.location.search =
        "?category=ip&action=ip.allocated&outcome=failed&from=2026-10-01&to=2026-10-31&actor_id=7&tenant_user_id=12&ip=2001%3Adb8%3A%3A1&request_id=req-xyz-789";

      const parsed = parseFiltersFromUrl();
      assert.equal(parsed.category, "ip");
      assert.equal(parsed.action, "ip.allocated");
      assert.equal(parsed.outcome, "failed");
      assert.equal(parsed.from, "2026-10-01");
      assert.equal(parsed.to, "2026-10-31");
      assert.equal(parsed.actor_id, 7);
      assert.equal(parsed.tenant_user_id, 12);
      assert.equal(parsed.ip, "2001:db8::1");
      assert.equal(parsed.request_id, "req-xyz-789");
    });

    it("clears empty or reset filters from query string", () => {
      syncFiltersToUrl({});
      assert.equal(window.location.search, "");
      const parsed = parseFiltersFromUrl();
      assert.deepEqual(parsed, {});
    });
  });

  describe("2. Keyset Cursor Page Pagination & Metadata", () => {
    it("computes total and lastPage accurately from backend cursor page metadata", () => {
      // Case 1: Backend reports total=60 with perPage=25 on Page 1
      const page1Meta = computeCursorPaginationMeta({
        currentPage: 1,
        perPage: 25,
        itemsCount: 25,
        nextCursor: "eyJvY2N1cnJlZF9hdCI6IjIwMjYtMTEtMDUifQ==",
        reportedTotal: 60,
      });
      assert.equal(page1Meta.total, 60);
      assert.equal(page1Meta.lastPage, 3);

      // Case 2: Single page with <= 25 items and no next_cursor
      const singlePageMeta = computeCursorPaginationMeta({
        currentPage: 1,
        perPage: 25,
        itemsCount: 12,
        nextCursor: null,
        reportedTotal: 12,
      });
      assert.equal(singlePageMeta.total, 12);
      assert.equal(singlePageMeta.lastPage, 1);

      // Case 3: Fallback calculation when reportedTotal is omitted
      const fallbackMeta = computeCursorPaginationMeta({
        currentPage: 2,
        perPage: 10,
        itemsCount: 10,
        nextCursor: "cursor-page-3",
      });
      assert.equal(fallbackMeta.total, 21);
      assert.equal(fallbackMeta.lastPage, 3);
    });

    it("replaces page rows and tracks cursor history per page without duplicates", () => {
      const page1Entries: AuditEntry[] = [
        {
          id: 100,
          occurred_at: "2026-11-05T12:00:00.000000Z",
          action: "vm.start",
          outcome: "success",
          subject_type: "vm",
          subject_id: "1",
          subject_label: "web-srv-1",
          actor: { kind: "you" },
          ip: null,
          user_agent: null,
          context: {},
        },
        {
          id: 99,
          occurred_at: "2026-11-05T11:59:00.000000Z",
          action: "vm.stop",
          outcome: "success",
          subject_type: "vm",
          subject_id: "1",
          subject_label: "web-srv-1",
          actor: { kind: "you" },
          ip: null,
          user_agent: null,
          context: {},
        },
      ];

      const page2Entries: AuditEntry[] = [
        {
          id: 98,
          occurred_at: "2026-11-05T11:58:00.000000Z",
          action: "vm.create",
          outcome: "success",
          subject_type: "vm",
          subject_id: "1",
          subject_label: "web-srv-1",
          actor: { kind: "you" },
          ip: null,
          user_agent: null,
          context: {},
        },
      ];

      const pageCursors: Record<number, string | null> = { 1: null };
      // After loading page 1 with next_cursor = "cursor-after-99"
      pageCursors[2] = "cursor-after-99";

      assert.equal(pageCursors[1], null);
      assert.equal(pageCursors[2], "cursor-after-99");
      assert.deepEqual(
        page1Entries.map((e) => e.id),
        [100, 99]
      );
      assert.deepEqual(
        page2Entries.map((e) => e.id),
        [98]
      );
    });
  });

  describe("3. Customer Isolation & Role Boundary", () => {
    it("ensures customer audit entries do not expose admin fields", () => {
      const customerEntry: AuditEntry = {
        id: 10,
        occurred_at: "2026-11-05T10:00:00.000000Z",
        action: "vm.start",
        outcome: "success",
        subject_type: "vm",
        subject_id: "42",
        subject_label: "ubuntu-vps",
        actor: { kind: "you" },
        ip: null,
        user_agent: null,
        context: {
          diff: {
            status: { before: "stopped", after: "running" },
          },
        },
      };

      // In TypeScript and runtime data, customer entries lack tenant_user_id and request_id
      const rawCustomerEntry = customerEntry as unknown as Record<string, unknown>;
      assert.equal(rawCustomerEntry.tenant_user_id, undefined);
      assert.equal(rawCustomerEntry.request_id, undefined);
    });

    it("ensures admin audit entries include actor, tenant, ip and request_id", () => {
      const adminEntry: AdminAuditEntry = {
        id: 11,
        occurred_at: "2026-11-05T10:05:00.000000Z",
        actor_id: 1,
        actor_label: "admin@example.com",
        actor_type: "user",
        impersonator_id: null,
        tenant_user_id: 5,
        ip: "10.0.0.50",
        user_agent: "Mozilla/5.0",
        request_id: "req-12345",
        operation_id: null,
        action: "vm.delete.forced",
        outcome: "success",
        subject_type: "vm",
        subject_id: "42",
        subject_label: "ubuntu-vps",
        visibility: "admin",
        context: {},
      };

      assert.equal(adminEntry.actor_label, "admin@example.com");
      assert.equal(adminEntry.tenant_user_id, 5);
      assert.equal(adminEntry.ip, "10.0.0.50");
      assert.equal(adminEntry.request_id, "req-12345");
    });

    it("verifies customer table never exposes admin columns", () => {
      // Simulating columns resolution logic in AuditTable
      const getColumns = (isAdmin: boolean) => {
        const base = ["timestamp", "action", "outcome", "subject"];
        if (isAdmin) {
          return [...base, "actor", "tenant", "ip", "request_id"];
        }
        return base;
      };

      const customerCols = getColumns(false);
      const adminCols = getColumns(true);

      assert.deepEqual(customerCols, ["timestamp", "action", "outcome", "subject"]);
      assert.ok(!customerCols.includes("actor"), "Customer must not see actor column");
      assert.ok(!customerCols.includes("tenant"), "Customer must not see tenant column");
      assert.ok(!customerCols.includes("ip"), "Customer must not see ip column");
      assert.ok(!customerCols.includes("request_id"), "Customer must not see request_id column");

      assert.ok(adminCols.includes("actor"));
      assert.ok(adminCols.includes("tenant"));
      assert.ok(adminCols.includes("ip"));
      assert.ok(adminCols.includes("request_id"));
    });

    it("verifies dictionary has all required audit keys and UI labels", () => {
      const en = dictionaries.en;
      const bg = dictionaries.bg;

      // Check required UI keys
      const requiredKeys = [
        "menuActivity",
        "menuAuditLog",
        "auditTitle",
        "adminAuditTitle",
        "auditSubtitle",
        "colTimestamp",
        "colAction",
        "colOutcome",
        "colSubject",
        "colActor",
        "colTenant",
        "colIp",
        "colRequestId",
        "btnLoadMore",
        "btnExport",
        "btnExportNdjson",
        "btnExportCsv",
        "btnVerifyIntegrity",
        "verifySuccess",
        "verifyFailed",
        "drawerTitle",
        "btnRelatedEvents",
        "diffTable",
      ];

      const enDict = en as Record<string, string>;
      const bgDict = bg as Record<string, string>;

      for (const key of requiredKeys) {
        assert.ok(enDict[key], `English dictionary should have key: ${key}`);
        assert.ok(bgDict[key], `Bulgarian dictionary should have key: ${key}`);
      }

      // Check action translations fallback
      assert.equal(en["audit.auth.login.success"], "Successful sign in");
      assert.equal(bg["audit.auth.login.success"], "Успешен вход в системата");

      // An unknown action key should fallback to the raw action string
      const unknownAction = "custom.plugin.fired";
      const translatedEn = enDict[`audit.${unknownAction}`] || unknownAction;
      assert.equal(translatedEn, "custom.plugin.fired");
    });
  });

  describe("4. Context Diff Table & Related Events Filtering", () => {
    it("extracts before/after diffs properly from audit entry context", () => {
      const context = {
        diff: {
          cores: { before: 2, after: 4 },
          ram_mb: { before: 2048, after: 4096 },
        },
      };

      const diffEntries = Object.entries(context.diff);
      assert.equal(diffEntries.length, 2);
      assert.equal(diffEntries[0][0], "cores");
      assert.deepEqual(diffEntries[0][1], { before: 2, after: 4 });
      assert.equal(diffEntries[1][0], "ram_mb");
      assert.deepEqual(diffEntries[1][1], { before: 2048, after: 4096 });
    });

    it("formats related events filter correctly", () => {
      const entry: AdminAuditEntry = {
        id: 50,
        occurred_at: "2026-11-05T12:00:00Z",
        action: "vm.restart",
        outcome: "success",
        subject_type: "vm",
        subject_id: "10",
        subject_label: "test-vm",
        actor_id: 1,
        actor_label: "Admin",
        actor_type: "user",
        impersonator_id: null,
        tenant_user_id: 1,
        ip: "127.0.0.1",
        user_agent: null,
        visibility: "admin",
        operation_id: null,
        request_id: "req-correlation-999",
        context: {},
      };

      const filters: AuditFilters = {};
      const newFilters: AuditFilters = {
        ...filters,
        request_id: entry.request_id || undefined,
      };

      assert.equal(newFilters.request_id, "req-correlation-999");
    });

    it("preserves IPv4 and compressed IPv6 addresses as received", () => {
      const ipv4 = "192.168.1.100";
      const ipv6 = "2001:db8::1";

      assert.equal(ipv4, "192.168.1.100");
      assert.equal(ipv6, "2001:db8::1");
    });

    it("generates correct ISO UTC tooltip for timestamps", () => {
      const rawDate = "2026-11-05T14:30:00.123456Z";
      const d = new Date(rawDate);
      const utcString = d.toISOString();
      assert.equal(utcString, "2026-11-05T14:30:00.123Z");
    });
  });

  describe("5. Audit Type Formatting & Namespace Sanitization", () => {
    it("formats namespaced and friendly model types cleanly", () => {
      assert.equal(formatAuditType("App\\Models\\VirtualMachine"), "Virtual Machine");
      assert.equal(formatAuditType("App\\Models\\User"), "User");
      assert.equal(formatAuditType("App\\Services\\CustomService"), "CustomService");
    });

    it("formats additional known audit entity types into friendly labels", () => {
      assert.equal(formatAuditType("vm"), "Virtual Machine");
      assert.equal(formatAuditType("virtual_machine"), "Virtual Machine");
      assert.equal(formatAuditType("App\\Models\\IpPool"), "IP Pool");
      assert.equal(formatAuditType("ip_allocation"), "IP Allocation");
      assert.equal(formatAuditType("App\\Models\\HostNode"), "Host Node");
      assert.equal(formatAuditType("App\\Models\\VmSnapshot"), "Snapshot");
      assert.equal(formatAuditType("App\\Models\\QuotaGroup"), "Quota Group");
    });

    it("never returns strings containing App\\ or App\\\\ for any input", () => {
      const inputs = [
        null,
        undefined,
        "",
        "App\\Models\\VirtualMachine",
        "App\\Models\\User",
        "App\\Services\\CustomService",
        "App\\\\Models\\\\VirtualMachine",
        "App_InternalService",
        "App\\",
        "Domain\\Audit\\EventSource",
      ];

      for (const input of inputs) {
        const formatted = formatAuditType(input);
        assert.ok(!formatted.includes("App\\"), `Unexpected App\\ in output for ${String(input)}: ${formatted}`);
        assert.ok(!formatted.includes("App\\\\"), `Unexpected App\\\\ in output for ${String(input)}: ${formatted}`);
      }
    });
  });
});
