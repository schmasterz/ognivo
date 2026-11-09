"use client";

import React from "react";
import { RefreshCw } from "lucide-react";
import { QuotaResponse, AdminStats } from "@/lib/api";
import { QuotaOverview } from "./QuotaOverview";
import { useI18n } from "@/lib/i18n/I18nContext";

export interface DashboardQuotaSectionProps {
  quotaData: QuotaResponse | null;
  adminStats: AdminStats | null;
  isClusterCardView: boolean;
  hasActiveFilters: boolean;
  selectedUserId: number | null;
  refreshing: boolean;
  onRefresh: () => void;
}

export function DashboardQuotaSection({
  quotaData,
  adminStats,
  isClusterCardView,
  hasActiveFilters,
  selectedUserId,
  refreshing,
  onRefresh,
}: DashboardQuotaSectionProps) {
  const { t } = useI18n();

  return (
    <div>
      <div className="flex items-center justify-between mb-3">
        <div>
          <div className="flex items-center space-x-2">
            <h2 className="text-base font-semibold text-gray-900 dark:text-white tracking-tight">
              {isClusterCardView ? t("clusterOverview") : t("quotaTitle")}
            </h2>
            {hasActiveFilters && (
              <span className="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-medium bg-blue-100 text-blue-800 dark:bg-blue-950/60 dark:text-blue-300 border border-blue-200 dark:border-blue-800">
                {t("filteredView")}
              </span>
            )}
          </div>
          <p className="text-xs text-gray-500 dark:text-gray-400">
            {isClusterCardView
              ? hasActiveFilters
                ? t("clusterOverviewFiltered")
                : t("clusterOverviewSubtitle")
              : hasActiveFilters
              ? t("quotaFilteredSubtitle")
              : selectedUserId !== null && quotaData?.user
              ? `${t("quotaLimitsFor")} ${quotaData.user.name} (${quotaData.user.email})`
              : t("quotaSubtitle")}
          </p>
        </div>
        <button
          onClick={onRefresh}
          disabled={refreshing}
          title="Refresh Data"
          className="p-2 rounded-xl bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 hover:bg-gray-100 dark:hover:bg-gray-800 text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-white transition disabled:opacity-50"
        >
          <RefreshCw
            className={`w-4 h-4 ${
              refreshing ? "animate-spin text-blue-500" : ""
            }`}
          />
        </button>
      </div>

      <QuotaOverview
        quotaData={quotaData}
        adminStats={adminStats}
        isClusterView={isClusterCardView}
        hasActiveFilters={hasActiveFilters}
      />
    </div>
  );
}
