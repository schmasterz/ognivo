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
  validateHostForm,
  buildCreateHostWithTokenPayload,
  buildCreateHostWithCertPayload,
  buildUpdateHostPayload,
  type HostFormCapacityValues,
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

const validFormCapacity: HostFormCapacityValues = {
  totalCpuCores: HOST_DEFAULTS.cpuCores,
  totalRamGb: HOST_DEFAULTS.ramGb,
  totalStorageGb: HOST_DEFAULTS.storageGb,
  cpuOvercommit: HOST_DEFAULTS.overcommit,
  ramOvercommit: HOST_DEFAULTS.overcommit,
  diskOvercommit: HOST_DEFAULTS.overcommit,
};

const zeroTotalsFormCapacity: HostFormCapacityValues = {
  totalCpuCores: 0,
  totalRamGb: 0,
  totalStorageGb: 0,
  cpuOvercommit: 2,
  ramOvercommit: 1.5,
  diskOvercommit: 1,
};

const VALID_TOKEN = "token-secret-12345";
const VALID_FINGERPRINT = "A".repeat(64);
const VALID_ENDPOINT = "https://127.0.0.1:8443";

describe("validateHostForm", () => {
  it("validates token mode and enforces totals only when autoDiscoverCapacity is false", () => {
    assert.equal(
      validateHostForm({
        mode: "token",
        name: "node-01",
        token: VALID_TOKEN,
        autoDiscoverCapacity: true,
        capacity: zeroTotalsFormCapacity,
      }),
      true
    );

    assert.equal(
      validateHostForm({
        mode: "token",
        name: "node-01",
        token: VALID_TOKEN,
        autoDiscoverCapacity: false,
        capacity: zeroTotalsFormCapacity,
      }),
      false
    );

    assert.equal(
      validateHostForm({
        mode: "token",
        name: "Invalid_Name",
        token: VALID_TOKEN,
        autoDiscoverCapacity: true,
        capacity: validFormCapacity,
      }),
      false
    );

    assert.equal(
      validateHostForm({
        mode: "token",
        name: "node-01",
        token: "short",
        autoDiscoverCapacity: true,
        capacity: validFormCapacity,
      }),
      false
    );

    assert.equal(
      validateHostForm({
        mode: "token",
        name: "node-01",
        token: VALID_TOKEN,
        autoDiscoverCapacity: true,
        capacity: { ...validFormCapacity, cpuOvercommit: CPU_OVERCOMMIT_MAX + 1 },
      }),
      false
    );
  });

  it("validates cert mode across name, endpointUrl, fingerprint, and capacity", () => {
    assert.equal(
      validateHostForm({
        mode: "cert",
        name: "node-02",
        endpointUrl: VALID_ENDPOINT,
        expectedFingerprint: VALID_FINGERPRINT,
        autoDiscoverCapacity: true,
        capacity: zeroTotalsFormCapacity,
      }),
      true
    );

    assert.equal(
      validateHostForm({
        mode: "cert",
        name: "node-02",
        endpointUrl: VALID_ENDPOINT,
        expectedFingerprint: VALID_FINGERPRINT,
        autoDiscoverCapacity: false,
        capacity: zeroTotalsFormCapacity,
      }),
      false
    );

    assert.equal(
      validateHostForm({
        mode: "cert",
        name: "x",
        endpointUrl: VALID_ENDPOINT,
        expectedFingerprint: VALID_FINGERPRINT,
        autoDiscoverCapacity: false,
        capacity: validFormCapacity,
      }),
      false
    );

    assert.equal(
      validateHostForm({
        mode: "cert",
        name: "node-02",
        endpointUrl: "ftp://127.0.0.1:8443",
        expectedFingerprint: VALID_FINGERPRINT,
        autoDiscoverCapacity: false,
        capacity: validFormCapacity,
      }),
      false
    );

    assert.equal(
      validateHostForm({
        mode: "cert",
        name: "node-02",
        endpointUrl: VALID_ENDPOINT,
        expectedFingerprint: "too-short",
        autoDiscoverCapacity: false,
        capacity: validFormCapacity,
      }),
      false
    );
  });

  it("validates edit mode and always enforces capacity total minimums", () => {
    assert.equal(
      validateHostForm({
        mode: "edit",
        name: "node-03",
        endpointUrl: VALID_ENDPOINT,
        capacity: validFormCapacity,
      }),
      true
    );

    assert.equal(
      validateHostForm({
        mode: "edit",
        name: "node-03",
        endpointUrl: VALID_ENDPOINT,
        capacity: zeroTotalsFormCapacity,
      }),
      false
    );

    assert.equal(
      validateHostForm({
        mode: "edit",
        name: "A",
        endpointUrl: VALID_ENDPOINT,
        capacity: validFormCapacity,
      }),
      false
    );

    assert.equal(
      validateHostForm({
        mode: "edit",
        name: "node-03",
        endpointUrl: "not-a-url",
        capacity: validFormCapacity,
      }),
      false
    );
  });
});

describe("host payload builders", () => {
  it("normalizes name and region identically across token, cert, and edit builders", () => {
    const tokenPayload = buildCreateHostWithTokenPayload({
      name: "  EU-West-01  ",
      region: "  eu-west  ",
      driver: "incus",
      isActive: true,
      autoDiscoverCapacity: true,
      capacity: validFormCapacity,
      token: `  ${VALID_TOKEN}  `,
      autoAttachImages: true,
    });

    const certPayload = buildCreateHostWithCertPayload({
      name: "  EU-West-01  ",
      region: "  eu-west  ",
      driver: "incus",
      isActive: true,
      autoDiscoverCapacity: true,
      capacity: validFormCapacity,
      endpointUrl: `  ${VALID_ENDPOINT}  `,
      expectedFingerprint: `  ${VALID_FINGERPRINT}  `,
      autoAttachImages: true,
    });

    const editPayload = buildUpdateHostPayload(
      {
        name: "  EU-West-01  ",
        region: "  eu-west  ",
        driver: "incus",
        isActive: true,
        endpointUrl: `  ${VALID_ENDPOINT}  `,
        capacity: validFormCapacity,
        autoAttachImages: true,
      },
      {
        total_cpu_cores: validFormCapacity.totalCpuCores,
        total_ram_mb: validFormCapacity.totalRamGb * 1024,
        total_storage_gb: validFormCapacity.totalStorageGb,
      }
    );

    assert.equal(tokenPayload.name, "eu-west-01");
    assert.equal(certPayload.name, "eu-west-01");
    assert.equal(editPayload.name, "eu-west-01");

    assert.equal(tokenPayload.region, "eu-west");
    assert.equal(certPayload.region, "eu-west");
    assert.equal(editPayload.region, "eu-west");

    assert.equal(tokenPayload.token, VALID_TOKEN);
    assert.equal(certPayload.address, VALID_ENDPOINT);
    assert.equal(certPayload.expected_fingerprint, VALID_FINGERPRINT.toLowerCase());
    assert.equal(editPayload.endpoint_url, VALID_ENDPOINT);

    const blankRegionToken = buildCreateHostWithTokenPayload({
      name: "node-01",
      region: "   ",
      driver: "lxd",
      isActive: false,
      autoDiscoverCapacity: true,
      capacity: validFormCapacity,
      token: VALID_TOKEN,
      autoAttachImages: false,
    });
    const blankRegionCert = buildCreateHostWithCertPayload({
      name: "node-01",
      region: "",
      driver: "lxd",
      isActive: false,
      autoDiscoverCapacity: true,
      capacity: validFormCapacity,
      endpointUrl: VALID_ENDPOINT,
      expectedFingerprint: VALID_FINGERPRINT,
      autoAttachImages: false,
    });
    const blankRegionEdit = buildUpdateHostPayload(
      {
        name: "node-01",
        region: "   ",
        driver: "lxd",
        isActive: false,
        endpointUrl: VALID_ENDPOINT,
        capacity: validFormCapacity,
        autoAttachImages: false,
      },
      {}
    );

    assert.equal(blankRegionToken.region, undefined);
    assert.equal(blankRegionCert.region, undefined);
    assert.equal(blankRegionEdit.region, undefined);
  });

  it("omits capacity totals when autoDiscoverCapacity is true and converts totalRamGb * 1024 when false", () => {
    const images = { base: "all" as const };

    const autoToken = buildCreateHostWithTokenPayload({
      name: "node-auto",
      region: "",
      driver: "incus",
      isActive: true,
      autoDiscoverCapacity: true,
      capacity: {
        totalCpuCores: 32,
        totalRamGb: 128,
        totalStorageGb: 1000,
        cpuOvercommit: 2,
        ramOvercommit: 1.5,
        diskOvercommit: 1,
      },
      token: VALID_TOKEN,
      autoAttachImages: true,
      images,
    });

    assert.equal("total_cpu_cores" in autoToken, false);
    assert.equal("total_ram_mb" in autoToken, false);
    assert.equal("total_storage_gb" in autoToken, false);
    assert.equal(autoToken.cpu_overcommit, 2);
    assert.equal(autoToken.ram_overcommit, 1.5);
    assert.equal(autoToken.disk_overcommit, 1);
    assert.deepEqual(autoToken.images, images);

    const autoCert = buildCreateHostWithCertPayload({
      name: "node-auto",
      region: "",
      driver: "incus",
      isActive: true,
      autoDiscoverCapacity: true,
      capacity: {
        totalCpuCores: 32,
        totalRamGb: 128,
        totalStorageGb: 1000,
        cpuOvercommit: 2,
        ramOvercommit: 1.5,
        diskOvercommit: 1,
      },
      endpointUrl: VALID_ENDPOINT,
      expectedFingerprint: VALID_FINGERPRINT,
      autoAttachImages: false,
      images,
    });

    assert.equal("total_cpu_cores" in autoCert, false);
    assert.equal("total_ram_mb" in autoCert, false);
    assert.equal("total_storage_gb" in autoCert, false);
    assert.deepEqual(autoCert.images, images);

    const manualToken = buildCreateHostWithTokenPayload({
      name: "node-manual",
      region: "us-east",
      driver: "incus",
      isActive: true,
      autoDiscoverCapacity: false,
      capacity: {
        totalCpuCores: 48,
        totalRamGb: 96,
        totalStorageGb: 2048,
        cpuOvercommit: 4,
        ramOvercommit: 2,
        diskOvercommit: 1.5,
      },
      token: VALID_TOKEN,
      autoAttachImages: true,
      images,
    });

    assert.equal(manualToken.total_cpu_cores, 48);
    assert.equal(manualToken.total_ram_mb, 96 * 1024);
    assert.equal(manualToken.total_storage_gb, 2048);

    const manualCert = buildCreateHostWithCertPayload({
      name: "node-manual",
      region: "us-east",
      driver: "incus",
      isActive: true,
      autoDiscoverCapacity: false,
      capacity: {
        totalCpuCores: 48,
        totalRamGb: 96,
        totalStorageGb: 2048,
        cpuOvercommit: 4,
        ramOvercommit: 2,
        diskOvercommit: 1.5,
      },
      endpointUrl: VALID_ENDPOINT,
      expectedFingerprint: VALID_FINGERPRINT,
      autoAttachImages: false,
      images,
    });

    assert.equal(manualCert.total_cpu_cores, 48);
    assert.equal(manualCert.total_ram_mb, 96 * 1024);
    assert.equal(manualCert.total_storage_gb, 2048);
  });

  it("includes update capacity totals only when they differ from existingHost or fallback defaults", () => {
    const unchangedDefaultsPayload = buildUpdateHostPayload(
      {
        name: "node-edit",
        region: "",
        driver: "fake",
        isActive: true,
        endpointUrl: VALID_ENDPOINT,
        capacity: validFormCapacity,
        autoAttachImages: true,
        token: "",
        tokenModified: false,
        images: undefined,
      },
      {
        total_cpu_cores: 0,
        total_ram_mb: null,
        total_storage_gb: undefined,
      }
    );

    assert.equal("total_cpu_cores" in unchangedDefaultsPayload, false);
    assert.equal("total_ram_mb" in unchangedDefaultsPayload, false);
    assert.equal("total_storage_gb" in unchangedDefaultsPayload, false);
    assert.equal("token" in unchangedDefaultsPayload, false);
    assert.equal("images" in unchangedDefaultsPayload, false);

    const unchangedExistingPayload = buildUpdateHostPayload(
      {
        name: "node-edit",
        region: "eu",
        driver: "incus",
        isActive: false,
        endpointUrl: VALID_ENDPOINT,
        capacity: {
          totalCpuCores: 16,
          totalRamGb: 32,
          totalStorageGb: 500,
          cpuOvercommit: 2,
          ramOvercommit: 1,
          diskOvercommit: 1,
        },
        autoAttachImages: false,
      },
      {
        total_cpu_cores: 16,
        total_ram_mb: 32 * 1024,
        total_storage_gb: 500,
      }
    );

    assert.equal("total_cpu_cores" in unchangedExistingPayload, false);
    assert.equal("total_ram_mb" in unchangedExistingPayload, false);
    assert.equal("total_storage_gb" in unchangedExistingPayload, false);

    const changedPayload = buildUpdateHostPayload(
      {
        name: "node-edit",
        region: "eu",
        driver: "incus",
        isActive: true,
        endpointUrl: VALID_ENDPOINT,
        capacity: {
          totalCpuCores: 32,
          totalRamGb: 64,
          totalStorageGb: 1000,
          cpuOvercommit: 2,
          ramOvercommit: 1.5,
          diskOvercommit: 1.2,
        },
        autoAttachImages: true,
        token: "  new-token-value  ",
        tokenModified: true,
        images: { includeIds: [1, 2] },
      },
      {
        total_cpu_cores: 16,
        total_ram_mb: 32 * 1024,
        total_storage_gb: 500,
      }
    );

    assert.equal(changedPayload.total_cpu_cores, 32);
    assert.equal(changedPayload.total_ram_mb, 64 * 1024);
    assert.equal(changedPayload.total_storage_gb, 1000);
    assert.equal(changedPayload.token, "new-token-value");
    assert.deepEqual(changedPayload.images, { includeIds: [1, 2] });

    const clearedTokenPayload = buildUpdateHostPayload(
      {
        name: "node-edit",
        region: "",
        driver: "incus",
        isActive: true,
        endpointUrl: VALID_ENDPOINT,
        capacity: validFormCapacity,
        autoAttachImages: true,
        token: "   ",
        tokenModified: true,
      },
      {}
    );

    assert.equal(clearedTokenPayload.token, null);
  });
});
