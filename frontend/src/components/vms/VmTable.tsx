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
} from "lucide-react";
import { VirtualMachine } from "@/lib/api";
import { useI18n } from "@/lib/i18n/I18nContext";
import { PaginationBar } from "@/components/ui/PaginationBar";

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
          Running
        </span>
      );
    case "provisioning":
      return (
        <span className="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium bg-amber-500/10 text-amber-600 dark:text-amber-400 border border-amber-500/20">
          <Loader2 className="w-3 h-3 mr-1.5 animate-spin" />
          Provisioning
        </span>
      );
    case "stopped":
      return (
        <span className="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium bg-zinc-200 dark:bg-zinc-700/30 text-zinc-700 dark:text-zinc-400 border border-zinc-300 dark:border-zinc-700/50">
          <span className="w-1.5 h-1.5 rounded-full bg-zinc-500 mr-1.5" />
          Stopped
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
          Deleting
        </span>
      );
    case "failed":
      return (
        <div className="flex flex-col items-start">
          <span className="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium bg-red-500/10 text-red-600 dark:text-red-400 border border-red-500/20">
            <AlertCircle className="w-3 h-3 mr-1.5" />
            Failed
          </span>
        </div>
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
  if (!ip) {
    return <span className="text-gray-400 italic text-xs">{t("unassignedIp")}</span>;
  }

  const status = vm.network_status ?? vm.network?.primary?.status ?? "assigned";

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
  const isRunning = vm.status.toLowerCase() === "running";
  const isStopped = vm.status.toLowerCase() === "stopped";
  const isSuspended = vm.status.toLowerCase() === "suspended";
  const isHostUnreachable = vm.host?.status === "unreachable";

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
            {isHostUnreachable && (
              <span
                className="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-amber-500/10 text-amber-600 dark:text-amber-400 border border-amber-500/20"
                title={t("hostUnreachableTooltip")}
              >
                <AlertCircle className="w-3 h-3 mr-1" />
                {t("hostUnreachable")}
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
          <div className="flex items-center space-x-1" title="CPU Cores">
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

      <td className="sticky right-0 z-10 px-4 sm:px-6 py-3.5 text-right bg-white dark:bg-gray-900 group-hover:bg-gray-50 dark:group-hover:bg-gray-800 border-l border-gray-200/80 dark:border-gray-800/80 shadow-[-4px_0_6px_-2px_rgba(0,0,0,0.05)] dark:shadow-[-4px_0_6px_-2px_rgba(0,0,0,0.3)] transition-colors">
        <div className="flex items-center justify-end space-x-1.5">
          {/* Snapshots Button */}
          {onOpenDetails && (
            <button
              onClick={() => onOpenDetails(vm, "snapshots")}
              disabled={isActing}
              title={t("tabSnapshots")}
              className="p-1.5 rounded-lg bg-gray-100 hover:bg-purple-500/20 dark:bg-gray-800 dark:hover:bg-purple-500/20 text-gray-600 dark:text-gray-300 hover:text-purple-600 dark:hover:text-purple-400 transition disabled:opacity-50"
            >
              <Camera className="w-4 h-4" />
            </button>
          )}

          {/* Console / Terminal Button */}
          <button
            onClick={() => onOpenConsole(vm)}
            disabled={isActing || isHostUnreachable}
            title={isHostUnreachable ? t("hostUnreachableTooltip") : "Open Console"}
            className="p-1.5 rounded-lg bg-gray-100 hover:bg-gray-200 dark:bg-gray-800 dark:hover:bg-gray-700 text-gray-600 dark:text-gray-300 hover:text-gray-900 dark:hover:text-white transition disabled:opacity-50"
          >
            <Terminal className="w-4 h-4" />
          </button>

          {/* Start/Stop Button with Confirmation */}
          {isRunning ? (
            <>
              <button
                onClick={() => onActionClick(vm, "stop")}
                disabled={isActing || isHostUnreachable}
                title={isHostUnreachable ? t("hostUnreachableTooltip") : t("stopInstance")}
                className="p-1.5 rounded-lg bg-gray-100 hover:bg-amber-500/20 dark:bg-gray-800 dark:hover:bg-amber-500/20 text-gray-600 dark:text-gray-300 hover:text-amber-500 transition disabled:opacity-50"
              >
                {isActing ? <Loader2 className="w-4 h-4 animate-spin" /> : <Square className="w-4 h-4" />}
              </button>

              <button
                onClick={() => onActionClick(vm, "suspend")}
                disabled={isActing || isHostUnreachable}
                title={isHostUnreachable ? t("hostUnreachableTooltip") : t("suspendInstance")}
                className="p-1.5 rounded-lg bg-gray-100 hover:bg-yellow-500/20 dark:bg-gray-800 dark:hover:bg-yellow-500/20 text-gray-600 dark:text-gray-300 hover:text-yellow-500 transition disabled:opacity-50"
              >
                <Pause className="w-4 h-4" />
              </button>
            </>
          ) : (
            <button
              onClick={() => onActionClick(vm, "start")}
              disabled={isActing || isHostUnreachable || vm.status.toLowerCase() === "deleting"}
              title={isHostUnreachable ? t("hostUnreachableTooltip") : t("startInstance")}
              className="p-1.5 rounded-lg bg-gray-100 hover:bg-emerald-500/20 dark:bg-gray-800 dark:hover:bg-emerald-500/20 text-gray-600 dark:text-gray-300 hover:text-emerald-500 transition disabled:opacity-50"
            >
              {isActing ? <Loader2 className="w-4 h-4 animate-spin" /> : <Play className="w-4 h-4" />}
            </button>
          )}

          {/* Restart Button with Confirmation */}
          <button
            onClick={() => onActionClick(vm, "restart")}
            disabled={isActing || isStopped || isSuspended || isHostUnreachable || vm.status.toLowerCase() === "deleting"}
            title={isHostUnreachable ? t("hostUnreachableTooltip") : t("restartInstance")}
            className="p-1.5 rounded-lg bg-gray-100 hover:bg-blue-500/20 dark:bg-gray-800 dark:hover:bg-blue-500/20 text-gray-600 dark:text-gray-300 hover:text-blue-500 transition disabled:opacity-50"
          >
            <RotateCw className="w-4 h-4" />
          </button>

          {/* Delete Button with Confirmation */}
          <button
            onClick={() => onActionClick(vm, "delete")}
            disabled={isActing || isHostUnreachable}
            title={isHostUnreachable ? t("hostUnreachableTooltip") : t("deleteInstance")}
            className="p-1.5 rounded-lg bg-gray-100 hover:bg-red-500/20 dark:bg-gray-800 dark:hover:bg-red-500/20 text-gray-600 dark:text-gray-300 hover:text-red-500 transition disabled:opacity-50"
          >
            <Trash2 className="w-4 h-4" />
          </button>
        </div>
      </td>
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
      if (vm.host?.status === "unreachable") {
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
              <th scope="col" className="sticky right-0 z-20 px-4 sm:px-6 py-3.5 text-right bg-gray-50 dark:bg-gray-950 border-l border-gray-200 dark:border-gray-800 shadow-[-4px_0_6px_-2px_rgba(0,0,0,0.05)] dark:shadow-[-4px_0_6px_-2px_rgba(0,0,0,0.3)]">{t("colActions")}</th>
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
