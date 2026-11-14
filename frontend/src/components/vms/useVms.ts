"use client";

import { useState, useCallback, useMemo, useEffect } from "react";
import { api, VirtualMachine, VmNetworkPrimary } from "@/lib/api";
import { useCachedFetch } from "@/lib/hooks/useCachedFetch";

export interface UseVmsParams {
  sessionUserId?: number | null;
  userId?: number | null;
  hostId?: number | null;
  status?: string | null;
  search?: string | null;
  perPage?: number;
  enabled?: boolean;
}

export function useVms({
  sessionUserId = null,
  userId = null,
  hostId = null,
  status = null,
  search = null,
  perPage = 10,
  enabled = true,
}: UseVmsParams = {}) {
  const [page, setPage] = useState<number>(1);
  const [localVms, setLocalVms] = useState<VirtualMachine[]>([]);

  // Reset to page 1 whenever search or filters change
  useEffect(() => {
    queueMicrotask(() => {
      setPage(1);
    });
  }, [sessionUserId, userId, hostId, status, search]);

  const cacheKey = useMemo(() => {
    if (!enabled) return null;
    return `uSession:${sessionUserId ?? "anon"}:vms:u${userId ?? ""}:h${hostId ?? ""}:s${status ?? ""}:q${search ?? ""}:p${page}:pp${perPage}`;
  }, [enabled, sessionUserId, userId, hostId, status, search, page, perPage]);

  const fetcher = useCallback(
    (signal: AbortSignal) =>
      api.getVms(
        {
          user_id: userId,
          host_id: hostId,
          status: status || null,
          search: search?.trim() || null,
          page,
          per_page: perPage,
        },
        { signal }
      ),
    [userId, hostId, status, search, page, perPage]
  );

  const {
    data: vmsRes,
    loading,
    refetch,
    clearCache,
  } = useCachedFetch(cacheKey, fetcher, {
    enabled,
    ttlMs: 10000,
  });

  // Keep local VMs state synchronized with fetched response
  useEffect(() => {
    if (vmsRes?.data) {
      queueMicrotask(() => {
        setLocalVms(vmsRes.data);
      });
    }
  }, [vmsRes]);

  const meta = useMemo(() => {
    return {
      current_page: vmsRes?.meta?.current_page ?? vmsRes?.current_page ?? page,
      last_page: vmsRes?.meta?.last_page ?? vmsRes?.last_page ?? 1,
      total: vmsRes?.meta?.total ?? vmsRes?.total ?? localVms.length,
    };
  }, [vmsRes, page, localVms.length]);

  /**
   * Merges real-time WebSocket VM state changes or compact deltas into local collection.
   */
  const mergeVmUpdate = useCallback(
    (updatedVm: Partial<VirtualMachine> & { id: number; is_delta?: boolean; ipv4?: string }) => {
      setLocalVms((prev) => {
        const index = prev.findIndex((v) => v.id === updatedVm.id);
        if (index !== -1) {
          if (updatedVm.status?.toLowerCase() === "deleted") {
            return prev.filter((v) => v.id !== updatedVm.id);
          }
          const next = [...prev];
          const ipv4Val =
            updatedVm.ipv4_address ??
            updatedVm.ipv4 ??
            next[index].ipv4_address;

          const networkStatusVal =
            updatedVm.network_status ??
            next[index].network_status;

          const updatedNetwork = next[index].network
            ? {
                ...next[index].network,
                primary: {
                  ...next[index].network!.primary,
                  address: ipv4Val,
                  status: (networkStatusVal as VmNetworkPrimary["status"]) ?? null,
                },
              }
            : next[index].network;

          next[index] = {
            ...next[index],
            ...updatedVm,
            ipv4_address: ipv4Val,
            network_status: networkStatusVal,
            network: updatedNetwork,
          };
          return next;
        } else if (!updatedVm.is_delta && page === 1 && updatedVm.status?.toLowerCase() !== "deleted") {
          return [updatedVm as VirtualMachine, ...prev.slice(0, perPage - 1)];
        }
        return prev;
      });
    },
    [page, perPage]
  );

  return {
    vms: localVms,
    loading,
    page,
    setPage,
    meta,
    refetch,
    clearCache,
    mergeVmUpdate,
    setVms: setLocalVms,
  };
}
