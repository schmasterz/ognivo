"use client";

import React, { createContext, useContext, useCallback, useMemo } from "react";
import {
  VirtualMachine,
  IpAllocation,
  IpPool,
  AdminUserItem,
  AdminStats,
  QuotaGroup,
  QuotaResponse,
  HostNode,
} from "@/lib/api";
import { useVms } from "@/components/vms/useVms";
import { useNetworking } from "@/components/networking/useNetworking";
import { useUsers } from "@/components/users/useUsers";
import { useConfirmDialog, ConfirmDialogState } from "@/lib/hooks/useConfirmDialog";
import { useDashboardConfirmActions } from "./useDashboardConfirmActions";
import { useDashboardData } from "./useDashboardData";
import { useDashboardRealtime } from "./useDashboardRealtime";
import { TabType, useDashboardNavigation } from "./useDashboardNavigation";
import {
  useDashboardModals,
  useDashboardModalActions,
  createDashboardModalsHandle,
} from "./useDashboardModals";
import { useI18n } from "@/lib/i18n/I18nContext";
import { useToast } from "@/lib/toast/ToastContext";
import { useAuth } from "@/lib/auth/AuthContext";
import { useGlobalFilters } from "@/lib/filters/GlobalFilterContext";

export interface PageMetaSummary {
  current_page: number;
  last_page: number;
  total: number;
}

export interface DashboardContextValue {
  // Active Route Tab & Navigation
  activeTab: TabType;
  setActiveTab: React.Dispatch<React.SetStateAction<TabType>>;
  detailsVm: { vm: VirtualMachine; initialTab?: string } | null;
  handleSetDetailsVm: (target: { vm: VirtualMachine; initialTab?: string } | null) => void;
  handleTabChange: (tab: TabType) => void;
  handleAuditNavigate: (section: "vms" | "networking" | "users", id?: string | null) => void;
  handlePerPageChange: (newPerPage: number) => void;

  // VMs Domain State
  vms: VirtualMachine[];
  loadingVms: boolean;
  vmsPage: number;
  setVmsPage: (page: number) => void;
  vmsMeta: PageMetaSummary;
  refetchVms: () => Promise<void>;

  // Networking Domain State
  ips: IpAllocation[];
  loadingIps: boolean;
  setIpsPage: (page: number) => void;
  ipsMeta: PageMetaSummary;
  pools: IpPool[];
  setPoolsPage: (page: number) => void;
  poolsPerPage: number;
  setPoolsPerPage: (perPage: number) => void;
  poolsMeta: PageMetaSummary;
  refetchPools: () => Promise<void>;
  refetchNetworking: () => Promise<void>;

  // Users Domain State
  adminUsers: AdminUserItem[];
  setUsersPage: (page: number) => void;
  usersMeta: PageMetaSummary;
  refetchUsers: () => Promise<void>;
  handleToggleRegistration: (nextEnabled: boolean) => Promise<void>;

  // Static & Quota/Stats Data
  adminStats: AdminStats | null;
  quotaGroups: QuotaGroup[];
  quotaData: QuotaResponse | null;
  setQuotaData: React.Dispatch<React.SetStateAction<QuotaResponse | null>>;
  hosts: HostNode[];
  setHosts: React.Dispatch<React.SetStateAction<HostNode[]>>;
  refreshing: boolean;
  loadStaticData: () => Promise<void>;
  loadQuotaAndStats: () => Promise<void>;
  loadData: () => Promise<void>;

  // Modals & Confirmation Actions
  modals: ReturnType<typeof useDashboardModals>;
  confirmDialog: ConfirmDialogState;
  setConfirmDialog: React.Dispatch<React.SetStateAction<ConfirmDialogState>>;
  handleRequestVmAction: (
    vm: VirtualMachine,
    action: "start" | "stop" | "restart" | "suspend" | "delete"
  ) => void;
  handleRequestReleaseIp: (ip: IpAllocation) => void;
  handleRequestDeleteUser: (userId: number, name: string) => void;
  handleRequestToggleUserStatus: (
    targetUser: AdminUserItem,
    nextStatus: "active" | "suspended"
  ) => void;
  handleRequestImpersonateUser: (targetUser: AdminUserItem) => void;
  handleStopImpersonation: () => Promise<void>;
  handleRequestDeletePool: (poolId: number, name: string) => void;
  handleRequestDeleteQuotaGroup: (group: QuotaGroup) => void;
  handleRequestDeleteHost: (hostId: number, hostName: string) => void;
}

const DashboardContext = createContext<DashboardContextValue | null>(null);

export function DashboardProvider({ children }: { children: React.ReactNode }) {
  const { t } = useI18n();
  const { toast } = useToast();

  const {
    user,
    isAdmin,
    authLoading,
    registrationEnabled,
    setRegistrationEnabled,
    setSystemSettings,
    startImpersonation,
    stopImpersonation,
  } = useAuth();

  const {
    selectedUserId,
    selectedHostId,
    selectedStatus,
    setSearchQuery,
    debouncedSearchQuery,
    perPage,
    setPerPage,
  } = useGlobalFilters();

  const {
    vms,
    loading: loadingVms,
    page: vmsPage,
    setPage: setVmsPage,
    meta: vmsMeta,
    refetch: refetchVms,
    mergeVmUpdate,
  } = useVms({
    sessionUserId: user?.id ?? null,
    userId: selectedUserId,
    hostId: selectedHostId,
    status: selectedStatus || null,
    search: debouncedSearchQuery.trim() || null,
    perPage,
    enabled: !!user,
  });

  const {
    activeTab,
    setActiveTab,
    detailsVm,
    handleSetDetailsVm,
    handleTabChange,
    handleAuditNavigate,
  } = useDashboardNavigation({
    isAdmin,
    authLoading,
    vms,
    setSearchQuery,
  });

  const {
    ips,
    loadingIps,
    setIpsPage,
    ipsMeta,
    pools,
    setPoolsPage,
    poolsPerPage,
    setPoolsPerPage,
    poolsMeta,
    refetchPools,
    refetchAll: refetchNetworking,
  } = useNetworking({
    sessionUserId: user?.id ?? null,
    userId: selectedUserId,
    hostId: selectedHostId,
    status: selectedStatus || null,
    search: debouncedSearchQuery.trim() || null,
    perPage,
    enabled: !!user && activeTab === "networking",
    isAdmin,
  });

  const {
    users: adminUsers,
    setPage: setUsersPage,
    meta: usersMeta,
    refetch: refetchUsers,
    toggleRegistration: handleToggleUsersRegistration,
  } = useUsers({
    sessionUserId: user?.id ?? null,
    userId: selectedUserId,
    hostId: selectedHostId,
    status: selectedStatus || null,
    search: debouncedSearchQuery.trim() || null,
    perPage,
    enabled: !!user && isAdmin && activeTab === "users",
    initialRegistrationEnabled: registrationEnabled,
  });

  const handleToggleRegistration = useCallback(
    async (nextEnabled: boolean) => {
      await handleToggleUsersRegistration(nextEnabled);
      setRegistrationEnabled(nextEnabled);
    },
    [handleToggleUsersRegistration, setRegistrationEnabled]
  );

  const {
    adminStats,
    quotaGroups,
    quotaData,
    setQuotaData,
    hosts,
    setHosts,
    refreshing,
    loadStaticData,
    loadQuotaAndStats,
    loadData,
  } = useDashboardData({
    user,
    isAdmin,
    activeTab,
    selectedUserId,
    selectedHostId,
    selectedStatus,
    debouncedSearchQuery,
    setSystemSettings,
    setRegistrationEnabled,
    refetchVms,
    refetchNetworking,
    refetchUsers,
  });

  useDashboardRealtime({
    user,
    isAdmin,
    activeTab,
    adminUsers,
    loadData,
    loadQuotaAndStats,
    refetchNetworking,
    refetchUsers,
    mergeVmUpdate,
  });

  const { confirmDialog, setConfirmDialog, requestConfirm } = useConfirmDialog(
    toast.error
  );
  const modalActions = useDashboardModalActions();
  const modals = useMemo<ReturnType<typeof useDashboardModals>>(
    () => createDashboardModalsHandle(modalActions),
    [modalActions]
  );

  const {
    handleRequestVmAction,
    handleRequestReleaseIp,
    handleRequestDeleteUser,
    handleRequestToggleUserStatus,
    handleRequestDeletePool,
    handleRequestDeleteQuotaGroup,
    handleRequestDeleteHost,
  } = useDashboardConfirmActions({
    requestConfirm,
    loadData,
    loadStaticData,
    loadQuotaAndStats,
    refetchNetworking,
    refetchUsers,
  });

  const handleRequestImpersonateUser = useCallback(
    (targetUser: AdminUserItem) => {
      requestConfirm({
        title: t("confirmImpersonateTitle"),
        message: `${t("confirmImpersonateDesc")} "${targetUser.name}" (${targetUser.email})?`,
        variant: "primary",
        confirmText: t("impersonateUser"),
        onConfirm: async () => {
          await startImpersonation(targetUser);
          handleTabChange("vms");
        },
      });
    },
    [requestConfirm, t, startImpersonation, handleTabChange]
  );

  const handleStopImpersonation = useCallback(async () => {
    const restored = await stopImpersonation();
    if (restored) {
      handleTabChange("users");
    }
  }, [stopImpersonation, handleTabChange]);

  const handlePerPageChange = useCallback(
    (newPerPage: number) => {
      setPerPage(newPerPage);
      setVmsPage(1);
      setIpsPage(1);
      setPoolsPage(1);
      setUsersPage(1);
    },
    [setPerPage, setVmsPage, setIpsPage, setPoolsPage, setUsersPage]
  );

  const value = useMemo<DashboardContextValue>(
    () => ({
      activeTab,
      setActiveTab,
      detailsVm,
      handleSetDetailsVm,
      handleTabChange,
      handleAuditNavigate,
      handlePerPageChange,
      vms,
      loadingVms,
      vmsPage,
      setVmsPage,
      vmsMeta,
      refetchVms,
      ips,
      loadingIps,
      setIpsPage,
      ipsMeta,
      pools,
      setPoolsPage,
      poolsPerPage,
      setPoolsPerPage,
      poolsMeta,
      refetchPools,
      refetchNetworking,
      adminUsers,
      setUsersPage,
      usersMeta,
      refetchUsers,
      handleToggleRegistration,
      adminStats,
      quotaGroups,
      quotaData,
      setQuotaData,
      hosts,
      setHosts,
      refreshing,
      loadStaticData,
      loadQuotaAndStats,
      loadData,
      modals,
      confirmDialog,
      setConfirmDialog,
      handleRequestVmAction,
      handleRequestReleaseIp,
      handleRequestDeleteUser,
      handleRequestToggleUserStatus,
      handleRequestImpersonateUser,
      handleStopImpersonation,
      handleRequestDeletePool,
      handleRequestDeleteQuotaGroup,
      handleRequestDeleteHost,
    }),
    [
      activeTab,
      setActiveTab,
      detailsVm,
      handleSetDetailsVm,
      handleTabChange,
      handleAuditNavigate,
      handlePerPageChange,
      vms,
      loadingVms,
      vmsPage,
      setVmsPage,
      vmsMeta,
      refetchVms,
      ips,
      loadingIps,
      setIpsPage,
      ipsMeta,
      pools,
      setPoolsPage,
      poolsPerPage,
      setPoolsPerPage,
      poolsMeta,
      refetchPools,
      refetchNetworking,
      adminUsers,
      setUsersPage,
      usersMeta,
      refetchUsers,
      handleToggleRegistration,
      adminStats,
      quotaGroups,
      quotaData,
      setQuotaData,
      hosts,
      setHosts,
      refreshing,
      loadStaticData,
      loadQuotaAndStats,
      loadData,
      modals,
      confirmDialog,
      setConfirmDialog,
      handleRequestVmAction,
      handleRequestReleaseIp,
      handleRequestDeleteUser,
      handleRequestToggleUserStatus,
      handleRequestImpersonateUser,
      handleStopImpersonation,
      handleRequestDeletePool,
      handleRequestDeleteQuotaGroup,
      handleRequestDeleteHost,
    ]
  );

  return (
    <DashboardContext.Provider value={value}>
      {children}
    </DashboardContext.Provider>
  );
}

export function useDashboard(): DashboardContextValue {
  const ctx = useContext(DashboardContext);
  if (!ctx) {
    throw new Error("useDashboard must be used within a DashboardProvider");
  }
  return ctx;
}
