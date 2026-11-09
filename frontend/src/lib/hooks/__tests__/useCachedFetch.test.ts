import { describe, it, beforeEach } from "node:test";
import assert from "node:assert/strict";
import {
  clearAllCachedFetchEntries,
  setCachedFetchEntry,
  getCachedFetchEntry,
  getValidCachedEntry,
} from "../useCachedFetch.ts";

describe("useCachedFetch Cache Store & Session Isolation Tests", () => {
  beforeEach(() => {
    clearAllCachedFetchEntries();
  });

  describe("1. clearAllCachedFetchEntries (Full & Prefix-Based Clear)", () => {
    it("clears all entries when called without a prefix", () => {
      setCachedFetchEntry("uSession:1:vms:u:h:s:q:p1:pp10", [{ id: 101 }]);
      setCachedFetchEntry("uSession:1:ips:u:h:s:q:p1:pp10", [{ id: 201 }]);
      setCachedFetchEntry("uSession:2:vms:u:h:s:q:p1:pp10", [{ id: 102 }]);

      assert.ok(getCachedFetchEntry("uSession:1:vms:u:h:s:q:p1:pp10"));
      assert.ok(getCachedFetchEntry("uSession:1:ips:u:h:s:q:p1:pp10"));
      assert.ok(getCachedFetchEntry("uSession:2:vms:u:h:s:q:p1:pp10"));

      clearAllCachedFetchEntries();

      assert.equal(getCachedFetchEntry("uSession:1:vms:u:h:s:q:p1:pp10"), undefined);
      assert.equal(getCachedFetchEntry("uSession:1:ips:u:h:s:q:p1:pp10"), undefined);
      assert.equal(getCachedFetchEntry("uSession:2:vms:u:h:s:q:p1:pp10"), undefined);
    });

    it("deletes only keys matching the provided prefix", () => {
      setCachedFetchEntry("uSession:1:vms:u:h:s:q:p1:pp10", [{ id: 101 }]);
      setCachedFetchEntry("uSession:1:ips:u:h:s:q:p1:pp10", [{ id: 201 }]);
      setCachedFetchEntry("uSession:2:vms:u:h:s:q:p1:pp10", [{ id: 102 }]);

      clearAllCachedFetchEntries("uSession:1:");

      assert.equal(getCachedFetchEntry("uSession:1:vms:u:h:s:q:p1:pp10"), undefined);
      assert.equal(getCachedFetchEntry("uSession:1:ips:u:h:s:q:p1:pp10"), undefined);
      assert.deepEqual(
        getCachedFetchEntry("uSession:2:vms:u:h:s:q:p1:pp10")?.data,
        [{ id: 102 }]
      );
    });
  });

  describe("2. TTL Validation & Manual Refetch Force Bypass", () => {
    it("returns cached entry within TTL when force is false", () => {
      const key = "uSession:1:vms:u:h:s:q:p1:pp10";
      setCachedFetchEntry(key, { items: ["vm-a"] }, Date.now());

      const valid = getValidCachedEntry<{ items: string[] }>(key, 10000, false);
      assert.ok(valid, "Should return valid cache entry within TTL");
      assert.deepEqual(valid.data, { items: ["vm-a"] });
    });

    it("bypasses TTL cache when force is true (manual refetch)", () => {
      const key = "uSession:1:vms:u:h:s:q:p1:pp10";
      setCachedFetchEntry(key, { items: ["stale-vm"] }, Date.now());

      const bypassed = getValidCachedEntry<{ items: string[] }>(key, 10000, true);
      assert.equal(
        bypassed,
        undefined,
        "Manual refetch (force = true) must bypass TTL cache even when entry is fresh"
      );
    });

    it("returns undefined when cached entry has expired past ttlMs", () => {
      const key = "uSession:1:vms:u:h:s:q:p1:pp10";
      const expiredTimestamp = Date.now() - 20000;
      setCachedFetchEntry(key, { items: ["expired-vm"] }, expiredTimestamp);

      const expired = getValidCachedEntry<{ items: string[] }>(key, 10000, false);
      assert.equal(expired, undefined, "Expired entry must not be returned");
    });
  });

  describe("3. Cross-User Session & Impersonation Cache Key Isolation", () => {
    it("partitions VM, IP, and Admin User cache keys by sessionUserId", () => {
      const buildVmKey = (sessionUserId: number | null) =>
        `uSession:${sessionUserId ?? "anon"}:vms:u:h:s:q:p1:pp10`;
      const buildIpKey = (sessionUserId: number | null) =>
        `uSession:${sessionUserId ?? "anon"}:ips:u:h:s:q:p1:pp10`;

      const adminVmKey = buildVmKey(1);
      const tenantVmKey = buildVmKey(42);
      const adminIpKey = buildIpKey(1);
      const tenantIpKey = buildIpKey(42);

      assert.notEqual(adminVmKey, tenantVmKey);
      assert.notEqual(adminIpKey, tenantIpKey);

      setCachedFetchEntry(adminVmKey, [{ id: 1, name: "admin-vm" }]);
      setCachedFetchEntry(tenantVmKey, [{ id: 42, name: "tenant-vm" }]);

      assert.deepEqual(getValidCachedEntry(adminVmKey, 10000, false)?.data, [
        { id: 1, name: "admin-vm" },
      ]);
      assert.deepEqual(getValidCachedEntry(tenantVmKey, 10000, false)?.data, [
        { id: 42, name: "tenant-vm" },
      ]);

      // Simulate impersonation stop or logout clearing all session cache
      clearAllCachedFetchEntries();
      assert.equal(getValidCachedEntry(adminVmKey, 10000, false), undefined);
      assert.equal(getValidCachedEntry(tenantVmKey, 10000, false), undefined);
    });
  });
});
