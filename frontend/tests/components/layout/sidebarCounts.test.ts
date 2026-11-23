import { describe, it } from "node:test";
import assert from "node:assert/strict";

export interface SidebarCountInput {
  vmsTotal: number;
  adminStats: {
    total_vms?: number;
    total_snapshots?: number;
    total_ips?: number;
    total_pools?: number;
    total_users?: number;
  } | null;
  quotaData: {
    usage?: {
      snapshots?: number;
      ips?: number;
    };
  } | null;
  ipsTotal: number;
  poolsTotal: number;
  usersTotal: number;
  quotaGroupsLength: number;
  nodesLength: number;
}

/**
 * Models the sidebar count resolution logic used in DashboardShell.
 */
export function resolveSidebarCounts(input: SidebarCountInput) {
  return {
    vms: input.vmsTotal,
    snapshots:
      input.adminStats?.total_snapshots ??
      input.quotaData?.usage?.snapshots ??
      0,
    ips:
      input.adminStats?.total_ips ??
      input.quotaData?.usage?.ips ??
      input.ipsTotal,
    pools: input.adminStats?.total_pools ?? input.poolsTotal,
    users: input.adminStats?.total_users ?? input.usersTotal,
    quotaGroups: input.quotaGroupsLength,
    nodes: input.nodesLength,
  };
}

/**
 * Models the networking badge count selection used in Sidebar.
 */
export function resolveNetworkingBadgeCount(isAdmin: boolean, counts: { pools: number; ips: number }) {
  return isAdmin ? counts.pools : counts.ips;
}

describe("resolveSidebarCounts", () => {
  it("resolves ips, users, pools, and snapshots from adminStats when sub-tabs are uninitialized", () => {
    const counts = resolveSidebarCounts({
      vmsTotal: 14,
      adminStats: {
        total_vms: 20,
        total_snapshots: 9,
        total_ips: 32,
        total_pools: 4,
        total_users: 11,
      },
      quotaData: {
        usage: {
          snapshots: 2,
          ips: 3,
        },
      },
      ipsTotal: 0,
      poolsTotal: 0,
      usersTotal: 0,
      quotaGroupsLength: 5,
      nodesLength: 3,
    });

    assert.deepEqual(counts, {
      vms: 14,
      snapshots: 9,
      ips: 32,
      pools: 4,
      users: 11,
      quotaGroups: 5,
      nodes: 3,
    });
  });

  it("resolves ips and snapshots from quotaData.usage for tenant sessions when adminStats is null", () => {
    const counts = resolveSidebarCounts({
      vmsTotal: 3,
      adminStats: null,
      quotaData: {
        usage: {
          snapshots: 6,
          ips: 4,
        },
      },
      ipsTotal: 0,
      poolsTotal: 0,
      usersTotal: 0,
      quotaGroupsLength: 0,
      nodesLength: 0,
    });

    assert.equal(counts.vms, 3);
    assert.equal(counts.snapshots, 6);
    assert.equal(counts.ips, 4);
    assert.equal(counts.pools, 0);
    assert.equal(counts.users, 0);
  });

  it("falls back to sub-tab totals and 0 when both adminStats and quotaData are null or missing fields", () => {
    const withNullSources = resolveSidebarCounts({
      vmsTotal: 2,
      adminStats: null,
      quotaData: null,
      ipsTotal: 7,
      poolsTotal: 3,
      usersTotal: 5,
      quotaGroupsLength: 1,
      nodesLength: 2,
    });

    assert.deepEqual(withNullSources, {
      vms: 2,
      snapshots: 0,
      ips: 7,
      pools: 3,
      users: 5,
      quotaGroups: 1,
      nodes: 2,
    });

    const withUndefinedUsageFields = resolveSidebarCounts({
      vmsTotal: 0,
      adminStats: {},
      quotaData: { usage: {} },
      ipsTotal: 0,
      poolsTotal: 0,
      usersTotal: 0,
      quotaGroupsLength: 0,
      nodesLength: 0,
    });

    assert.deepEqual(withUndefinedUsageFields, {
      vms: 0,
      snapshots: 0,
      ips: 0,
      pools: 0,
      users: 0,
      quotaGroups: 0,
      nodes: 0,
    });
  });

  it("respects priority chain when sub-tab metadata has populated values", () => {
    const adminWithLoadedTabs = resolveSidebarCounts({
      vmsTotal: 8,
      adminStats: {
        total_snapshots: 12,
        total_ips: 40,
        total_pools: 6,
        total_users: 15,
      },
      quotaData: {
        usage: {
          snapshots: 1,
          ips: 2,
        },
      },
      ipsTotal: 25,
      poolsTotal: 5,
      usersTotal: 10,
      quotaGroupsLength: 4,
      nodesLength: 2,
    });

    assert.equal(adminWithLoadedTabs.ips, 40);
    assert.equal(adminWithLoadedTabs.pools, 6);
    assert.equal(adminWithLoadedTabs.users, 15);
    assert.equal(adminWithLoadedTabs.snapshots, 12);

    const tenantWithQuotaAndLoadedIps = resolveSidebarCounts({
      vmsTotal: 3,
      adminStats: null,
      quotaData: {
        usage: {
          snapshots: 4,
          ips: 5,
        },
      },
      ipsTotal: 10,
      poolsTotal: 2,
      usersTotal: 1,
      quotaGroupsLength: 0,
      nodesLength: 0,
    });

    assert.equal(tenantWithQuotaAndLoadedIps.ips, 5);
    assert.equal(tenantWithQuotaAndLoadedIps.pools, 2);
    assert.equal(tenantWithQuotaAndLoadedIps.users, 1);
  });

  it("maps nodes and quotaGroups directly from array lengths", () => {
    const quotaGroups = [{ id: 1 }, { id: 2 }, { id: 3 }];
    const hosts = [{ id: "node-1" }, { id: "node-2" }, { id: "node-3" }, { id: "node-4" }];

    const counts = resolveSidebarCounts({
      vmsTotal: 0,
      adminStats: null,
      quotaData: null,
      ipsTotal: 0,
      poolsTotal: 0,
      usersTotal: 0,
      quotaGroupsLength: quotaGroups.length,
      nodesLength: hosts.length,
    });

    assert.equal(counts.quotaGroups, 3);
    assert.equal(counts.nodes, 4);
  });
});

describe("resolveNetworkingBadgeCount", () => {
  it("returns counts.pools when isAdmin is true", () => {
    assert.equal(resolveNetworkingBadgeCount(true, { pools: 4, ips: 32 }), 4);
    assert.equal(resolveNetworkingBadgeCount(true, { pools: 4, ips: 0 }), 4);
  });

  it("returns counts.ips when isAdmin is false", () => {
    assert.equal(resolveNetworkingBadgeCount(false, { pools: 4, ips: 3 }), 3);
    assert.equal(resolveNetworkingBadgeCount(false, { pools: 0, ips: 3 }), 3);
  });
});
