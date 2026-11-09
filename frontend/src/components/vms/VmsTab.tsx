"use client";

import React from "react";
import { Plus, Server } from "lucide-react";
import { VirtualMachine } from "@/lib/api";
import { VmTable } from "./VmTable";
import { useI18n } from "@/lib/i18n/I18nContext";

export interface VmsTabProps {
  vms: VirtualMachine[];
  loading: boolean;
  isAdmin: boolean;
  currentPage: number;
  lastPage: number;
  perPage: number;
  total: number;
  onPageChange: (page: number) => void;
  onPerPageChange: (perPage: number) => void;
  onDeployInstance: () => void;
  onRequestAction: (
    vm: VirtualMachine,
    action: "start" | "stop" | "restart" | "suspend" | "delete"
  ) => void;
  onOpenConsole: (vm: VirtualMachine) => void;
  onOpenDetails?: (vm: VirtualMachine, initialTab?: string) => void;
}

export function VmsTab({
  vms,
  loading,
  isAdmin,
  currentPage,
  lastPage,
  perPage,
  total,
  onPageChange,
  onPerPageChange,
  onDeployInstance,
  onRequestAction,
  onOpenConsole,
  onOpenDetails,
}: VmsTabProps) {
  const { t } = useI18n();

  return (
    <div className="space-y-4">
      {/* Tab Header & Quick Action */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
        <div className="flex items-center space-x-3">
          <div className="p-2 bg-emerald-500/10 border border-emerald-500/20 text-emerald-600 dark:text-emerald-400 rounded-xl">
            <Server className="w-5 h-5" />
          </div>
          <div>
            <h2 className="text-base font-semibold text-gray-900 dark:text-white tracking-tight">
              {t("activeWorkloads")}
            </h2>
            <p className="text-xs text-gray-500 dark:text-gray-400">{t("workloadsSubtitle")}</p>
          </div>
        </div>

        <button
          onClick={onDeployInstance}
          className="px-4 py-2 rounded-xl text-xs font-semibold text-white bg-blue-600 hover:bg-blue-500 transition flex items-center space-x-1.5 shadow-lg shadow-blue-600/25"
        >
          <Plus className="w-4 h-4" />
          <span>{t("deployInstance")}</span>
        </button>
      </div>

      {/* Main Virtual Machines Table */}
      <VmTable
        vms={vms}
        loading={loading}
        isAdmin={isAdmin}
        onRequestAction={onRequestAction}
        onOpenConsole={onOpenConsole}
        onOpenDetails={onOpenDetails}
        pagination={{
          currentPage,
          lastPage,
          perPage,
          total,
          onPageChange,
          onPerPageChange,
        }}
      />
    </div>
  );
}
