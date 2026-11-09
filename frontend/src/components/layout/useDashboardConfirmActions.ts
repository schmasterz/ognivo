"use client";

import { useCallback } from "react";
import {
  api,
  VirtualMachine,
  IpAllocation,
  AdminUserItem,
  QuotaGroup,
} from "@/lib/api";
import { ConfirmVariant } from "@/components/ui/ConfirmModal";
import { ConfirmActionOptions } from "@/lib/hooks/useConfirmDialog";
import { useI18n } from "@/lib/i18n/I18nContext";
import { useToast } from "@/lib/toast/ToastContext";

export interface UseDashboardConfirmActionsParams {
  requestConfirm: (options: ConfirmActionOptions) => void;
  loadData: () => Promise<void>;
  loadStaticData: () => Promise<void>;
  loadQuotaAndStats: () => Promise<void>;
  refetchNetworking: () => Promise<void>;
  refetchUsers: () => Promise<void>;
}

export function useDashboardConfirmActions({
  requestConfirm,
  loadData,
  loadStaticData,
  loadQuotaAndStats,
  refetchNetworking,
  refetchUsers,
}: UseDashboardConfirmActionsParams) {
  const { t } = useI18n();
  const { toast } = useToast();

  // VM Lifecycle Confirmation Dialog & Execution
  const handleRequestVmAction = useCallback(
    (
      vm: VirtualMachine,
      action: "start" | "stop" | "restart" | "suspend" | "delete"
    ) => {
      const titleMap: Record<string, string> = {
        start: t("confirmStartTitle"),
        stop: t("confirmStopTitle"),
        suspend: t("confirmSuspendTitle"),
        restart: t("confirmRestartTitle"),
        delete: t("confirmDeleteTitle"),
      };
      const messageMap: Record<string, string> = {
        start: `${t("confirmStart")} "${vm.name}"?`,
        stop: `${t("confirmStop")} "${vm.name}"?`,
        suspend: `${t("confirmSuspend")} "${vm.name}"?`,
        restart: `${t("confirmRestart")} "${vm.name}"?`,
        delete: `${t("confirmDelete")} "${vm.name}"?`,
      };
      const variantMap: Record<string, ConfirmVariant> = {
        start: "primary",
        stop: "warning",
        suspend: "warning",
        restart: "info",
        delete: "danger",
      };
      const buttonMap: Record<string, string> = {
        start: t("startInstance"),
        stop: t("stopInstance"),
        suspend: t("suspendInstance"),
        restart: t("restartInstance"),
        delete: t("deleteInstance"),
      };

      requestConfirm({
        title: titleMap[action] || t("confirmTitle"),
        message: messageMap[action] || "",
        variant: variantMap[action] || "danger",
        confirmText: buttonMap[action] || t("confirmAction"),
        onConfirm: async () => {
          const res = await api.vmAction(vm.id, action);
          toast.success(res.message);
          await loadData();
        },
      });
    },
    [t, toast, requestConfirm, loadData]
  );

  // IP Allocation Releasing with Confirmation Dialog
  const handleRequestReleaseIp = useCallback(
    (ip: IpAllocation) => {
      requestConfirm({
        title: t("confirmReleaseTitle"),
        message: `${t("confirmRelease")} "${ip.address}"?`,
        variant: "warning",
        confirmText: t("releaseIp"),
        onConfirm: async () => {
          const res = await api.releaseIp(ip.id);
          toast.success(res.message);
          await refetchNetworking();
          await loadQuotaAndStats();
        },
      });
    },
    [t, toast, requestConfirm, refetchNetworking, loadQuotaAndStats]
  );

  // Delete User with Confirmation Dialog
  const handleRequestDeleteUser = useCallback(
    (userId: number, name: string) => {
      requestConfirm({
        title: t("deleteUser"),
        message: `${t("confirmDeleteUser")} "${name}"?`,
        variant: "danger",
        confirmText: t("deleteUser"),
        onConfirm: async () => {
          const res = await api.adminDeleteUser(userId);
          toast.success(res.message);
          await refetchUsers();
          await loadQuotaAndStats();
        },
      });
    },
    [t, toast, requestConfirm, refetchUsers, loadQuotaAndStats]
  );

  // Suspend / Activate User with Confirmation Dialog
  const handleRequestToggleUserStatus = useCallback(
    (targetUser: AdminUserItem, nextStatus: "active" | "suspended") => {
      const isSuspending = nextStatus === "suspended";
      requestConfirm({
        title: isSuspending
          ? t("confirmSuspendUserTitle")
          : t("confirmActivateUserTitle"),
        message: isSuspending
          ? `${t("confirmSuspendUserDesc")} "${targetUser.name}" (${targetUser.email})? ${t("confirmSuspendUserVmsNotice")}`
          : `${t("confirmActivateUserDesc")} "${targetUser.name}" (${targetUser.email})?`,
        variant: isSuspending ? "warning" : "primary",
        confirmText: isSuspending ? t("suspendUser") : t("activateUser"),
        onConfirm: async () => {
          const res = await api.adminUpdateUserStatus(targetUser.id, nextStatus);
          toast.success(res.message);
          await refetchUsers();
        },
      });
    },
    [t, toast, requestConfirm, refetchUsers]
  );

  // Delete IP Pool with Confirmation Dialog
  const handleRequestDeletePool = useCallback(
    (poolId: number, name: string) => {
      requestConfirm({
        title: t("deletePool"),
        message: `${t("confirmDeletePool")} "${name}"?`,
        variant: "danger",
        confirmText: t("deletePool"),
        onConfirm: async () => {
          const res = await api.adminDeleteIpPool(poolId);
          toast.success(res.message);
          await refetchNetworking();
        },
      });
    },
    [t, toast, requestConfirm, refetchNetworking]
  );

  // Delete Quota Group with Confirmation Dialog
  const handleRequestDeleteQuotaGroup = useCallback(
    (group: QuotaGroup) => {
      requestConfirm({
        title: t("deleteQuotaGroup"),
        message: `${t("confirmDeleteQuotaGroup")} "${group.name}"?`,
        variant: "danger",
        confirmText: t("deleteQuotaGroup"),
        onConfirm: async () => {
          const res = await api.deleteQuotaGroup(group.id);
          toast.success(res.message || t("quotaGroupDeletedNotice"));
          await loadStaticData();
        },
      });
    },
    [t, toast, requestConfirm, loadStaticData]
  );

  // Delete Host Node with Confirmation Dialog
  const handleRequestDeleteHost = useCallback(
    (hostId: number, hostName: string) => {
      requestConfirm({
        title: t("deleteNode"),
        message: `${t("confirmDeleteNode")} "${hostName}"?`,
        variant: "danger",
        confirmText: t("deleteNode"),
        onConfirm: async () => {
          const res = await api.adminDeleteHost(hostId);
          toast.success(res.message || t("nodeDeletedNotice"));
          await loadStaticData();
        },
      });
    },
    [t, toast, requestConfirm, loadStaticData]
  );

  return {
    handleRequestVmAction,
    handleRequestReleaseIp,
    handleRequestDeleteUser,
    handleRequestToggleUserStatus,
    handleRequestDeletePool,
    handleRequestDeleteQuotaGroup,
    handleRequestDeleteHost,
  };
}
