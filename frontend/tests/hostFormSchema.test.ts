import { describe, it } from "node:test";
import assert from "node:assert/strict";
import {
  HOST_DEFAULTS,
  CPU_OVERCOMMIT_MAX,
  RAM_OVERCOMMIT_MAX,
  DISK_OVERCOMMIT_MAX,
  MIN_STORAGE_GB,
  isHostNameValid,
  isValidHttpUrl,
  isCapacityValid,
  isTokenValid,
  isFingerprintValid,
  hostRamMbToGb,
} from "../src/components/hosts/hostFormSchema.ts";

const validCapacity = {
  cpuCores: HOST_DEFAULTS.cpuCores,
  ramGb: HOST_DEFAULTS.ramGb,
  storageGb: HOST_DEFAULTS.storageGb,
  cpuOvercommit: HOST_DEFAULTS.overcommit,
  ramOvercommit: HOST_DEFAULTS.overcommit,
  diskOvercommit: HOST_DEFAULTS.overcommit,
};

describe("hostFormSchema constants", () => {
  it("exposes expected defaults and upper/lower bounds", () => {
    assert.deepEqual(HOST_DEFAULTS, {
      cpuCores: 64,
      ramGb: 256,
      storageGb: 4000,
      ramMbFallback: 262144,
      overcommit: 1.0,
    });
    assert.equal(CPU_OVERCOMMIT_MAX, 16);
    assert.equal(RAM_OVERCOMMIT_MAX, 4);
    assert.equal(DISK_OVERCOMMIT_MAX, 4);
    assert.equal(MIN_STORAGE_GB, 10);
  });
});

describe("isHostNameValid", () => {
  it("rejects empty, whitespace-only, and 1-character names", () => {
    assert.equal(isHostNameValid(""), false);
    assert.equal(isHostNameValid("   "), false);
    assert.equal(isHostNameValid("a"), false);
    assert.equal(isHostNameValid(" a "), false);
  });

  it("accepts 2-character and hyphenated lowercase alphanumeric names after trimming", () => {
    assert.equal(isHostNameValid("ab"), true);
    assert.equal(isHostNameValid("  node-01  "), true);
    assert.equal(isHostNameValid("eu-west-1a"), true);
  });

  it("rejects uppercase letters and disallowed characters", () => {
    assert.equal(isHostNameValid("Host-01"), false);
    assert.equal(isHostNameValid("AB"), false);
    assert.equal(isHostNameValid("node_01"), false);
    assert.equal(isHostNameValid("node 01"), false);
    assert.equal(isHostNameValid("node.01"), false);
  });
});

describe("isValidHttpUrl", () => {
  it("accepts http and https URLs with a hostname", () => {
    assert.equal(isValidHttpUrl("https://127.0.0.1:8443"), true);
    assert.equal(isValidHttpUrl("http://incus.internal:8443"), true);
    assert.equal(isValidHttpUrl("  https://host.example.com/  "), true);
  });

  it("rejects non-http(s) URL schemes and malformed URLs", () => {
    assert.equal(isValidHttpUrl("ftp://example.com"), false);
    assert.equal(isValidHttpUrl("unix:///var/lib/incus/unix.socket"), false);
    assert.equal(isValidHttpUrl("ws://localhost:8443"), false);
    assert.equal(isValidHttpUrl("javascript:alert(1)"), false);
    assert.equal(isValidHttpUrl("https://"), false);
    assert.equal(isValidHttpUrl("not-a-url"), false);
    assert.equal(isValidHttpUrl(""), false);
  });
});

describe("isCapacityValid", () => {
  it("accepts valid defaults and exact boundary values", () => {
    assert.equal(isCapacityValid(validCapacity), true);
    assert.equal(
      isCapacityValid({
        cpuCores: 1,
        ramGb: 1,
        storageGb: 10,
        cpuOvercommit: 1,
        ramOvercommit: 1,
        diskOvercommit: 1,
      }),
      true
    );
    assert.equal(
      isCapacityValid({
        cpuCores: 1,
        ramGb: 1,
        storageGb: 10,
        cpuOvercommit: 16,
        ramOvercommit: 4,
        diskOvercommit: 4,
      }),
      true
    );
  });

  it("enforces CPU, RAM, and storage minimums when skipTotals is false/omitted", () => {
    assert.equal(isCapacityValid({ ...validCapacity, cpuCores: 0 }), false);
    assert.equal(isCapacityValid({ ...validCapacity, ramGb: 0 }), false);
    assert.equal(isCapacityValid({ ...validCapacity, storageGb: 9 }), false);
    assert.equal(isCapacityValid({ ...validCapacity, storageGb: 10 }), true);
    assert.equal(
      isCapacityValid({ ...validCapacity, storageGb: 9 }, { skipTotals: false }),
      false
    );
  });

  it("skips total minimums when skipTotals is true while still enforcing overcommit bounds", () => {
    assert.equal(
      isCapacityValid(
        {
          cpuCores: 0,
          ramGb: 0,
          storageGb: 0,
          cpuOvercommit: 1,
          ramOvercommit: 1,
          diskOvercommit: 1,
        },
        { skipTotals: true }
      ),
      true
    );
    assert.equal(
      isCapacityValid(
        { ...validCapacity, cpuCores: 0, cpuOvercommit: 0.99 },
        { skipTotals: true }
      ),
      false
    );
    assert.equal(
      isCapacityValid(
        { ...validCapacity, cpuCores: 0, cpuOvercommit: 16.01 },
        { skipTotals: true }
      ),
      false
    );
  });

  it("enforces overcommit boundaries for CPU (1..16), RAM (1..4), and disk (1..4)", () => {
    assert.equal(isCapacityValid({ ...validCapacity, cpuOvercommit: 0.99 }), false);
    assert.equal(isCapacityValid({ ...validCapacity, cpuOvercommit: 1 }), true);
    assert.equal(isCapacityValid({ ...validCapacity, cpuOvercommit: 16 }), true);
    assert.equal(isCapacityValid({ ...validCapacity, cpuOvercommit: 16.01 }), false);

    assert.equal(isCapacityValid({ ...validCapacity, ramOvercommit: 0.99 }), false);
    assert.equal(isCapacityValid({ ...validCapacity, ramOvercommit: 1 }), true);
    assert.equal(isCapacityValid({ ...validCapacity, ramOvercommit: 4 }), true);
    assert.equal(isCapacityValid({ ...validCapacity, ramOvercommit: 4.01 }), false);

    assert.equal(isCapacityValid({ ...validCapacity, diskOvercommit: 0.99 }), false);
    assert.equal(isCapacityValid({ ...validCapacity, diskOvercommit: 1 }), true);
    assert.equal(isCapacityValid({ ...validCapacity, diskOvercommit: 4 }), true);
    assert.equal(isCapacityValid({ ...validCapacity, diskOvercommit: 4.01 }), false);
  });
});

describe("isTokenValid and isFingerprintValid", () => {
  it("requires trimmed token length strictly greater than 10", () => {
    assert.equal(isTokenValid("1234567890"), false);
    assert.equal(isTokenValid("  1234567890  "), false);
    assert.equal(isTokenValid("12345678901"), true);
    assert.equal(isTokenValid("  12345678901  "), true);
  });

  it("requires trimmed fingerprint length >= 32", () => {
    assert.equal(isFingerprintValid("a".repeat(31)), false);
    assert.equal(isFingerprintValid(`  ${"a".repeat(31)}  `), false);
    assert.equal(isFingerprintValid("a".repeat(32)), true);
    assert.equal(isFingerprintValid(`  ${"a".repeat(32)}  `), true);
  });
});

describe("hostRamMbToGb", () => {
  it("falls back to 256 GB when mb is undefined or 0 and rounds MB/1024", () => {
    assert.equal(hostRamMbToGb(), 256);
    assert.equal(hostRamMbToGb(undefined), 256);
    assert.equal(hostRamMbToGb(0), 256);
    assert.equal(hostRamMbToGb(262144), 256);
    assert.equal(hostRamMbToGb(16384), 16);
    assert.equal(hostRamMbToGb(1536), 2);
    assert.equal(hostRamMbToGb(1280), 1);
  });
});
