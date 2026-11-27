"use client";

import {
  useState,
  useCallback,
  useEffect,
  useRef,
  useMemo,
  type Dispatch,
  type SetStateAction,
} from "react";
import { usePathname, useRouter } from "next/navigation";
import { VirtualMachine, VmNetworkPrimary } from "@/lib/api";
import {
  useDashboardModalActions,
  useDashboardModalSelector,
} from "./useDashboardModals";

export type TabType =
  | "vms"
  | "plans"
  | "snapshots"
  | "networking"
  | "users"
  | "activity"
  | "audit"
  | "nodes"
  | "adminPlans"
  | "adminPlanChanges";

export function resolveTabFromPathname(
  pathname: string,
  isAdmin: boolean
): TabType {
  switch (pathname) {
    case "/plans":
      return "plans";
    case "/snapshots":
      return "snapshots";
    case "/networking":
      return "networking";
    case "/users":
      return isAdmin ? "users" : "vms";
    case "/activity":
      return isAdmin ? "audit" : "activity";
    case "/audit":
      return isAdmin ? "audit" : "vms";
    case "/nodes":
      return isAdmin ? "nodes" : "vms";
    case "/admin/plans":
      return isAdmin ? "adminPlans" : "plans";
    case "/admin/plan-changes":
      return isAdmin ? "adminPlanChanges" : "vms";
    case "/instances":
    case "/workloads":
    case "/workload":
    case "/":
    default:
      return "vms";
  }
}

export interface UseDashboardNavigationParams {
  isAdmin: boolean;
  authLoading: boolean;
  vms: VirtualMachine[];
  setSearchQuery: (query: string) => void;
}

export function useDashboardNavigation({
  isAdmin,
  authLoading,
  vms,
  setSearchQuery,
}: UseDashboardNavigationParams) {
  const pathname = usePathname();
  const router = useRouter();

  // Active navigation section derived reactively from Next.js pathname
  const activeTab = useMemo(
    () => resolveTabFromPathname(pathname || "/", authLoading ? true : isAdmin),
    [pathname, authLoading, isAdmin]
  );

  const [detailsVm, setDetailsVm] = useState<{
    vm: VirtualMachine;
    initialTab?: string;
  } | null>(null);
  const consoleVm = useDashboardModalSelector((s) => s.consoleVm);
  const { setConsoleVm } = useDashboardModalActions();
  const handledDeepLinkRef = useRef<string | null>(null);

  const handleSetDetailsVm = useCallback(
    (target: { vm: VirtualMachine; initialTab?: string } | null) => {
      setDetailsVm(target);
      if (typeof window === "undefined") return;
      const params = new URLSearchParams(window.location.search);
      if (target) {
        handledDeepLinkRef.current = `${target.vm.id}:${target.initialTab ?? ""}`;
        params.set("vm", String(target.vm.id));
        if (target.initialTab) {
          params.set("vm_tab", target.initialTab);
        } else {
          params.delete("vm_tab");
        }
        const qs = params.toString();
        window.history.replaceState(
          null,
          "",
          qs ? `${window.location.pathname}?${qs}` : window.location.pathname
        );
      } else {
        handledDeepLinkRef.current = null;
        if (params.has("vm") || params.has("vm_tab")) {
          params.delete("vm");
          params.delete("vm_tab");
          const qs = params.toString();
          window.history.replaceState(
            null,
            "",
            qs ? `${window.location.pathname}?${qs}` : window.location.pathname
          );
        }
      }
    },
    []
  );

  // Resolve ?vm=<id>&vm_tab=<tab> deep link once VMs are loaded
  useEffect(() => {
    if (typeof window === "undefined" || vms.length === 0) return;

    const params = new URLSearchParams(window.location.search);
    const vmParam = params.get("vm");
    if (!vmParam) {
      handledDeepLinkRef.current = null;
      return;
    }

    const vmTabParam = params.get("vm_tab") || undefined;
    const deepLinkKey = `${vmParam}:${vmTabParam ?? ""}`;
    if (handledDeepLinkRef.current === deepLinkKey) return;

    const matchedVm = vms.find(
      (v) => String(v.id) === vmParam || v.name === vmParam
    );
    if (!matchedVm) return;

    const timer = setTimeout(() => {
      handledDeepLinkRef.current = deepLinkKey;
      setDetailsVm({ vm: matchedVm, initialTab: vmTabParam });
    }, 0);
    return () => clearTimeout(timer);
  }, [vms, pathname]);

  const detailsVmId = detailsVm?.vm.id ?? null;

  // Synchronize detailsVm with updated VM in vms list (e.g. from refetch, poll, or mergeVmUpdate)
  useEffect(() => {
    if (detailsVmId === null) return;
    const matched = vms.find((v) => v.id === detailsVmId);
    if (!matched) return;

    const timer = setTimeout(() => {
      setDetailsVm((prev) => {
        if (!prev || prev.vm.id !== detailsVmId || prev.vm === matched) {
          return prev;
        }
        return { ...prev, vm: matched };
      });
    }, 0);
    return () => clearTimeout(timer);
  }, [vms, detailsVmId]);

  const consoleVmId = consoleVm?.id ?? null;

  // Synchronize consoleVm with updated VM in vms list (e.g. from refetch, poll, or mergeVmUpdate)
  useEffect(() => {
    if (consoleVmId === null) return;
    const matched = vms.find((v) => v.id === consoleVmId);
    if (!matched) return;

    const timer = setTimeout(() => {
      setConsoleVm((prev) => {
        if (!prev || prev.id !== consoleVmId || prev === matched) {
          return prev;
        }
        return matched;
      });
    }, 0);
    return () => clearTimeout(timer);
  }, [vms, consoleVmId, setConsoleVm]);

  const mergeRealtimeVm = useCallback(
    (
      prevVm: VirtualMachine,
      updatedVm: Partial<VirtualMachine> & { id: number; ipv4?: string }
    ): VirtualMachine => {
      const ipv4Val =
        updatedVm.ipv4_address ?? updatedVm.ipv4 ?? prevVm.ipv4_address;
      const networkStatusVal =
        updatedVm.network_status ?? prevVm.network_status;
      const updatedNetwork = prevVm.network
        ? {
            ...prevVm.network,
            primary: {
              ...prevVm.network.primary,
              address: ipv4Val,
              status: (networkStatusVal as VmNetworkPrimary["status"]) ?? null,
            },
          }
        : prevVm.network;

      return {
        ...prevVm,
        ...updatedVm,
        ipv4_address: ipv4Val,
        network_status: networkStatusVal,
        network: updatedNetwork,
      };
    },
    []
  );

  const handleVmRealtimeUpdate = useCallback(
    (updatedVm: Partial<VirtualMachine> & { id: number; ipv4?: string }) => {
      setDetailsVm((prev) => {
        if (!prev || prev.vm.id !== updatedVm.id) return prev;
        return {
          ...prev,
          vm: mergeRealtimeVm(prev.vm, updatedVm),
        };
      });

      setConsoleVm((prev) => {
        if (!prev || prev.id !== updatedVm.id) return prev;
        return mergeRealtimeVm(prev, updatedVm);
      });
    },
    [mergeRealtimeVm, setConsoleVm]
  );

  const handleTabChange = useCallback(
    (tab: TabType) => {
      const pathMap: Record<TabType, string> = {
        vms: "/instances",
        plans: "/plans",
        snapshots: "/snapshots",
        networking: "/networking",
        users: "/users",
        activity: "/activity",
        audit: "/audit",
        nodes: "/nodes",
        adminPlans: "/admin/plans",
        adminPlanChanges: "/admin/plan-changes",
      };
      if (pathMap[tab]) {
        router.push(pathMap[tab]);
      }
    },
    [router]
  );

  const setActiveTab: Dispatch<SetStateAction<TabType>> = useCallback(
    (action) => {
      const nextTab =
        typeof action === "function" ? action(activeTab) : action;
      handleTabChange(nextTab);
    },
    [activeTab, handleTabChange]
  );

  const handleAuditNavigate = useCallback(
    (section: "vms" | "networking" | "users", id?: string | null) => {
      handleTabChange(section);
      if (section === "vms" && id) {
        setSearchQuery(id);
      }
    },
    [handleTabChange, setSearchQuery]
  );

  return {
    activeTab,
    setActiveTab,
    detailsVm,
    handleSetDetailsVm,
    handleVmRealtimeUpdate,
    handleTabChange,
    handleAuditNavigate,
  };
}
