"use client";

import React, { useCallback, useEffect, useState } from "react";
import { AlertCircle, PackageOpen, RefreshCw } from "lucide-react";
import { ApiError, VpsPlanCatalogItem, vpsPlansApi } from "@/lib/api";
import { useI18n } from "@/lib/i18n/I18nContext";
import { VpsPlanCard } from "@/components/plans/VpsPlanCard";

export default function PlansCatalogPage() {
  const { t } = useI18n();
  const [plans, setPlans] = useState<VpsPlanCatalogItem[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const fetchCatalog = useCallback(async (signal?: AbortSignal) => {
    try {
      setLoading(true);
      setError(null);
      const data = await vpsPlansApi.listCatalog({ signal });
      if (!signal?.aborted) {
        setPlans(data);
      }
    } catch (err: unknown) {
      if (signal?.aborted) return;
      if (err instanceof ApiError || err instanceof Error) {
        setError(err.message || t("vpsPlansLoadError"));
      } else {
        setError(t("vpsPlansLoadError"));
      }
    } finally {
      if (!signal?.aborted) {
        setLoading(false);
      }
    }
  }, [t]);

  useEffect(() => {
    const controller = new AbortController();
    queueMicrotask(() => {
      if (!controller.signal.aborted) {
        void fetchCatalog(controller.signal);
      }
    });
    return () => controller.abort();
  }, [fetchCatalog]);

  return (
    <div className="space-y-6">
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
          <h2 className="text-2xl font-extrabold text-gray-900 dark:text-white tracking-tight">
            {t("vpsPlansCatalogTitle")}
          </h2>
          <p className="text-xs sm:text-sm text-gray-500 dark:text-gray-400 mt-1">
            {t("vpsPlansCatalogSubtitle")}
          </p>
        </div>

        <button
          type="button"
          onClick={() => void fetchCatalog()}
          disabled={loading}
          className="inline-flex items-center space-x-2 px-4 py-2 rounded-2xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 hover:bg-gray-50 dark:hover:bg-gray-800 text-xs font-semibold text-gray-700 dark:text-gray-200 transition disabled:opacity-50 self-start sm:self-auto"
        >
          <RefreshCw className={`w-3.5 h-3.5 ${loading ? "animate-spin" : ""}`} />
          <span>{t("vpsPlansRefresh")}</span>
        </button>
      </div>

      {loading && plans.length === 0 ? (
        <div className="flex flex-col items-center justify-center py-20 bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-3xl">
          <RefreshCw className="w-7 h-7 animate-spin text-blue-500 mb-3" />
          <p className="text-xs font-medium text-gray-500 dark:text-gray-400">
            {t("vpsPlansLoading")}
          </p>
        </div>
      ) : error ? (
        <div className="p-6 rounded-3xl bg-red-500/10 border border-red-500/20 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
          <div className="flex items-center space-x-3 text-red-600 dark:text-red-400">
            <AlertCircle className="w-5 h-5 flex-shrink-0" />
            <span className="text-xs sm:text-sm font-medium">{error}</span>
          </div>
          <button
            type="button"
            onClick={() => void fetchCatalog()}
            className="px-4 py-2 rounded-xl bg-red-600 hover:bg-red-500 text-white text-xs font-semibold transition self-start sm:self-auto"
          >
            {t("vpsPlansRetry")}
          </button>
        </div>
      ) : plans.length === 0 ? (
        <div className="flex flex-col items-center justify-center text-center py-20 px-6 bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-3xl">
          <div className="p-3 rounded-2xl bg-gray-100 dark:bg-gray-800 text-gray-400 mb-4">
            <PackageOpen className="w-8 h-8" />
          </div>
          <h3 className="text-base font-bold text-gray-900 dark:text-white">
            {t("vpsPlansEmptyTitle")}
          </h3>
          <p className="text-xs text-gray-500 dark:text-gray-400 mt-1 max-w-md">
            {t("vpsPlansEmptyDesc")}
          </p>
        </div>
      ) : (
        <div className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-6">
          {plans.map((plan) => (
            <VpsPlanCard key={plan.id} plan={plan} />
          ))}
        </div>
      )}
    </div>
  );
}
