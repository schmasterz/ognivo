"use client";

import { useEffect, useCallback, useRef } from "react";
import { User, VirtualMachine, AdminUserItem } from "@/lib/api";
import {
  getEcho,
  disconnectEcho,
  onEchoConnectionStateChange,
} from "@/lib/echo";

export interface UseDashboardRealtimeParams {
  user: User | null;
  isAdmin: boolean;
  activeTab: string;
  adminUsers: AdminUserItem[];
  detailsVm?: { vm: VirtualMachine; initialTab?: string } | null;
  loadData: () => Promise<void>;
  loadQuotaAndStats: () => Promise<void>;
  refetchNetworking: () => Promise<void>;
  refetchUsers: () => Promise<void>;
  mergeVmUpdate: (
    vmUpdate: Partial<VirtualMachine> & {
      id: number;
      is_delta?: boolean;
      ipv4?: string;
    }
  ) => void;
  onVmRealtimeUpdate?: (
    vm: Partial<VirtualMachine> & {
      id: number;
      is_delta?: boolean;
      ipv4?: string;
    }
  ) => void;
}

export function useDashboardRealtime({
  user,
  isAdmin,
  activeTab,
  adminUsers,
  detailsVm,
  loadData,
  loadQuotaAndStats,
  refetchNetworking,
  refetchUsers,
  mergeVmUpdate,
  onVmRealtimeUpdate,
}: UseDashboardRealtimeParams) {
  // Debounced quota/stats refetch upon WebSocket VM updates (max once per 2s)
  const quotaRefreshTimerRef = useRef<NodeJS.Timeout | null>(null);
  const activeTabRef = useRef(activeTab);
  const refetchNetworkingRef = useRef(refetchNetworking);

  useEffect(() => {
    activeTabRef.current = activeTab;
    refetchNetworkingRef.current = refetchNetworking;
  }, [activeTab, refetchNetworking]);

  useEffect(() => {
    return () => {
      if (quotaRefreshTimerRef.current) {
        clearTimeout(quotaRefreshTimerRef.current);
        quotaRefreshTimerRef.current = null;
      }
    };
  }, []);

  const scheduleDebouncedQuotaRefresh = useCallback(() => {
    if (quotaRefreshTimerRef.current) return;

    quotaRefreshTimerRef.current = setTimeout(() => {
      quotaRefreshTimerRef.current = null;
      void loadQuotaAndStats();
    }, 2000);
  }, [loadQuotaAndStats]);

  // Auto-resync dashboard data upon WebSocket reconnection
  useEffect(() => {
    if (!user) return;
    let wasDisconnected = false;

    const unsubscribe = onEchoConnectionStateChange((state) => {
      if (
        state === "disconnected" ||
        state === "unavailable" ||
        state === "failed"
      ) {
        wasDisconnected = true;
      } else if (state === "connected" && wasDisconnected) {
        wasDisconnected = false;
        void loadData();
      }
    });

    return () => {
      unsubscribe();
    };
  }, [user, loadData]);

  // Poll admin users list when any user is in 'deleting' state
  useEffect(() => {
    const hasDeletingUser = adminUsers.some((u) => u.status === "deleting");
    if (!hasDeletingUser || !isAdmin || activeTab !== "users") {
      return;
    }

    const intervalId = setInterval(() => {
      void refetchUsers();
    }, 2500);

    return () => clearInterval(intervalId);
  }, [adminUsers, isAdmin, activeTab, refetchUsers]);

  // Polling fallback when detailsVm is in 'provisioning' state
  useEffect(() => {
    if (detailsVm?.vm.status?.toLowerCase() !== "provisioning") {
      return;
    }

    const intervalId = setInterval(() => {
      void loadData();
    }, 3000);

    return () => clearInterval(intervalId);
  }, [detailsVm?.vm.status, loadData]);

  // Real-time WebSocket subscriptions for instantaneous VM and resource state updates
  useEffect(() => {
    if (!user) {
      disconnectEcho();
      return;
    }

    const echo = getEcho();
    if (!echo) {
      return;
    }

    const userChannel = echo.private(`users.${user.id}`);
    const adminChannel = isAdmin ? echo.private("admin.workloads") : null;

    const handleVmUpdate = (event: {
      vm: Partial<VirtualMachine> & {
        id: number;
        is_delta?: boolean;
        ipv4?: string;
      };
    }) => {
      if (!event?.vm) return;
      mergeVmUpdate(event.vm);
      onVmRealtimeUpdate?.(event.vm);
      scheduleDebouncedQuotaRefresh();
      if (activeTabRef.current === "networking") {
        void refetchNetworkingRef.current();
      }
    };

    userChannel.listen(".VirtualMachineStatusUpdated", handleVmUpdate);
    userChannel.listen("VirtualMachineStatusUpdated", handleVmUpdate);

    if (adminChannel) {
      adminChannel.listen(".VirtualMachineStatusUpdated", handleVmUpdate);
      adminChannel.listen("VirtualMachineStatusUpdated", handleVmUpdate);
    }

    // Cleanup: stop listeners and explicitly leave channels to prevent connection leaks
    return () => {
      userChannel.stopListening(".VirtualMachineStatusUpdated");
      userChannel.stopListening("VirtualMachineStatusUpdated");
      echo.leave(`users.${user.id}`);

      if (adminChannel) {
        adminChannel.stopListening(".VirtualMachineStatusUpdated");
        adminChannel.stopListening("VirtualMachineStatusUpdated");
        echo.leave("admin.workloads");
      }
    };
  }, [user, isAdmin, mergeVmUpdate, onVmRealtimeUpdate, scheduleDebouncedQuotaRefresh]);
}
