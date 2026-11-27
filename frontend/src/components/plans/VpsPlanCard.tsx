"use client";

import React from "react";
import { Cpu, HardDrive, Layers, MemoryStick, Network, Server } from "lucide-react";
import type { VpsPlanCatalogItem } from "@/lib/api";
import { useI18n } from "@/lib/i18n/I18nContext";
import { getSlotComponents } from "@/lib/registry/slotRegistry";

export interface VpsPlanCardProps {
  plan: VpsPlanCatalogItem;
}

/**
 * Formats RAM in MiB alongside its GiB equivalent for clear plan comparison.
 */
export function formatPlanRam(ramMb: number): string {
  const gib = ramMb / 1024;
  const gibText = Number.isInteger(gib)
    ? String(gib)
    : Number(gib.toFixed(2)).toString();
  return `${gibText} GiB (${ramMb} MiB)`;
}

/**
 * Formats a network bandwidth limit in Mbps, or returns the localized unlimited label.
 */
export function formatNetworkLimit(
  mbps: number | null | undefined,
  unlimitedLabel: string,
  formatMbps: (value: number) => string
): string {
  if (mbps === null || mbps === undefined) {
    return unlimitedLabel;
  }
  return formatMbps(mbps);
}

export function VpsPlanCard({ plan }: VpsPlanCardProps) {
  const { t } = useI18n();
  const version = plan.current_version;
  const slotItems = getSlotComponents<{ plan: VpsPlanCatalogItem }>("vps.plan.card");

  const isVm = version?.instance_type === "virtual-machine";

  return (
    <article
      data-testid={`vps-plan-card-${plan.slug}`}
      className="flex flex-col justify-between bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-3xl p-6 shadow-sm hover:shadow-md hover:border-blue-500/40 dark:hover:border-blue-500/40 transition"
    >
      <div className="space-y-4">
        <div className="flex items-start justify-between gap-3">
          <div className="min-w-0">
            <h3 className="text-lg font-bold text-gray-900 dark:text-white tracking-tight truncate">
              {plan.name}
            </h3>
            <p className="text-[11px] font-mono text-gray-400 dark:text-gray-500 truncate mt-0.5">
              {plan.slug}
            </p>
          </div>

          <div className="flex items-center gap-1.5 flex-shrink-0">
            {version && (
              <span className="px-2 py-0.5 rounded-full text-[11px] font-mono font-semibold bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-300">
                {t("vpsPlanVersionLabel", { version: version.version })}
              </span>
            )}
            {version && (
              <span
                className={`inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[11px] font-semibold ${
                  isVm
                    ? "bg-blue-500/10 text-blue-600 dark:text-blue-400"
                    : "bg-indigo-500/10 text-indigo-600 dark:text-indigo-400"
                }`}
              >
                {isVm ? <Server className="w-3 h-3" /> : <Layers className="w-3 h-3" />}
                <span>
                  {isVm ? t("vpsPlanInstanceTypeVm") : t("vpsPlanInstanceTypeContainer")}
                </span>
              </span>
            )}
          </div>
        </div>

        {plan.description && (
          <p className="text-xs text-gray-600 dark:text-gray-400 leading-relaxed">
            {plan.description}
          </p>
        )}

        {version ? (
          <dl className="grid grid-cols-1 sm:grid-cols-2 gap-3 pt-2">
            <div className="p-3 rounded-2xl bg-gray-50 dark:bg-gray-950/60 border border-gray-100 dark:border-gray-800/80">
              <dt className="flex items-center gap-1.5 text-[11px] font-semibold text-gray-500 dark:text-gray-400">
                <Cpu className="w-3.5 h-3.5 text-blue-500" />
                <span>{t("vpsPlanCpuLabel")}</span>
              </dt>
              <dd className="mt-1 text-sm font-bold text-gray-900 dark:text-white">
                {t("vpsPlanCpuValue", { count: version.cpu_cores })}
              </dd>
            </div>

            <div className="p-3 rounded-2xl bg-gray-50 dark:bg-gray-950/60 border border-gray-100 dark:border-gray-800/80">
              <dt className="flex items-center gap-1.5 text-[11px] font-semibold text-gray-500 dark:text-gray-400">
                <MemoryStick className="w-3.5 h-3.5 text-emerald-500" />
                <span>{t("vpsPlanRamLabel")}</span>
              </dt>
              <dd className="mt-1 text-sm font-bold text-gray-900 dark:text-white">
                {formatPlanRam(version.ram_mb)}
              </dd>
            </div>

            <div className="p-3 rounded-2xl bg-gray-50 dark:bg-gray-950/60 border border-gray-100 dark:border-gray-800/80">
              <dt className="flex items-center gap-1.5 text-[11px] font-semibold text-gray-500 dark:text-gray-400">
                <HardDrive className="w-3.5 h-3.5 text-amber-500" />
                <span>{t("vpsPlanDiskLabel")}</span>
              </dt>
              <dd className="mt-1 text-sm font-bold text-gray-900 dark:text-white">
                {t("vpsPlanDiskValue", { gb: version.disk_gb })}
              </dd>
            </div>

            <div className="p-3 rounded-2xl bg-gray-50 dark:bg-gray-950/60 border border-gray-100 dark:border-gray-800/80">
              <dt className="flex items-center gap-1.5 text-[11px] font-semibold text-gray-500 dark:text-gray-400">
                <Network className="w-3.5 h-3.5 text-purple-500" />
                <span>{t("vpsPlanNetworkLabel")}</span>
              </dt>
              <dd className="mt-1 text-xs font-semibold text-gray-900 dark:text-white space-y-0.5">
                <div className="flex items-center justify-between gap-2">
                  <span className="text-gray-500 dark:text-gray-400 font-normal">
                    {t("vpsPlanDownloadLabel")}:
                  </span>
                  <span>
                    {formatNetworkLimit(
                      version.net_download_mbps,
                      t("vpsPlanUnlimited"),
                      (mbps) => t("vpsPlanMbpsValue", { mbps })
                    )}
                  </span>
                </div>
                <div className="flex items-center justify-between gap-2">
                  <span className="text-gray-500 dark:text-gray-400 font-normal">
                    {t("vpsPlanUploadLabel")}:
                  </span>
                  <span>
                    {formatNetworkLimit(
                      version.net_upload_mbps,
                      t("vpsPlanUnlimited"),
                      (mbps) => t("vpsPlanMbpsValue", { mbps })
                    )}
                  </span>
                </div>
              </dd>
            </div>
          </dl>
        ) : (
          <div className="p-3 rounded-2xl bg-gray-50 dark:bg-gray-950/60 text-xs text-gray-500 dark:text-gray-400">
            {t("vpsPlanNoPublishedVersion")}
          </div>
        )}
      </div>

      {slotItems.length > 0 && (
        <div
          data-testid="vps-plan-card-slot"
          className="mt-5 pt-4 border-t border-gray-100 dark:border-gray-800 space-y-2"
        >
          {slotItems.map((slot) => (
            <slot.component key={slot.id} plan={plan} />
          ))}
        </div>
      )}
    </article>
  );
}
