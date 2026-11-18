"use client";

import { useState, useCallback, useEffect, useRef } from "react";
import { usePathname, useRouter } from "next/navigation";
import { VirtualMachine } from "@/lib/api";

export type TabType =
  | "vms"
  | "snapshots"
  | "networking"
  | "users"
  | "activity"
  | "audit"
  | "nodes";

export function resolveTabFromPathname(
  pathname: string,
  isAdmin: boolean
): TabType {
  switch (pathname) {
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

  // Active navigation section initialized synchronously from current route
  const [activeTab, setActiveTab] = useState<TabType>(() => {
    const initialPath =
      pathname ||
      (typeof window !== "undefined" ? window.location.pathname : "/");
    return resolveTabFromPathname(initialPath, authLoading ? true : isAdmin);
  });

  const [detailsVm, setDetailsVm] = useState<{
    vm: VirtualMachine;
    initialTab?: string;
  } | null>(null);
  const pendingVmDeepLinkRef = useRef<{ vmId: string; vmTab?: string } | null>(
    null
  );

  const handleSetDetailsVm = useCallback(
    (target: { vm: VirtualMachine; initialTab?: string } | null) => {
      setDetailsVm(target);
      if (typeof window === "undefined") return;
      const params = new URLSearchParams(window.location.search);
      if (target) {
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
      } else if (params.has("vm") || params.has("vm_tab")) {
        params.delete("vm");
        params.delete("vm_tab");
        const qs = params.toString();
        window.history.replaceState(
          null,
          "",
          qs ? `${window.location.pathname}?${qs}` : window.location.pathname
        );
      }
    },
    []
  );

  // Synchronize activeTab and VM deep-link query params with Next.js pathname and browser navigation
  useEffect(() => {
    const handleLocationChange = () => {
      const currentPath =
        pathname ||
        (typeof window !== "undefined" ? window.location.pathname : "/");
      setActiveTab(
        resolveTabFromPathname(currentPath, authLoading ? true : isAdmin)
      );

      if (typeof window !== "undefined") {
        const params = new URLSearchParams(window.location.search);
        const vmParam = params.get("vm");
        const vmTabParam = params.get("vm_tab") || undefined;
        if (vmParam) {
          pendingVmDeepLinkRef.current = { vmId: vmParam, vmTab: vmTabParam };
        }
      }
    };

    handleLocationChange();
    window.addEventListener("popstate", handleLocationChange);
    return () => window.removeEventListener("popstate", handleLocationChange);
  }, [pathname, isAdmin, authLoading]);

  // Resolve pending ?vm=<id>&vm_tab=<tab> deep link once VMs are loaded
  useEffect(() => {
    const pending = pendingVmDeepLinkRef.current;
    if (!pending || vms.length === 0) return;

    const matchedVm = vms.find(
      (v) => String(v.id) === pending.vmId || v.name === pending.vmId
    );
    if (matchedVm) {
      pendingVmDeepLinkRef.current = null;
      const timer = setTimeout(() => {
        setDetailsVm({ vm: matchedVm, initialTab: pending.vmTab });
      }, 0);
      return () => clearTimeout(timer);
    }
  }, [vms]);

  const handleTabChange = useCallback(
    (tab: TabType) => {
      setActiveTab(tab);
      const pathMap: Record<TabType, string> = {
        vms: "/instances",
        snapshots: "/snapshots",
        networking: "/networking",
        users: "/users",
        activity: "/activity",
        audit: "/audit",
        nodes: "/nodes",
      };
      if (pathMap[tab]) {
        router.push(pathMap[tab]);
      }
    },
    [router]
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
    handleTabChange,
    handleAuditNavigate,
  };
}
