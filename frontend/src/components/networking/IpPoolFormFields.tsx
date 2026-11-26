"use client";

import React from "react";
import { useI18n } from "@/lib/i18n/I18nContext";
import { PoolNetworkMode, getFieldError } from "./cidrUtils";

export interface IpPoolFormFieldsProps {
  /** Current network mode, used for gateway label and hint text. */
  networkMode: PoolNetworkMode | string;
  gateway: string;
  onGatewayChange: (value: string) => void;
  firstUsable: string;
  onFirstUsableChange: (value: string) => void;
  lastUsable: string;
  onLastUsableChange: (value: string) => void;
  dnsServers: string;
  onDnsServersChange: (value: string) => void;
  cooldownSeconds: number;
  onCooldownSecondsChange: (value: number) => void;
  excludedIps: string;
  onExcludedIpsChange: (value: string) => void;
  description: string;
  onDescriptionChange: (value: string) => void;
  isPublic: boolean;
  onIsPublicChange: (value: boolean) => void;
  active?: boolean;
  onActiveChange?: (value: boolean) => void;

  /** Field-level validation errors keyed by API field name. */
  fieldErrors: Record<string, string[]>;

  /** Controls the `required` attribute on the gateway input. */
  isGatewayRequired: boolean;
  /**
   * Controls whether the gateway label displays the required asterisk (`*`).
   * Differs from `isGatewayRequired` in Edit mode when `isGatewayLocked` is true
   * (Edit keeps the `*` label in L2 mode while `required` is false).
   * Defaults to `isGatewayRequired` when omitted.
   */
  isGatewayLabelRequired?: boolean;
  /**
   * Edit-only flag: sets `disabled` and `readOnly` on the gateway input and
   * renders the amber `gatewayImmutableAllocatedDesc` hint.
   */
  isGatewayLocked?: boolean;
  /**
   * Edit-only flag: appends `disabled:opacity-60 disabled:cursor-not-allowed`
   * to the gateway input classes (Create omits those classes).
   */
  includeGatewayDisabledStyles?: boolean;
  /** Create sets `"203.0.113.1"`; Edit omits placeholder. */
  firstUsablePlaceholder?: string;
  /** Create sets `"203.0.113.254"`; Edit omits placeholder. */
  lastUsablePlaceholder?: string;
  /**
   * Create uses `"px-3 py-2"` on first/last usable inputs; Edit uses `"px-3.5 py-2.5"`.
   * Defaults to `false` (`"px-3.5 py-2.5"`).
   */
  compactUsableInputs?: boolean;
  /** Create sets `"300"`; Edit omits placeholder. */
  cooldownPlaceholder?: string;
  /** Create renders `placeholder={t("poolDescriptionPlaceholder")}`; Edit omits placeholder. */
  showDescriptionPlaceholder?: boolean;
  /** Whether to render the `isPublic` checkbox. Defaults to `true`. */
  showPublicToggle?: boolean;
  /** Edit-only flag: renders the `active` checkbox alongside `isPublic` in a 2-column grid. */
  showActiveToggle?: boolean;

  /** Optional slot rendered before the gateway row (e.g. name, host, CIDR, network mode). */
  children?: React.ReactNode;
  /** Optional slot rendered inside the gateway 2-column grid immediately before the gateway field. */
  beforeGatewaySlot?: React.ReactNode;
  /** Optional slot rendered after the toggles (e.g. submit/cancel footer). */
  afterFieldsSlot?: React.ReactNode;
}

/**
 * Shared form fields for CreateIpPoolModal and EditIpPoolModal.
 * Preserves exact inline markup, class names, and i18n keys across both modals.
 */
export function IpPoolFormFields({
  networkMode,
  gateway,
  onGatewayChange,
  firstUsable,
  onFirstUsableChange,
  lastUsable,
  onLastUsableChange,
  dnsServers,
  onDnsServersChange,
  cooldownSeconds,
  onCooldownSecondsChange,
  excludedIps,
  onExcludedIpsChange,
  description,
  onDescriptionChange,
  isPublic,
  onIsPublicChange,
  active = true,
  onActiveChange,
  fieldErrors,
  isGatewayRequired,
  isGatewayLabelRequired,
  isGatewayLocked = false,
  includeGatewayDisabledStyles = false,
  firstUsablePlaceholder,
  lastUsablePlaceholder,
  compactUsableInputs = false,
  cooldownPlaceholder,
  showDescriptionPlaceholder = false,
  showPublicToggle = true,
  showActiveToggle = false,
  children,
  beforeGatewaySlot,
  afterFieldsSlot,
}: IpPoolFormFieldsProps) {
  const { t } = useI18n();

  const gatewayError = getFieldError(fieldErrors, "gateway");
  const firstUsableError = getFieldError(fieldErrors, "first_usable");
  const lastUsableError = getFieldError(fieldErrors, "last_usable");
  const dnsServersError = getFieldError(fieldErrors, "dns_servers");
  const cooldownError = getFieldError(fieldErrors, "cooldown_seconds");
  const excludedIpsError = getFieldError(fieldErrors, "excluded_ips");
  const descriptionError = getFieldError(fieldErrors, "description");

  const showGatewayRequiredLabel = isGatewayLabelRequired ?? isGatewayRequired;
  const usablePaddingClass = compactUsableInputs ? "px-3 py-2" : "px-3.5 py-2.5";

  const renderToggleCard = (
    checked: boolean,
    onChange: (nextChecked: boolean) => void,
    label: string
  ) => (
    <div className="p-3.5 rounded-xl bg-gray-50 dark:bg-gray-800/40 border border-gray-200 dark:border-gray-700/60">
      <label className="flex items-center space-x-2.5 cursor-pointer">
        <input
          type="checkbox"
          checked={checked}
          onChange={(e) => onChange(e.target.checked)}
          className="rounded border-gray-300 text-teal-600 focus:ring-teal-500"
        />
        <span className="text-xs font-medium text-gray-800 dark:text-gray-200">{label}</span>
      </label>
    </div>
  );

  const publicToggle = showPublicToggle
    ? renderToggleCard(isPublic, onIsPublicChange, t("isPublic"))
    : null;
  const activeToggle = showActiveToggle
    ? renderToggleCard(active, (val) => onActiveChange?.(val), t("poolEnabledActive"))
    : null;

  return (
    <>
      {children}

      <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
        {beforeGatewaySlot}

        <div>
          <label className="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1.5">
            {showGatewayRequiredLabel ? (
              <>
                {t("gatewayIpLabel")} <span className="text-red-500">*</span>
              </>
            ) : networkMode === "routed" ? (
              t("gatewayIpOptionalReserved")
            ) : (
              t("gatewayIpOptionalSmallSubnets")
            )}
          </label>
          <input
            type="text"
            required={isGatewayRequired}
            disabled={isGatewayLocked}
            readOnly={isGatewayLocked}
            value={gateway}
            onChange={(e) => onGatewayChange(e.target.value)}
            placeholder="203.0.113.1"
            className={`w-full px-3.5 py-2.5 rounded-xl bg-gray-50 dark:bg-gray-800/60 border ${
              gatewayError ? "border-red-500 dark:border-red-500/70" : "border-gray-200 dark:border-gray-700"
            } text-sm text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-teal-500/50 font-mono${
              includeGatewayDisabledStyles ? " disabled:opacity-60 disabled:cursor-not-allowed" : ""
            }`}
          />
          {gatewayError && (
            <p className="text-[11px] text-red-500 dark:text-red-400 mt-1">{gatewayError}</p>
          )}
          {isGatewayLocked ? (
            <span className="text-[11px] text-amber-500 mt-1 block">{t("gatewayImmutableAllocatedDesc")}</span>
          ) : (
            <span className="text-[11px] text-gray-400 mt-1 block">
              {networkMode === "routed" ? t("gatewayRoutedHint") : t("gatewayReservedDesc")}
            </span>
          )}
        </div>
      </div>

      <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div>
          <label className="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1.5">
            {t("firstUsableIp")}
          </label>
          <input
            type="text"
            value={firstUsable}
            onChange={(e) => onFirstUsableChange(e.target.value)}
            placeholder={firstUsablePlaceholder}
            className={`w-full ${usablePaddingClass} rounded-xl bg-gray-50 dark:bg-gray-800/60 border ${
              firstUsableError ? "border-red-500 dark:border-red-500/70" : "border-gray-200 dark:border-gray-700"
            } text-xs text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-teal-500/50 font-mono`}
          />
          {firstUsableError && (
            <p className="text-[11px] text-red-500 dark:text-red-400 mt-1">{firstUsableError}</p>
          )}
        </div>

        <div>
          <label className="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1.5">
            {t("lastUsableIp")}
          </label>
          <input
            type="text"
            value={lastUsable}
            onChange={(e) => onLastUsableChange(e.target.value)}
            placeholder={lastUsablePlaceholder}
            className={`w-full ${usablePaddingClass} rounded-xl bg-gray-50 dark:bg-gray-800/60 border ${
              lastUsableError ? "border-red-500 dark:border-red-500/70" : "border-gray-200 dark:border-gray-700"
            } text-xs text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-teal-500/50 font-mono`}
          />
          {lastUsableError && (
            <p className="text-[11px] text-red-500 dark:text-red-400 mt-1">{lastUsableError}</p>
          )}
        </div>
      </div>

      <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div>
          <label className="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1.5">
            {t("dnsServersLabel")}
          </label>
          <input
            type="text"
            value={dnsServers}
            onChange={(e) => onDnsServersChange(e.target.value)}
            placeholder="1.1.1.1, 8.8.8.8"
            className={`w-full px-3.5 py-2.5 rounded-xl bg-gray-50 dark:bg-gray-800/60 border ${
              dnsServersError ? "border-red-500 dark:border-red-500/70" : "border-gray-200 dark:border-gray-700"
            } text-sm text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-teal-500/50`}
          />
          {dnsServersError && (
            <p className="text-[11px] text-red-500 dark:text-red-400 mt-1">{dnsServersError}</p>
          )}
        </div>

        <div>
          <label className="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1.5">
            {t("cooldownQuarantineSeconds")}
          </label>
          <input
            type="number"
            min={60}
            max={86400}
            value={cooldownSeconds}
            onChange={(e) => onCooldownSecondsChange(Number(e.target.value))}
            placeholder={cooldownPlaceholder}
            className={`w-full px-3.5 py-2.5 rounded-xl bg-gray-50 dark:bg-gray-800/60 border ${
              cooldownError ? "border-red-500 dark:border-red-500/70" : "border-gray-200 dark:border-gray-700"
            } text-sm text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-teal-500/50`}
          />
          {cooldownError && (
            <p className="text-[11px] text-red-500 dark:text-red-400 mt-1">{cooldownError}</p>
          )}
        </div>
      </div>

      <div>
        <label className="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1.5">
          {t("excludedReservedIps")}
        </label>
        {gateway.trim() && (
          <div className="mb-2 flex flex-wrap items-center gap-2">
            <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg text-[11px] font-medium bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 border border-indigo-500/20">
              <span className="font-semibold">{t("gatewayReservedBadgeLabel")}:</span>
              <span className="font-mono">{gateway.trim()}</span>
            </span>
            <span className="text-[11px] text-gray-400">{t("gatewayReservedDesc")}</span>
          </div>
        )}
        <input
          type="text"
          value={excludedIps}
          onChange={(e) => onExcludedIpsChange(e.target.value)}
          placeholder={t("excludedIpsPlaceholder")}
          className={`w-full px-3.5 py-2.5 rounded-xl bg-gray-50 dark:bg-gray-800/60 border ${
            excludedIpsError ? "border-red-500 dark:border-red-500/70" : "border-gray-200 dark:border-gray-700"
          } text-sm text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-teal-500/50`}
        />
        {excludedIpsError ? (
          <p className="text-[11px] text-red-500 dark:text-red-400 mt-1">{excludedIpsError}</p>
        ) : (
          <span className="text-[11px] text-gray-400 mt-1 block">{t("excludedIpsHint")}</span>
        )}
      </div>

      <div>
        <label className="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1.5">
          {t("description")}
        </label>
        <input
          type="text"
          value={description}
          onChange={(e) => onDescriptionChange(e.target.value)}
          placeholder={showDescriptionPlaceholder ? t("poolDescriptionPlaceholder") : undefined}
          className={`w-full px-3.5 py-2.5 rounded-xl bg-gray-50 dark:bg-gray-800/60 border ${
            descriptionError ? "border-red-500 dark:border-red-500/70" : "border-gray-200 dark:border-gray-700"
          } text-sm text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-teal-500/50`}
        />
        {descriptionError && (
          <p className="text-[11px] text-red-500 dark:text-red-400 mt-1">{descriptionError}</p>
        )}
      </div>

      {showPublicToggle && showActiveToggle ? (
        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
          {publicToggle}
          {activeToggle}
        </div>
      ) : (
        <>
          {publicToggle}
          {activeToggle}
        </>
      )}

      {afterFieldsSlot}
    </>
  );
}
