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
  Key,
  ShieldAlert,
  FileCheck,
} from "lucide-react";
import { api, HostNode, TestConnectionResponse, ImageCatalogCounts } from "@/lib/api";
import {
  createInitialSelection,
  canSaveSelection,
  toSelectionPayload,
  StagedImageSelection,
} from "@/lib/images/imageSelection";
import { HostImagesSection } from "./HostImagesSection";
import { useI18n } from "@/lib/i18n/I18nContext";
import { useModalEscape } from "@/lib/hooks/useModalEscape";

interface CreateHostModalProps {
  onClose: () => void;
  onSuccess: (newHost: HostNode) => void;
}

export function CreateHostModal({ onClose, onSuccess }: CreateHostModalProps) {
  const { t } = useI18n();

  // Mode: primary is "token" (Connect with token), secondary is "cert" (Add with certificate)
  const [mode, setMode] = useState<"token" | "cert">("token");

  // Common Fields
  const [name, setName] = useState("");
  const [region] = useState("");
  const [driver, setDriver] = useState<"incus" | "lxd" | "fake">("incus");
  const [isActive, setIsActive] = useState(true);
  const [totalCpuCores, setTotalCpuCores] = useState(64);
  const [totalRamGb, setTotalRamGb] = useState(256);
  const [totalStorageGb, setTotalStorageGb] = useState(4000);

  // Token Mode
  const [token, setToken] = useState("");

  // Certificate Mode
  const [endpointUrl, setEndpointUrl] = useState("https://127.0.0.1:8443");
  const [expectedFingerprint, setExpectedFingerprint] = useState("");

  // Verification State
  const [verifyLoading, setVerifyLoading] = useState(false);
  const [verifyResult, setVerifyResult] = useState<TestConnectionResponse | null>(null);
  const [verifyError, setVerifyError] = useState<string | null>(null);

  // Image Selection State
  const [autoAttachImages, setAutoAttachImages] = useState(true);
  const [imageSelection, setImageSelection] = useState<StagedImageSelection>(() =>
    createInitialSelection("create")
  );
  const [imageCounts, setImageCounts] = useState<ImageCatalogCounts | null>(null);
  const [countsLoading, setCountsLoading] = useState(false);

  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useModalEscape(onClose, !loading && !verifyLoading);

  useEffect(() => {
    const controller = new AbortController();
    queueMicrotask(() => {
      setCountsLoading(true);
    });
    api
      .adminGetImages({ per_page: 1 }, { signal: controller.signal })
      .then((res) => {
        setImageCounts(res.counts);
      })
      .catch((err: unknown) => {
        if (err instanceof DOMException && err.name === "AbortError") {
          return;
        }
      })
      .finally(() => {
        setCountsLoading(false);
      });

    return () => {
      controller.abort();
    };
  }, []);

  // Validation
  const isNameValid = name.trim().length >= 2 && /^[a-z0-9-]+$/.test(name.trim());
  const isCapacityValid = totalCpuCores >= 1 && totalRamGb >= 1 && totalStorageGb >= 10;
  const selectionValidation = canSaveSelection(imageSelection, imageCounts);

  const isValidUrl = (urlStr: string): boolean => {
    try {
      const parsed = new URL(urlStr.trim());
      return (parsed.protocol === "http:" || parsed.protocol === "https:") && Boolean(parsed.hostname);
    } catch {
      return false;
    }
  };

  const isFormValid =
    mode === "token"
      ? Boolean(isNameValid && token.trim().length > 10 && isCapacityValid)
      : Boolean(
          isNameValid &&
            isValidUrl(endpointUrl) &&
            expectedFingerprint.trim().length >= 32 &&
            isCapacityValid
        );

  const handleVerifyConnection = async () => {
    setVerifyLoading(true);
    setVerifyResult(null);
    setVerifyError(null);

    try {
      const res = await api.adminTestHostConnection({
        endpoint_url: endpointUrl.trim(),
        driver,
        expected_fingerprint: expectedFingerprint.trim() || undefined,
      });
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

    if (!selectionValidation.valid) {
      setError(t("atLeastOneImageRequired"));
      return;
    }

    try {
      setLoading(true);

      const imagesPayload = toSelectionPayload(imageSelection, "create");
      let createdHost: HostNode;

      if (mode === "token") {
        const res = await api.adminConnectWithToken({
          name: name.trim().toLowerCase(),
          token: token.trim(),
          driver,
          region: region.trim() || undefined,
          is_active: isActive,
          total_cpu_cores: totalCpuCores,
          total_ram_mb: totalRamGb * 1024,
          total_storage_gb: totalStorageGb,
          auto_attach_images: autoAttachImages,
          images: imagesPayload,
        });
        createdHost = res.host;
      } else {
        const res = await api.adminAddWithCertificate({
          name: name.trim().toLowerCase(),
          address: endpointUrl.trim(),
          expected_fingerprint: expectedFingerprint.trim().toLowerCase(),
          driver,
          region: region.trim() || undefined,
          is_active: isActive,
          total_cpu_cores: totalCpuCores,
          total_ram_mb: totalRamGb * 1024,
          total_storage_gb: totalStorageGb,
          auto_attach_images: autoAttachImages,
          images: imagesPayload,
        });
        createdHost = res.host;
      }

      onSuccess(createdHost);
      onClose();
    } catch (err: unknown) {
      if (err instanceof Error) {
        setError(err.message);
      } else {
        setError("Failed to create compute node.");
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
                {t("createNode")}
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

        {/* Primary Action / Secondary Action Tabs */}
        <div className="flex space-x-2 p-1 bg-gray-100 dark:bg-gray-800/80 rounded-2xl mb-5">
          <button
            type="button"
            onClick={() => {
              setMode("token");
              setError(null);
            }}
            className={`flex-1 flex items-center justify-center space-x-2 py-2 px-3 rounded-xl text-xs font-semibold transition ${
              mode === "token"
                ? "bg-white dark:bg-gray-900 text-indigo-600 dark:text-indigo-400 shadow-sm"
                : "text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-white"
            }`}
          >
            <Key className="w-3.5 h-3.5" />
            <span>{t("connectWithToken") || "Connect with token (Primary)"}</span>
          </button>
          <button
            type="button"
            onClick={() => {
              setMode("cert");
              setError(null);
            }}
            className={`flex-1 flex items-center justify-center space-x-2 py-2 px-3 rounded-xl text-xs font-semibold transition ${
              mode === "cert"
                ? "bg-white dark:bg-gray-900 text-indigo-600 dark:text-indigo-400 shadow-sm"
                : "text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-white"
            }`}
          >
            <FileCheck className="w-3.5 h-3.5" />
            <span>{t("addWithCertificate") || "Add with certificate"}</span>
          </button>
        </div>

        {error && (
          <div className="mb-5 p-3.5 rounded-xl bg-red-500/10 border border-red-500/20 text-xs text-red-600 dark:text-red-400">
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
              {name.length > 0 && !isNameValid && (
                <p className="mt-1 text-[11px] text-red-500">Min 2 lowercase chars (a-z, 0-9, -)</p>
              )}
            </div>

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
                {process.env.NEXT_PUBLIC_ENABLE_FAKE_DRIVER === "true" && (
                  <option value="fake">Emulated Driver (Dev / Test)</option>
                )}
              </select>
            </div>
          </div>

          {/* Mode-specific Fields */}
          {mode === "token" ? (
            <div className="p-4 rounded-2xl bg-gray-50 dark:bg-gray-800/40 border border-gray-200 dark:border-gray-700/60 space-y-2">
              <div className="flex items-center space-x-1.5 text-xs font-bold uppercase tracking-wider text-gray-700 dark:text-gray-300">
                <Key className="w-3.5 h-3.5 text-amber-500" />
                <span>{t("trustToken")}</span>
              </div>
              <p className="text-[11px] text-gray-500 dark:text-gray-400">
                Generate a single-use token on your {driver === "lxd" ? "LXD" : "Incus"} server via:
                <code className="ml-1 px-1.5 py-0.5 rounded bg-gray-200 dark:bg-gray-700 font-mono text-[10px]">
                  {driver === "lxd"
                    ? "lxc config trust add --name panel"
                    : "incus config trust add panel"}
                </code>
              </p>
              <div className="relative">
                <textarea
                  rows={3}
                  required
                  value={token}
                  onChange={(e) => setToken(e.target.value.trim())}
                  placeholder={t("trustTokenPlaceholder")}
                  className="w-full p-3 rounded-xl bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 text-xs font-mono text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-indigo-500/50 resize-none break-all"
                />
              </div>
            </div>
          ) : (
            <div className="space-y-4">
              <div>
                <label className="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1.5">
                  {t("nodeEndpoint")} (Host:Port) <span className="text-red-500">*</span>
                </label>
                <input
                  type="url"
                  required
                  value={endpointUrl}
                  onChange={(e) => setEndpointUrl(e.target.value)}
                  placeholder="https://compute-node.internal:8443"
                  className={`w-full px-3.5 py-2.5 rounded-xl bg-gray-50 dark:bg-gray-800/60 border text-sm font-mono text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-indigo-500/50 ${
                    endpointUrl.length > 0 && !isValidUrl(endpointUrl)
                      ? "border-red-400 dark:border-red-500/60"
                      : "border-gray-200 dark:border-gray-700"
                  }`}
                />
              </div>

              <div>
                <label className="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1.5">
                  {t("serverFingerprint")} (SHA-256) <span className="text-red-500">*</span>
                </label>
                <input
                  type="text"
                  required
                  value={expectedFingerprint}
                  onChange={(e) => setExpectedFingerprint(e.target.value.trim().toLowerCase())}
                  placeholder={t("serverFingerprintPlaceholder")}
                  className="w-full px-3.5 py-2.5 rounded-xl bg-gray-50 dark:bg-gray-800/60 border border-gray-200 dark:border-gray-700 text-xs font-mono text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-indigo-500/50"
                />
                <p className="mt-1 text-[11px] text-gray-500 dark:text-gray-400">
                  Run <code className="font-mono text-[10px]">{driver === "lxd" ? "lxc info" : "incus info"}</code> on the host to copy its server fingerprint. First add the panel certificate with <code className="font-mono text-[10px]">{driver === "lxd" ? "lxc config trust add incus-panel.crt" : "incus config trust add-certificate incus-panel.crt"}</code>.
                </p>
              </div>

              {/* Verify Connection Button in Certificate Mode */}
              <div className="p-3.5 rounded-2xl bg-indigo-500/5 dark:bg-indigo-500/10 border border-indigo-500/20 space-y-2.5">
                <div className="flex items-center justify-between">
                  <span className="text-xs font-semibold text-gray-800 dark:text-gray-200">
                    {t("verifyConnection")}
                  </span>
                  <button
                    type="button"
                    onClick={handleVerifyConnection}
                    disabled={verifyLoading || !isValidUrl(endpointUrl)}
                    className="flex items-center space-x-1.5 px-3 py-1.5 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-semibold shadow-sm transition disabled:opacity-40 disabled:cursor-not-allowed"
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
            </div>
          )}

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

          {/* Node Images */}
          <HostImagesSection
            flow="create"
            autoAttachImages={autoAttachImages}
            onAutoAttachChange={setAutoAttachImages}
            selection={imageSelection}
            onSelectionChange={setImageSelection}
            counts={imageCounts}
            onCountsUpdate={setImageCounts}
            countsLoading={countsLoading}
            validationError={!selectionValidation.valid ? t("atLeastOneImageRequired") : null}
          />

          <div className="p-3.5 rounded-xl bg-gray-50 dark:bg-gray-800/40 border border-gray-200 dark:border-gray-700/60">
            <label className="flex items-center space-x-2.5 cursor-pointer">
              <input
                type="checkbox"
                checked={isActive}
                onChange={(e) => setIsActive(e.target.checked)}
                className="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
              />
              <span className="text-xs font-medium text-gray-800 dark:text-gray-200">
                {t("activeNode")}
              </span>
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
              {loading ? (
                <Loader2 className="w-4 h-4 animate-spin" />
              ) : mode === "token" ? (
                <Key className="w-4 h-4" />
              ) : (
                <CheckCircle2 className="w-4 h-4" />
              )}
              <span>
                {mode === "token"
                  ? t("connectWithToken") || "Connect with token"
                  : t("addWithCertificate") || "Add with certificate"}
              </span>
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}
