"use client";

import React, { useState, useEffect } from "react";
import {
  X,
  Server,
  Loader2,
  CheckCircle2,
  ShieldAlert,
  Fingerprint,
} from "lucide-react";
import { api, HostNode, PreviewImageDetachResponse } from "@/lib/api";
import { toSelectionPayload } from "@/lib/images/imageSelection";
import { HostImagesSection } from "./HostImagesSection";
import { ConfirmModal } from "@/components/ui/ConfirmModal";
import { useI18n } from "@/lib/i18n/I18nContext";
import { useModalEscape } from "@/lib/hooks/useModalEscape";
import {
  HOST_DEFAULTS,
  HostDriver,
  hostRamMbToGb,
  validateHostForm,
  buildUpdateHostPayload,
} from "./hostFormSchema";
import { useHostVerify } from "./useHostVerify";
import { useHostImageSelection } from "./useHostImageSelection";
import { HostCapacityFields, HostCapacityValues } from "./HostCapacityFields";
import { HostConnectionFields } from "./HostConnectionFields";

interface EditHostModalProps {
  host: HostNode;
  onClose: () => void;
  onSuccess: (updatedHost: HostNode) => void;
}

function normalizeDriver(driver: HostNode["driver"]): HostDriver {
  if (driver === "fake") return "fake";
  if (driver === "lxd") return "lxd";
  return "incus";
}

function getInitialCapacity(host: HostNode): HostCapacityValues {
  return {
    totalCpuCores: host.total_cpu_cores || HOST_DEFAULTS.cpuCores,
    totalRamGb: hostRamMbToGb(host.total_ram_mb),
    totalStorageGb: host.total_storage_gb || HOST_DEFAULTS.storageGb,
    cpuOvercommit: host.cpu_overcommit ?? HOST_DEFAULTS.overcommit,
    ramOvercommit: host.ram_overcommit ?? HOST_DEFAULTS.overcommit,
    diskOvercommit: host.disk_overcommit ?? HOST_DEFAULTS.overcommit,
  };
}

export function EditHostModal({ host, onClose, onSuccess }: EditHostModalProps) {
  const { t } = useI18n();

  const [name, setName] = useState(host.name);
  const [region, setRegion] = useState(host.region || "");
  const [endpointUrl, setEndpointUrl] = useState(host.endpoint_url ?? "");
  const [driver, setDriver] = useState<HostDriver>(() => normalizeDriver(host.driver));
  const [isActive, setIsActive] = useState(host.is_active);
  const [capacity, setCapacity] = useState<HostCapacityValues>(() => getInitialCapacity(host));

  // Trust Token State
  const [token] = useState("");
  const [tokenModified] = useState(false);

  const {
    verifyLoading,
    verifyResult,
    verifyError,
    verify: handleVerifyConnection,
    reset: resetVerify,
  } = useHostVerify<void>(() => api.adminVerifyHostConnection(host.id));

  const {
    autoAttachImages,
    setAutoAttachImages,
    imageSelection,
    setImageSelection,
    imageCounts,
    setImageCounts,
    countsLoading,
    selectionValidation,
    resetImageSelection,
  } = useHostImageSelection({
    mode: "edit",
    hostId: host.id,
    initialAutoAttach: host.auto_attach_images ?? true,
  });

  const [detachPreview, setDetachPreview] = useState<PreviewImageDetachResponse | null>(null);
  const [pendingUpdatePayload, setPendingUpdatePayload] = useState<Parameters<
    typeof api.adminUpdateHost
  >[1] | null>(null);

  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useModalEscape(onClose, !loading && !verifyLoading);

  useEffect(() => {
    queueMicrotask(() => {
      setName(host.name);
      setRegion(host.region || "");
      setEndpointUrl(host.endpoint_url ?? "");
      setDriver(normalizeDriver(host.driver));
      setIsActive(host.is_active);
      setCapacity(getInitialCapacity(host));
      resetImageSelection(host.auto_attach_images ?? true);
      setDetachPreview(null);
      setPendingUpdatePayload(null);
      setError(null);
      resetVerify();
      setLoading(false);
    });
  }, [host, resetVerify, resetImageSelection]);

  const isFormValid = validateHostForm({
    mode: "edit",
    name,
    endpointUrl,
    capacity,
  });

  const executeHostUpdate = async (payload: Parameters<typeof api.adminUpdateHost>[1]) => {
    try {
      setLoading(true);
      setError(null);
      const res = await api.adminUpdateHost(host.id, payload);
      onSuccess(res.host);
      onClose();
    } catch (err: unknown) {
      setDetachPreview(null);
      setPendingUpdatePayload(null);
      if (err instanceof Error) {
        setError(err.message);
      } else {
        setError("Failed to update compute node.");
      }
    } finally {
      setLoading(false);
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

    const imagesPayload = toSelectionPayload(imageSelection, "edit");
    const updatePayload = buildUpdateHostPayload(
      {
        name,
        region,
        endpointUrl,
        driver,
        isActive,
        capacity,
        token,
        tokenModified,
        autoAttachImages,
        images: imagesPayload,
      },
      host
    );

    if (updatePayload.images !== undefined) {
      try {
        setLoading(true);
        const preview = await api.adminPreviewHostImageDetach(host.id, updatePayload.images);
        if (preview.detached > 0 && preview.total_instances > 0) {
          setPendingUpdatePayload(updatePayload);
          setDetachPreview(preview);
          setLoading(false);
          return;
        }
      } catch (err: unknown) {
        setLoading(false);
        if (err instanceof Error) {
          setError(err.message);
        } else {
          setError("Failed to preview image detachment.");
        }
        return;
      }
    }

    await executeHostUpdate(updatePayload);
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
            onVerify={() => void handleVerifyConnection()}
            showActive={false}
            driverPlacement="below"
            allowFakeDriver={host.driver === "fake"}
            verifySubtitle="Check mTLS cryptographic trust & pinned certificate"
            nameSibling={
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
            }
          >
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
          </HostConnectionFields>

          <HostCapacityFields
            values={capacity}
            onChange={(patch) => setCapacity((prev) => ({ ...prev, ...patch }))}
          />

          {/* Node Images */}
          <HostImagesSection
            flow="edit"
            hostId={host.id}
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

        {detachPreview !== null && pendingUpdatePayload !== null && (
          <ConfirmModal
            isOpen={detachPreview !== null && pendingUpdatePayload !== null}
            title={t("confirmDetachImagesTitle")}
            message={t("confirmDetachImagesMessage")
              .replace("{count}", String(detachPreview.total_instances))
              .replace(
                "{images}",
                detachPreview.images
                  .slice(0, 5)
                  .map((img) => `${img.name} (${img.instance_count})`)
                  .join(", ") +
                  (detachPreview.images.length > 5
                    ? ` +${detachPreview.images.length - 5}`
                    : "")
              )}
            confirmText={t("confirmDetachImagesBtn")}
            variant="warning"
            isLoading={loading}
            onConfirm={async () => {
              if (pendingUpdatePayload) {
                await executeHostUpdate(pendingUpdatePayload);
              }
            }}
            onClose={() => {
              if (!loading) {
                setDetachPreview(null);
                setPendingUpdatePayload(null);
              }
            }}
          />
        )}
      </div>
    </div>
  );
}
