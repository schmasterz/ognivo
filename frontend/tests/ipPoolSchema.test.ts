import { describe, it } from "node:test";
import assert from "node:assert/strict";
import {
  buildIpPoolPayload,
  parseDnsServers,
  parseExcludedIps,
  validateIpPoolForm,
  type IpPoolFormValues,
} from "../src/components/networking/ipPoolSchema.ts";
import { dictionaries, type TranslationKey } from "../src/lib/i18n/dictionaries.ts";

const tKey = (key: TranslationKey): string => key;
const tEn = (key: TranslationKey): string => dictionaries.en[key];

function makeBaseValues(overrides: Partial<IpPoolFormValues> = {}): IpPoolFormValues {
  return {
    mode: "create",
    name: "Node 1 Public",
    hostId: 1,
    cidr: "203.0.113.0/24",
    firstUsable: "203.0.113.1",
    lastUsable: "203.0.113.254",
    parentInterface: "eno1",
    networkMode: "routed",
    gateway: "",
    dnsServers: "1.1.1.1, 8.8.8.8",
    excludedIps: "",
    cooldownSeconds: 300,
    description: "",
    isPublic: true,
    ...overrides,
  };
}

describe("parseDnsServers", () => {
  it("parses comma-separated DNS servers and trims whitespace", () => {
    assert.deepEqual(parseDnsServers("1.1.1.1, 8.8.8.8"), ["1.1.1.1", "8.8.8.8"]);
    assert.deepEqual(parseDnsServers("  9.9.9.9 ,1.0.0.1 , "), ["9.9.9.9", "1.0.0.1"]);
  });

  it("returns an empty array for empty or comma-only inputs", () => {
    assert.deepEqual(parseDnsServers(""), []);
    assert.deepEqual(parseDnsServers("   "), []);
    assert.deepEqual(parseDnsServers(" , ,  ,"), []);
  });
});

describe("parseExcludedIps", () => {
  it("parses comma-separated excluded IPs and trims whitespace", () => {
    assert.deepEqual(parseExcludedIps("203.0.113.2, 203.0.113.10"), [
      "203.0.113.2",
      "203.0.113.10",
    ]);
    assert.deepEqual(parseExcludedIps(" 192.0.2.5 , , 192.0.2.9 "), [
      "192.0.2.5",
      "192.0.2.9",
    ]);
  });

  it("returns an empty array for empty or whitespace-only inputs", () => {
    assert.deepEqual(parseExcludedIps(""), []);
    assert.deepEqual(parseExcludedIps("  ,   "), []);
  });
});

describe("validateIpPoolForm", () => {
  it("returns no errors for a valid create form across supported modes", () => {
    assert.deepEqual(validateIpPoolForm(makeBaseValues(), tKey), {});
    assert.deepEqual(
      validateIpPoolForm(
        makeBaseValues({
          networkMode: "bridged",
          gateway: "203.0.113.1",
        }),
        tKey
      ),
      {}
    );
    assert.deepEqual(
      validateIpPoolForm(
        makeBaseValues({
          networkMode: "macvlan",
          gateway: "203.0.113.1",
        }),
        tKey
      ),
      {}
    );
    assert.deepEqual(
      validateIpPoolForm(
        makeBaseValues({
          networkMode: "manual",
          parentInterface: "",
          gateway: "203.0.113.1",
        }),
        tKey
      ),
      {}
    );
  });

  it("validates required name, host_id in create mode, and parent_interface for non-manual modes", () => {
    const errors = validateIpPoolForm(
      makeBaseValues({
        mode: "create",
        name: "   ",
        hostId: null,
        parentInterface: "  ",
        networkMode: "routed",
      }),
      tKey
    );

    assert.equal(errors.name?.length, 1);
    assert.deepEqual(errors.host_id, ["selectTargetHostNode"]);
    assert.deepEqual(errors.parent_interface, ["parentInterfaceRequired"]);
  });

  it("does not require host_id in edit mode and allows empty parent_interface in manual mode", () => {
    const errors = validateIpPoolForm(
      makeBaseValues({
        mode: "edit",
        hostId: null,
        networkMode: "manual",
        parentInterface: "",
        gateway: "203.0.113.1",
      }),
      tKey
    );

    assert.deepEqual(errors, {});
  });

  it("validates CIDR format, /16-/32 bounds, and L2 /31-/32 restriction", () => {
    assert.deepEqual(
      validateIpPoolForm(makeBaseValues({ mode: "create", cidr: "" }), tKey).cidr,
      ["validIpv4PrefixHint"]
    );
    assert.deepEqual(
      validateIpPoolForm(makeBaseValues({ cidr: "not-a-cidr" }), tKey).cidr,
      ["validIpv4PrefixHint"]
    );
    assert.deepEqual(
      validateIpPoolForm(makeBaseValues({ cidr: "10.0.0.0/8" }), tKey).cidr,
      ["validIpv4PrefixHint"]
    );

    for (const mode of ["bridged", "macvlan"] as const) {
      for (const cidr of ["203.0.113.10/31", "203.0.113.10/32"]) {
        const errors = validateIpPoolForm(
          makeBaseValues({ networkMode: mode, cidr, gateway: "203.0.113.10" }),
          tKey
        );
        assert.deepEqual(errors.cidr, ["l2ModePrefixUnsupported"]);
      }
    }

    assert.deepEqual(
      validateIpPoolForm(
        makeBaseValues({ networkMode: "routed", cidr: "203.0.113.10/31", gateway: "" }),
        tKey
      ),
      {}
    );
    assert.deepEqual(
      validateIpPoolForm(
        makeBaseValues({ networkMode: "manual", cidr: "203.0.113.10/32", gateway: "" }),
        tKey
      ),
      {}
    );
  });

  it("validates valid and invalid gateway addresses across modes", () => {
    // Missing required gateway for L2 and manual /16-/30
    assert.deepEqual(
      validateIpPoolForm(
        makeBaseValues({ networkMode: "bridged", gateway: "" }),
        tKey
      ).gateway,
      ["gatewayRequiredForL2Modes"]
    );
    assert.deepEqual(
      validateIpPoolForm(
        makeBaseValues({ networkMode: "manual", gateway: "" }),
        tKey
      ).gateway,
      ["gatewayRequiredForManualSubnets"]
    );

    // Invalid IPv4
    assert.deepEqual(
      validateIpPoolForm(
        makeBaseValues({ networkMode: "routed", gateway: "999.0.0.1" }),
        tEn
      ).gateway,
      [dictionaries.en.gatewayInvalidIpv4]
    );

    // Outside CIDR
    assert.deepEqual(
      validateIpPoolForm(
        makeBaseValues({ networkMode: "routed", gateway: "192.0.2.1" }),
        tKey
      ).gateway,
      ["gatewayOutsideCidr"]
    );

    // Network or broadcast address
    assert.deepEqual(
      validateIpPoolForm(
        makeBaseValues({ networkMode: "bridged", gateway: "203.0.113.0" }),
        tKey
      ).gateway,
      ["gatewayNetworkOrBroadcast"]
    );
    assert.deepEqual(
      validateIpPoolForm(
        makeBaseValues({ networkMode: "bridged", gateway: "203.0.113.255" }),
        tKey
      ).gateway,
      ["gatewayNetworkOrBroadcast"]
    );

    // Link-local rejection
    assert.deepEqual(
      validateIpPoolForm(
        makeBaseValues({ networkMode: "routed", gateway: "169.254.0.1" }),
        tKey
      ).gateway,
      ["Link-local addresses (169.254.0.0/16) cannot be used as pool CIDR, gateway, or excluded IPs."]
    );

    // Valid optional reserved gateway in routed mode
    assert.deepEqual(
      validateIpPoolForm(
        makeBaseValues({ networkMode: "routed", gateway: "203.0.113.1" }),
        tKey
      ),
      {}
    );
  });

  it("skips gateway validation in edit mode when isGatewayLocked is true", () => {
    const errors = validateIpPoolForm(
      makeBaseValues({
        mode: "edit",
        networkMode: "bridged",
        gateway: "",
        hasAllocations: true,
        isGatewayLocked: true,
      }),
      tKey
    );

    assert.deepEqual(errors, {});
  });

  it("validates cooldownSeconds >= 60", () => {
    assert.deepEqual(
      validateIpPoolForm(makeBaseValues({ cooldownSeconds: 60 }), tKey),
      {}
    );
    assert.deepEqual(
      validateIpPoolForm(makeBaseValues({ cooldownSeconds: 600 }), tKey),
      {}
    );

    for (const tooLow of [0, 59]) {
      assert.equal(
        validateIpPoolForm(makeBaseValues({ cooldownSeconds: tooLow }), tKey).cooldown_seconds?.length,
        1
      );
    }

    const negativeErrors = validateIpPoolForm(
      makeBaseValues({ cooldownSeconds: -1 }),
      tKey
    );
    assert.equal(negativeErrors.cooldown_seconds?.length, 1);

    const nanErrors = validateIpPoolForm(
      makeBaseValues({ cooldownSeconds: Number.NaN }),
      tKey
    );
    assert.equal(nanErrors.cooldown_seconds?.length, 1);
  });
});

describe("buildIpPoolPayload", () => {
  it("builds the common create payload subset while preserving Create semantics", () => {
    const payload = buildIpPoolPayload(
      makeBaseValues({
        mode: "create",
        name: "  Untrimmed Create Name  ",
        networkMode: "manual",
        parentInterface: "eno1",
        gateway: "   ",
        dnsServers: "",
        excludedIps: "  ",
        description: "   ",
        firstUsable: " 203.0.113.1 ",
        lastUsable: " ",
        cooldownSeconds: 120,
        isPublic: false,
      })
    );

    assert.deepEqual(payload, {
      name: "Untrimmed Create Name",
      first_usable: "203.0.113.1",
      last_usable: undefined,
      network_mode: "manual",
      gateway: undefined,
      dns_servers: undefined,
      excluded_ips: undefined,
      cooldown_seconds: 120,
      is_public: false,
      description: undefined,
    });
    assert.equal("host_id" in payload, false);
    assert.equal("cidr" in payload, false);
    assert.equal("active" in payload, false);
    assert.equal("parent_interface" in payload, false);
  });

  it("builds the common edit payload subset while preserving Edit semantics", () => {
    const payload = buildIpPoolPayload(
      makeBaseValues({
        mode: "edit",
        name: "  Trimmed Edit Name  ",
        networkMode: "manual",
        parentInterface: "eno1",
        gateway: "",
        dnsServers: "1.1.1.1, 8.8.8.8",
        excludedIps: "",
        description: "  ",
        cooldownSeconds: 300,
        isPublic: true,
        hasAllocations: false,
      })
    );

    assert.deepEqual(payload, {
      name: "Trimmed Edit Name",
      description: null,
      dns_servers: ["1.1.1.1", "8.8.8.8"],
      excluded_ips: [],
      cooldown_seconds: 300,
      is_public: true,
      gateway: null,
      first_usable: "203.0.113.1",
      last_usable: "203.0.113.254",
      parent_interface: "",
      network_mode: "manual",
    });
  });

  it("omits gateway and network_mode in edit payload when pool has allocations in L2 mode", () => {
    const payload = buildIpPoolPayload(
      makeBaseValues({
        mode: "edit",
        networkMode: "bridged",
        parentInterface: " br0 ",
        gateway: "203.0.113.1",
        hasAllocations: true,
        isGatewayLocked: true,
      })
    );

    assert.equal("gateway" in payload, false);
    assert.equal("network_mode" in payload, false);
    assert.equal(payload.parent_interface, "br0");
  });
});

describe("buildIpPoolPayload cooldown handling", () => {
  it("keeps cooldown >= 60 and falls back to 300 for lower or invalid values", () => {
    for (const mode of ["create", "edit"] as const) {
      assert.equal(buildIpPoolPayload(makeBaseValues({ mode, cooldownSeconds: 60 })).cooldown_seconds, 60);
      assert.equal(buildIpPoolPayload(makeBaseValues({ mode, cooldownSeconds: 0 })).cooldown_seconds, 300);
      assert.equal(buildIpPoolPayload(makeBaseValues({ mode, cooldownSeconds: -5 })).cooldown_seconds, 300);
      assert.equal(buildIpPoolPayload(makeBaseValues({ mode, cooldownSeconds: Number.NaN })).cooldown_seconds, 300);
    }
  });
});
