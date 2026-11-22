import { describe, it } from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import vm from "node:vm";
import { fileURLToPath } from "node:url";
import ts from "typescript";
import { dictionaries } from "../../../src/lib/i18n/dictionaries.ts";

type PoolNetworkMode = "routed" | "bridged" | "macvlan" | "manual";

interface UsableRangeResult {
  first: string;
  last: string;
  gateway: string;
}

interface CidrRangeResult {
  ipStr?: string;
  prefix: number;
  network: number;
  broadcast: number;
}

interface CreateModalHelpers {
  parseIpv4ToNum: (ip: string) => number | null;
  parseCidrPrefix: (cidr: string) => number | null;
  parseCidrRange: (cidr: string) => CidrRangeResult | null;
  calculateUsableRange: (cidr: string, networkMode: PoolNetworkMode) => UsableRangeResult | null;
  validateGatewayField: (gatewayInput: string, cidrInput: string, mode: PoolNetworkMode) => string | null;
  isGatewayRequiredFor: (networkMode: PoolNetworkMode, cidr: string) => boolean;
}

interface EditPoolInput {
  network_mode?: string;
  cidr?: string;
  subnet?: string;
  allocated_ips?: number;
  cooling_ips?: number;
  utilization?: {
    allocated?: number;
    cooling?: number;
  };
}

interface EditModalStateEval {
  hasAllocations: boolean;
  isL2Mode: boolean;
  isGatewayLocked: boolean;
  isGatewayRequired: boolean;
}

interface EditModeChangeInput {
  pool: EditPoolInput;
  initialMode: string;
  initialParentInterface: string;
  initialGateway: string;
  isCustomInterface?: boolean;
  discoveredNetworks?: Array<{
    name: string;
    recommended_modes: Array<"routed" | "bridged" | "macvlan">;
  }>;
  nextMode: string;
}

interface EditModeChangeResult {
  networkMode: string;
  parentInterface: string;
  gateway: string;
  clearedErrors: string[];
}

interface EditModalHelpers {
  parseIpv4ToNum: (ip: string) => number | null;
  parseCidrPrefix: (cidr: string) => number | null;
  parseCidrRange: (cidr: string) => CidrRangeResult | null;
  validateGatewayField: (gatewayInput: string, cidrInput: string, mode: string) => string | null;
  evaluateEditGatewayFlags: (pool: EditPoolInput, networkMode: string) => EditModalStateEval;
  simulateEditModeChange: (input: EditModeChangeInput) => EditModeChangeResult;
}

const createModalPath = fileURLToPath(
  new URL("../../../src/components/networking/CreateIpPoolModal.tsx", import.meta.url),
);
const editModalPath = fileURLToPath(
  new URL("../../../src/components/networking/EditIpPoolModal.tsx", import.meta.url),
);

const createModalSource = fs.readFileSync(createModalPath, "utf8");
const editModalSource = fs.readFileSync(editModalPath, "utf8");

function findNodeText(sourceText: string, fileName: string, predicate: (node: ts.Node) => boolean): string {
  const sourceFile = ts.createSourceFile(fileName, sourceText, ts.ScriptTarget.Latest, true, ts.ScriptKind.TSX);
  let match: ts.Node | undefined;

  const visit = (node: ts.Node) => {
    if (match) return;
    if (predicate(node)) {
      match = node;
      return;
    }
    ts.forEachChild(node, visit);
  };

  visit(sourceFile);
  if (!match) {
    throw new Error(`Expected AST node not found in ${fileName}`);
  }
  return sourceText.slice(match.getStart(sourceFile), match.getEnd());
}

function getTopLevelFunctionSnippet(sourceText: string, fileName: string, fnName: string): string {
  return findNodeText(
    sourceText,
    fileName,
    (node) => ts.isFunctionDeclaration(node) && node.name?.text === fnName,
  );
}

function getTypeAliasSnippet(sourceText: string, fileName: string, typeName: string): string {
  return findNodeText(
    sourceText,
    fileName,
    (node) => ts.isTypeAliasDeclaration(node) && node.name.text === typeName,
  );
}

function getVariableDeclarationSnippet(sourceText: string, fileName: string, varName: string): string {
  return findNodeText(
    sourceText,
    fileName,
    (node) =>
      ts.isVariableStatement(node) &&
      node.declarationList.declarations.some(
        (decl) => ts.isIdentifier(decl.name) && decl.name.text === varName,
      ),
  );
}

function runTranspiledSnippet<T>(
  tsCode: string,
  t: (key: string) => string = (key: string) => key,
): T {
  const transpiled = ts.transpileModule(tsCode, {
    compilerOptions: {
      module: ts.ModuleKind.CommonJS,
      target: ts.ScriptTarget.ES2020,
      strict: false,
    },
  });

  const exportsObj: Record<string, unknown> = {};
  const moduleObj: { exports: unknown } = { exports: exportsObj };
  const wrapper = vm.runInThisContext(
    `(function(exports, module, t) {\n${transpiled.outputText}\n})`,
  ) as (
    exportsArg: Record<string, unknown>,
    moduleArg: { exports: unknown },
    tArg: (key: string) => string,
  ) => void;

  wrapper(exportsObj, moduleObj, t);
  return moduleObj.exports as T;
}

function loadCreateModalHelpers(): CreateModalHelpers {
  const snippets = [
    getTypeAliasSnippet(createModalSource, "CreateIpPoolModal.tsx", "PoolNetworkMode"),
    getTopLevelFunctionSnippet(createModalSource, "CreateIpPoolModal.tsx", "parseIpv4ToNum"),
    getTopLevelFunctionSnippet(createModalSource, "CreateIpPoolModal.tsx", "parseCidrPrefix"),
    getTopLevelFunctionSnippet(createModalSource, "CreateIpPoolModal.tsx", "parseCidrRange"),
    getTopLevelFunctionSnippet(createModalSource, "CreateIpPoolModal.tsx", "calculateUsableRange"),
    getVariableDeclarationSnippet(createModalSource, "CreateIpPoolModal.tsx", "validateGatewayField"),
    `function isGatewayRequiredFor(networkMode: PoolNetworkMode, cidr: string): boolean {
      const cidrPrefix = parseCidrPrefix(cidr);
      const isL2Mode = networkMode === "bridged" || networkMode === "macvlan";
      ${getVariableDeclarationSnippet(createModalSource, "CreateIpPoolModal.tsx", "isGatewayRequired")}
      return isGatewayRequired;
    }`,
    `module.exports = {
      parseIpv4ToNum,
      parseCidrPrefix,
      parseCidrRange,
      calculateUsableRange,
      validateGatewayField,
      isGatewayRequiredFor,
    };`,
  ].join("\n\n");

  return runTranspiledSnippet<CreateModalHelpers>(snippets);
}

function loadEditModalHelpers(): EditModalHelpers {
  const snippets = [
    getTopLevelFunctionSnippet(editModalSource, "EditIpPoolModal.tsx", "parseIpv4ToNum"),
    getTopLevelFunctionSnippet(editModalSource, "EditIpPoolModal.tsx", "parseCidrPrefix"),
    getTopLevelFunctionSnippet(editModalSource, "EditIpPoolModal.tsx", "parseCidrRange"),
    getVariableDeclarationSnippet(editModalSource, "EditIpPoolModal.tsx", "validateGatewayField"),
    `function evaluateEditGatewayFlags(
      pool: {
        cidr?: string;
        subnet?: string;
        allocated_ips?: number;
        cooling_ips?: number;
        utilization?: { allocated?: number; cooling?: number };
      },
      networkMode: string
    ) {
      ${getVariableDeclarationSnippet(editModalSource, "EditIpPoolModal.tsx", "hasAllocations")}
      ${getVariableDeclarationSnippet(editModalSource, "EditIpPoolModal.tsx", "isL2Mode")}
      ${getVariableDeclarationSnippet(editModalSource, "EditIpPoolModal.tsx", "isGatewayLocked")}
      ${getVariableDeclarationSnippet(editModalSource, "EditIpPoolModal.tsx", "poolCidr")}
      ${getVariableDeclarationSnippet(editModalSource, "EditIpPoolModal.tsx", "cidrPrefix")}
      ${getVariableDeclarationSnippet(editModalSource, "EditIpPoolModal.tsx", "isGatewayRequired")}
      return { hasAllocations, isL2Mode, isGatewayLocked, isGatewayRequired };
    }`,
    `function simulateEditModeChange(input: {
      pool: { cidr?: string; subnet?: string };
      initialMode: string;
      initialParentInterface: string;
      initialGateway: string;
      isCustomInterface?: boolean;
      discoveredNetworks?: Array<{ name: string; recommended_modes: Array<"routed" | "bridged" | "macvlan"> }>;
      nextMode: string;
    }) {
      const pool = input.pool;
      let networkMode = input.initialMode;
      let parentInterface = input.initialParentInterface;
      let gateway = input.initialGateway;
      const isCustomInterface = input.isCustomInterface ?? false;
      const discoveredNetworks = input.discoveredNetworks ?? [];
      const clearedErrors: string[] = [];

      const setNetworkMode = (mode: string) => {
        networkMode = mode;
      };
      const setParentInterface = (iface: string) => {
        parentInterface = iface;
      };
      const setGateway = (gw: string) => {
        gateway = gw;
      };
      const clearFieldError = (field: string) => {
        clearedErrors.push(field);
      };

      ${getVariableDeclarationSnippet(editModalSource, "EditIpPoolModal.tsx", "handleModeChange")}
      handleModeChange(input.nextMode);

      return { networkMode, parentInterface, gateway, clearedErrors };
    }`,
    `module.exports = {
      parseIpv4ToNum,
      parseCidrPrefix,
      parseCidrRange,
      validateGatewayField,
      evaluateEditGatewayFlags,
      simulateEditModeChange,
    };`,
  ].join("\n\n");

  return runTranspiledSnippet<EditModalHelpers>(snippets);
}

const createHelpers = loadCreateModalHelpers();
const editHelpers = loadEditModalHelpers();

describe("IP Pool Modals (Create & Edit) IPAM Tests", () => {
  describe("1. Dictionary Translations (EN & BG)", () => {
    it("provides English and Bulgarian translations for all new IPAM gateway/reservation keys", () => {
      const requiredKeys = [
        "gatewayIpOptionalReserved",
        "gatewayReservedDesc",
        "gatewayReservedBadgeLabel",
        "gatewayImmutableAllocatedDesc",
        "gatewayRequiredForManualSubnets",
      ] as const;

      const enDict = dictionaries.en as Record<string, string>;
      const bgDict = dictionaries.bg as Record<string, string>;

      for (const key of requiredKeys) {
        assert.equal(typeof enDict[key], "string", `Missing English translation for ${key}`);
        assert.ok(enDict[key].trim().length > 0, `Empty English translation for ${key}`);
        assert.notEqual(enDict[key], key, `English translation for ${key} must not fall back to key`);

        assert.equal(typeof bgDict[key], "string", `Missing Bulgarian translation for ${key}`);
        assert.ok(bgDict[key].trim().length > 0, `Empty Bulgarian translation for ${key}`);
        assert.notEqual(bgDict[key], key, `Bulgarian translation for ${key} must not fall back to key`);
        assert.notEqual(bgDict[key], enDict[key], `Bulgarian translation for ${key} should be localized`);
      }
    });

    it("retains supporting IPAM gateway validation and prefix restriction keys in EN and BG", () => {
      const supportingKeys = [
        "gatewayRequiredForL2Modes",
        "gatewayInvalidIpv4",
        "gatewayOutsideCidr",
        "gatewayNetworkOrBroadcast",
        "gatewayRoutedHint",
        "l2ModePrefixUnsupported",
      ] as const;

      const enDict = dictionaries.en as Record<string, string>;
      const bgDict = dictionaries.bg as Record<string, string>;

      for (const key of supportingKeys) {
        assert.ok(enDict[key]?.trim().length > 0, `Missing English translation for ${key}`);
        assert.ok(bgDict[key]?.trim().length > 0, `Missing Bulgarian translation for ${key}`);
      }
    });
  });

  describe("2. Routed Pool Mode Rules", () => {
    it("treats gateway as optional, defaults gateway to empty, and starts first_usable at network + 1", () => {
      assert.equal(createHelpers.isGatewayRequiredFor("routed", "203.0.113.0/24"), false);
      assert.equal(createHelpers.validateGatewayField("", "203.0.113.0/24", "routed"), null);
      assert.equal(editHelpers.validateGatewayField("", "203.0.113.0/24", "routed"), null);

      const range24 = createHelpers.calculateUsableRange("203.0.113.0/24", "routed");
      assert.deepEqual(range24, {
        gateway: "",
        first: "203.0.113.1",
        last: "203.0.113.254",
      });

      const range30 = createHelpers.calculateUsableRange("198.51.100.4/30", "routed");
      assert.deepEqual(range30, {
        gateway: "",
        first: "198.51.100.5",
        last: "198.51.100.6",
      });
    });

    it("computes /31 and /32 routed ranges with empty default gateway", () => {
      assert.deepEqual(createHelpers.calculateUsableRange("203.0.113.10/31", "routed"), {
        gateway: "",
        first: "203.0.113.10",
        last: "203.0.113.11",
      });

      assert.deepEqual(createHelpers.calculateUsableRange("203.0.113.10/32", "routed"), {
        gateway: "",
        first: "203.0.113.10",
        last: "203.0.113.10",
      });
    });

    it("validates optional reserved gateway in routed pools when provided", () => {
      assert.equal(createHelpers.validateGatewayField("203.0.113.1", "203.0.113.0/24", "routed"), null);
      assert.equal(editHelpers.validateGatewayField("203.0.113.1", "203.0.113.0/24", "routed"), null);

      assert.equal(
        createHelpers.validateGatewayField("not-an-ip", "203.0.113.0/24", "routed"),
        "gatewayInvalidIpv4",
      );
      assert.equal(
        createHelpers.validateGatewayField("192.0.2.1", "203.0.113.0/24", "routed"),
        "gatewayOutsideCidr",
      );
      assert.equal(
        createHelpers.validateGatewayField("203.0.113.0", "203.0.113.0/24", "routed"),
        "gatewayNetworkOrBroadcast",
      );
      assert.equal(
        createHelpers.validateGatewayField("203.0.113.255", "203.0.113.0/24", "routed"),
        "gatewayNetworkOrBroadcast",
      );
    });
  });

  describe("3. Bridged & Macvlan (L2) Pool Mode Rules", () => {
    it("requires gateway and defaults gateway to network + 1 with first_usable at network + 1", () => {
      for (const mode of ["bridged", "macvlan"] as const) {
        assert.equal(createHelpers.isGatewayRequiredFor(mode, "203.0.113.0/24"), true);
        assert.equal(
          createHelpers.validateGatewayField("", "203.0.113.0/24", mode),
          "gatewayRequiredForL2Modes",
        );
        assert.equal(
          editHelpers.validateGatewayField("   ", "203.0.113.0/24", mode),
          "gatewayRequiredForL2Modes",
        );
        assert.equal(
          createHelpers.validateGatewayField("203.0.113.1", "203.0.113.0/24", mode),
          null,
        );

        assert.deepEqual(createHelpers.calculateUsableRange("203.0.113.0/24", mode), {
          gateway: "203.0.113.1",
          first: "203.0.113.1",
          last: "203.0.113.254",
        });
      }
    });

    it("computes first_usable at network + 1 across all modes for /16-/30 subnets", () => {
      const subnets = [
        { cidr: "10.10.0.0/16", first: "10.10.0.1", last: "10.10.255.254" },
        { cidr: "192.168.50.0/24", first: "192.168.50.1", last: "192.168.50.254" },
        { cidr: "198.51.100.4/30", first: "198.51.100.5", last: "198.51.100.6" },
      ];

      for (const { cidr, first, last } of subnets) {
        for (const mode of ["bridged", "macvlan", "manual", "routed"] as const) {
          assert.deepEqual(createHelpers.calculateUsableRange(cidr, mode), {
            gateway: mode === "routed" ? "" : first,
            first,
            last,
          });
        }
      }
    });

    it("restricts L2 modes (bridged / macvlan) to /16-/30 and rejects /31-/32 or out-of-range prefixes", () => {
      // parseCidrRange enforces prefix bounds 16..32
      assert.equal(createHelpers.parseCidrRange("10.0.0.0/15"), null);
      assert.notEqual(createHelpers.parseCidrRange("10.0.0.0/16"), null);
      assert.notEqual(createHelpers.parseCidrRange("10.0.0.0/30"), null);

      // Both Create and Edit modals explicitly reject /31 and /32 for L2 modes on submit
      const l2GuardPattern = /isL2Mode\s*&&\s*\(cidrPrefix\s*===\s*31\s*\|\|\s*cidrPrefix\s*===\s*32\)/;
      assert.match(createModalSource, l2GuardPattern);
      assert.match(editModalSource, l2GuardPattern);
      assert.match(createModalSource, /t\("l2ModePrefixUnsupported"\)/);
      assert.match(editModalSource, /t\("l2ModePrefixUnsupported"\)/);

      // Rejects network/broadcast gateway addresses in /16-/30 L2 subnets
      assert.equal(
        createHelpers.validateGatewayField("203.0.113.0", "203.0.113.0/24", "bridged"),
        "gatewayNetworkOrBroadcast",
      );
      assert.equal(
        editHelpers.validateGatewayField("203.0.113.255", "203.0.113.0/24", "macvlan"),
        "gatewayNetworkOrBroadcast",
      );
    });

    it("marks gateway immutable in Edit modal when allocations or cooling IPs exist for bridged/macvlan", () => {
      for (const mode of ["bridged", "macvlan"] as const) {
        const allocatedViaUtilization = editHelpers.evaluateEditGatewayFlags(
          { cidr: "203.0.113.0/24", utilization: { allocated: 1, cooling: 0 } },
          mode,
        );
        assert.equal(allocatedViaUtilization.hasAllocations, true);
        assert.equal(allocatedViaUtilization.isL2Mode, true);
        assert.equal(allocatedViaUtilization.isGatewayLocked, true);
        assert.equal(allocatedViaUtilization.isGatewayRequired, false);

        const coolingViaLegacyCount = editHelpers.evaluateEditGatewayFlags(
          { subnet: "203.0.113.0/24", allocated_ips: 0, cooling_ips: 2 },
          mode,
        );
        assert.equal(coolingViaLegacyCount.hasAllocations, true);
        assert.equal(coolingViaLegacyCount.isGatewayLocked, true);
        assert.equal(coolingViaLegacyCount.isGatewayRequired, false);

        const freePool = editHelpers.evaluateEditGatewayFlags(
          { cidr: "203.0.113.0/24", utilization: { allocated: 0, cooling: 0 } },
          mode,
        );
        assert.equal(freePool.hasAllocations, false);
        assert.equal(freePool.isGatewayLocked, false);
        assert.equal(freePool.isGatewayRequired, true);
      }

      // Routed and manual pools with allocations never lock gateway
      for (const mode of ["routed", "manual"] as const) {
        const flags = editHelpers.evaluateEditGatewayFlags(
          { cidr: "203.0.113.0/24", utilization: { allocated: 3, cooling: 1 } },
          mode,
        );
        assert.equal(flags.hasAllocations, true);
        assert.equal(flags.isL2Mode, false);
        assert.equal(flags.isGatewayLocked, false);
      }

      // Verify Edit modal enforces immutability in UI and update payload
      assert.match(editModalSource, /disabled=\{isGatewayLocked\}/);
      assert.match(editModalSource, /readOnly=\{isGatewayLocked\}/);
      assert.match(editModalSource, /t\("gatewayImmutableAllocatedDesc"\)/);
      assert.match(editModalSource, /\.\.\.\(isGatewayLocked\s*\?\s*\{\}\s*:\s*\{\s*gateway:\s*gateway\.trim\(\)\s*\|\|\s*null\s*\}\)/);
    });
  });

  describe("4. Manual Pool Mode Rules", () => {
    it("requires gateway for /16-/30 subnets", () => {
      for (const cidr of ["10.10.0.0/16", "203.0.113.0/24", "198.51.100.0/30"]) {
        assert.equal(createHelpers.isGatewayRequiredFor("manual", cidr), true);
        assert.equal(
          createHelpers.validateGatewayField("", cidr, "manual"),
          "gatewayRequiredForManualSubnets",
        );
        assert.equal(
          editHelpers.validateGatewayField("", cidr, "manual"),
          "gatewayRequiredForManualSubnets",
        );

        const editFlags = editHelpers.evaluateEditGatewayFlags({ cidr }, "manual");
        assert.equal(editFlags.isGatewayRequired, true);
      }

      assert.equal(createHelpers.validateGatewayField("203.0.113.1", "203.0.113.0/24", "manual"), null);
      assert.equal(
        createHelpers.validateGatewayField("192.0.2.1", "203.0.113.0/24", "manual"),
        "gatewayOutsideCidr",
      );
    });

    it("rejects network and broadcast addresses as gateway for /16-/30 subnets in manual mode", () => {
      const cases = [
        { cidr: "10.10.0.0/16", network: "10.10.0.0", broadcast: "10.10.255.255" },
        { cidr: "203.0.113.0/24", network: "203.0.113.0", broadcast: "203.0.113.255" },
        { cidr: "198.51.100.4/30", network: "198.51.100.4", broadcast: "198.51.100.7" },
      ];

      for (const { cidr, network, broadcast } of cases) {
        assert.equal(
          createHelpers.validateGatewayField(network, cidr, "manual"),
          "gatewayNetworkOrBroadcast",
        );
        assert.equal(
          createHelpers.validateGatewayField(broadcast, cidr, "manual"),
          "gatewayNetworkOrBroadcast",
        );
        assert.equal(
          editHelpers.validateGatewayField(network, cidr, "manual"),
          "gatewayNetworkOrBroadcast",
        );
        assert.equal(
          editHelpers.validateGatewayField(broadcast, cidr, "manual"),
          "gatewayNetworkOrBroadcast",
        );
      }
    });

    it("keeps gateway optional for /31 and /32 subnets", () => {
      for (const cidr of ["203.0.113.10/31", "203.0.113.10/32"]) {
        assert.equal(createHelpers.isGatewayRequiredFor("manual", cidr), false);
        assert.equal(createHelpers.validateGatewayField("", cidr, "manual"), null);
        assert.equal(editHelpers.validateGatewayField("", cidr, "manual"), null);

        const editFlags = editHelpers.evaluateEditGatewayFlags({ cidr }, "manual");
        assert.equal(editFlags.isGatewayRequired, false);
      }

      assert.deepEqual(createHelpers.calculateUsableRange("203.0.113.10/31", "manual"), {
        gateway: "",
        first: "203.0.113.10",
        last: "203.0.113.11",
      });
      assert.deepEqual(createHelpers.calculateUsableRange("203.0.113.10/32", "manual"), {
        gateway: "",
        first: "203.0.113.10",
        last: "203.0.113.10",
      });
      assert.equal(createHelpers.validateGatewayField("203.0.113.10", "203.0.113.10/32", "manual"), null);
    });
  });

  describe("5. Reserved Gateway Separation from Editable excluded_ips", () => {
    it("keeps reserved gateway separate from manual excluded_ips state, UI badge, and submit payloads", () => {
      // Edit modal initializes excludedIps strictly from pool.excluded_ips and gateway strictly from pool.gateway
      assert.match(
        editModalSource,
        /useState\(\s*pool\.excluded_ips\s*&&\s*pool\.excluded_ips\.length\s*>\s*0\s*\?\s*pool\.excluded_ips\.join\(", "\)\s*:\s*""\s*\)/,
      );
      assert.match(editModalSource, /useState\(pool\.gateway\s*\|\|\s*""\)/);

      // Submit payloads parse excluded_ips solely from excludedIps without injecting gateway
      assert.match(
        createModalSource,
        /const\s+parsedExcluded\s*=\s*excludedIps[\s\S]*?excluded_ips:\s*parsedExcluded\.length\s*>\s*0\s*\?\s*parsedExcluded\s*:\s*undefined/,
      );
      assert.match(
        editModalSource,
        /const\s+parsedExcluded\s*=\s*excludedIps[\s\S]*?excluded_ips:\s*parsedExcluded/,
      );

      // Both modals render the dedicated reserved gateway badge above the editable excludedIps input
      for (const [name, source] of [
        ["CreateIpPoolModal.tsx", createModalSource],
        ["EditIpPoolModal.tsx", editModalSource],
      ] as const) {
        assert.match(
          source,
          /\{gateway\.trim\(\)\s*&&\s*\([\s\S]*?t\("gatewayReservedBadgeLabel"\)[\s\S]*?gateway\.trim\(\)[\s\S]*?t\("gatewayReservedDesc"\)/,
          `${name} should render reserved gateway badge separately from excludedIps input`,
        );
      }
    });
  });

  describe("6. Link-Local Gateway Rejection (169.254.0.0/16)", () => {
    it("rejects link-local addresses in 169.254.0.0/16 in both Create and Edit modals", () => {
      const expectedError =
        "Link-local addresses (169.254.0.0/16) cannot be used as pool CIDR, gateway, or excluded IPs.";
      const linkLocalGateways = ["169.254.0.1", "169.254.10.20"];

      for (const gw of linkLocalGateways) {
        for (const mode of ["routed", "bridged", "macvlan", "manual"] as const) {
          assert.equal(
            createHelpers.validateGatewayField(gw, "169.254.0.0/16", mode),
            expectedError,
          );
          assert.equal(
            editHelpers.validateGatewayField(gw, "169.254.0.0/16", mode),
            expectedError,
          );
          assert.equal(
            createHelpers.validateGatewayField(gw, "203.0.113.0/24", mode),
            expectedError,
          );
          assert.equal(
            editHelpers.validateGatewayField(gw, "203.0.113.0/24", mode),
            expectedError,
          );
        }
      }
    });
  });

  describe("7. EditIpPoolModal Network Mode Switching & L2 Prefix Guard", () => {
    it("defines handleModeChange, clears parent_interface when manual, and disables bridged/macvlan on /31-/32", () => {
      assert.match(editModalSource, /const\s+handleModeChange\s*=\s*\(mode:\s*string\)\s*=>/);
      assert.match(editModalSource, /onChange=\{\(e\)\s*=>\s*handleModeChange\(e\.target\.value\)\}/);
      assert.match(
        editModalSource,
        /parent_interface:\s*networkMode\s*===\s*"manual"\s*\?\s*""\s*:\s*trimmedParentInterface/,
      );
      assert.match(
        editModalSource,
        /<option\s+value="bridged"\s+disabled=\{cidrPrefix\s*===\s*31\s*\|\|\s*cidrPrefix\s*===\s*32\}>/,
      );
      assert.match(
        editModalSource,
        /<option\s+value="macvlan"\s+disabled=\{cidrPrefix\s*===\s*31\s*\|\|\s*cidrPrefix\s*===\s*32\}>/,
      );
    });

    it("updates networkMode, clears field errors, and handles parentInterface and gateway on mode change", () => {
      const toManual = editHelpers.simulateEditModeChange({
        pool: { cidr: "203.0.113.0/24" },
        initialMode: "bridged",
        initialParentInterface: "eno1",
        initialGateway: "",
        discoveredNetworks: [
          { name: "eno1", recommended_modes: ["bridged", "macvlan"] },
          { name: "incusbr0", recommended_modes: ["routed"] },
        ],
        nextMode: "manual",
      });
      assert.equal(toManual.networkMode, "manual");
      assert.equal(toManual.gateway, "203.0.113.1");
      assert.deepEqual(toManual.clearedErrors, ["network_mode", "gateway", "cidr", "parent_interface"]);

      const toBridged = editHelpers.simulateEditModeChange({
        pool: { cidr: "203.0.113.0/24" },
        initialMode: "manual",
        initialParentInterface: "",
        initialGateway: "",
        discoveredNetworks: [
          { name: "incusbr0", recommended_modes: ["routed"] },
          { name: "eno1", recommended_modes: ["bridged", "macvlan"] },
        ],
        nextMode: "bridged",
      });
      assert.equal(toBridged.networkMode, "bridged");
      assert.equal(toBridged.parentInterface, "eno1");
      assert.equal(toBridged.gateway, "203.0.113.1");

      const toRouted = editHelpers.simulateEditModeChange({
        pool: { cidr: "203.0.113.0/24" },
        initialMode: "manual",
        initialParentInterface: "",
        initialGateway: "",
        discoveredNetworks: [{ name: "incusbr0", recommended_modes: ["routed"] }],
        nextMode: "routed",
      });
      assert.equal(toRouted.networkMode, "routed");
      assert.equal(toRouted.parentInterface, "incusbr0");
      assert.equal(toRouted.gateway, "");
    });
  });
});
