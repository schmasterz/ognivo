"use client";

import React from "react";
import { useI18n } from "@/lib/i18n/I18nContext";
import {
  HOST_DEFAULTS,
  CPU_OVERCOMMIT_MAX,
  RAM_OVERCOMMIT_MAX,
  DISK_OVERCOMMIT_MAX,
  MIN_STORAGE_GB,
} from "./hostFormSchema";

/** Numeric capacity and overcommit fields edited in the host capacity section. */
export interface HostCapacityValues {
  totalCpuCores: number;
  totalRamGb: number;
  totalStorageGb: number;
  cpuOvercommit: number;
  ramOvercommit: number;
  diskOvercommit: number;
}

export interface HostCapacityFieldsProps {
  values: HostCapacityValues;
  onChange: (patch: Partial<HostCapacityValues>) => void;
  autoDiscover?: boolean;
  onAutoDiscoverChange?: (checked: boolean) => void;
  showAutoDiscover?: boolean;
}

/**
 * Shared Node Capacity form section for host create and edit modals.
 * Renders optional auto-discover toggle, manual CPU/RAM/storage totals, and overcommit multipliers.
 */
export function HostCapacityFields({
  values,
  onChange,
  autoDiscover = false,
  onAutoDiscoverChange,
  showAutoDiscover = false,
}: HostCapacityFieldsProps) {
  const { t } = useI18n();

  const totalFields: ReadonlyArray<{
    key: "totalCpuCores" | "totalRamGb" | "totalStorageGb";
    label: string;
    min: number;
  }> = [
    { key: "totalCpuCores", label: t("nodeTotalVcpus"), min: 1 },
    { key: "totalRamGb", label: "RAM (GB)", min: 1 },
    { key: "totalStorageGb", label: "Storage (GB)", min: MIN_STORAGE_GB },
  ];

  const overcommitFields: ReadonlyArray<{
    key: "cpuOvercommit" | "ramOvercommit" | "diskOvercommit";
    label: string;
    max: number;
  }> = [
    { key: "cpuOvercommit", label: t("cpuOvercommit"), max: CPU_OVERCOMMIT_MAX },
    { key: "ramOvercommit", label: t("ramOvercommit"), max: RAM_OVERCOMMIT_MAX },
    { key: "diskOvercommit", label: t("diskOvercommit"), max: DISK_OVERCOMMIT_MAX },
  ];

  const showManualTotals = !(showAutoDiscover && autoDiscover);

  return (
    <div className="p-4 rounded-2xl bg-gray-50 dark:bg-gray-800/40 border border-gray-200 dark:border-gray-700/60 space-y-3">
      <h3 className="text-xs font-bold uppercase tracking-wider text-gray-700 dark:text-gray-300">
        {t("nodeCapacity")}
      </h3>

      {showAutoDiscover && (
        <label className="flex items-center space-x-2.5 cursor-pointer">
          <input
            type="checkbox"
            checked={autoDiscover}
            onChange={(e) => onAutoDiscoverChange?.(e.target.checked)}
            className="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
          />
          <span className="text-xs font-medium text-gray-800 dark:text-gray-200">
            {t("autoDiscoverCapacity")}
          </span>
        </label>
      )}

      {showManualTotals && (
        <>
          <div className="grid grid-cols-3 gap-3">
            {totalFields.map((field) => (
              <div key={field.key}>
                <label className="block text-[11px] font-medium text-gray-600 dark:text-gray-400 mb-1">
                  {field.label}
                </label>
                <input
                  type="number"
                  min={field.min}
                  value={values[field.key]}
                  onChange={(e) =>
                    onChange({ [field.key]: parseInt(e.target.value, 10) || field.min })
                  }
                  className="w-full px-2.5 py-1.5 rounded-lg bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 text-xs text-gray-900 dark:text-white"
                />
                {field.key === "totalCpuCores" && (
                  <p className="text-[10px] text-gray-400 dark:text-gray-500 mt-1 leading-snug">
                    {t("nodeTotalVcpusHint")}
                  </p>
                )}
              </div>
            ))}
          </div>
          <p className="text-[11px] text-gray-500 dark:text-gray-400">
            {t("manualCapacityCapHint")}
          </p>
        </>
      )}

      <div className="pt-2 border-t border-gray-200 dark:border-gray-700/60 space-y-2.5">
        <div>
          <h4 className="text-[11px] font-bold uppercase tracking-wider text-gray-700 dark:text-gray-300">
            {t("overcommitMultipliers")}
          </h4>
          <p className="text-[11px] text-gray-500 dark:text-gray-400 mt-0.5">
            {t("overcommitHint")}
          </p>
        </div>
        <div className="grid grid-cols-3 gap-3">
          {overcommitFields.map((field) => (
            <div key={field.key}>
              <label className="block text-[11px] font-medium text-gray-600 dark:text-gray-400 mb-1">
                {field.label}
              </label>
              <input
                type="number"
                step="0.1"
                min={1}
                max={field.max}
                value={values[field.key]}
                onChange={(e) => {
                  const val = parseFloat(e.target.value);
                  onChange({
                    [field.key]: Number.isNaN(val) ? HOST_DEFAULTS.overcommit : val,
                  });
                }}
                className="w-full px-2.5 py-1.5 rounded-lg bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 text-xs text-gray-900 dark:text-white"
              />
            </div>
          ))}
        </div>
      </div>
    </div>
  );
}
