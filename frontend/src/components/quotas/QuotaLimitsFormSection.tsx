"use client";

import React from "react";
import { useI18n } from "@/lib/i18n/I18nContext";

export interface QuotaLimitsFormValue {
  unlimitedVms: boolean;
  maxVms: number;
  unlimitedCpu: boolean;
  maxCpu: number;
  unlimitedRam: boolean;
  maxRamGb: number;
  unlimitedStorage: boolean;
  maxStorageGb: number;
  unlimitedIps: boolean;
  maxIps: number;
  unlimitedSnapshots: boolean;
  maxSnapshots: number;
}

const DEFAULT_QUOTA_FORM_VALUE: QuotaLimitsFormValue = {
  unlimitedVms: false,
  maxVms: 2,
  unlimitedCpu: false,
  maxCpu: 4,
  unlimitedRam: false,
  maxRamGb: 4,
  unlimitedStorage: false,
  maxStorageGb: 80,
  unlimitedIps: false,
  maxIps: 1,
  unlimitedSnapshots: false,
  maxSnapshots: 3,
};

export function quotaRecordToFormValue(
  source?: Partial<{
    max_vms: number;
    max_cpu_cores: number;
    max_ram_mb: number;
    max_storage_gb: number;
    max_ips: number;
    max_snapshots: number;
  }> | null,
  defaults?: Partial<QuotaLimitsFormValue>
): QuotaLimitsFormValue {
  const base: QuotaLimitsFormValue = {
    ...DEFAULT_QUOTA_FORM_VALUE,
    ...defaults,
  };

  if (!source) {
    return base;
  }

  return {
    unlimitedVms: source.max_vms === -1,
    maxVms: source.max_vms !== undefined && source.max_vms > 0 ? source.max_vms : base.maxVms,
    unlimitedCpu: source.max_cpu_cores === -1,
    maxCpu:
      source.max_cpu_cores !== undefined && source.max_cpu_cores > 0
        ? source.max_cpu_cores
        : base.maxCpu,
    unlimitedRam: source.max_ram_mb === -1,
    maxRamGb:
      source.max_ram_mb !== undefined && source.max_ram_mb > 0
        ? Math.round(source.max_ram_mb / 1024)
        : base.maxRamGb,
    unlimitedStorage: source.max_storage_gb === -1,
    maxStorageGb:
      source.max_storage_gb !== undefined && source.max_storage_gb > 0
        ? source.max_storage_gb
        : base.maxStorageGb,
    unlimitedIps: source.max_ips === -1,
    maxIps: source.max_ips !== undefined && source.max_ips > 0 ? source.max_ips : base.maxIps,
    unlimitedSnapshots: source.max_snapshots === -1,
    maxSnapshots:
      source.max_snapshots !== undefined && source.max_snapshots > 0
        ? source.max_snapshots
        : base.maxSnapshots,
  };
}

export function quotaFormValueToPayload(val: QuotaLimitsFormValue): {
  max_vms: number;
  max_cpu_cores: number;
  max_ram_mb: number;
  max_storage_gb: number;
  max_ips: number;
  max_snapshots: number;
} {
  return {
    max_vms: val.unlimitedVms ? -1 : val.maxVms,
    max_cpu_cores: val.unlimitedCpu ? -1 : val.maxCpu,
    max_ram_mb: val.unlimitedRam ? -1 : val.maxRamGb * 1024,
    max_storage_gb: val.unlimitedStorage ? -1 : val.maxStorageGb,
    max_ips: val.unlimitedIps ? -1 : val.maxIps,
    max_snapshots: val.unlimitedSnapshots ? -1 : val.maxSnapshots,
  };
}

export interface QuotaLimitsFormSectionProps {
  value: QuotaLimitsFormValue;
  onChange: (next: QuotaLimitsFormValue) => void;
  disabled?: boolean;
  columns?: 2 | 3;
}

export function QuotaLimitsFormSection({
  value,
  onChange,
  disabled = false,
  columns = 2,
}: QuotaLimitsFormSectionProps) {
  const { t } = useI18n();

  const updateField = <K extends keyof QuotaLimitsFormValue>(
    key: K,
    fieldValue: QuotaLimitsFormValue[K]
  ) => {
    onChange({
      ...value,
      [key]: fieldValue,
    });
  };

  const fields: Array<{
    label: string;
    unlimitedKey: keyof QuotaLimitsFormValue;
    maxKey: keyof QuotaLimitsFormValue;
    min: number;
  }> = [
    {
      label: t("instances"),
      unlimitedKey: "unlimitedVms",
      maxKey: "maxVms",
      min: 1,
    },
    {
      label: t("vCpuCores"),
      unlimitedKey: "unlimitedCpu",
      maxKey: "maxCpu",
      min: 1,
    },
    {
      label: `${t("allocatedRam")} (GB)`,
      unlimitedKey: "unlimitedRam",
      maxKey: "maxRamGb",
      min: 1,
    },
    {
      label: `${t("storage")} (GB)`,
      unlimitedKey: "unlimitedStorage",
      maxKey: "maxStorageGb",
      min: 1,
    },
    {
      label: columns === 3 ? "Max IPs" : "Max Public IPs",
      unlimitedKey: "unlimitedIps",
      maxKey: "maxIps",
      min: 1,
    },
    {
      label: t("maxSnapshots"),
      unlimitedKey: "unlimitedSnapshots",
      maxKey: "maxSnapshots",
      min: 1,
    },
  ];

  const gridClass =
    columns === 3
      ? "grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-3"
      : "grid grid-cols-1 sm:grid-cols-2 gap-3.5";

  return (
    <div className={gridClass}>
      {fields.map((field) => {
        const isUnlimited = Boolean(value[field.unlimitedKey]);
        const numVal = Number(value[field.maxKey]);

        return (
          <div
            key={field.maxKey}
            className={`p-3.5 rounded-xl bg-white dark:bg-gray-800/80 border border-gray-200 dark:border-gray-700 ${
              disabled ? "opacity-75" : ""
            }`}
          >
            <div className="flex items-center justify-between mb-1.5">
              <span className="text-xs font-medium text-gray-700 dark:text-gray-300">
                {field.label}
              </span>
              <label
                className={`flex items-center space-x-1 ${
                  disabled ? "cursor-not-allowed opacity-60" : "cursor-pointer"
                }`}
              >
                <input
                  type="checkbox"
                  disabled={disabled}
                  checked={isUnlimited}
                  onChange={(e) => updateField(field.unlimitedKey, e.target.checked)}
                  className="rounded border-gray-300 text-blue-600 focus:ring-blue-500 disabled:opacity-50"
                />
                <span className="text-[10px] text-gray-500 dark:text-gray-400">
                  {t("infinite")}
                </span>
              </label>
            </div>
            {isUnlimited ? (
              <div className="py-2 text-center text-xs font-semibold text-blue-600 dark:text-blue-400">
                ∞ {t("unlimited")}
              </div>
            ) : (
              <input
                type="number"
                min={field.min}
                disabled={disabled}
                value={numVal}
                onChange={(e) =>
                  updateField(field.maxKey, parseInt(e.target.value, 10) || field.min)
                }
                className="w-full px-2.5 py-1.5 rounded-lg bg-gray-50 dark:bg-gray-900 border border-gray-200 dark:border-gray-700 text-xs text-gray-900 dark:text-white disabled:opacity-60 disabled:cursor-not-allowed"
              />
            )}
          </div>
        );
      })}
    </div>
  );
}
