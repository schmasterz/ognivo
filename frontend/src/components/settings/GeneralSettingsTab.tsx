"use client";

import React from "react";
import { Shield, Server, UserCheck, Globe } from "lucide-react";
import { useI18n } from "@/lib/i18n/I18nContext";

interface GeneralSettingsTabProps {
  allowVm: boolean;
  allowContainers: boolean;
  registrationEnabled: boolean;
  frontendUrl: string;
  onToggleVm: () => void;
  onToggleContainers: () => void;
  onToggleRegistration: () => void;
  onFrontendUrlChange: (val: string) => void;
}

/**
 * Presentational tab panel for general platform virtualization, registration, and panel URL settings.
 */
export function GeneralSettingsTab({
  allowVm,
  allowContainers,
  registrationEnabled,
  frontendUrl,
  onToggleVm,
  onToggleContainers,
  onToggleRegistration,
  onFrontendUrlChange,
}: GeneralSettingsTabProps) {
  const { t } = useI18n();

  return (
    <div className="space-y-6">
      {/* Section 1: Virtualization Modes */}
      <div className="space-y-3">
        <div className="flex items-center space-x-2 text-xs font-semibold uppercase tracking-wider text-purple-600 dark:text-purple-400">
          <Shield className="w-3.5 h-3.5" />
          <span>{t("virtualizationModes")}</span>
        </div>
        <p className="text-xs text-gray-500 dark:text-gray-400">
          {t("virtualizationModesDesc")}
        </p>

        <div className="space-y-3 pt-1">
          {/* Full Virtual Machine Switch */}
          <div
            onClick={onToggleVm}
            className={`p-4 rounded-2xl border transition-all cursor-pointer flex items-start justify-between gap-4 ${
              allowVm
                ? "bg-blue-500/5 border-blue-500/30 dark:bg-blue-950/20"
                : "bg-gray-50 dark:bg-gray-900/40 border-gray-200 dark:border-gray-800 opacity-60"
            }`}
          >
            <div className="flex items-start space-x-3.5 min-w-0">
              <div
                className={`p-2 rounded-xl mt-0.5 ${
                  allowVm
                    ? "bg-blue-500/10 text-blue-600 dark:text-blue-400"
                    : "bg-gray-200 dark:bg-gray-800 text-gray-400"
                }`}
              >
                <Server className="w-4 h-4" />
              </div>
              <div>
                <div className="text-xs font-bold text-gray-900 dark:text-white flex items-center space-x-2">
                  <span>{t("fullVm")}</span>
                  {allowVm && !allowContainers && (
                    <span className="text-[10px] px-2 py-0.2 rounded-full font-normal bg-blue-100 dark:bg-blue-900/40 text-blue-700 dark:text-blue-300">
                      {t("atLeastOneModeRequired")}
                    </span>
                  )}
                </div>
                <p className="text-[11px] text-gray-500 dark:text-gray-400 mt-0.5">
                  {t("fullVmDescLong")}
                </p>
              </div>
            </div>

            <div
              className={`w-11 h-6 rounded-full transition-colors relative flex-shrink-0 p-0.5 ${
                allowVm ? "bg-blue-600" : "bg-gray-300 dark:bg-gray-700"
              }`}
            >
              <div
                className={`w-5 h-5 rounded-full bg-white shadow-md transform transition-transform ${
                  allowVm ? "translate-x-5" : "translate-x-0"
                }`}
              />
            </div>
          </div>

          {/* LXC Container Switch */}
          <div
            onClick={onToggleContainers}
            className={`p-4 rounded-2xl border transition-all cursor-pointer flex items-start justify-between gap-4 ${
              allowContainers
                ? "bg-indigo-500/5 border-indigo-500/30 dark:bg-indigo-950/20"
                : "bg-gray-50 dark:bg-gray-900/40 border-gray-200 dark:border-gray-800 opacity-60"
            }`}
          >
            <div className="flex items-start space-x-3.5 min-w-0">
              <div
                className={`p-2 rounded-xl mt-0.5 ${
                  allowContainers
                    ? "bg-indigo-500/10 text-indigo-600 dark:text-indigo-400"
                    : "bg-gray-200 dark:bg-gray-800 text-gray-400"
                }`}
              >
                <Server className="w-4 h-4" />
              </div>
              <div>
                <div className="text-xs font-bold text-gray-900 dark:text-white flex items-center space-x-2">
                  <span>{t("lxcContainer")}</span>
                  {allowContainers && !allowVm && (
                    <span className="text-[10px] px-2 py-0.2 rounded-full font-normal bg-indigo-100 dark:bg-indigo-900/40 text-indigo-700 dark:text-indigo-300">
                      {t("atLeastOneModeRequired")}
                    </span>
                  )}
                </div>
                <p className="text-[11px] text-gray-500 dark:text-gray-400 mt-0.5">
                  {t("lxcDescLong")}
                </p>
              </div>
            </div>

            <div
              className={`w-11 h-6 rounded-full transition-colors relative flex-shrink-0 p-0.5 ${
                allowContainers ? "bg-indigo-600" : "bg-gray-300 dark:bg-gray-700"
              }`}
            >
              <div
                className={`w-5 h-5 rounded-full bg-white shadow-md transform transition-transform ${
                  allowContainers ? "translate-x-5" : "translate-x-0"
                }`}
              />
            </div>
          </div>
        </div>
      </div>

      {/* Section 2: Tenant Public Registration */}
      <div className="space-y-3 pt-2">
        <div className="flex items-center space-x-2 text-xs font-semibold uppercase tracking-wider text-emerald-600 dark:text-emerald-400">
          <UserCheck className="w-3.5 h-3.5" />
          <span>{t("accessAndRegistration")}</span>
        </div>

        <div
          onClick={onToggleRegistration}
          className={`p-4 rounded-2xl border transition-all cursor-pointer flex items-start justify-between gap-4 ${
            registrationEnabled
              ? "bg-emerald-500/5 border-emerald-500/30 dark:bg-emerald-950/20"
              : "bg-gray-50 dark:bg-gray-900/40 border-gray-200 dark:border-gray-800 opacity-60"
          }`}
        >
          <div className="flex items-start space-x-3.5 min-w-0">
            <div
              className={`p-2 rounded-xl mt-0.5 ${
                registrationEnabled
                  ? "bg-emerald-500/10 text-emerald-600 dark:text-emerald-400"
                  : "bg-gray-200 dark:bg-gray-800 text-gray-400"
              }`}
            >
              <UserCheck className="w-4 h-4" />
            </div>
            <div>
              <div className="text-xs font-bold text-gray-900 dark:text-white">
                {t("publicRegistration")}
              </div>
              <p className="text-[11px] text-gray-500 dark:text-gray-400 mt-0.5">
                {t("publicRegistrationDesc")}
              </p>
            </div>
          </div>

          <div
            className={`w-11 h-6 rounded-full transition-colors relative flex-shrink-0 p-0.5 ${
              registrationEnabled ? "bg-emerald-600" : "bg-gray-300 dark:bg-gray-700"
            }`}
          >
            <div
              className={`w-5 h-5 rounded-full bg-white shadow-md transform transition-transform ${
                registrationEnabled ? "translate-x-5" : "translate-x-0"
              }`}
            />
          </div>
        </div>
      </div>

      {/* Section 3: Public Web Panel URL */}
      <div className="space-y-3 pt-2">
        <div className="flex items-center space-x-2 text-xs font-semibold uppercase tracking-wider text-blue-600 dark:text-blue-400">
          <Globe className="w-3.5 h-3.5" />
          <span>{t("frontendUrl")}</span>
        </div>
        <p className="text-xs text-gray-500 dark:text-gray-400">
          {t("frontendUrlDesc")}
        </p>
        <div className="relative">
          <input
            type="url"
            value={frontendUrl}
            onChange={(e) => onFrontendUrlChange(e.target.value)}
            placeholder={t("frontendUrlPlaceholder")}
            className="w-full bg-gray-50 dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-xl px-3.5 py-2 pl-9 text-xs text-gray-900 dark:text-white focus:outline-none focus:border-blue-500 transition font-mono"
          />
          <Globe className="w-4 h-4 text-gray-400 absolute left-3 top-2.5" />
        </div>
      </div>
    </div>
  );
}
