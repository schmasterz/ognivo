"use client";

import React, { useCallback, useEffect, useMemo, useState } from "react";
import {
  Plus,
  ShieldCheck,
  Download,
  RefreshCw,
  AlertTriangle,
  Loader2,
  X,
  Search,
} from "lucide-react";
import { api, HostNode, PanelCertificateInfo } from "@/lib/api";
import { extractPaginationMeta } from "@/lib/api/client";
import { useI18n } from "@/lib/i18n/I18nContext";
import { NodesTable } from "./NodesTable";

export interface NodesTabProps {
  initialHosts?: HostNode[];
  onAddNode: () => void;
  onEditNode: (host: HostNode) => void;
  onDeleteNode: (hostId: number, hostName: string) => void;
  onRefreshHosts?: () => void;
}

export function NodesTab({
  initialHosts,
  onAddNode,
  onEditNode,
  onDeleteNode,
  onRefreshHosts,
}: NodesTabProps) {
  const { t } = useI18n();
  const fallbackHosts = useMemo(() => initialHosts ?? [], [initialHosts]);

  const [hosts, setHosts] = useState<HostNode[]>(fallbackHosts);
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(10);
  const [lastPage, setLastPage] = useState(1);
  const [total, setTotal] = useState(fallbackHosts.length);
  const [tableLoading, setTableLoading] = useState(false);

  const [searchQuery, setSearchQuery] = useState("");
  const [debouncedSearch, setDebouncedSearch] = useState("");
  const [statusFilter, setStatusFilter] = useState("");
  const [activeFilter, setActiveFilter] = useState("");
  const [driverFilter, setDriverFilter] = useState("");

  const [certInfo, setCertInfo] = useState<PanelCertificateInfo | null>(null);
  const [certLoading, setCertLoading] = useState(true);
  const [downloadLoading, setDownloadLoading] = useState(false);
  const [rotateLoading, setRotateLoading] = useState(false);
  const [revokeLoading, setRevokeLoading] = useState(false);

  const [verifyingHostId, setVerifyingHostId] = useState<number | null>(null);
  const [retrustLoadingId, setRetrustLoadingId] = useState<number | null>(null);
  const [syncingHostId, setSyncingHostId] = useState<number | null>(null);
  const [actionMessage, setActionMessage] = useState<string | null>(null);

  useEffect(() => {
    const timer = setTimeout(() => {
      setDebouncedSearch(searchQuery);
      setPage(1);
    }, 300);
    return () => clearTimeout(timer);
  }, [searchQuery]);

  const hasActiveFilters = Boolean(
    searchQuery.trim() || statusFilter || activeFilter || driverFilter
  );

  const handleClearFilters = () => {
    setSearchQuery("");
    setDebouncedSearch("");
    setStatusFilter("");
    setActiveFilter("");
    setDriverFilter("");
    setPage(1);
  };

  const fetchHostsPage = useCallback(async () => {
    try {
      setTableLoading(true);
      const res = await api.adminGetHosts({
        page,
        per_page: perPage,
        search: debouncedSearch || undefined,
        status: statusFilter || undefined,
        is_active: activeFilter || undefined,
        driver: driverFilter || undefined,
      });
      setHosts(res.data);
      const meta = extractPaginationMeta(res, perPage);
      setLastPage(meta.lastPage);
      setTotal(meta.total);
      if (page > meta.lastPage && meta.lastPage >= 1) {
        setPage(meta.lastPage);
      }
    } catch {
      let filtered = fallbackHosts;
      const query = debouncedSearch.trim().toLowerCase();
      if (query) {
        filtered = filtered.filter(
          (h) =>
            h.name.toLowerCase().includes(query) ||
            (h.region && h.region.toLowerCase().includes(query)) ||
            (h.endpoint_url && h.endpoint_url.toLowerCase().includes(query)) ||
            (h.server_fingerprint &&
              h.server_fingerprint.toLowerCase().includes(query))
        );
      }
      if (statusFilter === "needs_retrust") {
        filtered = filtered.filter((h) => Boolean(h.needs_retrust));
      } else if (statusFilter) {
        filtered = filtered.filter((h) => h.status === statusFilter);
      }
      if (activeFilter === "true") {
        filtered = filtered.filter((h) => Boolean(h.is_active));
      } else if (activeFilter === "false") {
        filtered = filtered.filter((h) => !h.is_active);
      }
      if (driverFilter) {
        filtered = filtered.filter((h) => h.driver === driverFilter);
      }
      const filteredTotal = filtered.length;
      const calculatedLastPage = Math.max(1, Math.ceil(filteredTotal / perPage));
      const effectivePage = Math.min(page, calculatedLastPage);
      setHosts(
        filtered.slice((effectivePage - 1) * perPage, effectivePage * perPage)
      );
      setTotal(filteredTotal);
      setLastPage(calculatedLastPage);
      if (page > calculatedLastPage) {
        setPage(calculatedLastPage);
      }
    } finally {
      setTableLoading(false);
    }
  }, [
    page,
    perPage,
    debouncedSearch,
    statusFilter,
    activeFilter,
    driverFilter,
    fallbackHosts,
  ]);

  useEffect(() => {
    let ignore = false;
    queueMicrotask(() => {
      if (!ignore) {
        void fetchHostsPage();
      }
    });
    return () => {
      ignore = true;
    };
  }, [fetchHostsPage]);

  const loadCertificate = useCallback(async () => {
    try {
      const res = await api.adminGetPanelCertificate();
      setCertInfo(res);
    } catch {
      // Certificate might not be generated yet
    } finally {
      setCertLoading(false);
    }
  }, []);

  useEffect(() => {
    let ignore = false;
    queueMicrotask(() => {
      if (!ignore) {
        void loadCertificate();
      }
    });
    return () => {
      ignore = true;
    };
  }, [loadCertificate]);

  const handleDownloadCertificate = async () => {
    try {
      setDownloadLoading(true);
      await api.adminDownloadPanelCertificate();
      await loadCertificate();
    } catch (err: unknown) {
      alert(err instanceof Error ? err.message : "Failed to download certificate.");
    } finally {
      setDownloadLoading(false);
    }
  };

  const handleRotateCertificate = async () => {
    if (
      !confirm(
        "Are you sure you want to rotate the panel client certificate? The current certificate will be marked as 'retiring' and remain usable until all compute nodes trust the new certificate."
      )
    ) {
      return;
    }

    try {
      setRotateLoading(true);
      setActionMessage(null);
      const res = await api.adminRotatePanelCertificate(365);
      setActionMessage(res.message);
      await Promise.all([loadCertificate(), fetchHostsPage()]);
      onRefreshHosts?.();
    } catch (err: unknown) {
      alert(err instanceof Error ? err.message : "Failed to rotate certificate.");
    } finally {
      setRotateLoading(false);
    }
  };

  const handleRevokeOldCertificate = async () => {
    try {
      setRevokeLoading(true);
      setActionMessage(null);
      const res = await api.adminRevokeOldPanelCertificate();
      setActionMessage(
        `${res.message} To clean up remote hosts, run: ${res.cleanup_instruction}`
      );
      await loadCertificate();
    } catch (err: unknown) {
      alert(err instanceof Error ? err.message : "Failed to revoke retiring certificate.");
    } finally {
      setRevokeLoading(false);
    }
  };

  const handleVerifyHost = async (hostId: number) => {
    try {
      setVerifyingHostId(hostId);
      const res = await api.adminVerifyHostConnection(hostId);
      setHosts((prev) =>
        prev.map((h) =>
          h.id === hostId
            ? {
                ...h,
                status: res.state ?? (res.success ? "ok" : "error"),
                needs_retrust: res.is_trusted ? false : h.needs_retrust,
                last_checked_at: new Date().toISOString(),
              }
            : h
        )
      );
      await Promise.all([loadCertificate(), fetchHostsPage()]);
      onRefreshHosts?.();
    } catch (err: unknown) {
      alert(err instanceof Error ? err.message : "Verification failed.");
    } finally {
      setVerifyingHostId(null);
    }
  };

  const handleRetrustHost = async (hostId: number, token: string) => {
    const trimmed = token.trim();
    if (!trimmed) return;

    try {
      setRetrustLoadingId(hostId);
      setActionMessage(null);
      const res = await api.adminRetrustHost(hostId, trimmed);
      setActionMessage(res.message);
      setHosts((prev) =>
        prev.map((h) =>
          h.id === hostId
            ? {
                ...h,
                needs_retrust: false,
                status: res.host.status ?? "ok",
                server_fingerprint: res.host.server_fingerprint,
                endpoint_url: res.host.endpoint_url ?? h.endpoint_url,
                last_checked_at: new Date().toISOString(),
              }
            : h
        )
      );
      await Promise.all([loadCertificate(), fetchHostsPage()]);
      onRefreshHosts?.();
    } catch (err: unknown) {
      alert(err instanceof Error ? err.message : "Failed to re-trust host.");
      throw err;
    } finally {
      setRetrustLoadingId(null);
    }
  };

  const handleSyncHostResources = async (hostId: number) => {
    try {
      setSyncingHostId(hostId);
      setActionMessage(null);
      const res = await api.adminSyncHostResources(hostId);
      setHosts((prev) =>
        prev.map((h) => (h.id === hostId ? { ...h, ...res.host } : h))
      );
      setActionMessage(
        t("hostCapacitySyncSuccess") || "Compute node capacity synchronized successfully."
      );
      onRefreshHosts?.();
    } catch (err: unknown) {
      const msg = err instanceof Error ? err.message : t("hostCapacitySyncFailed");
      setActionMessage(msg || "Failed to synchronize compute node capacity.");
    } finally {
      setSyncingHostId(null);
    }
  };

  return (
    <div className="space-y-4 animate-fadeIn">
      {/* Top action header */}
      <div className="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4">
        <div>
          <h2 className="text-lg font-bold text-gray-900 dark:text-white tracking-tight">
            {t("manageNodes")}
          </h2>
          <p className="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
            {t("nodesSubtitle")}
          </p>
        </div>

        <button
          type="button"
          onClick={onAddNode}
          className="flex items-center space-x-2 px-4 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-semibold shadow-md shadow-indigo-500/20 transition"
        >
          <Plus className="w-3.5 h-3.5" />
          <span>{t("createNode")}</span>
        </button>
      </div>

      {/* Action message alert */}
      {actionMessage && (
        <div className="p-3 rounded-xl bg-emerald-500/10 border border-emerald-500/20 text-xs text-emerald-800 dark:text-emerald-300 flex items-center justify-between gap-3">
          <span>{actionMessage}</span>
          <button
            type="button"
            onClick={() => setActionMessage(null)}
            aria-label="Dismiss message"
            className="p-1 rounded-lg hover:bg-emerald-500/20 transition shrink-0"
          >
            <X className="w-3.5 h-3.5" />
          </button>
        </div>
      )}

      {/* Panel Client Certificate (mTLS) Card */}
      <div className="p-4 rounded-2xl bg-gradient-to-r from-indigo-500/5 to-purple-500/5 dark:from-indigo-500/10 dark:to-purple-500/10 border border-indigo-200/60 dark:border-indigo-800/60">
        <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-3">
          <div className="flex items-center space-x-2">
            <ShieldCheck className="w-4 h-4 text-indigo-600 dark:text-indigo-400" />
            <span className="text-xs font-bold uppercase tracking-wider text-gray-800 dark:text-gray-200">
              {t("panelCertificate") || "Panel Client Certificate (mTLS)"}
            </span>
            {certInfo?.certificate ? (
              <span className="px-2 py-0.5 rounded-full text-[10px] font-semibold bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border border-emerald-500/20">
                {certInfo.certificate.status}
              </span>
            ) : (
              <span className="px-2 py-0.5 rounded-full text-[10px] font-semibold bg-gray-500/10 text-gray-600 dark:text-gray-400 border border-gray-500/20">
                {certLoading ? "Loading..." : "Not initialized"}
              </span>
            )}
          </div>

          <div className="flex items-center space-x-2">
            <button
              type="button"
              onClick={handleDownloadCertificate}
              disabled={downloadLoading}
              className="inline-flex items-center space-x-1.5 px-3 py-1.5 rounded-xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-200 text-xs font-medium hover:bg-gray-50 dark:hover:bg-gray-700 transition shadow-sm disabled:opacity-40"
            >
              {downloadLoading ? (
                <Loader2 className="w-3.5 h-3.5 animate-spin text-indigo-500" />
              ) : (
                <Download className="w-3.5 h-3.5 text-indigo-500" />
              )}
              <span>{t("downloadPublicCertificate") || "Download .crt"}</span>
            </button>
            <button
              type="button"
              onClick={handleRotateCertificate}
              disabled={rotateLoading}
              className="inline-flex items-center space-x-1.5 px-3 py-1.5 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-medium shadow-sm transition disabled:opacity-40"
            >
              {rotateLoading ? (
                <Loader2 className="w-3.5 h-3.5 animate-spin" />
              ) : (
                <RefreshCw className="w-3.5 h-3.5" />
              )}
              <span>
                {certInfo?.certificate
                  ? t("rotateCertificate") || "Rotate"
                  : "Generate / Rotate"}
              </span>
            </button>
          </div>
        </div>

        {certInfo?.certificate ? (
          <div className="grid grid-cols-1 md:grid-cols-2 gap-2 text-xs">
            <div className="bg-white/80 dark:bg-gray-900/60 p-2.5 rounded-xl border border-gray-200/60 dark:border-gray-800">
              <span className="text-[10px] text-gray-500 dark:text-gray-400 block font-medium mb-0.5">
                SHA-256 Fingerprint
              </span>
              <span className="font-mono text-[11px] text-gray-900 dark:text-white select-all break-all">
                {certInfo.certificate.fingerprint}
              </span>
            </div>

            <div className="bg-white/80 dark:bg-gray-900/60 p-2.5 rounded-xl border border-gray-200/60 dark:border-gray-800 flex items-center justify-between">
              <div>
                <span className="text-[10px] text-gray-500 dark:text-gray-400 block font-medium mb-0.5">
                  Valid Until
                </span>
                <span className="font-mono text-[11px] text-gray-900 dark:text-white">
                  {new Date(certInfo.certificate.not_after).toLocaleDateString()}
                </span>
              </div>
              {certInfo.certificate.is_expiring_soon && (
                <span className="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold bg-amber-500/10 text-amber-700 dark:text-amber-400 border border-amber-500/30">
                  <AlertTriangle className="w-3 h-3 mr-1" />
                  Expiring soon ({certInfo.certificate.days_until_expiration}d)
                </span>
              )}
            </div>
          </div>
        ) : (
          !certLoading && (
            <p className="text-xs text-gray-500 dark:text-gray-400">
              No active panel client certificate is initialized yet. Download or generate one to establish mTLS trust with compute nodes.
            </p>
          )
        )}

        {certInfo?.retiring_certificate && (
          <div className="mt-3 p-3 rounded-xl bg-amber-500/10 border border-amber-500/20 text-xs text-amber-900 dark:text-amber-200 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
            <div>
              <span className="font-bold">Retiring Certificate active during rotation:</span>
              <span className="font-mono text-[11px] ml-1">
                {certInfo.retiring_certificate.fingerprint.substring(0, 16)}...
              </span>
              {certInfo.pending_retrust_hosts_count > 0 ? (
                <span className="block text-[11px] opacity-90 mt-0.5">
                  {certInfo.pending_retrust_hosts_count} host(s) pending re-trust before this certificate can be revoked.
                </span>
              ) : (
                <span className="block text-[11px] opacity-90 mt-0.5">
                  All hosts trust the new certificate. You can safely revoke the old certificate.
                </span>
              )}
            </div>

            <button
              type="button"
              onClick={handleRevokeOldCertificate}
              disabled={revokeLoading || certInfo.pending_retrust_hosts_count > 0}
              title={
                certInfo.pending_retrust_hosts_count > 0
                  ? `All ${certInfo.pending_retrust_hosts_count} pending host(s) must be re-trusted before revoking the old certificate.`
                  : "Revoke retiring certificate"
              }
              className="self-start sm:self-auto px-3 py-1.5 rounded-lg bg-amber-600 hover:bg-amber-700 text-white text-[11px] font-semibold transition disabled:opacity-50 disabled:cursor-not-allowed"
            >
              {revokeLoading ? "Revoking..." : "Revoke Old Certificate"}
            </button>
          </div>
        )}
      </div>

      {/* Filter Toolbar */}
      <div className="p-3 sm:p-4 rounded-2xl bg-white dark:bg-gray-900/60 border border-gray-200 dark:border-gray-800 shadow-sm dark:shadow-none">
        <div className="flex flex-col lg:flex-row lg:items-center gap-2.5">
          {/* Search Input */}
          <div className="relative flex-1 min-w-0">
            <Search className="w-4 h-4 text-gray-400 absolute left-3 top-1/2 -translate-y-1/2 pointer-events-none" />
            <input
              type="text"
              value={searchQuery}
              onChange={(e) => setSearchQuery(e.target.value)}
              placeholder="Search by name, region, endpoint, or fingerprint..."
              aria-label="Search compute nodes"
              className="w-full bg-gray-50 dark:bg-gray-950/60 border border-gray-200 dark:border-gray-800 rounded-xl pl-9 pr-8 py-2 text-xs text-gray-800 dark:text-gray-200 placeholder-gray-400 focus:outline-none focus:border-indigo-500 transition"
            />
            {searchQuery && (
              <button
                type="button"
                onClick={() => {
                  setSearchQuery("");
                  setDebouncedSearch("");
                  setPage(1);
                }}
                aria-label="Clear search"
                className="absolute right-2.5 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600 dark:hover:text-gray-200"
              >
                <X className="w-3.5 h-3.5" />
              </button>
            )}
          </div>

          <div className="flex flex-wrap sm:flex-nowrap items-center gap-2">
            {/* Connection / Trust Status Filter */}
            <select
              value={statusFilter}
              onChange={(e) => {
                setStatusFilter(e.target.value);
                setPage(1);
              }}
              aria-label="Filter by status"
              className="bg-gray-50 dark:bg-gray-950/60 border border-gray-200 dark:border-gray-800 rounded-xl px-3 py-2 text-xs text-gray-800 dark:text-gray-200 focus:outline-none focus:border-indigo-500 transition cursor-pointer"
            >
              <option value="">{t("allStatuses") || "All Statuses"}</option>
              <option value="ok">Verified &amp; Trusted</option>
              <option value="needs_retrust">Needs Re-trust</option>
              <option value="unreachable">Unreachable</option>
              <option value="fingerprint_mismatch">Fingerprint Mismatch</option>
              <option value="not_trusted">Untrusted</option>
              <option value="error">Error</option>
            </select>

            {/* Active State Filter */}
            <select
              value={activeFilter}
              onChange={(e) => {
                setActiveFilter(e.target.value);
                setPage(1);
              }}
              aria-label="Filter by active state"
              className="bg-gray-50 dark:bg-gray-950/60 border border-gray-200 dark:border-gray-800 rounded-xl px-3 py-2 text-xs text-gray-800 dark:text-gray-200 focus:outline-none focus:border-indigo-500 transition cursor-pointer"
            >
              <option value="">All States</option>
              <option value="true">{t("nodeActive") || "Active"}</option>
              <option value="false">{t("nodeInactive") || "Inactive"}</option>
            </select>

            {/* Driver Filter */}
            <select
              value={driverFilter}
              onChange={(e) => {
                setDriverFilter(e.target.value);
                setPage(1);
              }}
              aria-label="Filter by driver"
              className="bg-gray-50 dark:bg-gray-950/60 border border-gray-200 dark:border-gray-800 rounded-xl px-3 py-2 text-xs text-gray-800 dark:text-gray-200 focus:outline-none focus:border-indigo-500 transition cursor-pointer"
            >
              <option value="">All Drivers</option>
              <option value="incus">Incus</option>
              <option value="lxd">LXD</option>
              <option value="fake">Fake</option>
            </select>

            {hasActiveFilters && (
              <button
                type="button"
                onClick={handleClearFilters}
                className="inline-flex items-center space-x-1 px-3 py-2 rounded-xl bg-gray-100 hover:bg-gray-200 dark:bg-gray-800 dark:hover:bg-gray-700 text-gray-700 dark:text-gray-200 text-xs font-medium transition shrink-0"
              >
                <X className="w-3.5 h-3.5" />
                <span>Clear Filters</span>
              </button>
            )}

            <button
              type="button"
              onClick={() => void fetchHostsPage()}
              disabled={tableLoading}
              title="Refresh compute nodes"
              aria-label="Refresh compute nodes"
              className="inline-flex items-center space-x-1.5 px-3 py-2 rounded-xl bg-gray-100 hover:bg-gray-200 dark:bg-gray-800 dark:hover:bg-gray-700 text-gray-700 dark:text-gray-200 text-xs font-medium transition disabled:opacity-50 shrink-0"
            >
              <RefreshCw
                className={`w-3.5 h-3.5 ${tableLoading ? "animate-spin text-indigo-500" : ""}`}
              />
              <span>Refresh</span>
            </button>
          </div>
        </div>
      </div>

      {/* Compute Nodes Table */}
      <NodesTable
        hosts={hosts}
        loading={tableLoading}
        onVerifyHost={handleVerifyHost}
        verifyingHostId={verifyingHostId}
        onSyncHostResources={handleSyncHostResources}
        syncingHostId={syncingHostId}
        onRetrustHost={handleRetrustHost}
        retrustLoadingId={retrustLoadingId}
        onEditNode={onEditNode}
        onDeleteNode={onDeleteNode}
        onAddNode={onAddNode}
        hasActiveFilters={hasActiveFilters}
        onClearFilters={handleClearFilters}
        pagination={{
          currentPage: page,
          lastPage,
          perPage,
          total: total > 0 ? total : hosts.length,
          onPageChange: setPage,
          onPerPageChange: (newPerPage) => {
            setPerPage(newPerPage);
            setPage(1);
          },
        }}
      />
    </div>
  );
}
