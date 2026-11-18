"use client";

import React, { useState } from "react";
import {
  Server,
  Globe,
  Plus,
  Edit2,
  Trash2,
  Layers,
  Network,
  ShieldAlert,
  RefreshCw,
  AlertTriangle,
  CheckCircle2,
  AlertCircle,
  Loader2,
  Activity,
  Fingerprint,
  Clock,
} from "lucide-react";
import { HostNode } from "@/lib/api";
import { PaginationBar } from "@/components/ui/PaginationBar";
import { useI18n } from "@/lib/i18n/I18nContext";

export interface NodesTableProps {
  hosts: HostNode[];
  loading?: boolean;
  onVerifyHost: (hostId: number) => Promise<void>;
  verifyingHostId: number | null;
  onSyncHostResources: (hostId: number) => Promise<void>;
  syncingHostId: number | null;
  onRetrustHost: (hostId: number, token: string) => Promise<void>;
  retrustLoadingId: number | null;
  onEditNode: (host: HostNode) => void;
  onDeleteNode: (hostId: number, hostName: string) => void;
  onAddNode: () => void;
  pagination?: {
    currentPage: number;
    lastPage: number;
    perPage: number;
    total: number;
    onPageChange: (page: number) => void;
    onPerPageChange?: (perPage: number) => void;
  };
}

export function NodesTable({
  hosts,
  loading = false,
  onVerifyHost,
  verifyingHostId,
  onSyncHostResources,
  syncingHostId,
  onRetrustHost,
  retrustLoadingId,
  onEditNode,
  onDeleteNode,
  onAddNode,
  pagination,
}: NodesTableProps) {
  const { t } = useI18n();
  const [retrustTokens, setRetrustTokens] = useState<Record<number, string>>({});

  const handleRetrustSubmit = async (hostId: number) => {
    const token = retrustTokens[hostId]?.trim();
    if (!token) return;

    try {
      await onRetrustHost(hostId, token);
      setRetrustTokens((prev) => {
        const next = { ...prev };
        delete next[hostId];
        return next;
      });
    } catch {
      // Preserve entered token so the administrator can correct it if re-trust fails.
    }
  };

  const renderConnectionBadge = (status?: string) => {
    switch (status) {
      case "ok":
        return (
          <span className="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-medium bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border border-emerald-500/20">
            <CheckCircle2 className="w-3 h-3 mr-1" />
            Verified &amp; Trusted
          </span>
        );
      case "unreachable":
        return (
          <span className="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-medium bg-slate-500/10 text-slate-600 dark:text-slate-400 border border-slate-500/20">
            <AlertCircle className="w-3 h-3 mr-1" />
            Unreachable
          </span>
        );
      case "fingerprint_mismatch":
        return (
          <span className="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-medium bg-amber-500/15 text-amber-700 dark:text-amber-300 border border-amber-500/30">
            <ShieldAlert className="w-3 h-3 mr-1 text-amber-600 dark:text-amber-400" />
            Fingerprint Mismatch
          </span>
        );
      case "not_trusted":
        return (
          <span className="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-medium bg-orange-500/10 text-orange-700 dark:text-orange-400 border border-orange-500/20">
            <AlertTriangle className="w-3 h-3 mr-1" />
            Untrusted
          </span>
        );
      case "error":
        return (
          <span className="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-medium bg-red-500/10 text-red-600 dark:text-red-400 border border-red-500/20">
            <AlertCircle className="w-3 h-3 mr-1" />
            Error
          </span>
        );
      default:
        return null;
    }
  };

  const renderCapacitySourceBadge = (source?: string) => {
    const resolved = source || "unknown";
    if (resolved === "discovered") {
      return (
        <span className="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border border-emerald-500/20">
          <CheckCircle2 className="w-2.5 h-2.5 mr-1" />
          {t("capacitySourceDetected") || "Detected"}
        </span>
      );
    }
    if (resolved === "manual") {
      return (
        <span className="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 border border-indigo-500/20">
          {t("capacitySourceManual") || "Manual"}
        </span>
      );
    }
    return (
      <span className="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold bg-amber-500/10 text-amber-600 dark:text-amber-400 border border-amber-500/20">
        <AlertTriangle className="w-2.5 h-2.5 mr-1" />
        {t("capacitySourceUnknown") || "Unknown — sync needed"}
      </span>
    );
  };

  return (
    <div className="bg-white dark:bg-gray-900/60 border border-gray-200 dark:border-gray-800 rounded-2xl overflow-hidden backdrop-blur-sm shadow-sm dark:shadow-none transition-colors">
      {loading && hosts.length === 0 ? (
        <div className="flex flex-col items-center justify-center py-14 text-gray-400 space-y-2.5">
          <Loader2 className="w-7 h-7 animate-spin text-indigo-500" />
          <span className="text-xs text-gray-500 dark:text-gray-400">
            Loading compute nodes...
          </span>
        </div>
      ) : hosts.length === 0 ? (
        <div className="p-12 text-center">
          <div className="w-12 h-12 rounded-2xl bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 flex items-center justify-center mx-auto mb-4">
            <Server className="w-6 h-6" />
          </div>
          <h3 className="text-base font-semibold text-gray-900 dark:text-white mb-1">
            No compute nodes configured
          </h3>
          <p className="text-xs text-gray-500 dark:text-gray-400 mb-5 max-w-sm mx-auto">
            Add your first Incus compute node via trust token or public certificate to begin deploying workloads.
          </p>
          <button
            type="button"
            onClick={onAddNode}
            className="inline-flex items-center space-x-2 px-4 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-semibold shadow-md shadow-indigo-500/20 transition"
          >
            <Plus className="w-4 h-4" />
            <span>{t("createNode")}</span>
          </button>
        </div>
      ) : (
        <div className="overflow-x-auto relative">
          <table className="w-full text-left text-sm text-gray-600 dark:text-gray-300">
            <thead className="bg-gray-50 dark:bg-gray-950/60 text-xs uppercase text-gray-500 dark:text-gray-400 border-b border-gray-200 dark:border-gray-800 font-semibold tracking-wider">
              <tr>
                <th scope="col" className="px-4 sm:px-5 lg:px-6 py-3.5">
                  {t("colNode")}
                </th>
                <th scope="col" className="px-4 sm:px-5 lg:px-6 py-3.5">
                  {t("colStatus")} &amp; Trust
                </th>
                <th scope="col" className="px-4 sm:px-5 lg:px-6 py-3.5">
                  {t("nodeCapacity")}
                </th>
                <th scope="col" className="px-4 sm:px-5 lg:px-6 py-3.5">
                  {t("colWorkloadsCount")}
                </th>
                <th
                  scope="col"
                  className="sticky right-0 z-20 px-4 sm:px-5 lg:px-6 py-3.5 text-right bg-gray-50 dark:bg-gray-950 border-l border-gray-200 dark:border-gray-800 shadow-[-4px_0_6px_-2px_rgba(0,0,0,0.05)] dark:shadow-[-4px_0_6px_-2px_rgba(0,0,0,0.3)]"
                >
                  {t("colActions")}
                </th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-200 dark:divide-gray-800/60">
              {hosts.map((host) => {
                const isVerifying = verifyingHostId === host.id;
                const isSyncing = syncingHostId === host.id;
                const isRetrusting = retrustLoadingId === host.id;
                const capacitySource = host.capacity_source || host.capacity?.source;
                const committedCpu = host.capacity?.cpu?.committed ?? 0;
                const committedRamGb = Math.round((host.capacity?.ram?.committed ?? 0) / 1024);
                const totalRamGb = Math.round(host.total_ram_mb / 1024);
                const committedDisk = host.capacity?.disk?.committed ?? 0;

                return (
                  <React.Fragment key={host.id}>
                    <tr className="hover:bg-gray-50 dark:hover:bg-gray-800/40 transition group">
                      {/* Node Name, Driver, Region, Endpoint URL & Fingerprint */}
                      <td className="px-4 sm:px-5 lg:px-6 py-3.5 align-top">
                        <div className="flex items-center space-x-2 flex-wrap gap-y-1">
                          <span className="font-mono text-sm font-bold text-gray-900 dark:text-white">
                            {host.name}
                          </span>
                          <span className="font-mono text-[10px] uppercase font-bold text-indigo-600 dark:text-indigo-400 px-1.5 py-0.5 rounded bg-indigo-50 dark:bg-indigo-950/40 border border-indigo-200/50 dark:border-indigo-800/50">
                            {host.driver}
                          </span>
                        </div>

                        {host.region && (
                          <div className="flex items-center space-x-1.5 text-xs text-gray-500 dark:text-gray-400 mt-1">
                            <Globe className="w-3.5 h-3.5 text-gray-400 shrink-0" />
                            <span>{host.region}</span>
                          </div>
                        )}

                        {host.endpoint_url && (
                          <div className="mt-1.5 font-mono text-[11px] text-gray-600 dark:text-gray-400 truncate max-w-xs">
                            {host.endpoint_url}
                          </div>
                        )}

                        {host.server_fingerprint && (
                          <div
                            title={host.server_fingerprint}
                            className="mt-1 flex items-center space-x-1 text-[10px] text-gray-400 font-mono truncate max-w-xs"
                          >
                            <Fingerprint className="w-3 h-3 shrink-0 text-indigo-400" />
                            <span className="truncate">{host.server_fingerprint}</span>
                          </div>
                        )}
                      </td>

                      {/* Status & Trust */}
                      <td className="px-4 sm:px-5 lg:px-6 py-3.5 align-top whitespace-nowrap">
                        <div className="flex flex-col items-start space-y-1.5">
                          <div className="flex items-center flex-wrap gap-1.5">
                            <span
                              className={`inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-medium ${
                                host.is_active
                                  ? "bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border border-emerald-500/20"
                                  : "bg-gray-500/10 text-gray-600 dark:text-gray-400 border border-gray-500/20"
                              }`}
                            >
                              <span
                                className={`w-1.5 h-1.5 rounded-full mr-1.5 ${
                                  host.is_active ? "bg-emerald-500" : "bg-gray-400"
                                }`}
                              />
                              {host.is_active ? t("nodeActive") : t("nodeInactive")}
                            </span>
                            {renderConnectionBadge(host.status)}
                          </div>

                          {host.last_checked_at && (
                            <div className="flex items-center space-x-1 text-[10px] text-gray-400">
                              <Clock className="w-3 h-3 shrink-0" />
                              <span>{new Date(host.last_checked_at).toLocaleString()}</span>
                            </div>
                          )}
                        </div>
                      </td>

                      {/* Capacity */}
                      <td className="px-4 sm:px-5 lg:px-6 py-3.5 align-top whitespace-nowrap">
                        <div className="mb-1.5">{renderCapacitySourceBadge(capacitySource)}</div>
                        <div className="grid grid-cols-3 gap-x-3 gap-y-1 text-xs">
                          <div>
                            <span className="text-[10px] text-gray-400 block">vCPU</span>
                            <span className="font-mono font-semibold text-gray-900 dark:text-white">
                              {committedCpu} / {host.total_cpu_cores}
                            </span>
                          </div>
                          <div>
                            <span className="text-[10px] text-gray-400 block">RAM</span>
                            <span className="font-mono font-semibold text-gray-900 dark:text-white">
                              {committedRamGb} / {totalRamGb} GB
                            </span>
                          </div>
                          <div>
                            <span className="text-[10px] text-gray-400 block">Storage</span>
                            <span className="font-mono font-semibold text-gray-900 dark:text-white">
                              {committedDisk} / {host.total_storage_gb} GB
                            </span>
                          </div>
                        </div>
                      </td>

                      {/* Workloads */}
                      <td className="px-4 sm:px-5 lg:px-6 py-3.5 align-top whitespace-nowrap">
                        <div className="flex flex-col space-y-1.5 text-xs text-gray-600 dark:text-gray-300">
                          <span className="inline-flex items-center space-x-1.5">
                            <Layers className="w-3.5 h-3.5 text-blue-500 shrink-0" />
                            <span className="font-mono font-semibold text-gray-900 dark:text-white">
                              {host.virtual_machines_count ?? 0}
                            </span>
                            <span className="text-gray-500 dark:text-gray-400">
                              {t("colInstance")}
                            </span>
                          </span>
                          <span className="inline-flex items-center space-x-1.5">
                            <Network className="w-3.5 h-3.5 text-teal-500 shrink-0" />
                            <span className="font-mono font-semibold text-gray-900 dark:text-white">
                              {host.ip_pools_count ?? 0}
                            </span>
                            <span className="text-gray-500 dark:text-gray-400">
                              {t("ipPools")}
                            </span>
                          </span>
                        </div>
                      </td>

                      {/* Sticky Right Actions */}
                      <td className="sticky right-0 z-10 px-4 sm:px-5 lg:px-6 py-3.5 align-top text-right bg-white dark:bg-gray-900 group-hover:bg-gray-50 dark:group-hover:bg-gray-800 border-l border-gray-200/80 dark:border-gray-800/80 shadow-[-4px_0_6px_-2px_rgba(0,0,0,0.05)] dark:shadow-[-4px_0_6px_-2px_rgba(0,0,0,0.3)] transition-colors">
                        <div className="flex items-center justify-end space-x-1.5">
                          <button
                            type="button"
                            onClick={() => onSyncHostResources(host.id)}
                            disabled={isSyncing}
                            title={t("syncFromHypervisor") || "Sync from hypervisor"}
                            className="p-1.5 rounded-lg bg-gray-100 hover:bg-indigo-500/15 dark:bg-gray-800 dark:hover:bg-indigo-500/20 text-gray-600 dark:text-gray-300 hover:text-indigo-600 dark:hover:text-indigo-400 transition disabled:opacity-40 disabled:pointer-events-none"
                          >
                            {isSyncing ? (
                              <Loader2 className="w-4 h-4 animate-spin text-indigo-600 dark:text-indigo-400" />
                            ) : (
                              <RefreshCw className="w-4 h-4" />
                            )}
                          </button>

                          <button
                            type="button"
                            onClick={() => onVerifyHost(host.id)}
                            disabled={isVerifying}
                            title={t("verifyConnection") || "Verify connection"}
                            className="p-1.5 rounded-lg bg-gray-100 hover:bg-indigo-500/15 dark:bg-gray-800 dark:hover:bg-indigo-500/20 text-gray-600 dark:text-gray-300 hover:text-indigo-600 dark:hover:text-indigo-400 transition disabled:opacity-40 disabled:pointer-events-none"
                          >
                            {isVerifying ? (
                              <Loader2 className="w-4 h-4 animate-spin text-indigo-600 dark:text-indigo-400" />
                            ) : (
                              <Activity className="w-4 h-4" />
                            )}
                          </button>

                          <button
                            type="button"
                            onClick={() => onEditNode(host)}
                            title={t("editNode")}
                            className="p-1.5 rounded-lg bg-gray-100 hover:bg-gray-200 dark:bg-gray-800 dark:hover:bg-gray-700 text-gray-600 dark:text-gray-300 hover:text-gray-900 dark:hover:text-white transition"
                          >
                            <Edit2 className="w-4 h-4" />
                          </button>

                          <button
                            type="button"
                            onClick={() => onDeleteNode(host.id, host.name)}
                            title={t("deleteNode")}
                            className="p-1.5 rounded-lg bg-gray-100 hover:bg-red-500/20 dark:bg-gray-800 dark:hover:bg-red-500/20 text-gray-600 dark:text-gray-300 hover:text-red-500 transition"
                          >
                            <Trash2 className="w-4 h-4" />
                          </button>
                        </div>
                      </td>
                    </tr>

                    {host.needs_retrust && (
                      <tr className="bg-amber-500/5 dark:bg-amber-500/10">
                        <td colSpan={5} className="px-4 sm:px-5 lg:px-6 py-3">
                          <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                            <div className="flex items-center space-x-2 text-xs text-amber-800 dark:text-amber-300">
                              <ShieldAlert className="w-4 h-4 text-amber-600 dark:text-amber-400 shrink-0" />
                              <span className="font-medium">
                                {t("needsRetrustWarning") ||
                                  "Needs re-trust after panel certificate rotation"}
                              </span>
                            </div>
                            <div className="flex items-center space-x-2 sm:min-w-[320px]">
                              <input
                                type="password"
                                placeholder="Paste Incus trust token..."
                                value={retrustTokens[host.id] ?? ""}
                                onChange={(e) =>
                                  setRetrustTokens((prev) => ({
                                    ...prev,
                                    [host.id]: e.target.value,
                                  }))
                                }
                                className="flex-1 px-2.5 py-1.5 text-xs rounded-lg bg-white dark:bg-gray-900 border border-amber-300 dark:border-amber-700 font-mono text-gray-900 dark:text-gray-100 placeholder-gray-400 focus:outline-none focus:ring-1 focus:ring-amber-500"
                              />
                              <button
                                type="button"
                                onClick={() => handleRetrustSubmit(host.id)}
                                disabled={isRetrusting || !retrustTokens[host.id]?.trim()}
                                className="px-3 py-1.5 text-xs font-semibold rounded-lg bg-amber-600 hover:bg-amber-700 text-white disabled:opacity-50 transition flex items-center space-x-1.5 shrink-0"
                              >
                                {isRetrusting ? (
                                  <Loader2 className="w-3.5 h-3.5 animate-spin" />
                                ) : (
                                  <span>Re-trust</span>
                                )}
                              </button>
                            </div>
                          </div>
                        </td>
                      </tr>
                    )}
                  </React.Fragment>
                );
              })}
            </tbody>
          </table>
        </div>
      )}

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
