"use client";

import React from "react";
import {
  Loader2,
  CheckCircle2,
  AlertCircle,
  AlertTriangle,
  Activity,
  ShieldAlert,
} from "lucide-react";
import type { TestConnectionResponse } from "@/lib/api";
import { useI18n } from "@/lib/i18n/I18nContext";
import { useConfig } from "@/lib/config/ConfigContext";
import { isHostNameValid, isValidHttpUrl } from "./hostFormSchema";

export type HostDriver = "incus" | "lxd" | "fake";

export interface HostConnectionFieldsProps {
  name: string;
  onNameChange: (value: string) => void;
  endpointUrl: string;
  onEndpointUrlChange: (value: string) => void;
  driver: HostDriver;
  onDriverChange: (value: HostDriver) => void;
  isActive: boolean;
  onIsActiveChange: (checked: boolean) => void;
  verifyResult: TestConnectionResponse | null;
  verifyError: string | null;
  verifyLoading?: boolean;
  onVerify?: () => void;

  /** Visibility flags */
  showName?: boolean;
  showDriver?: boolean;
  showEndpoint?: boolean;
  showVerify?: boolean;
  showActive?: boolean;

  /** Endpoint input behavior */
  endpointReadOnly?: boolean;

  /** Create vs Edit markup differences */
  showNameValidationHint?: boolean;
  driverPlacement?: "grid" | "below";
  nameSibling?: React.ReactNode;
  showEndpointPortHint?: boolean;
  validateEndpointBorder?: boolean;
  wrapEndpointSection?: boolean;
  allowFakeDriver?: boolean;
  verifySubtitle?: string;
  verifyDisabled?: boolean;
  verifyButtonCursorNotAllowed?: boolean;

  /** Slot for mode/modal-specific fields (e.g. token, expected fingerprint, pinned fingerprint) */
  children?: React.ReactNode;
}

/**
 * Shared host connection fields and verification status banner extracted from
 * CreateHostModal and EditHostModal.
 */
export function HostConnectionFields({
  name,
  onNameChange,
  endpointUrl,
  onEndpointUrlChange,
  driver,
  onDriverChange,
  isActive,
  onIsActiveChange,
  verifyResult,
  verifyError,
  verifyLoading = false,
  onVerify,
  showName = true,
  showDriver = true,
  showEndpoint = true,
  showVerify = true,
  showActive = true,
  endpointReadOnly = false,
  showNameValidationHint = false,
  driverPlacement = "grid",
  nameSibling,
  showEndpointPortHint = false,
  validateEndpointBorder = false,
  wrapEndpointSection = false,
  allowFakeDriver = false,
  verifySubtitle,
  verifyDisabled = false,
  verifyButtonCursorNotAllowed = false,
  children,
}: HostConnectionFieldsProps) {
  const { t } = useI18n();
  const { features } = useConfig();

  const isNameValid = isHostNameValid(name);
  const showFakeOption = Boolean(features?.fake_driver || allowFakeDriver);

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

  const driverField = showDriver ? (
    <div>
      <label className="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1.5">
        {t("nodeDriver")}
      </label>
      <select
        value={driver}
        onChange={(e) => onDriverChange(e.target.value as HostDriver)}
        className="w-full px-3.5 py-2.5 rounded-xl bg-gray-50 dark:bg-gray-800/60 border border-gray-200 dark:border-gray-700 text-sm text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-indigo-500/50"
      >
        <option value="incus">Incus Hypervisor</option>
        <option value="lxd">LXD Hypervisor</option>
        {showFakeOption && (
          <option value="fake">Emulated Driver (Dev / Test)</option>
        )}
      </select>
    </div>
  ) : null;

  const endpointField = showEndpoint ? (
    <div>
      <label className="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1.5">
        {t("nodeEndpoint")}
        {showEndpointPortHint ? " (Host:Port) " : " "}
        <span className="text-red-500">*</span>
      </label>
      <input
        type="url"
        required
        readOnly={endpointReadOnly}
        value={endpointUrl}
        onChange={(e) => onEndpointUrlChange(e.target.value)}
        placeholder="https://compute-node.internal:8443"
        className={
          validateEndpointBorder
            ? `w-full px-3.5 py-2.5 rounded-xl bg-gray-50 dark:bg-gray-800/60 border text-sm font-mono text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-indigo-500/50 ${
                endpointUrl.length > 0 && !isValidHttpUrl(endpointUrl)
                  ? "border-red-400 dark:border-red-500/60"
                  : "border-gray-200 dark:border-gray-700"
              }`
            : "w-full px-3.5 py-2.5 rounded-xl bg-gray-50 dark:bg-gray-800/60 border border-gray-200 dark:border-gray-700 text-sm font-mono text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-indigo-500/50"
        }
      />
    </div>
  ) : null;

  const verifyBannerContent = (
    <>
      {verifyError && (
        <div className="p-2.5 rounded-xl bg-red-500/10 border border-red-500/20 text-xs text-red-600 dark:text-red-400 flex items-start space-x-2">
          <AlertCircle className="w-4 h-4 mt-0.5 shrink-0" />
          <span>{verifyError}</span>
        </div>
      )}

      {verifyResult && renderStateBadge(verifyResult)}
    </>
  );

  const verifySection = showVerify ? (
    onVerify ? (
      <div className="p-3.5 rounded-2xl bg-indigo-500/5 dark:bg-indigo-500/10 border border-indigo-500/20 space-y-2.5">
        <div className="flex items-center justify-between">
          {verifySubtitle ? (
            <div>
              <span className="text-xs font-semibold text-gray-800 dark:text-gray-200">
                {t("verifyConnection") || "Verify connection"}
              </span>
              <p className="text-[10px] text-gray-500 dark:text-gray-400">
                {verifySubtitle}
              </p>
            </div>
          ) : (
            <span className="text-xs font-semibold text-gray-800 dark:text-gray-200">
              {t("verifyConnection")}
            </span>
          )}
          <button
            type="button"
            onClick={onVerify}
            disabled={verifyLoading || verifyDisabled}
            className={`flex items-center space-x-1.5 px-3 py-1.5 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-semibold shadow-sm transition disabled:opacity-40${
              verifyButtonCursorNotAllowed ? " disabled:cursor-not-allowed" : ""
            }`}
          >
            {verifyLoading ? (
              <Loader2 className="w-3.5 h-3.5 animate-spin" />
            ) : (
              <Activity className="w-3.5 h-3.5" />
            )}
            <span>{verifyLoading ? t("verifyingConnection") : t("verifyConnection")}</span>
          </button>
        </div>

        {verifyBannerContent}
      </div>
    ) : (
      (verifyError || verifyResult) && verifyBannerContent
    )
  ) : null;

  const middleSection = (
    <>
      {endpointField}
      {children}
      {driverPlacement === "below" && driverField}
      {verifySection}
    </>
  );

  const showTopGrid = showName || (driverPlacement === "grid" && showDriver) || Boolean(nameSibling);

  return (
    <>
      {showTopGrid && (
        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
          {showName && (
            <div>
              <label className="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1.5">
                {t("nodeName")} <span className="text-red-500">*</span>
              </label>
              <input
                type="text"
                required
                value={name}
                onChange={(e) => onNameChange(e.target.value.toLowerCase().replace(/[^a-z0-9-]/g, ""))}
                placeholder="e.g. compute-01 or eu-west-1"
                className={`w-full px-3.5 py-2.5 rounded-xl bg-gray-50 dark:bg-gray-800/60 border text-sm font-mono text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-indigo-500/50 ${
                  name.length > 0 && !isNameValid
                    ? "border-red-400 dark:border-red-500/60"
                    : "border-gray-200 dark:border-gray-700"
                }`}
              />
              {showNameValidationHint && name.length > 0 && !isNameValid && (
                <p className="mt-1 text-[11px] text-red-500">Min 2 lowercase chars (a-z, 0-9, -)</p>
              )}
            </div>
          )}

          {driverPlacement === "grid" ? driverField : nameSibling}
        </div>
      )}

      {wrapEndpointSection ? (
        <div className="space-y-4">{middleSection}</div>
      ) : (
        middleSection
      )}

      {showActive && (
        <div className="p-3.5 rounded-xl bg-gray-50 dark:bg-gray-800/40 border border-gray-200 dark:border-gray-700/60">
          <label className="flex items-center space-x-2.5 cursor-pointer">
            <input
              type="checkbox"
              checked={isActive}
              onChange={(e) => onIsActiveChange(e.target.checked)}
              className="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
            />
            <span className="text-xs font-medium text-gray-800 dark:text-gray-200">
              {t("activeNode")}
            </span>
          </label>
        </div>
      )}
    </>
  );
}
