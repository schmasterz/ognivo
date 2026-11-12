"use client";

import React, { useState, useEffect } from "react";
import {
  X,
  Server,
  Loader2,
  CheckCircle2,
  AlertCircle,
  AlertTriangle,
  Activity,
  ShieldAlert,
  Fingerprint,
} from "lucide-react";
import { api, HostNode, TestConnectionResponse } from "@/lib/api";
import { useI18n } from "@/lib/i18n/I18nContext";
import { useModalEscape } from "@/lib/hooks/useModalEscape";

interface EditHostModalProps {
  host: HostNode;
  onClose: () => void;
  onSuccess: (updatedHost: HostNode) => void;
}

export function EditHostModal({ host, onClose, onSuccess }: EditHostModalProps) {
  const { t } = useI18n();

  const [name, setName] = useState(host.name);
  const [region, setRegion] = useState(host.region || "");
  const [endpointUrl, setEndpointUrl] = useState(host.endpoint_url ?? "");
  const [driver, setDriver] = useState<"incus" | "lxd" | "fake">(
    host.driver === "fake" ? "fake" : host.driver === "lxd" ? "lxd" : "incus"
  );
  const [isActive, setIsActive] = useState(host.is_active);
  const [totalCpuCores, setTotalCpuCores] = useState(host.total_cpu_cores || 64);
  const [totalRamGb, setTotalRamGb] = useState(Math.round((host.total_ram_mb || 262144) / 1024));
  const [totalStorageGb, setTotalStorageGb] = useState(host.total_storage_gb || 4000);

  // Trust Token State
  const [token] = useState("");
  const [tokenModified] = useState(false);

  // Verification State
  const [verifyLoading, setVerifyLoading] = useState(false);
  const [verifyResult, setVerifyResult] = useState<TestConnectionResponse | null>(null);
  const [verifyError, setVerifyError] = useState<string | null>(null);

  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useModalEscape(onClose, !loading && !verifyLoading);

  useEffect(() => {
    setName(host.name);
    setRegion(host.region || "");
    setEndpointUrl(host.endpoint_url ?? "");
    setDriver(host.driver === "fake" ? "fake" : host.driver === "lxd" ? "lxd" : "incus");
    setIsActive(host.is_active);
    setTotalCpuCores(host.total_cpu_cores || 64);
    setTotalRamGb(Math.round((host.total_ram_mb || 262144) / 1024));
    setTotalStorageGb(host.total_storage_gb || 4000);
    setError(null);
    setVerifyError(null);
    setVerifyResult(null);
    setLoading(false);
  }, [host]);

  const isValidUrl = (urlStr: string): boolean => {
    try {
      const parsed = new URL(urlStr.trim());
      return (parsed.protocol === "http:" || parsed.protocol === "https:") && Boolean(parsed.hostname);
    } catch {
      return false;
    }
  };

  const isNameValid = name.trim().length >= 2 && /^[a-z0-9-]+$/.test(name.trim());
  const isEndpointValid = isValidUrl(endpointUrl);
  const isCapacityValid = totalCpuCores >= 1 && totalRamGb >= 1 && totalStorageGb >= 10;

  const isFormValid = Boolean(isNameValid && isEndpointValid && isCapacityValid);

  const handleVerifyConnection = async () => {
    setVerifyLoading(true);
    setVerifyResult(null);
    setVerifyError(null);

    try {
      // Check saved host directly via verify-connection endpoint
      const res = await api.adminVerifyHostConnection(host.id);
      setVerifyResult(res);
    } catch (err: unknown) {
      if (err instanceof Error) {
        setVerifyError(err.message);
      } else {
        setVerifyError("Verification failed.");
      }
    } finally {
      setVerifyLoading(false);
    }
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setError(null);

    if (!isFormValid) {
      setError(t("fillRequiredFieldsToTest") || "Please fill in all required fields correctly.");
      return;
    }

    try {
      setLoading(true);
      const updatePayload: Parameters<typeof api.adminUpdateHost>[1] = {
        name: name.trim().toLowerCase(),
        region: region.trim() || undefined,
        endpoint_url: endpointUrl.trim(),
        driver,
        is_active: isActive,
        total_cpu_cores: totalCpuCores,
        total_ram_mb: totalRamGb * 1024,
        total_storage_gb: totalStorageGb,
      };

      if (tokenModified) {
        updatePayload.token = token.trim() || null;
      }

      const res = await api.adminUpdateHost(host.id, updatePayload);

      onSuccess(res.host);
      onClose();
    } catch (err: unknown) {
      if (err instanceof Error) {
        setError(err.message);
      } else {
        setError("Failed to update compute node.");
      }
    } finally {
      setLoading(false);
    }
  };

  const renderStateBadge = (res: TestConnectionResponse) => {
    const state = res.state ?? (res.success ? (res.is_trusted ? "ok" : "not_trusted") : "error");

    switch (state) {
      case "ok":
        return (
          <div className="p-3 rounded-xl bg-emerald-500/10 border border-emerald-500/20 text-emerald-800 dark:text-emerald-300 text-xs">
            <div className="flex items-center justify-between mb-1">
              <div className="flex items-center space-x-1.5 font-bold">
                <CheckCircle2 className="w-4 h-4 text-emerald-500" />
                <span>{t("authTrustedBadge") || "Authorized & Trusted (mTLS)"}</span>
              </div>
              <div className="flex items-center space-x-1.5 font-mono text-[10px]">
                {res.latency_ms !== undefined && (
                  <span className="px-1.5 py-0.5 rounded bg-black/10 dark:bg-white/10 font-bold">
                    {res.latency_ms} ms
                  </span>
                )}
                {res.server && (
                  <span className="px-1.5 py-0.5 rounded bg-black/10 dark:bg-white/10 font-bold uppercase">
                    {res.server} {res.server_version}
                  </span>
                )}
              </div>
            </div>
            <p className="text-[11px] opacity-90">{res.message}</p>
          </div>
        );

      case "unreachable":
        return (
          <div className="p-3 rounded-xl bg-slate-500/10 border border-slate-500/20 text-slate-800 dark:text-slate-300 text-xs">
            <div className="flex items-center justify-between mb-1">
              <div className="flex items-center space-x-1.5 font-bold">
                <AlertCircle className="w-4 h-4 text-slate-500" />
                <span>Host Unreachable</span>
              </div>
              {res.latency_ms !== undefined && (
                <span className="font-mono text-[10px] px-1.5 py-0.5 rounded bg-slate-500/20">
                  {res.latency_ms} ms
                </span>
              )}
            </div>
            <p className="text-[11px] opacity-90">{res.message}</p>
          </div>
        );

      case "fingerprint_mismatch":
        return (
          <div className="p-3 rounded-xl bg-amber-500/15 border border-amber-500/30 text-amber-900 dark:text-amber-200 text-xs">
            <div className="flex items-center justify-between mb-1">
              <div className="flex items-center space-x-1.5 font-bold">
                <ShieldAlert className="w-4 h-4 text-amber-600 dark:text-amber-400" />
                <span>Fingerprint Mismatch (Security Alert)</span>
              </div>
            </div>
            <p className="text-[11px] font-medium">{res.message}</p>
          </div>
        );

      case "not_trusted":
        return (
          <div className="p-3 rounded-xl bg-orange-500/10 border border-orange-500/20 text-orange-800 dark:text-orange-300 text-xs">
            <div className="flex items-center justify-between mb-1">
              <div className="flex items-center space-x-1.5 font-bold">
                <AlertTriangle className="w-4 h-4 text-orange-500" />
                <span>{t("authUntrustedBadge") || "Unauthorized / Untrusted"}</span>
              </div>
              {res.latency_ms !== undefined && (
                <span className="font-mono text-[10px] px-1.5 py-0.5 rounded bg-orange-500/20">
                  {res.latency_ms} ms
                </span>
              )}
            </div>
            <p className="text-[11px] opacity-90">{res.message}</p>
            <p className="mt-1 text-[10px] font-mono text-orange-700 dark:text-orange-400">
              {t("authUntrustedNotice")}
            </p>
          </div>
        );

      case "error":
      default:
        return (
          <div className="p-3 rounded-xl bg-red-500/10 border border-red-500/20 text-xs text-red-600 dark:text-red-400">
            <div className="flex items-center justify-between mb-1">
              <div className="flex items-center space-x-1.5 font-bold">
                <AlertCircle className="w-4 h-4 text-red-500" />
                <span>{t("connectionFailed") || "Verification Failed"}</span>
              </div>
              {res.latency_ms !== undefined && (
                <span className="font-mono text-[10px] px-1.5 py-0.5 rounded bg-red-500/20">
                  {res.latency_ms} ms
                </span>
              )}
            </div>
            <p className="text-[11px]">{res.message}</p>
          </div>
        );
    }
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-sm animate-fadeIn">
      <div className="relative w-full max-w-xl bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-3xl p-6 sm:p-8 shadow-2xl overflow-y-auto max-h-[90vh] transition-colors">
        <div className="flex items-center justify-between pb-4 mb-4 border-b border-gray-100 dark:border-gray-800">
          <div className="flex items-center space-x-3">
            <div className="p-2.5 rounded-2xl bg-indigo-500/10 text-indigo-600 dark:text-indigo-400">
              <Server className="w-6 h-6" />
            </div>
            <div>
              <h2 className="text-xl font-bold text-gray-900 dark:text-white tracking-tight">
                {t("editNode")}
              </h2>
              <p className="text-xs text-gray-500 dark:text-gray-400">{t("nodesSubtitle")}</p>
            </div>
          </div>
          <button
            onClick={onClose}
            className="p-2 rounded-xl text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-800 transition"
          >
            <X className="w-5 h-5" />
          </button>
        </div>

        {host.needs_retrust && (
          <div className="mb-4 p-3.5 rounded-xl bg-amber-500/10 border border-amber-500/30 text-amber-900 dark:text-amber-200 text-xs flex items-start space-x-2.5">
            <ShieldAlert className="w-4 h-4 text-amber-600 shrink-0 mt-0.5" />
            <div>
              <span className="font-bold">Re-trust Required:</span> A new panel certificate was rotated. Please add the new public certificate on this host or provide a new trust token.
            </div>
          </div>
        )}

        {error && (
          <div className="mb-4 p-3.5 rounded-xl bg-red-500/10 border border-red-500/20 text-xs text-red-600 dark:text-red-400">
            {error}
          </div>
        )}

        <form onSubmit={handleSubmit} className="space-y-4">
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <label className="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1.5">
                {t("nodeName")} <span className="text-red-500">*</span>
              </label>
              <input
                type="text"
                required
                value={name}
                onChange={(e) => setName(e.target.value.toLowerCase().replace(/[^a-z0-9-]/g, ""))}
                placeholder="e.g. compute-01 or eu-west-1"
                className={`w-full px-3.5 py-2.5 rounded-xl bg-gray-50 dark:bg-gray-800/60 border text-sm font-mono text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-indigo-500/50 ${
                  name.length > 0 && !isNameValid
                    ? "border-red-400 dark:border-red-500/60"
                    : "border-gray-200 dark:border-gray-700"
                }`}
              />
            </div>

            <div>
              <label className="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1.5">
                {t("nodeRegion")}
              </label>
              <input
                type="text"
                value={region}
                onChange={(e) => setRegion(e.target.value)}
                placeholder={t("nodeRegionPlaceholder")}
                className="w-full px-3.5 py-2.5 rounded-xl bg-gray-50 dark:bg-gray-800/60 border border-gray-200 dark:border-gray-700 text-sm text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-indigo-500/50"
              />
            </div>
          </div>

          <div>
            <label className="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1.5">
              {t("nodeEndpoint")} <span className="text-red-500">*</span>
            </label>
            <input
              type="url"
              required
              value={endpointUrl}
              onChange={(e) => setEndpointUrl(e.target.value)}
              placeholder="https://compute-node.internal:8443"
              className="w-full px-3.5 py-2.5 rounded-xl bg-gray-50 dark:bg-gray-800/60 border border-gray-200 dark:border-gray-700 text-sm font-mono text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-indigo-500/50"
            />
          </div>

          {host.server_fingerprint && (
            <div className="p-3 rounded-xl bg-gray-50 dark:bg-gray-800/40 border border-gray-200 dark:border-gray-700/60 text-xs">
              <div className="flex items-center space-x-1.5 text-gray-600 dark:text-gray-400 font-semibold mb-1">
                <Fingerprint className="w-3.5 h-3.5 text-indigo-500" />
                <span>Pinned Server Fingerprint (SHA-256)</span>
              </div>
              <p className="font-mono text-[11px] text-gray-900 dark:text-gray-200 break-all select-all">
                {host.server_fingerprint}
              </p>
            </div>
          )}

          <div>
            <label className="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1.5">
              {t("nodeDriver")}
            </label>
            <select
              value={driver}
              onChange={(e) => setDriver(e.target.value as "incus" | "lxd" | "fake")}
              className="w-full px-3.5 py-2.5 rounded-xl bg-gray-50 dark:bg-gray-800/60 border border-gray-200 dark:border-gray-700 text-sm text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-indigo-500/50"
            >
              <option value="incus">Incus Hypervisor</option>
              <option value="lxd">LXD Hypervisor</option>
              <option value="fake">Emulated Driver (Dev / Test)</option>
            </select>
          </div>

          {/* Verify Connection Button & Result Section */}
          <div className="p-3.5 rounded-2xl bg-indigo-500/5 dark:bg-indigo-500/10 border border-indigo-500/20 space-y-2.5">
            <div className="flex items-center justify-between">
              <div>
                <span className="text-xs font-semibold text-gray-800 dark:text-gray-200">
                  {t("verifyConnection") || "Verify connection"}
                </span>
                <p className="text-[10px] text-gray-500 dark:text-gray-400">
                  Check mTLS cryptographic trust & pinned certificate
                </p>
              </div>
              <button
                type="button"
                onClick={handleVerifyConnection}
                disabled={verifyLoading}
                className="flex items-center space-x-1.5 px-3 py-1.5 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-semibold shadow-sm transition disabled:opacity-40"
              >
                {verifyLoading ? (
                  <Loader2 className="w-3.5 h-3.5 animate-spin" />
                ) : (
                  <Activity className="w-3.5 h-3.5" />
                )}
                <span>{verifyLoading ? t("verifyingConnection") : t("verifyConnection")}</span>
              </button>
            </div>

            {verifyError && (
              <div className="p-2.5 rounded-xl bg-red-500/10 border border-red-500/20 text-xs text-red-600 dark:text-red-400 flex items-start space-x-2">
                <AlertCircle className="w-4 h-4 mt-0.5 shrink-0" />
                <span>{verifyError}</span>
              </div>
            )}

            {verifyResult && renderStateBadge(verifyResult)}
          </div>

          {/* Node Capacity */}
          <div className="p-4 rounded-2xl bg-gray-50 dark:bg-gray-800/40 border border-gray-200 dark:border-gray-700/60 space-y-3">
            <h3 className="text-xs font-bold uppercase tracking-wider text-gray-700 dark:text-gray-300">
              {t("nodeCapacity")}
            </h3>
            <div className="grid grid-cols-3 gap-3">
              <div>
                <label className="block text-[11px] font-medium text-gray-600 dark:text-gray-400 mb-1">
                  {t("vCpuCores")}
                </label>
                <input
                  type="number"
                  min={1}
                  value={totalCpuCores}
                  onChange={(e) => setTotalCpuCores(parseInt(e.target.value, 10) || 1)}
                  className="w-full px-2.5 py-1.5 rounded-lg bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 text-xs text-gray-900 dark:text-white"
                />
              </div>

              <div>
                <label className="block text-[11px] font-medium text-gray-600 dark:text-gray-400 mb-1">
                  RAM (GB)
                </label>
                <input
                  type="number"
                  min={1}
                  value={totalRamGb}
                  onChange={(e) => setTotalRamGb(parseInt(e.target.value, 10) || 1)}
                  className="w-full px-2.5 py-1.5 rounded-lg bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 text-xs text-gray-900 dark:text-white"
                />
              </div>

              <div>
                <label className="block text-[11px] font-medium text-gray-600 dark:text-gray-400 mb-1">
                  Storage (GB)
                </label>
                <input
                  type="number"
                  min={10}
                  value={totalStorageGb}
                  onChange={(e) => setTotalStorageGb(parseInt(e.target.value, 10) || 10)}
                  className="w-full px-2.5 py-1.5 rounded-lg bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 text-xs text-gray-900 dark:text-white"
                />
              </div>
            </div>
          </div>

          <div className="p-3.5 rounded-xl bg-gray-50 dark:bg-gray-800/40 border border-gray-200 dark:border-gray-700/60">
            <label className="flex items-center space-x-2.5 cursor-pointer">
              <input
                type="checkbox"
                checked={isActive}
                onChange={(e) => setIsActive(e.target.checked)}
                className="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
              />
              <span className="text-xs font-medium text-gray-800 dark:text-gray-200">{t("activeNode")}</span>
            </label>
          </div>

          <div className="flex items-center justify-end space-x-3 pt-4 border-t border-gray-100 dark:border-gray-800">
            <button
              type="button"
              onClick={onClose}
              disabled={loading}
              className="px-4 py-2.5 rounded-xl text-xs font-medium text-gray-600 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-800 transition"
            >
              {t("cancel")}
            </button>
            <button
              type="submit"
              disabled={loading || !isFormValid}
              className="flex items-center space-x-2 px-5 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-semibold shadow-md shadow-indigo-500/20 transition disabled:opacity-40 disabled:cursor-not-allowed"
            >
              {loading ? <Loader2 className="w-4 h-4 animate-spin" /> : <CheckCircle2 className="w-4 h-4" />}
              <span>{t("saveChanges") || "Save Changes"}</span>
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}
