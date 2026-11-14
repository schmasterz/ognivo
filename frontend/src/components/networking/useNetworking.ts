"use client";

import { useState, useCallback, useMemo, useEffect } from "react";
import { api, IpAllocation, IpPool } from "@/lib/api";
import { useCachedFetch } from "@/lib/hooks/useCachedFetch";

export interface UseNetworkingParams {
  sessionUserId?: number | null;
  userId?: number | null;
  hostId?: number | null;
  status?: string | null;
  search?: string | null;
  perPage?: number;
  enabled?: boolean;
  isAdmin?: boolean;
}

export function useNetworking({
  sessionUserId = null,
  userId = null,
  hostId = null,
  status = null,
  search = null,
  perPage = 10,
  enabled = true,
  isAdmin = false,
}: UseNetworkingParams = {}) {
  const [ipsPage, setIpsPage] = useState<number>(1);
  const [poolsPage, setPoolsPage] = useState<number>(1);
  const [poolsPerPage, setPoolsPerPageState] = useState<number>(perPage);

  const setPoolsPerPage = useCallback((newPerPage: number) => {
    setPoolsPerPageState(newPerPage);
    setPoolsPage(1);
  }, []);

  // Reset pagination to page 1 on filter changes
  useEffect(() => {
    queueMicrotask(() => {
      setIpsPage(1);
      setPoolsPage(1);
    });
  }, [sessionUserId, userId, hostId, status, search]);

  const poolsEnabled = enabled && isAdmin;

  const ipsCacheKey = useMemo(() => {
    if (!enabled) return null;
    return `uSession:${sessionUserId ?? "anon"}:ips:u${userId ?? ""}:h${hostId ?? ""}:s${status ?? ""}:q${search ?? ""}:p${ipsPage}:pp${perPage}`;
  }, [enabled, sessionUserId, userId, hostId, status, search, ipsPage, perPage]);

  const poolsCacheKey = useMemo(() => {
    if (!poolsEnabled) return null;
    return `uSession:${sessionUserId ?? "anon"}:pools:h${hostId ?? ""}:p${poolsPage}:pp${poolsPerPage}`;
  }, [poolsEnabled, sessionUserId, hostId, poolsPage, poolsPerPage]);

  const ipsFetcher = useCallback(
    (signal: AbortSignal) =>
      api.getIps(
        {
          user_id: userId,
          host_id: hostId,
          status: status || null,
          search: search?.trim() || null,
          page: ipsPage,
          per_page: perPage,
        },
        { signal }
      ),
    [userId, hostId, status, search, ipsPage, perPage]
  );

  const poolsFetcher = useCallback(
    (signal: AbortSignal) =>
      api.getIpPools(
        {
          host_id: hostId,
          page: poolsPage,
          per_page: poolsPerPage,
        },
        { signal }
      ),
    [hostId, poolsPage, poolsPerPage]
  );

  const {
    data: ipsRes,
    loading: loadingIps,
    refetch: refetchIps,
    clearCache: clearIpsCache,
  } = useCachedFetch(ipsCacheKey, ipsFetcher, {
    enabled,
    ttlMs: 10000,
  });

  const {
    data: poolsRes,
    loading: loadingPools,
    refetch: refetchPools,
    clearCache: clearPoolsCache,
  } = useCachedFetch(poolsCacheKey, poolsFetcher, {
    enabled: poolsEnabled,
    ttlMs: 15000,
  });

  const ips: IpAllocation[] = useMemo(() => ipsRes?.data ?? [], [ipsRes]);
  const pools: IpPool[] = useMemo(() => poolsRes?.data ?? [], [poolsRes]);

  const ipsMeta = useMemo(() => {
    return {
      current_page: ipsRes?.meta?.current_page ?? ipsRes?.current_page ?? ipsPage,
      last_page: ipsRes?.meta?.last_page ?? ipsRes?.last_page ?? 1,
      total: ipsRes?.meta?.total ?? ipsRes?.total ?? ips.length,
    };
  }, [ipsRes, ipsPage, ips.length]);

  const poolsMeta = useMemo(() => {
    return {
      current_page: poolsRes?.meta?.current_page ?? poolsRes?.current_page ?? poolsPage,
      last_page: poolsRes?.meta?.last_page ?? poolsRes?.last_page ?? 1,
      total: poolsRes?.meta?.total ?? poolsRes?.total ?? pools.length,
    };
  }, [poolsRes, poolsPage, pools.length]);

  const refetchAll = useCallback(async () => {
    clearIpsCache();
    clearPoolsCache();
    await Promise.all([refetchIps(), refetchPools()]);
  }, [clearIpsCache, clearPoolsCache, refetchIps, refetchPools]);

  // Periodic polling when any IP is actively in cooling state
  const hasCoolingIps = useMemo(() => {
    return ips.some(
      (ip) =>
        ip.status === "cooling" &&
        (ip.cooldown_remaining_seconds ?? 0) > 0
    );
  }, [ips]);

  useEffect(() => {
    if (!hasCoolingIps || !enabled) return;

    const interval = setInterval(() => {
      refetchAll();
    }, 5000);

    return () => clearInterval(interval);
  }, [hasCoolingIps, enabled, refetchAll]);

  const updatePool = useCallback(
    async (
      poolId: number,
      data: Parameters<typeof api.adminUpdateIpPool>[1]
    ) => {
      const res = await api.adminUpdateIpPool(poolId, data);
      clearPoolsCache();
      await refetchPools();
      return res;
    },
    [clearPoolsCache, refetchPools]
  );

  const preflightPool = useCallback(async (poolId: number) => {
    return api.adminPreflightIpPool(poolId);
  }, []);

  return {
    ips,
    loadingIps,
    ipsPage,
    setIpsPage,
    ipsMeta,
    pools,
    loadingPools,
    poolsPage,
    setPoolsPage,
    poolsPerPage,
    setPoolsPerPage,
    poolsMeta,
    refetchIps,
    refetchPools,
    refetchAll,
    updatePool,
    preflightPool,
  };
}
