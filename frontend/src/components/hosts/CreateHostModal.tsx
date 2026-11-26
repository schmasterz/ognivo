"use client";

import React, { useState } from "react";
import {
  X,
  Server,
  Loader2,
  CheckCircle2,
  Key,
  FileCheck,
} from "lucide-react";
import { api, HostNode } from "@/lib/api";
import { toSelectionPayload } from "@/lib/images/imageSelection";
import { HostImagesSection } from "./HostImagesSection";
import { HostCapacityFields, HostCapacityValues } from "./HostCapacityFields";
import { HostConnectionFields, HostDriver } from "./HostConnectionFields";
import { useHostVerify } from "./useHostVerify";
import { useHostImageSelection } from "./useHostImageSelection";
import {
  HOST_DEFAULTS,
  isHostNameValid,
  isValidHttpUrl,
  isCapacityValid,
  isTokenValid,
  isFingerprintValid,
} from "./hostFormSchema";
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
  const [driver, setDriver] = useState<HostDriver>("incus");
  const [isActive, setIsActive] = useState(true);
  const [autoDiscoverCapacity, setAutoDiscoverCapacity] = useState(true);
  const [capacity, setCapacity] = useState<HostCapacityValues>({
    totalCpuCores: HOST_DEFAULTS.cpuCores,
    totalRamGb: HOST_DEFAULTS.ramGb,
    totalStorageGb: HOST_DEFAULTS.storageGb,
    cpuOvercommit: HOST_DEFAULTS.overcommit,
    ramOvercommit: HOST_DEFAULTS.overcommit,
    diskOvercommit: HOST_DEFAULTS.overcommit,
  });

  // Token Mode
  const [token, setToken] = useState("");

  // Certificate Mode
  const [endpointUrl, setEndpointUrl] = useState("https://127.0.0.1:8443");
  const [expectedFingerprint, setExpectedFingerprint] = useState("");

  // Verification State
  const {
    verifyLoading,
    verifyResult,
    verifyError,
    verify,
  } = useHostVerify(
    (params: {
      endpoint_url: string;
      driver: HostDriver;
      expected_fingerprint?: string;
    }) => api.adminTestHostConnection(params)
  );

  // Image Selection State
  const {
    autoAttachImages,
    setAutoAttachImages,
    imageSelection,
    setImageSelection,
    imageCounts,
    setImageCounts,
    countsLoading,
    selectionValidation,
  } = useHostImageSelection({ mode: "create" });

  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useModalEscape(onClose, !loading && !verifyLoading);

  // Validation
  const capacityValid = isCapacityValid(
    {
      cpuCores: capacity.totalCpuCores,
      ramGb: capacity.totalRamGb,
      storageGb: capacity.totalStorageGb,
      cpuOvercommit: capacity.cpuOvercommit,
      ramOvercommit: capacity.ramOvercommit,
      diskOvercommit: capacity.diskOvercommit,
    },
    { skipTotals: autoDiscoverCapacity }
  );

  const isFormValid =
    mode === "token"
      ? Boolean(isHostNameValid(name) && isTokenValid(token) && capacityValid)
      : Boolean(
          isHostNameValid(name) &&
            isValidHttpUrl(endpointUrl) &&
            isFingerprintValid(expectedFingerprint) &&
            capacityValid
        );

  const handleVerifyConnection = () => {
    void verify({
      endpoint_url: endpointUrl.trim(),
      driver,
      expected_fingerprint: expectedFingerprint.trim() || undefined,
    });
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
          total_cpu_cores: autoDiscoverCapacity ? undefined : capacity.totalCpuCores,
          total_ram_mb: autoDiscoverCapacity ? undefined : capacity.totalRamGb * 1024,
          total_storage_gb: autoDiscoverCapacity ? undefined : capacity.totalStorageGb,
          cpu_overcommit: capacity.cpuOvercommit,
          ram_overcommit: capacity.ramOvercommit,
          disk_overcommit: capacity.diskOvercommit,
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
          total_cpu_cores: autoDiscoverCapacity ? undefined : capacity.totalCpuCores,
          total_ram_mb: autoDiscoverCapacity ? undefined : capacity.totalRamGb * 1024,
          total_storage_gb: autoDiscoverCapacity ? undefined : capacity.totalStorageGb,
          cpu_overcommit: capacity.cpuOvercommit,
          ram_overcommit: capacity.ramOvercommit,
          disk_overcommit: capacity.diskOvercommit,
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
          <HostConnectionFields
            name={name}
            onNameChange={setName}
            endpointUrl={endpointUrl}
            onEndpointUrlChange={setEndpointUrl}
            driver={driver}
            onDriverChange={setDriver}
            isActive={isActive}
            onIsActiveChange={setIsActive}
            verifyResult={verifyResult}
            verifyError={verifyError}
            verifyLoading={verifyLoading}
            onVerify={handleVerifyConnection}
            showEndpoint={mode === "cert"}
            showVerify={mode === "cert"}
            showActive={false}
            showNameValidationHint
            showEndpointPortHint
            validateEndpointBorder
            wrapEndpointSection={mode === "cert"}
            verifyDisabled={!isValidHttpUrl(endpointUrl)}
            verifyButtonCursorNotAllowed
          >
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
            )}
          </HostConnectionFields>

          {/* Node Capacity */}
          <HostCapacityFields
            values={capacity}
            onChange={(patch) => setCapacity((prev) => ({ ...prev, ...patch }))}
            autoDiscover={autoDiscoverCapacity}
            onAutoDiscoverChange={setAutoDiscoverCapacity}
            showAutoDiscover
          />

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
