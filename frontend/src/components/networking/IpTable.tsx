"use client";

import React, { useState, useEffect, useCallback } from "react";
import {
  Globe,
  Trash2,
  Server,
  Copy,
  Check,
  Loader2,
  Network,
  Plus,
  Clock,
  Cpu,
  Edit2,
  AlertTriangle,
  ShieldCheck,
  Eye,
} from "lucide-react";
import { IpAllocation, IpPool } from "@/lib/api";
import { useI18n } from "@/lib/i18n/I18nContext";
import { PaginationBar } from "@/components/ui/PaginationBar";
import { EditIpPoolModal } from "./EditIpPoolModal";
import { PreflightModal } from "./PreflightModal";
import { PoolDetailModal } from "./PoolDetailModal";

interface IpTableProps {
  ips: IpAllocation[];
  pools?: IpPool[];
  loading: boolean;
  isAdmin?: boolean;
  onRequestRelease?: (ip: IpAllocation) => void;
  onAddPool?: () => void;
  onRequestDeletePool?: (poolId: number, poolName: string) => void;
  onPoolUpdated?: (pool: IpPool) => void;
  pagination?: {
    currentPage: number;
    lastPage: number;
    perPage: number;
    total: number;
    onPageChange: (page: number) => void;
    onPerPageChange?: (perPage: number) => void;
  };
  poolsPagination?: {
    currentPage: number;
    lastPage: number;
    perPage: number;
    total: number;
    onPageChange: (page: number) => void;
    onPerPageChange?: (perPage: number) => void;
  };
}

interface CoolingBadgeProps {
  ip: IpAllocation;
}

function CoolingBadge({ ip }: CoolingBadgeProps) {
  const { t } = useI18n();
  const initial = ip.cooldown_remaining_seconds ?? 0;
  const [remaining, setRemaining] = useState<number>(initial);

  useEffect(() => {
    queueMicrotask(() => {
      setRemaining(ip.cooldown_remaining_seconds ?? 0);
    });
  }, [ip.cooldown_remaining_seconds]);

  useEffect(() => {
    const timer = setInterval(() => {
      setRemaining((prev) => (prev > 1 ? prev - 1 : 0));
    }, 1000);

    return () => clearInterval(timer);
  }, []);

  if (remaining <= 0 || ip.status === "released") {
    return (
      <span className="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-500/10 text-blue-600 dark:text-blue-400 border border-blue-500/20">
        <Check className="w-3 h-3 mr-1 text-blue-500" />
        {t("statusReleased")}
      </span>
    );
  }

  return (
    <span className="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-amber-500/10 text-amber-600 dark:text-amber-400 border border-amber-500/20">
      <Clock className="w-3 h-3 mr-1 text-amber-500 animate-spin" />
      {t("statusCooling", { seconds: remaining })}
    </span>
  );
}

type TranslateFn = ReturnType<typeof useI18n>["t"];

function getIpStatusBadge(ip: IpAllocation, t: TranslateFn) {
  if (ip.status === "allocated") {
    return (
      <span className="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border border-emerald-500/20">
        <span className="w-1.5 h-1.5 rounded-full bg-emerald-500 dark:bg-emerald-400 mr-1.5" />
        {t("statusFloating")}
      </span>
    );
  }

  if (ip.status === "cooling" || ip.status === "released") {
    return <CoolingBadge ip={ip} />;
  }

  return (
    <span className="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-200 dark:bg-gray-800 text-gray-700 dark:text-gray-400">
      {ip.status}
    </span>
  );
}

interface IpTableRowProps {
  ip: IpAllocation;
  isAdmin?: boolean;
  isCopied: boolean;
  onCopy: (address: string) => void;
  onRequestRelease?: (ip: IpAllocation) => void;
}

/**
 * Memoized table row for a single IP allocation so cooling timer ticks or status
 * updates only re-render the affected row.
 */
export const IpTableRow = React.memo(function IpTableRow({
  ip,
  isAdmin,
  isCopied,
  onCopy,
  onRequestRelease,
}: IpTableRowProps) {
  const { t } = useI18n();
  const hasVm = ip.virtual_machine_id !== null;

  return (
    <tr className="hover:bg-gray-50 dark:hover:bg-gray-800/40 transition group">
      {/* IP & MAC Address */}
      <td className="px-4 sm:px-6 py-3.5">
        <div className="flex items-center space-x-2">
          <span className="font-mono font-semibold text-gray-900 dark:text-white">
            {ip.address || ip.ip_string}
          </span>
          <button
            onClick={() => onCopy(ip.address || ip.ip_string || "")}
            title={t("copyIp")}
            className="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 transition"
          >
            {isCopied ? (
              <Check className="w-3.5 h-3.5 text-emerald-500" />
            ) : (
              <Copy className="w-3.5 h-3.5" />
            )}
          </button>
        </div>
        {ip.mac_address && (
          <div className="text-[11px] font-mono text-gray-400 mt-0.5 flex items-center space-x-1">
            <Cpu className="w-3 h-3 text-gray-400" />
            <span>{ip.mac_address}</span>
          </div>
        )}
      </td>

      {/* Owner Column (Admin only) */}
      {isAdmin && (
        <td className="px-4 sm:px-6 py-3.5 text-xs whitespace-nowrap">
          {ip.user ? (
            <div>
              <div className="font-semibold text-gray-900 dark:text-white">{ip.user.name}</div>
              <div className="text-gray-400 text-[11px]">{ip.user.email}</div>
            </div>
          ) : (
            <span className="text-gray-400 italic">{t("unassignedOwner")}</span>
          )}
        </td>
      )}

      {/* Status */}
      <td className="px-4 sm:px-6 py-3.5 whitespace-nowrap">
        <div>{getIpStatusBadge(ip, t)}</div>
      </td>

      {/* Subnet Pool (Admin only) */}
      {isAdmin && (
        <td className="px-4 sm:px-6 py-3.5 whitespace-nowrap">
          <div className="text-xs font-mono text-gray-800 dark:text-gray-200">
            {ip.pool?.cidr || ip.pool?.subnet || "-"}
          </div>
          {ip.pool?.host && (
            <div className="text-[10px] text-indigo-600 dark:text-indigo-400 font-medium flex items-center space-x-1 mt-0.5">
              <Server className="w-2.5 h-2.5 flex-shrink-0" />
              <span>
                {ip.pool.parent_interface
                  ? `${ip.pool.host.name} (${ip.pool.parent_interface})`
                  : ip.pool.host.name}
              </span>
            </div>
          )}
        </td>
      )}

      {/* Attached Workload */}
      <td className="px-4 sm:px-6 py-3.5 whitespace-nowrap">
        {hasVm && ip.virtual_machine ? (
          <div className="flex items-center space-x-2">
            <div className="p-1.5 rounded-lg bg-blue-500/10 text-blue-600 dark:text-blue-400">
              <Server className="w-3.5 h-3.5" />
            </div>
            <div>
              <div className="font-semibold text-gray-900 dark:text-white text-xs">
                {ip.virtual_machine.name}
              </div>
              <div className="text-[10px] text-gray-500 capitalize">
                {ip.virtual_machine.status}
              </div>
            </div>
          </div>
        ) : (
          <span className="text-gray-400 italic text-xs">
            {t("noInstanceAttached")}
          </span>
        )}
      </td>

      {/* Emergency Release Action (Admin only) */}
      {isAdmin && (
        <td className="sticky right-0 z-10 px-4 sm:px-6 py-3.5 text-right bg-white dark:bg-gray-900 group-hover:bg-gray-50 dark:group-hover:bg-gray-800 border-l border-gray-200/80 dark:border-gray-800/80 shadow-[-4px_0_6px_-2px_rgba(0,0,0,0.05)] dark:shadow-[-4px_0_6px_-2px_rgba(0,0,0,0.3)] transition-colors">
          <button
            onClick={() => onRequestRelease?.(ip)}
            title={ip.status === "allocated" ? t("forceReleaseAllocation") : t("dismissCooledIp")}
            className="p-1.5 rounded-lg bg-gray-100 hover:bg-red-500/20 dark:bg-gray-800 dark:hover:bg-red-500/20 text-gray-600 dark:text-gray-300 hover:text-red-500 transition"
          >
            <Trash2 className="w-4 h-4" />
          </button>
        </td>
      )}
    </tr>
  );
});

export function IpTable({
  ips,
  loading,
  isAdmin,
  pools = [],
  onRequestRelease,
  onAddPool,
  onRequestDeletePool,
  onPoolUpdated,
  pagination,
  poolsPagination,
}: IpTableProps) {
  const { t } = useI18n();
  const [copiedIp, setCopiedIp] = useState<string | null>(null);
  const [editingPool, setEditingPool] = useState<IpPool | null>(null);
  const [preflightTarget, setPreflightTarget] = useState<IpPool | null>(null);
  const [inspectingPool, setInspectingPool] = useState<IpPool | null>(null);

  const handleCopy = useCallback((address: string) => {
    navigator.clipboard.writeText(address);
    setCopiedIp(address);
    setTimeout(() => setCopiedIp(null), 2000);
  }, []);

  return (
    <div className="space-y-6">
      {/* Admin IP Subnet Pools Section */}
      {isAdmin && (
        <div className="space-y-3">
          <div className="flex items-center justify-between">
            <div>
              <h3 className="text-base font-bold text-gray-900 dark:text-white tracking-tight flex items-center space-x-2">
                <Network className="w-4 h-4 text-teal-500" />
                <span>{t("ipPools")}</span>
              </h3>
              <p className="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                {t("ipPoolsRangedSubtitle")}
              </p>
            </div>

            {isAdmin && onAddPool && (
              <button
                onClick={onAddPool}
                className="flex items-center space-x-2 px-3.5 py-2 rounded-xl bg-teal-600 hover:bg-teal-500 text-white text-xs font-semibold shadow-md shadow-teal-500/20 transition"
              >
                <Plus className="w-3.5 h-3.5" />
                <span>{t("addPool")}</span>
              </button>
            )}
          </div>

          <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
            {pools.map((p) => {
              const util = p.utilization || {
                total: p.total_ips ?? 0,
                allocated: p.allocated_ips ?? 0,
                cooling: p.cooling_ips ?? 0,
                free: p.free_ips ?? 0,
                percent: 0,
              };
              const occupied = util.allocated + util.cooling;
              const percent = util.total > 0 ? Math.round((occupied / util.total) * 100) : 0;

              return (
                <div
                  key={p.id}
                  className="bg-white dark:bg-gray-900/60 border border-gray-200 dark:border-gray-800 rounded-2xl p-4 shadow-sm relative transition-colors flex flex-col justify-between"
                >
                  <div>
                    <div className="flex items-center justify-between mb-1.5">
                      <div className="flex items-center space-x-2">
                        <button
                          type="button"
                          onClick={() => setInspectingPool(p)}
                          title={t("viewPoolIps")}
                          className="font-semibold text-gray-900 dark:text-white text-sm cursor-pointer hover:underline text-left"
                        >
                          {p.name}
                        </button>
                        {p.network_mode === "manual" ? (
                          <span
                            title={t("manualWarning")}
                            className="inline-flex items-center space-x-1 text-[10px] px-2 py-0.5 rounded-full font-medium bg-amber-500/10 text-amber-700 dark:text-amber-300 border border-amber-500/30"
                          >
                            <AlertTriangle className="w-3 h-3 text-amber-500" />
                            <span>{t("manualBadge")}</span>
                          </span>
                        ) : (
                          <span
                            className={`text-[10px] px-2 py-0.5 rounded-full font-mono border ${
                              p.network_mode === "bridged"
                                ? "bg-blue-500/10 text-blue-600 dark:text-blue-400 border-blue-500/20"
                                : p.network_mode === "macvlan"
                                  ? "bg-purple-500/10 text-purple-600 dark:text-purple-400 border-purple-500/20"
                                  : "bg-teal-500/10 text-teal-600 dark:text-teal-400 border-teal-500/20"
                            }`}
                          >
                            {p.network_mode === "bridged"
                              ? t("bridgedBadge")
                              : p.network_mode === "macvlan"
                                ? t("macvlanBadge")
                                : t("routedBadge")}
                          </span>
                        )}
                      </div>

                      <div className="flex items-center space-x-1">
                        <button
                          type="button"
                          onClick={() => setInspectingPool(p)}
                          title={t("viewPoolIps")}
                          className="p-1.5 rounded-lg text-gray-400 hover:text-teal-600 dark:hover:text-teal-400 hover:bg-teal-500/10 transition"
                        >
                          <Eye className="w-3.5 h-3.5" />
                        </button>
                        <button
                          onClick={() => setPreflightTarget(p)}
                          title={t("preflight")}
                          className="p-1.5 rounded-lg text-gray-400 hover:text-teal-600 dark:hover:text-teal-400 hover:bg-teal-500/10 transition"
                        >
                          <ShieldCheck className="w-3.5 h-3.5" />
                        </button>
                        <button
                          onClick={() => setEditingPool(p)}
                          title={t("editPool")}
                          className="p-1.5 rounded-lg text-gray-400 hover:text-blue-600 dark:hover:text-blue-400 hover:bg-blue-500/10 transition"
                        >
                          <Edit2 className="w-3.5 h-3.5" />
                        </button>
                        {onRequestDeletePool && (
                          <button
                            onClick={() => onRequestDeletePool(p.id, p.name)}
                            title={t("deletePool")}
                            className="p-1.5 rounded-lg text-gray-400 hover:text-red-500 hover:bg-red-500/10 transition"
                          >
                            <Trash2 className="w-3.5 h-3.5" />
                          </button>
                        )}
                      </div>
                    </div>

                    <div className="font-mono text-xs font-semibold text-teal-600 dark:text-teal-400 mb-2">
                      {p.cidr || p.subnet}
                    </div>

                    <div className="text-[11px] text-gray-500 dark:text-gray-400 space-y-1">
                      {p.host ? (
                        <div className="flex items-center space-x-1.5 text-indigo-600 dark:text-indigo-400 font-medium">
                          <Server className="w-3 h-3 flex-shrink-0" />
                          <span>
                            {p.parent_interface ? `${p.host.name} (${p.parent_interface})` : p.host.name}
                          </span>
                        </div>
                      ) : (
                        <div className="flex items-center space-x-1.5 text-gray-500 dark:text-gray-400">
                          <Globe className="w-3 h-3 flex-shrink-0" />
                          <span>{t("globalNode")}</span>
                        </div>
                      )}
                      {p.gateway && (
                        <div>
                          {t("poolGateway")}: <span className="font-mono text-gray-700 dark:text-gray-300">{p.gateway}</span>
                        </div>
                      )}
                      {p.first_usable && p.last_usable && (
                        <div className="font-mono text-[10px] text-gray-400">
                          {t("poolRange", { first: p.first_usable, last: p.last_usable })}
                        </div>
                      )}
                      {p.description && <div className="italic pt-0.5">{p.description}</div>}
                    </div>
                  </div>

                  {/* Utilization Progress Bar */}
                  <div className="mt-3 pt-2.5 border-t border-gray-100 dark:border-gray-800">
                    <div className="flex items-center justify-between text-[11px] mb-1">
                      <span className="text-gray-500 dark:text-gray-400 font-medium">{t("poolCapacity")}</span>
                      <span className="font-mono font-semibold text-gray-900 dark:text-white">
                        {occupied} / {util.total} ({percent}%)
                      </span>
                    </div>
                    <div className="w-full h-1.5 bg-gray-100 dark:bg-gray-800 rounded-full overflow-hidden">
                      <div
                        className={`h-full transition-all duration-300 rounded-full ${
                          percent > 90
                            ? "bg-red-500"
                            : percent > 70
                            ? "bg-amber-500"
                            : "bg-teal-500"
                        }`}
                        style={{ width: `${Math.min(100, percent)}%` }}
                      />
                    </div>
                    <div className="flex items-center justify-between mt-1 text-[10px] text-gray-400">
                      <button
                        type="button"
                        onClick={() => setInspectingPool(p)}
                        title={t("viewPoolIps")}
                        className="cursor-pointer hover:underline hover:text-teal-600 dark:hover:text-teal-400 transition"
                      >
                        {t("poolFreeCount", { count: util.free })}
                      </button>
                      {util.cooling > 0 && <span>{t("poolCoolingCount", { count: util.cooling })}</span>}
                    </div>
                  </div>
                </div>
              );
            })}
          </div>
          {poolsPagination && poolsPagination.total > 0 && (
            <div className="bg-white dark:bg-gray-900/60 border border-gray-200 dark:border-gray-800 rounded-2xl overflow-hidden">
              <PaginationBar
                currentPage={poolsPagination.currentPage}
                lastPage={poolsPagination.lastPage}
                perPage={poolsPagination.perPage}
                total={poolsPagination.total}
                onPageChange={poolsPagination.onPageChange}
                onPerPageChange={poolsPagination.onPerPageChange}
              />
            </div>
          )}
        </div>
      )}

      {/* IP Allocations Table */}
      {loading && ips.length === 0 ? (
        <div className="bg-white dark:bg-gray-900/60 border border-gray-200 dark:border-gray-800 rounded-2xl p-12 text-center transition-colors">
          <Loader2 className="w-8 h-8 text-blue-500 animate-spin mx-auto mb-3" />
          <p className="text-sm text-gray-500 dark:text-gray-400">{t("loadingIps")}</p>
        </div>
      ) : ips.length === 0 ? (
        <div className="bg-white dark:bg-gray-900/60 border border-gray-200 dark:border-gray-800 rounded-2xl p-12 text-center transition-colors">
          <div className="w-12 h-12 rounded-2xl bg-gray-100 dark:bg-gray-800/80 text-gray-500 dark:text-gray-400 flex items-center justify-center mx-auto mb-4">
            <Globe className="w-6 h-6" />
          </div>
          <h3 className="text-base font-semibold text-gray-900 dark:text-gray-200">{t("noActiveIpAllocations")}</h3>
          <p className="text-xs text-gray-500 dark:text-gray-400 mt-1 max-w-sm mx-auto">
            {t("noActiveIpAllocationsDesc")}
          </p>
        </div>
      ) : (
        <div className="bg-white dark:bg-gray-900/60 border border-gray-200 dark:border-gray-800 rounded-2xl overflow-hidden backdrop-blur-sm shadow-sm dark:shadow-none transition-colors">
          <div className="overflow-x-auto relative">
            <table className="w-full text-left text-sm text-gray-600 dark:text-gray-300">
              <thead className="bg-gray-50 dark:bg-gray-950/60 text-xs uppercase text-gray-500 dark:text-gray-400 border-b border-gray-200 dark:border-gray-800 font-semibold tracking-wider">
                <tr>
                  <th scope="col" className="px-4 sm:px-6 py-3.5">{t("colIpMac")}</th>
                  {isAdmin && <th scope="col" className="px-4 sm:px-6 py-3.5">{t("colOwner")}</th>}
                  <th scope="col" className="px-4 sm:px-6 py-3.5">{t("colStatus")}</th>
                  {isAdmin && <th scope="col" className="px-4 sm:px-6 py-3.5">{t("colPoolSubnet")}</th>}
                  <th scope="col" className="px-4 sm:px-6 py-3.5">{t("colAttachedVm")}</th>
                  {isAdmin && (
                    <th scope="col" className="sticky right-0 z-20 px-4 sm:px-6 py-3.5 text-right bg-gray-50 dark:bg-gray-950 border-l border-gray-200 dark:border-gray-800 shadow-[-4px_0_6px_-2px_rgba(0,0,0,0.05)] dark:shadow-[-4px_0_6px_-2px_rgba(0,0,0,0.3)]">
                      {t("colActions")}
                    </th>
                  )}
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-200 dark:divide-gray-800/60">
                {ips.map((ip) => (
                  <IpTableRow
                    key={ip.id}
                    ip={ip}
                    isAdmin={isAdmin}
                    isCopied={copiedIp === ip.address}
                    onCopy={handleCopy}
                    onRequestRelease={onRequestRelease}
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
      )}

      {editingPool && (
        <EditIpPoolModal
          pool={editingPool}
          onClose={() => setEditingPool(null)}
          onSuccess={(updated) => {
            setEditingPool(null);
            onPoolUpdated?.(updated);
          }}
        />
      )}

      {preflightTarget && (
        <PreflightModal
          pool={preflightTarget}
          onClose={() => setPreflightTarget(null)}
        />
      )}

      {inspectingPool && (
        <PoolDetailModal
          pool={inspectingPool}
          onClose={() => setInspectingPool(null)}
        />
      )}
    </div>
  );
}
