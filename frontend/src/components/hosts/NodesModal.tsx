"use client";

import React, { useCallback, useEffect, useState } from "react";
import {
  X,
  Server,
  Globe,
  Plus,
  Edit2,
  Trash2,
  Layers,
  Network,
  ShieldCheck,
  ShieldAlert,
  Download,
  RefreshCw,
  AlertTriangle,
  CheckCircle2,
  AlertCircle,
  Loader2,
  Activity,
  Fingerprint,
} from "lucide-react";
import { api, HostNode, PanelCertificateInfo } from "@/lib/api";
import { extractPaginationMeta } from "@/lib/api/client";
import { PaginationBar } from "@/components/ui/PaginationBar";
import { useI18n } from "@/lib/i18n/I18nContext";
import { useModalEscape } from "@/lib/hooks/useModalEscape";

interface NodesModalProps {
  hosts: HostNode[];
  onClose: () => void;
  onAddNode: () => void;
  onEditNode: (host: HostNode) => void;
  onDeleteNode: (hostId: number, hostName: string) => void;
  onRefreshHosts?: () => void;
}

export function NodesModal({
  hosts: initialHosts,
  onClose,
  onAddNode,
  onEditNode,
  onDeleteNode,
  onRefreshHosts,
}: NodesModalProps) {
  const { t } = useI18n();

  const [hosts, setHosts] = useState<HostNode[]>(initialHosts);
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(10);
  const [lastPage, setLastPage] = useState(1);
  const [total, setTotal] = useState(initialHosts.length);
  const [tableLoading, setTableLoading] = useState(false);
  const [certInfo, setCertInfo] = useState<PanelCertificateInfo | null>(null);
  const [rotateLoading, setRotateLoading] = useState(false);
  const [revokeLoading, setRevokeLoading] = useState(false);
  const [verifyingHostId, setVerifyingHostId] = useState<number | null>(null);
  const [retrustTokens, setRetrustTokens] = useState<Record<number, string>>({});
  const [retrustLoadingId, setRetrustLoadingId] = useState<number | null>(null);
  const [syncingHostId, setSyncingHostId] = useState<number | null>(null);
  const [actionMessage, setActionMessage] = useState<string | null>(null);

  useModalEscape(onClose, !tableLoading && !rotateLoading && !revokeLoading && !retrustLoadingId && !syncingHostId);

  const fetchHostsPage = useCallback(async () => {
    try {
      setTableLoading(true);
      const res = await api.adminGetHosts({ page, per_page: perPage });
      setHosts(res.data);
      const meta = extractPaginationMeta(res, perPage);
      setLastPage(meta.lastPage);
      setTotal(meta.total);
      if (page > meta.lastPage && meta.lastPage >= 1) {
        setPage(meta.lastPage);
      }
    } catch {
      setHosts(initialHosts.slice((page - 1) * perPage, page * perPage));
      setTotal(initialHosts.length);
      setLastPage(Math.max(1, Math.ceil(initialHosts.length / perPage)));
    } finally {
      setTableLoading(false);
    }
  }, [page, perPage, initialHosts]);

  useEffect(() => {
    fetchHostsPage();
  }, [fetchHostsPage]);

  const loadCertificate = async () => {
    try {
      const res = await api.adminGetPanelCertificate();
      setCertInfo(res);
    } catch {
      // Certificate might not be generated yet
    }
  };

  useEffect(() => {
    loadCertificate();
  }, []);

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
      if (onRefreshHosts) onRefreshHosts();
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
      setActionMessage(`${res.message} To clean up remote hosts, run: ${res.cleanup_instruction}`);
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
      // Update host state locally
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
      if (onRefreshHosts) onRefreshHosts();
    } catch (err: unknown) {
      alert(err instanceof Error ? err.message : "Verification failed.");
    } finally {
      setVerifyingHostId(null);
    }
  };

  const handleRetrustHost = async (hostId: number) => {
    const token = retrustTokens[hostId]?.trim();
    if (!token) return;

    try {
      setRetrustLoadingId(hostId);
      setActionMessage(null);
      const res = await api.adminRetrustHost(hostId, token);
      setActionMessage(res.message);
      setRetrustTokens((prev) => {
        const next = { ...prev };
        delete next[hostId];
        return next;
      });
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
      if (onRefreshHosts) onRefreshHosts();
    } catch (err: unknown) {
      alert(err instanceof Error ? err.message : "Failed to re-trust host.");
    } finally {
      setRetrustLoadingId(null);
    }
  };

  const renderStatusBadge = (status?: string) => {
    switch (status) {
      case "ok":
        return (
          <span className="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-medium bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border border-emerald-500/20">
            <CheckCircle2 className="w-3 h-3 mr-1" />
            Verified & Trusted
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
            <ShieldAlert className="w-3 h-3 mr-1 text-amber-600" />
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

  const handleSyncHostResources = async (hostId: number) => {
    try {
      setSyncingHostId(hostId);
      setActionMessage(null);
      const res = await api.adminSyncHostResources(hostId);
      setHosts((prev) =>
        prev.map((h) => (h.id === hostId ? { ...h, ...res.host } : h))
      );
      setActionMessage(t("hostCapacitySyncSuccess") || "Compute node capacity synchronized successfully.");
      if (onRefreshHosts) onRefreshHosts();
    } catch (err: unknown) {
      const msg = err instanceof Error ? err.message : t("hostCapacitySyncFailed");
      setActionMessage(msg || "Failed to synchronize compute node capacity.");
    } finally {
      setSyncingHostId(null);
    }
  };

  const renderCapacitySourceBadge = (source?: string) => {
    const s = source || "unknown";
    if (s === "discovered") {
      return (
        <span className="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border border-emerald-500/20">
          <CheckCircle2 className="w-2.5 h-2.5 mr-1" />
          {t("capacitySourceDetected") || "Detected"}
        </span>
      );
    }
    if (s === "manual") {
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
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-sm animate-fadeIn">
      <div className="relative w-full max-w-4xl bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-3xl p-6 sm:p-8 shadow-2xl overflow-y-auto max-h-[90vh] transition-colors">
        {/* Header */}
        <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-5 pb-4 border-b border-gray-100 dark:border-gray-800">
          <div className="flex items-center space-x-3">
            <div className="p-2.5 rounded-2xl bg-indigo-500/10 text-indigo-600 dark:text-indigo-400">
              <Server className="w-6 h-6" />
            </div>
            <div>
              <h2 className="text-xl font-bold text-gray-900 dark:text-white tracking-tight">
                {t("manageNodes")}
              </h2>
              <p className="text-xs text-gray-500 dark:text-gray-400">{t("nodesSubtitle")}</p>
            </div>
          </div>

          <div className="flex items-center space-x-2 self-start sm:self-auto">
            <button
              onClick={onAddNode}
              className="flex items-center space-x-2 px-4 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-semibold shadow-md shadow-indigo-500/20 transition"
            >
              <Plus className="w-4 h-4" />
              <span>{t("createNode")}</span>
            </button>
            <button
              onClick={onClose}
              className="p-2 rounded-xl text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-800 transition"
            >
              <X className="w-5 h-5" />
            </button>
          </div>
        </div>

        {actionMessage && (
          <div className="mb-4 p-3 rounded-xl bg-emerald-500/10 border border-emerald-500/20 text-xs text-emerald-800 dark:text-emerald-300">
            {actionMessage}
          </div>
        )}

        {/* Panel Client Certificate Section */}
        {certInfo?.certificate && (
          <div className="mb-6 p-4 rounded-2xl bg-gradient-to-r from-indigo-500/5 to-purple-500/5 dark:from-indigo-500/10 dark:to-purple-500/10 border border-indigo-200/60 dark:border-indigo-800/60">
            <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-3">
              <div className="flex items-center space-x-2">
                <ShieldCheck className="w-4 h-4 text-indigo-600 dark:text-indigo-400" />
                <span className="text-xs font-bold uppercase tracking-wider text-gray-800 dark:text-gray-200">
                  {t("panelCertificate") || "Panel Client Certificate (mTLS)"}
                </span>
                <span className="px-2 py-0.5 rounded-full text-[10px] font-semibold bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border border-emerald-500/20">
                  {certInfo.certificate.status}
                </span>
              </div>

              <div className="flex items-center space-x-2">
                <a
                  href="/api/admin/incus/certificate/download"
                  download="incus-panel.crt"
                  className="inline-flex items-center space-x-1.5 px-3 py-1.5 rounded-xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-200 text-xs font-medium hover:bg-gray-50 dark:hover:bg-gray-700 transition shadow-sm"
                >
                  <Download className="w-3.5 h-3.5 text-indigo-500" />
                  <span>{t("downloadPublicCertificate") || "Download .crt"}</span>
                </a>
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
                  <span>{t("rotateCertificate") || "Rotate"}</span>
                </button>
              </div>
            </div>

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

            {/* Retiring Certificate notice */}
            {certInfo.retiring_certificate && (
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

                {certInfo.pending_retrust_hosts_count === 0 && (
                  <button
                    type="button"
                    onClick={handleRevokeOldCertificate}
                    disabled={revokeLoading}
                    className="self-start sm:self-auto px-3 py-1.5 rounded-lg bg-amber-600 hover:bg-amber-700 text-white text-[11px] font-semibold transition"
                  >
                    {revokeLoading ? "Revoking..." : "Revoke Old Certificate"}
                  </button>
                )}
              </div>
            )}
          </div>
        )}

        {/* Compute Nodes Grid */}
        {tableLoading && hosts.length === 0 ? (
          <div className="flex flex-col items-center justify-center py-12 text-gray-400 space-y-2">
            <Loader2 className="w-6 h-6 animate-spin text-indigo-500" />
            <span className="text-xs">Loading compute nodes...</span>
          </div>
        ) : hosts.length === 0 ? (
          <div className="text-center py-12 bg-gray-50 dark:bg-gray-800/40 rounded-2xl border border-gray-200 dark:border-gray-800">
            <Server className="w-10 h-10 text-gray-400 mx-auto mb-3" />
            <h3 className="text-sm font-semibold text-gray-900 dark:text-white mb-1">
              No compute nodes configured
            </h3>
            <p className="text-xs text-gray-500 dark:text-gray-400 mb-4">
              Add your first Incus compute node via trust token or public certificate to begin deploying workloads.
            </p>
            <button
              onClick={onAddNode}
              className="inline-flex items-center space-x-2 px-4 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-semibold"
            >
              <Plus className="w-4 h-4" />
              <span>{t("createNode")}</span>
            </button>
          </div>
        ) : (
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            {hosts.map((host) => (
              <div
                key={host.id}
                className="bg-gray-50 dark:bg-gray-800/40 border border-gray-200 dark:border-gray-800 rounded-2xl p-5 relative hover:border-indigo-500/40 transition group"
              >
                <div className="flex items-start justify-between mb-3">
                  <div className="flex items-center space-x-2.5">
                    <div className="p-2 rounded-xl bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700/60 shadow-sm text-indigo-600 dark:text-indigo-400">
                      <Server className="w-5 h-5" />
                    </div>
                    <div>
                      <div className="flex items-center space-x-2 flex-wrap gap-y-1">
                        <span className="font-mono text-sm font-bold text-gray-900 dark:text-white">
                          {host.name}
                        </span>
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
                        {renderStatusBadge(host.status)}
                      </div>
                      {host.region && (
                        <div className="flex items-center space-x-1.5 text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                          <Globe className="w-3.5 h-3.5 text-gray-400" />
                          <span>{host.region}</span>
                        </div>
                      )}
                    </div>
                  </div>

                  <div className="flex items-center space-x-1">
                    <button
                      onClick={() => handleVerifyHost(host.id)}
                      disabled={verifyingHostId === host.id}
                      title={t("verifyConnection") || "Verify connection"}
                      className="p-1.5 rounded-lg text-gray-400 hover:text-indigo-600 dark:hover:text-indigo-400 hover:bg-white dark:hover:bg-gray-800 transition"
                    >
                      {verifyingHostId === host.id ? (
                        <Loader2 className="w-4 h-4 animate-spin text-indigo-600" />
                      ) : (
                        <Activity className="w-4 h-4" />
                      )}
                    </button>
                    <button
                      onClick={() => onEditNode(host)}
                      title={t("editNode")}
                      className="p-1.5 rounded-lg text-gray-400 hover:text-indigo-600 dark:hover:text-indigo-400 hover:bg-white dark:hover:bg-gray-800 transition"
                    >
                      <Edit2 className="w-4 h-4" />
                    </button>
                    <button
                      onClick={() => onDeleteNode(host.id, host.name)}
                      title={t("deleteNode")}
                      className="p-1.5 rounded-lg text-gray-400 hover:text-red-500 hover:bg-white dark:hover:bg-gray-800 transition"
                    >
                      <Trash2 className="w-4 h-4" />
                    </button>
                  </div>
                </div>

                {host.needs_retrust && (
                  <div className="mb-2.5 p-2.5 rounded-lg bg-amber-500/10 border border-amber-500/20 text-[11px] text-amber-800 dark:text-amber-300">
                    <div className="flex items-center space-x-1.5 mb-2">
                      <ShieldAlert className="w-3.5 h-3.5 text-amber-600 shrink-0" />
                      <span className="font-medium">Needs re-trust after panel certificate rotation</span>
                    </div>
                    <div className="flex items-center space-x-1.5">
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
                        className="flex-1 px-2 py-1 text-xs rounded bg-white dark:bg-gray-900 border border-amber-300 dark:border-amber-700 font-mono text-gray-900 dark:text-gray-100 placeholder-gray-400 focus:outline-none focus:ring-1 focus:ring-amber-500"
                      />
                      <button
                        type="button"
                        onClick={() => handleRetrustHost(host.id)}
                        disabled={retrustLoadingId === host.id || !retrustTokens[host.id]?.trim()}
                        className="px-2.5 py-1 text-xs font-semibold rounded bg-amber-600 hover:bg-amber-700 text-white disabled:opacity-50 transition flex items-center space-x-1 shrink-0"
                      >
                        {retrustLoadingId === host.id ? (
                          <Loader2 className="w-3 h-3 animate-spin" />
                        ) : (
                          <span>Re-trust</span>
                        )}
                      </button>
                    </div>
                  </div>
                )}

                {host.endpoint_url && (
                  <div className="font-mono text-[11px] text-gray-500 dark:text-gray-400 truncate mb-2 bg-white dark:bg-gray-900/60 px-2.5 py-1.5 rounded-lg border border-gray-200 dark:border-gray-800">
                    {host.endpoint_url}
                  </div>
                )}

                {host.server_fingerprint && (
                  <div className="flex items-center space-x-1 text-[10px] text-gray-400 font-mono mb-3 truncate">
                    <Fingerprint className="w-3 h-3 shrink-0 text-indigo-400" />
                    <span className="truncate">{host.server_fingerprint}</span>
                  </div>
                )}

                {/* Specs & Capacity */}
                <div className="mb-3">
                  <div className="flex items-center justify-between mb-2">
                    <div className="flex items-center space-x-1.5 flex-wrap gap-y-1">
                      <span className="text-[10px] font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                        {t("nodeCapacity") || "Capacity"}
                      </span>
                      {renderCapacitySourceBadge(host.capacity_source || host.capacity?.source)}
                    </div>
                    <button
                      type="button"
                      onClick={() => handleSyncHostResources(host.id)}
                      disabled={syncingHostId === host.id}
                      className="inline-flex items-center space-x-1 px-2 py-0.5 rounded-lg bg-indigo-50 dark:bg-indigo-950/60 hover:bg-indigo-100 dark:hover:bg-indigo-900/60 text-indigo-700 dark:text-indigo-300 text-[10px] font-medium transition disabled:opacity-50"
                      title={t("syncFromHypervisor") || "Sync from hypervisor"}
                    >
                      {syncingHostId === host.id ? (
                        <Loader2 className="w-3 h-3 animate-spin text-indigo-600 dark:text-indigo-400" />
                      ) : (
                        <RefreshCw className="w-3 h-3 text-indigo-600 dark:text-indigo-400" />
                      )}
                      <span>
                        {syncingHostId === host.id
                          ? t("syncingFromHypervisor") || "Syncing..."
                          : t("syncFromHypervisor") || "Sync from hypervisor"}
                      </span>
                    </button>
                  </div>

                  <div className="grid grid-cols-3 gap-2 text-center">
                    <div className="p-2 rounded-xl bg-white dark:bg-gray-900/80 border border-gray-200/80 dark:border-gray-800">
                      <div className="text-[10px] text-gray-400 font-medium">vCPU</div>
                      <div className="text-xs font-bold text-gray-800 dark:text-gray-200 mt-0.5">
                        {host.capacity?.cpu?.committed ?? 0} / {host.total_cpu_cores}
                      </div>
                      <div className="text-[9px] text-gray-400 mt-0.5">
                        {t("capacityCommitted") || "committed"} / {t("capacityTotal") || "total"}
                      </div>
                    </div>
                    <div className="p-2 rounded-xl bg-white dark:bg-gray-900/80 border border-gray-200/80 dark:border-gray-800">
                      <div className="text-[10px] text-gray-400 font-medium">RAM</div>
                      <div className="text-xs font-bold text-gray-800 dark:text-gray-200 mt-0.5">
                        {Math.round((host.capacity?.ram?.committed ?? 0) / 1024)} / {Math.round(host.total_ram_mb / 1024)} GB
                      </div>
                      <div className="text-[9px] text-gray-400 mt-0.5">
                        {t("capacityCommitted") || "committed"} / {t("capacityTotal") || "total"}
                      </div>
                    </div>
                    <div className="p-2 rounded-xl bg-white dark:bg-gray-900/80 border border-gray-200/80 dark:border-gray-800">
                      <div className="text-[10px] text-gray-400 font-medium">Storage</div>
                      <div className="text-xs font-bold text-gray-800 dark:text-gray-200 mt-0.5">
                        {host.capacity?.disk?.committed ?? 0} / {host.total_storage_gb} GB
                      </div>
                      <div className="text-[9px] text-gray-400 mt-0.5">
                        {t("capacityCommitted") || "committed"} / {t("capacityTotal") || "total"}
                      </div>
                    </div>
                  </div>
                </div>

                {/* Subnet pools & Workloads counts */}
                <div className="pt-2.5 border-t border-gray-200/60 dark:border-gray-800 flex items-center justify-between text-[11px] text-gray-500 dark:text-gray-400">
                  <span className="flex items-center space-x-1.5">
                    <Layers className="w-3.5 h-3.5 text-blue-500" />
                    <span>
                      {host.virtual_machines_count ?? 0} {t("colInstance")}
                    </span>
                  </span>

                  <span className="flex items-center space-x-1.5">
                    <Network className="w-3.5 h-3.5 text-teal-500" />
                    <span>
                      {host.ip_pools_count ?? 0} {t("ipPools")}
                    </span>
                  </span>

                  <span className="font-mono text-[10px] uppercase font-bold text-indigo-600 dark:text-indigo-400 px-1.5 py-0.5 rounded bg-indigo-50 dark:bg-indigo-950/40 border border-indigo-200/50 dark:border-indigo-800/50">
                    {host.driver}
                  </span>
                </div>
              </div>
            ))}
          </div>
        )}

        {/* Pagination Bar */}
        {hosts.length > 0 && (
          <div className="mt-4 bg-white dark:bg-gray-900/60 border border-gray-200 dark:border-gray-800 rounded-2xl overflow-hidden">
            <PaginationBar
              currentPage={page}
              lastPage={lastPage}
              perPage={perPage}
              total={total > 0 ? total : hosts.length}
              onPageChange={setPage}
              onPerPageChange={(newPerPage) => {
                setPerPage(newPerPage);
                setPage(1);
              }}
            />
          </div>
        )}
      </div>
    </div>
  );
}
