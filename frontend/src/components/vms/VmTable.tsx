"use client";

import React, { useState, useCallback } from "react";
import {
  Play,
  Square,
  Pause,
  RotateCw,
  Terminal,
  Trash2,
  Cpu,
  Layers,
  HardDrive,
  Globe,
  Loader2,
  AlertCircle,
  Server,
  Box,
  Camera,
  Activity,
} from "lucide-react";
import { VirtualMachine } from "@/lib/api";
import { useI18n } from "@/lib/i18n/I18nContext";
import { PaginationBar } from "@/components/ui/PaginationBar";
import { RowActions } from "@/components/ui/rowActions/RowActions";
import { type RowAction } from "@/components/ui/rowActions/types";
import { StickyActionsTh, StickyActionsTd } from "@/components/ui/rowActions/StickyActionsCell";

interface VmTableProps {
  vms: VirtualMachine[];
  loading: boolean;
  isAdmin?: boolean;
  onAction?: (vmId: number, action: "start" | "stop" | "restart" | "suspend" | "delete") => Promise<void>;
  onRequestAction?: (vm: VirtualMachine, action: "start" | "stop" | "restart" | "suspend" | "delete") => void;
  onOpenConsole: (vm: VirtualMachine) => void;
  onOpenDetails?: (vm: VirtualMachine, initialTab?: string) => void;
  pagination?: {
    currentPage: number;
    lastPage: number;
    perPage: number;
    total: number;
    onPageChange: (page: number) => void;
    onPerPageChange?: (perPage: number) => void;
  };
}

type TranslateFn = ReturnType<typeof useI18n>["t"];
type VmAction = "start" | "stop" | "restart" | "suspend" | "delete";

function getStatusBadge(status: string, t: TranslateFn) {
  switch (status.toLowerCase()) {
    case "running":
      return (
        <span className="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border border-emerald-500/20">
          <span className="w-1.5 h-1.5 rounded-full bg-emerald-500 dark:bg-emerald-400 mr-1.5 animate-pulse" />
          {t("vmStatusRunning")}
        </span>
      );
    case "provisioning":
      return (
        <span className="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium bg-amber-500/10 text-amber-600 dark:text-amber-400 border border-amber-500/20">
          <Loader2 className="w-3 h-3 mr-1.5 animate-spin" />
          {t("vmStatusProvisioning")}
        </span>
      );
    case "stopped":
      return (
        <span className="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium bg-zinc-200 dark:bg-zinc-700/30 text-zinc-700 dark:text-zinc-400 border border-zinc-300 dark:border-zinc-700/50">
          <span className="w-1.5 h-1.5 rounded-full bg-zinc-500 mr-1.5" />
          {t("vmStatusStopped")}
        </span>
      );
    case "suspended":
      return (
        <span className="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium bg-amber-500/10 text-amber-600 dark:text-amber-400 border border-amber-500/20">
          <Pause className="w-3 h-3 mr-1.5" />
          {t("statusSuspended")}
        </span>
      );
    case "deleting":
      return (
        <span className="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium bg-red-500/10 text-red-600 dark:text-red-400 border border-red-500/20">
          <Loader2 className="w-3 h-3 mr-1.5 animate-spin" />
          {t("vmStatusDeleting")}
        </span>
      );
    case "failed":
      return (
        <div className="flex flex-col items-start">
          <span className="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium bg-red-500/10 text-red-600 dark:text-red-400 border border-red-500/20">
            <AlertCircle className="w-3 h-3 mr-1.5" />
            {t("vmStatusFailed")}
          </span>
        </div>
      );
    case "deleted":
      return (
        <span className="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium bg-zinc-200/80 dark:bg-zinc-800 text-zinc-500 dark:text-zinc-400 border border-zinc-300 dark:border-zinc-700">
          <span className="w-1.5 h-1.5 rounded-full bg-zinc-400 mr-1.5" />
          {t("vmStatusDeleted")}
        </span>
      );
    default:
      return (
        <span className="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium bg-gray-200 dark:bg-gray-800 text-gray-700 dark:text-gray-400">
          {status}
        </span>
      );
  }
}

function renderIpNetworkStatus(vm: VirtualMachine, t: TranslateFn) {
  const ip = vm.network?.primary?.address ?? vm.ipv4_address;
  const status = vm.network_status ?? vm.network?.primary?.status ?? (ip ? "assigned" : "unassigned");

  if (!ip) {
    if (status === "waiting") {
      return (
        <div className="flex flex-col" title={t("networkStatusWaitingHint")}>
          <div className="flex items-center space-x-1.5 text-amber-600 dark:text-amber-400">
            <span className="w-2 h-2 rounded-full bg-amber-400 animate-pulse flex-shrink-0" />
            <span>{t("networkStatusWaiting")}</span>
          </div>
        </div>
      );
    }
    return <span className="text-gray-400 italic text-xs">{t("unassignedIp")}</span>;
  }

  if (vm.network?.settings?.mode === "manual" && status !== "observed") {
    return (
      <div className="flex flex-col" title={t("networkOperatorManagedHint")}>
        <div className="flex items-center space-x-1.5 text-gray-800 dark:text-gray-200">
          <span className="w-2 h-2 rounded-full bg-amber-500 flex-shrink-0" />
          <span>{ip}</span>
        </div>
        <span className="text-[10px] text-amber-600 dark:text-amber-400 font-sans">
          {t("networkOperatorManaged")}
        </span>
      </div>
    );
  }

  if (status === "observed") {
    return (
      <div className="flex flex-col">
        <div className="flex items-center space-x-1.5 text-gray-900 dark:text-white font-semibold">
          <span className="w-2 h-2 rounded-full bg-emerald-500 flex-shrink-0" />
          <span>{ip}</span>
        </div>
        <span className="text-[10px] text-emerald-600 dark:text-emerald-400 font-sans">Active (Observed)</span>
      </div>
    );
  }

  if (status === "assigned") {
    return (
      <div className="flex flex-col">
        <div className="flex items-center space-x-1.5 text-gray-800 dark:text-gray-200">
          <span className="w-2 h-2 rounded-full bg-amber-400 animate-pulse flex-shrink-0" />
          <span>{ip}</span>
        </div>
        <span className="text-[10px] text-amber-600 dark:text-amber-400 font-sans">Configuring routed NIC...</span>
      </div>
    );
  }

  if (status === "waiting") {
    return (
      <div className="flex flex-col" title={t("networkStatusWaitingHint")}>
        <div className="flex items-center space-x-1.5 text-gray-800 dark:text-gray-200">
          <span className="w-2 h-2 rounded-full bg-amber-400 animate-pulse flex-shrink-0" />
          <span>{ip}</span>
        </div>
        <span className="text-[10px] text-amber-600 dark:text-amber-400 font-sans">
          {t("networkStatusWaiting")}
        </span>
      </div>
    );
  }

  if (status === "unverified") {
    return (
      <div className="flex flex-col" title="Instance is running but guest agent is not reporting observed address yet.">
        <div className="flex items-center space-x-1.5 text-gray-800 dark:text-gray-200">
          <span className="w-2 h-2 rounded-full bg-amber-500 flex-shrink-0" />
          <span>{ip}</span>
        </div>
        <span className="text-[10px] text-amber-600 dark:text-amber-400 font-sans">Unverified (Agent pending)</span>
      </div>
    );
  }

  if (status === "mismatch") {
    return (
      <div className="flex flex-col" title="Reported address does not match allocated IP.">
        <div className="flex items-center space-x-1.5 text-red-600 dark:text-red-400 font-semibold">
          <span className="w-2 h-2 rounded-full bg-red-500 flex-shrink-0" />
          <span>{ip}</span>
        </div>
        <span className="text-[10px] text-red-500 font-sans">IP Mismatch</span>
      </div>
    );
  }

  if (status === "unassigned") {
    return (
      <div className="flex flex-col">
        <div className="flex items-center space-x-1.5 text-gray-500 dark:text-gray-400">
          <span className="w-2 h-2 rounded-full bg-zinc-400 flex-shrink-0" />
          <span>{ip}</span>
        </div>
        <span className="text-[10px] text-zinc-500 dark:text-zinc-400 font-sans">
          {t("networkStatusUnassigned")}
        </span>
      </div>
    );
  }

  return (
    <div className="flex items-center space-x-1 text-gray-800 dark:text-gray-200">
      <Globe className="w-3.5 h-3.5 text-blue-500" />
      <span>{ip}</span>
    </div>
  );
}

interface VmTableRowProps {
  vm: VirtualMachine;
  isAdmin?: boolean;
  isActing: boolean;
  onActionClick: (vm: VirtualMachine, action: VmAction) => void;
  onOpenConsole: (vm: VirtualMachine) => void;
  onOpenDetails?: (vm: VirtualMachine, initialTab?: string) => void;
}

/**
 * Memoized table row for a single virtual machine so status or WebSocket updates
 * only re-render the affected row.
 */
export const VmTableRow = React.memo(function VmTableRow({
  vm,
  isAdmin,
  isActing,
  onActionClick,
  onOpenConsole,
  onOpenDetails,
}: VmTableRowProps) {
  const { t } = useI18n();
  const statusLower = vm.status.toLowerCase();
  const isRunning = statusLower === "running";
  const isStopped = statusLower === "stopped";
  const isSuspended = statusLower === "suspended";
  const isNonOperationalStatus = ["provisioning", "failed", "deleting", "deleted"].includes(
    statusLower
  );
  const isCannotDeleteStatus = ["provisioning", "deleting", "deleted"].includes(statusLower);
  const isHostUnavailable = Boolean(vm.host && vm.host.status !== "ok");
  const hostUnavailableLabel =
    vm.host?.status === "unreachable" ? t("hostUnreachable") : t("hostUnavailable");
  const hostUnavailableTooltip =
    vm.host?.status === "unreachable" ? t("hostUnreachableTooltip") : t("hostUnavailableTooltip");
  const disabledReason = isHostUnavailable ? hostUnavailableTooltip : undefined;

  const actions: RowAction[] = [
    {
      id: "console",
      icon: Terminal,
      label: t("openConsole"),
      severity: "safe",
      tone: "neutral",
      disabled: isActing || isHostUnavailable || isNonOperationalStatus,
      disabledReason,
      onSelect: () => onOpenConsole(vm),
    },
    {
      id: "details",
      icon: Activity,
      label: t("vmActionDetailsMetrics"),
      severity: "safe",
      tone: "info",
      hidden: !onOpenDetails,
      disabled: isActing || isHostUnavailable,
      disabledReason,
      onSelect: () => onOpenDetails?.(vm, "overview"),
    },
    {
      id: "snapshots",
      icon: Camera,
      label: t("tabSnapshots"),
      severity: "safe",
      tone: "accent",
      hidden: !onOpenDetails,
      disabled: isActing || isHostUnavailable,
      disabledReason,
      onSelect: () => onOpenDetails?.(vm, "snapshots"),
    },
    {
      id: "start",
      icon: Play,
      label: t("startInstance"),
      severity: "caution",
      tone: "success",
      hidden: isRunning,
      disabled: isActing || isHostUnavailable || isNonOperationalStatus,
      disabledReason,
      loading: isActing,
      onSelect: () => onActionClick(vm, "start"),
    },
    {
      id: "restart",
      icon: RotateCw,
      label: t("restartInstance"),
      severity: "disruptive",
      tone: "neutral",
      disabled: isActing || isStopped || isSuspended || isHostUnavailable || isNonOperationalStatus,
      disabledReason,
      onSelect: () => onActionClick(vm, "restart"),
    },
    {
      id: "suspend",
      icon: Pause,
      label: t("suspendInstance"),
      severity: "disruptive",
      tone: "neutral",
      hidden: !isRunning,
      disabled: isActing || isHostUnavailable,
      disabledReason,
      onSelect: () => onActionClick(vm, "suspend"),
    },
    {
      id: "stop",
      icon: Square,
      label: t("stopInstance"),
      severity: "disruptive",
      tone: "neutral",
      hidden: !isRunning,
      disabled: isActing || isHostUnavailable,
      disabledReason,
      loading: isActing,
      onSelect: () => onActionClick(vm, "stop"),
    },
    {
      id: "delete",
      icon: Trash2,
      label: t("deleteInstance"),
      severity: "critical",
      tone: "neutral",
      disabled: isActing || isHostUnavailable || isCannotDeleteStatus,
      disabledReason,
      onSelect: () => onActionClick(vm, "delete"),
    },
  ];

  return (
    <tr className="hover:bg-gray-50 dark:hover:bg-gray-800/40 transition group">
      <td className="px-4 sm:px-6 py-3.5">
        {onOpenDetails ? (
          <button
            onClick={() => onOpenDetails(vm, "overview")}
            className="font-semibold text-gray-900 dark:text-white hover:text-blue-600 dark:hover:text-blue-400 transition text-left cursor-pointer"
            title={t("tabOverview")}
          >
            {vm.name}
          </button>
        ) : (
          <div className="font-semibold text-gray-900 dark:text-white">{vm.name}</div>
        )}
        <div className="text-xs text-gray-500 dark:text-gray-400 flex items-center space-x-1.5 mt-0.5">
          {vm.host && (
            <span className="font-mono text-[11px] text-indigo-600 dark:text-indigo-400 font-medium">
              {vm.host.name} {vm.host.region ? `(${vm.host.region})` : ""}
            </span>
          )}
          {isAdmin && (
            <>
              {vm.host && <span>•</span>}
              <span className="font-mono text-[11px] text-gray-500">Project: {vm.incus_project}</span>
            </>
          )}
        </div>
      </td>

      {/* VPS Type Column */}
      <td className="px-4 sm:px-6 py-3.5 whitespace-nowrap">
        {vm.instance_type === "container" ? (
          <span className="inline-flex items-center px-2.5 py-1 rounded-xl text-xs font-semibold bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 border border-indigo-500/20">
            <Box className="w-3.5 h-3.5 mr-1.5" />
            {t("lxcBadge")}
          </span>
        ) : (
          <span className="inline-flex items-center px-2.5 py-1 rounded-xl text-xs font-semibold bg-blue-500/10 text-blue-600 dark:text-blue-400 border border-blue-500/20">
            <Server className="w-3.5 h-3.5 mr-1.5" />
            {t("fullVmBadge")}
          </span>
        )}
      </td>

      {isAdmin && (
        <td className="px-4 sm:px-6 py-3.5">
          {vm.user ? (
            <div>
              <div className="font-semibold text-gray-900 dark:text-white text-xs">
                {vm.user.name}
              </div>
              <div className="text-[11px] text-gray-500 font-mono">
                {vm.user.email}
              </div>
            </div>
          ) : (
            <span className="text-gray-400 italic text-xs">Tenant #{vm.user_id}</span>
          )}
        </td>
      )}

      <td className="px-4 sm:px-6 py-3.5" title={vm.failure_reason || undefined}>
        <div className="flex flex-col items-start gap-1">
          <div className="flex items-center gap-1.5 flex-wrap">
            {getStatusBadge(vm.status, t)}
            {isHostUnavailable && (
              <span
                className="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-amber-500/10 text-amber-600 dark:text-amber-400 border border-amber-500/20"
                title={hostUnavailableTooltip}
              >
                <AlertCircle className="w-3 h-3 mr-1" />
                {hostUnavailableLabel}
              </span>
            )}
          </div>
          {vm.status.toLowerCase() === "failed" && vm.failure_reason && (
            <div className="mt-1 max-w-[220px] truncate text-[11px] text-red-500 dark:text-red-400 font-mono">
              {vm.failure_reason}
            </div>
          )}
        </div>
      </td>

      <td className="px-4 sm:px-6 py-3.5 font-mono text-xs whitespace-nowrap">
        {renderIpNetworkStatus(vm, t)}
      </td>

      <td className="px-4 sm:px-6 py-3.5 whitespace-nowrap">
        <div className="flex items-center space-x-4 text-xs text-gray-600 dark:text-gray-300">
          <div className="flex items-center space-x-1" title="vCPU">
            <Cpu className="w-3.5 h-3.5 text-emerald-500" />
            <span>{vm.cpu_cores} vCPU</span>
          </div>
          <div className="flex items-center space-x-1" title="RAM">
            <Layers className="w-3.5 h-3.5 text-purple-500" />
            <span>{Math.round(vm.ram_mb / 1024 * 10) / 10} GB</span>
          </div>
          <div className="flex items-center space-x-1" title="Disk">
            <HardDrive className="w-3.5 h-3.5 text-amber-500" />
            <span>{vm.disk_gb} GB</span>
          </div>
        </div>
      </td>

      <td className="px-4 sm:px-6 py-3.5 text-xs font-mono text-gray-700 dark:text-gray-300 whitespace-nowrap">
        <span className="px-2 py-0.5 rounded bg-gray-100 dark:bg-gray-800/80 border border-gray-200 dark:border-gray-700/50 font-medium">
          {vm.os_image_alias || vm.os_image.replace("images:", "")}
        </span>
      </td>

      <StickyActionsTd>
        <RowActions actions={actions} />
      </StickyActionsTd>
    </tr>
  );
});

export function VmTable({
  vms,
  loading,
  isAdmin,
  onAction,
  onRequestAction,
  onOpenConsole,
  onOpenDetails,
  pagination,
}: VmTableProps) {
  const { t } = useI18n();
  const [actingVmId, setActingVmId] = useState<number | null>(null);

  const handleActionClick = useCallback(
    async (
      vm: VirtualMachine,
      action: VmAction
    ) => {
      if (vm.host && vm.host.status !== "ok") {
        return;
      }
      if (onRequestAction) {
        onRequestAction(vm, action);
        return;
      }
      if (onAction) {
        try {
          setActingVmId(vm.id);
          await onAction(vm.id, action);
        } finally {
          setActingVmId(null);
        }
      }
    },
    [onRequestAction, onAction]
  );

  if (loading && vms.length === 0) {
    return (
      <div className="bg-white dark:bg-gray-900/60 border border-gray-200 dark:border-gray-800 rounded-2xl p-12 text-center transition-colors">
        <Loader2 className="w-8 h-8 text-blue-500 animate-spin mx-auto mb-3" />
        <p className="text-sm text-gray-500 dark:text-gray-400">{t("loadingVms")}</p>
      </div>
    );
  }

  if (vms.length === 0) {
    return (
      <div className="bg-white dark:bg-gray-900/60 border border-gray-200 dark:border-gray-800 rounded-2xl p-12 text-center transition-colors">
        <div className="w-12 h-12 rounded-2xl bg-gray-100 dark:bg-gray-800/80 text-gray-500 dark:text-gray-400 flex items-center justify-center mx-auto mb-4">
          <Layers className="w-6 h-6" />
        </div>
        <h3 className="text-base font-semibold text-gray-900 dark:text-gray-200">{t("noVmsTitle")}</h3>
        <p className="text-xs text-gray-500 dark:text-gray-400 mt-1 max-w-sm mx-auto">{t("noVmsDesc")}</p>
      </div>
    );
  }

  return (
    <div className="bg-white dark:bg-gray-900/60 border border-gray-200 dark:border-gray-800 rounded-2xl overflow-hidden backdrop-blur-sm shadow-sm dark:shadow-none transition-colors">
      <div className="overflow-x-auto relative">
        <table className="w-full text-left text-sm text-gray-600 dark:text-gray-300">
          <thead className="bg-gray-50 dark:bg-gray-950/60 text-xs uppercase text-gray-500 dark:text-gray-400 border-b border-gray-200 dark:border-gray-800 font-semibold tracking-wider">
            <tr>
              <th scope="col" className="px-4 sm:px-6 py-3.5">{t("colInstance")}</th>
              <th scope="col" className="px-4 sm:px-6 py-3.5">{t("colType")}</th>
              {isAdmin && <th scope="col" className="px-4 sm:px-6 py-3.5">{t("colOwner")}</th>}
              <th scope="col" className="px-4 sm:px-6 py-3.5">{t("colStatus")}</th>
              <th scope="col" className="px-4 sm:px-6 py-3.5">{t("colIp")}</th>
              <th scope="col" className="px-4 sm:px-6 py-3.5">{t("colSpecs")}</th>
              <th scope="col" className="px-4 sm:px-6 py-3.5">{t("colOs")}</th>
              <StickyActionsTh>{t("colActions")}</StickyActionsTh>
            </tr>
          </thead>
          <tbody className="divide-y divide-gray-200 dark:divide-gray-800/60">
            {vms.map((vm) => (
              <VmTableRow
                key={vm.id}
                vm={vm}
                isAdmin={isAdmin}
                isActing={actingVmId === vm.id}
                onActionClick={handleActionClick}
                onOpenConsole={onOpenConsole}
                onOpenDetails={onOpenDetails}
              />
            ))}
          </tbody>
        </table>
      </div>
      {pagination && (
        <PaginationBar
          currentPage={pagination.currentPage}
          lastPage={pagination.lastPage}
          perPage={pagination.perPage}
          total={pagination.total}
          onPageChange={pagination.onPageChange}
          onPerPageChange={pagination.onPerPageChange}
        />
      )}
    </div>
  );
}
