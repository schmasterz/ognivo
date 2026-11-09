"use client";

import React from "react";
import { Server, Cpu, Layers, HardDrive, Camera } from "lucide-react";
import { QuotaResponse, AdminStats } from "@/lib/api";
import { useI18n } from "@/lib/i18n/I18nContext";

interface QuotaOverviewProps {
  quotaData: QuotaResponse | null;
  adminStats?: AdminStats | null;
  isClusterView?: boolean;
  hasActiveFilters?: boolean;
}

export function QuotaOverview({
  quotaData,
  adminStats,
  isClusterView = false,
  hasActiveFilters = false,
}: QuotaOverviewProps) {
  const { t } = useI18n();

  if (isClusterView && adminStats) {
    const clusterItems = [
      {
        title: t("instances"),
        icon: Server,
        value: adminStats.total_vms,
        unit: t("machinesUnit"),
        subtitle: `${adminStats.running_vms} ${t("runningSubtitle")}`,
        color: "from-blue-600 to-indigo-600",
      },
      {
        title: t("vCpuCores"),
        icon: Cpu,
        value: adminStats.allocated_cpu_cores,
        unit: t("coresUnit"),
        subtitle: t("clusterTotal"),
        color: "from-emerald-600 to-teal-600",
      },
      {
        title: t("allocatedRam"),
        icon: Layers,
        value: Math.round((adminStats.allocated_ram_mb / 1024) * 10) / 10,
        unit: "GB",
        subtitle: t("clusterTotal"),
        color: "from-purple-600 to-violet-600",
      },
      {
        title: t("storage"),
        icon: HardDrive,
        value: adminStats.allocated_disk_gb,
        unit: "GB",
        subtitle: t("clusterTotal"),
        color: "from-amber-600 to-orange-600",
      },
      {
        title: t("snapshots"),
        icon: Camera,
        value: adminStats.total_snapshots ?? 0,
        unit: t("snapshotsUnit"),
        subtitle: t("clusterTotal"),
        color: "from-pink-600 to-rose-600",
      },
    ];

    return (
      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4">
        {clusterItems.map((item) => {
          const Icon = item.icon;
          return (
            <div
              key={item.title}
              className="bg-white dark:bg-gray-900/60 border border-gray-200 dark:border-gray-800/80 rounded-2xl p-5 shadow-sm dark:shadow-none backdrop-blur-sm relative overflow-hidden transition-colors"
            >
              <div className="flex items-center justify-between mb-3">
                <span className="text-xs font-medium text-gray-500 dark:text-gray-400">{item.title}</span>
                <div className="p-2 rounded-xl bg-gray-100 dark:bg-gray-800/60 text-gray-600 dark:text-gray-300">
                  <Icon className="w-4 h-4" />
                </div>
              </div>

              <div className="flex items-baseline space-x-1.5 mb-2">
                <span className="text-2xl font-bold text-gray-900 dark:text-white tracking-tight">{item.value}</span>
                <span className="text-xs text-gray-500 dark:text-gray-400">{item.unit}</span>
              </div>

              <div className="w-full bg-gray-100 dark:bg-gray-800 h-2 rounded-full overflow-hidden">
                <div
                  className={`h-full rounded-full transition-all duration-500 bg-gradient-to-r ${item.color}`}
                  style={{ width: "100%" }}
                />
              </div>

              <div className="mt-2 flex justify-between items-center text-[11px] text-gray-500 dark:text-gray-400">
                <span>{item.subtitle}</span>
                <span className="text-xs font-semibold text-blue-600 dark:text-blue-400">
                  {hasActiveFilters ? `${t("clusterOverview")} (${t("filtered")})` : t("clusterOverview")}
                </span>
              </div>
            </div>
          );
        })}
      </div>
    );
  }

  if (!quotaData) return null;

  const { quota, usage } = quotaData;

  const isUnlimited = (val: number) => val === -1;

  const ramUsedGb = Math.round((usage.ram_mb / 1024) * 10) / 10;
  const ramMaxGb = isUnlimited(quota.max_ram_mb) ? -1 : Math.round((quota.max_ram_mb / 1024) * 10) / 10;

  const calcPercentage = (used: number, max: number) => {
    if (isUnlimited(max) || max <= 0) return 0;
    return Math.min(100, Math.round((used / max) * 100));
  };

  const items = [
    {
      title: t("instances"),
      icon: Server,
      used: usage.vms,
      max: quota.max_vms,
      unit: t("machinesUnit"),
      percentage: calcPercentage(usage.vms, quota.max_vms),
      isInfinite: isUnlimited(quota.max_vms),
      color: "from-blue-600 to-indigo-600",
    },
    {
      title: t("vCpuCores"),
      icon: Cpu,
      used: usage.cpu_cores,
      max: quota.max_cpu_cores,
      unit: t("coresUnit"),
      percentage: calcPercentage(usage.cpu_cores, quota.max_cpu_cores),
      isInfinite: isUnlimited(quota.max_cpu_cores),
      color: "from-emerald-600 to-teal-600",
    },
    {
      title: t("allocatedRam"),
      icon: Layers,
      used: ramUsedGb,
      max: ramMaxGb,
      unit: "GB",
      percentage: calcPercentage(usage.ram_mb, quota.max_ram_mb),
      isInfinite: isUnlimited(quota.max_ram_mb),
      color: "from-purple-600 to-violet-600",
    },
    {
      title: t("storage"),
      icon: HardDrive,
      used: usage.disk_gb,
      max: quota.max_storage_gb,
      unit: "GB",
      percentage: calcPercentage(usage.disk_gb, quota.max_storage_gb),
      isInfinite: isUnlimited(quota.max_storage_gb),
      color: "from-amber-600 to-orange-600",
    },
    {
      title: t("snapshots"),
      icon: Camera,
      used: usage.snapshots ?? 0,
      max: quota.max_snapshots ?? -1,
      unit: t("snapshotsUnit"),
      percentage: calcPercentage(usage.snapshots ?? 0, quota.max_snapshots ?? -1),
      isInfinite: isUnlimited(quota.max_snapshots ?? -1),
      color: "from-pink-600 to-rose-600",
    },
  ];

  return (
    <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4">
      {items.map((item) => {
        const Icon = item.icon;
        const isNearLimit = !item.isInfinite && item.percentage >= 80;

        return (
          <div
            key={item.title}
            className="bg-white dark:bg-gray-900/60 border border-gray-200 dark:border-gray-800/80 rounded-2xl p-5 shadow-sm dark:shadow-none backdrop-blur-sm relative overflow-hidden transition-colors"
          >
            <div className="flex items-center justify-between mb-3">
              <span className="text-xs font-medium text-gray-500 dark:text-gray-400">{item.title}</span>
              <div className="p-2 rounded-xl bg-gray-100 dark:bg-gray-800/60 text-gray-600 dark:text-gray-300">
                <Icon className="w-4 h-4" />
              </div>
            </div>

            <div className="flex items-baseline space-x-1.5 mb-3">
              <span className="text-2xl font-bold text-gray-900 dark:text-white tracking-tight">{item.used}</span>
              <span className="text-xs text-gray-500 dark:text-gray-400">
                / {item.isInfinite ? "∞ " + t("unlimited") : `${item.max} ${item.unit}`}
              </span>
            </div>

            {/* Progress bar */}
            <div className="w-full bg-gray-100 dark:bg-gray-800 h-2 rounded-full overflow-hidden">
              <div
                className={`h-full rounded-full transition-all duration-500 bg-gradient-to-r ${item.color} ${
                  isNearLimit ? "brightness-125 ring-2 ring-red-500/50" : ""
                }`}
                style={{ width: item.isInfinite ? "10%" : `${item.percentage}%` }}
              />
            </div>

            <div className="mt-2 flex justify-between items-center text-[11px] text-gray-500 dark:text-gray-400">
              <span>
                {item.isInfinite ? t("unlimited") : `${item.percentage}% ${t("allocated")}`}
                {hasActiveFilters && (
                  <span className="ml-1 text-blue-600 dark:text-blue-400 font-medium">({t("filtered")})</span>
                )}
              </span>
              {isNearLimit && <span className="text-amber-500 dark:text-amber-400 font-medium">{t("nearQuota")}</span>}
            </div>
          </div>
        );
      })}
    </div>
  );
}
