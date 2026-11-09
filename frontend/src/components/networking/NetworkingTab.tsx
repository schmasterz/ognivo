"use client";

import React from "react";
import { Globe } from "lucide-react";
import { IpAllocation, IpPool, VirtualMachine } from "@/lib/api";
import { IpTable } from "./IpTable";
import { useI18n } from "@/lib/i18n/I18nContext";

export interface NetworkingTabProps {
  ips: IpAllocation[];
  vms: VirtualMachine[];
  pools: IpPool[];
  loading: boolean;
  isAdmin: boolean;
  onAddPool: () => void;
  onRequestRelease: (ip: IpAllocation) => void;
  onRequestDeletePool: (poolId: number, poolName: string) => void;
  onPoolUpdated?: (pool: IpPool) => void;
  onRefresh?: () => void;
  ipsPagination: {
    currentPage: number;
    lastPage: number;
    perPage: number;
    total: number;
    onPageChange: (page: number) => void;
    onPerPageChange: (perPage: number) => void;
  };
  poolsPagination: {
    currentPage: number;
    lastPage: number;
    perPage: number;
    total: number;
    onPageChange: (page: number) => void;
    onPerPageChange: (perPage: number) => void;
  };
}

export function NetworkingTab({
  ips,
  vms,
  pools,
  loading,
  isAdmin,
  onAddPool,
  onRequestRelease,
  onRequestDeletePool,
  onPoolUpdated,
  onRefresh,
  ipsPagination,
  poolsPagination,
}: NetworkingTabProps) {
  const { t } = useI18n();

  return (
    <div className="space-y-4">
      {/* Tab Header */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
        <div className="flex items-center space-x-3">
          <div className="p-2 bg-blue-500/10 border border-blue-500/20 text-blue-600 dark:text-blue-400 rounded-xl">
            <Globe className="w-5 h-5" />
          </div>
          <div>
            <h2 className="text-base font-semibold text-gray-900 dark:text-white tracking-tight">
              {t("networkingTitle")}
            </h2>
            <p className="text-xs text-gray-500 dark:text-gray-400">
              Routed L3 Networking and IPAM Allocations
            </p>
          </div>
        </div>
      </div>

      {/* Main IPAM & Pool Routing Table */}
      <IpTable
        ips={ips}
        vms={vms}
        pools={pools}
        loading={loading}
        isAdmin={isAdmin}
        onRequestRelease={onRequestRelease}
        onAddPool={onAddPool}
        onRequestDeletePool={onRequestDeletePool}
        onPoolUpdated={onPoolUpdated}
        onRefresh={onRefresh}
        pagination={{
          currentPage: ipsPagination.currentPage,
          lastPage: ipsPagination.lastPage,
          perPage: ipsPagination.perPage,
          total: ipsPagination.total,
          onPageChange: ipsPagination.onPageChange,
          onPerPageChange: ipsPagination.onPerPageChange,
        }}
        poolsPagination={{
          currentPage: poolsPagination.currentPage,
          lastPage: poolsPagination.lastPage,
          perPage: poolsPagination.perPage,
          total: poolsPagination.total,
          onPageChange: poolsPagination.onPageChange,
          onPerPageChange: poolsPagination.onPerPageChange,
        }}
      />
    </div>
  );
}
