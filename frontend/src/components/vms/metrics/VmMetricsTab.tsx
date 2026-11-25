"use client";

import React, { useCallback, useEffect, useMemo, useRef, useState } from "react";
import {
  Activity,
  AlertCircle,
  BarChart3,
  Clock,
  Cpu,
  HardDrive,
  Info,
  MemoryStick,
  Network,
  RefreshCw,
} from "lucide-react";
import { ApiError, VmMetricPointDTO, VmMetricsResponse, vmsApi } from "@/lib/api";
import { useAuth } from "@/lib/auth/AuthContext";
import { TranslationKey } from "@/lib/i18n/dictionaries";
import { useI18n } from "@/lib/i18n/I18nContext";
import {
  VmMetricsRange,
  computeStaleness,
  filterSupportedRanges,
  formatMetricBytes,
  formatMetricRateBps,
  getRefreshPeriodMs,
} from "@/lib/metrics/vmMetricsHelpers";
import { VmTabComponentProps } from "@/lib/registry/vmRegistry";
import { MetricsSvgChart } from "./MetricsSvgChart";

const RANGE_LABEL_KEYS: Record<VmMetricsRange, TranslationKey> = {
  "1h": "metricsRange1h",
  "6h": "metricsRange6h",
  "24h": "metricsRange24h",
  "7d": "metricsRange7d",
  "30d": "metricsRange30d",
};

function allPointsAreGap(points: readonly VmMetricPointDTO[]): boolean {
  return points.every((p) => p.avg === null && p.reason === "gap");
}

function isMetricsCompletelyEmpty(data: VmMetricsResponse): boolean {
  if (data.last_sample_at !== null) {
    return false;
  }

  const { cpu, memory, disk_space, disk_io, network } = data.series;
  if (
    !cpu?.available ||
    !memory?.available ||
    !disk_space?.available ||
    !disk_io?.available ||
    !network?.available
  ) {
    return false;
  }

  return (
    allPointsAreGap(cpu.points) &&
    allPointsAreGap(memory.points) &&
    allPointsAreGap(disk_space.points) &&
    allPointsAreGap(disk_io.read) &&
    allPointsAreGap(disk_io.write) &&
    allPointsAreGap(network.rx) &&
    allPointsAreGap(network.tx)
  );
}

export function VmMetricsTab({ vm }: VmTabComponentProps) {
  const { t } = useI18n();
  const { systemSettings } = useAuth();

  const [selectedRange, setSelectedRange] = useState<VmMetricsRange>("1h");
  const [metricsData, setMetricsData] = useState<VmMetricsResponse | null>(null);
  const [loading, setLoading] = useState<boolean>(true);
  const [refreshing, setRefreshing] = useState<boolean>(false);
  const [error, setError] = useState<string | null>(null);
  const [isDisabledError, setIsDisabledError] = useState<boolean>(false);
  const [nowMs, setNowMs] = useState<number>(() => Date.now());
  const [isDocumentVisible, setIsDocumentVisible] = useState<boolean>(() =>
    typeof document === "undefined" ? true : document.visibilityState === "visible"
  );

  const etagRef = useRef<string | null>(null);

  const supportedRanges = useMemo(
    () =>
      filterSupportedRanges(
        metricsData?.available_ranges ?? systemSettings?.metrics_available_ranges
      ),
    [metricsData?.available_ranges, systemSettings?.metrics_available_ranges]
  );

  const effectiveRange: VmMetricsRange = supportedRanges.includes(selectedRange)
    ? selectedRange
    : (supportedRanges[0] ?? "1h");

  useEffect(() => {
    if (effectiveRange !== selectedRange) {
      queueMicrotask(() => {
        setSelectedRange(effectiveRange);
      });
    }
  }, [effectiveRange, selectedRange]);

  const fetchMetrics = useCallback(
    async (
      range: VmMetricsRange,
      useConditionalEtag: boolean,
      signal?: AbortSignal
    ) => {
      try {
        const res = await vmsApi.getVmMetrics(vm.id, {
          range,
          maxPoints: 500,
          etag: useConditionalEtag ? etagRef.current : null,
          signal,
        });

        if (signal?.aborted) return;

        if (res.status === 200) {
          etagRef.current = res.etag;
          setMetricsData(res.data);
          setError(null);
          setIsDisabledError(false);
        } else {
          setError(null);
        }
        setNowMs(Date.now());
      } catch (err: unknown) {
        if (signal?.aborted) return;
        if (err instanceof Error && err.name === "AbortError") {
          return;
        }
        if (err instanceof ApiError && err.status === 503) {
          setIsDisabledError(true);
          setError(null);
        } else {
          setError(err instanceof Error ? err.message : "Failed to load metrics");
        }
      } finally {
        if (!signal?.aborted) {
          setLoading(false);
          setRefreshing(false);
        }
      }
    },
    [vm.id]
  );

  // Initial load and range / VM changes
  useEffect(() => {
    etagRef.current = null;
    const controller = new AbortController();
    void fetchMetrics(effectiveRange, false, controller.signal);
    return () => {
      controller.abort();
    };
  }, [effectiveRange, fetchMetrics]);

  // Document visibility tracking
  useEffect(() => {
    if (typeof document === "undefined") return;
    const handleVisibilityChange = () => {
      setIsDocumentVisible(document.visibilityState === "visible");
    };
    document.addEventListener("visibilitychange", handleVisibilityChange);
    return () => {
      document.removeEventListener("visibilitychange", handleVisibilityChange);
    };
  }, []);

  // Visibility-gated auto-refresh at the range's change rate
  const collectionIntervalSeconds =
    metricsData?.refresh_interval_seconds ??
    systemSettings?.metrics_interval_seconds ??
    60;

  useEffect(() => {
    if (!isDocumentVisible || isDisabledError) {
      return;
    }

    const controller = new AbortController();
    const periodMs = getRefreshPeriodMs(effectiveRange, collectionIntervalSeconds);
    const timer = setInterval(() => {
      setRefreshing(true);
      void fetchMetrics(effectiveRange, true, controller.signal);
    }, periodMs);

    return () => {
      clearInterval(timer);
      controller.abort();
    };
  }, [
    isDocumentVisible,
    isDisabledError,
    effectiveRange,
    collectionIntervalSeconds,
    fetchMetrics,
  ]);

  const handleSelectRange = (nextRange: VmMetricsRange) => {
    if (nextRange === effectiveRange) return;
    etagRef.current = null;
    setError(null);
    setRefreshing(true);
    setSelectedRange(nextRange);
  };

  const handleManualRefresh = () => {
    setRefreshing(true);
    void fetchMetrics(effectiveRange, false);
  };

  const handleRetry = () => {
    setError(null);
    if (!metricsData) {
      setLoading(true);
    } else {
      setRefreshing(true);
    }
    void fetchMetrics(effectiveRange, false);
  };

  const staleness = useMemo(
    () =>
      computeStaleness(
        metricsData?.last_sample_at,
        nowMs,
        collectionIntervalSeconds
      ),
    [metricsData?.last_sample_at, nowMs, collectionIntervalSeconds]
  );

  const isEmpty = useMemo(
    () => (metricsData ? isMetricsCompletelyEmpty(metricsData) : false),
    [metricsData]
  );

  // 1. Feature Disabled State
  if (isDisabledError) {
    return (
      <div className="rounded-xl border border-zinc-200 dark:border-zinc-800 bg-zinc-50 dark:bg-zinc-800/40 p-6 flex items-center space-x-3 text-sm text-zinc-700 dark:text-zinc-300">
        <div className="p-2.5 rounded-xl bg-blue-500/10 text-blue-600 dark:text-blue-400 shrink-0">
          <Info className="w-5 h-5" />
        </div>
        <p className="font-medium">{t("metricsDisabledNotice")}</p>
      </div>
    );
  }

  // 2. Fatal Error State (when no cached data is present)
  if (error && !metricsData) {
    return (
      <div className="rounded-xl border border-red-500/25 bg-red-500/10 p-5 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div className="flex items-start space-x-3">
          <AlertCircle className="w-5 h-5 text-red-500 shrink-0 mt-0.5" />
          <div className="text-xs text-red-700 dark:text-red-300">
            <p className="font-semibold text-sm text-red-800 dark:text-red-200">
              {error}
            </p>
          </div>
        </div>
        <button
          type="button"
          onClick={handleRetry}
          className="inline-flex items-center space-x-1.5 px-3.5 py-1.5 rounded-lg text-xs font-medium bg-red-600 hover:bg-red-500 text-white transition shrink-0"
        >
          <RefreshCw className="w-3.5 h-3.5" />
          <span>{t("retryBtn")}</span>
        </button>
      </div>
    );
  }

  const controlsBar = (
    <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 p-3.5 rounded-xl bg-zinc-50 dark:bg-zinc-800/40 border border-zinc-200 dark:border-zinc-800">
      {/* Range Selector */}
      <div className="flex items-center space-x-1 bg-zinc-200/70 dark:bg-zinc-900 p-1 rounded-lg border border-zinc-200 dark:border-zinc-800 w-fit">
        {supportedRanges.map((r) => {
          const active = r === effectiveRange;
          return (
            <button
              key={r}
              type="button"
              onClick={() => handleSelectRange(r)}
              className={`px-2.5 py-1 rounded-md text-xs font-medium transition ${
                active
                  ? "bg-white dark:bg-zinc-800 text-zinc-900 dark:text-zinc-100 shadow-sm"
                  : "text-zinc-600 dark:text-zinc-400 hover:text-zinc-900 dark:hover:text-zinc-200"
              }`}
            >
              {t(RANGE_LABEL_KEYS[r])}
            </button>
          );
        })}
      </div>

      {/* Status Pills + Refresh */}
      <div className="flex items-center flex-wrap gap-2.5">
        {staleness.isStale && staleness.minutesAgo !== null && (
          <span className="inline-flex items-center space-x-1.5 px-2.5 py-1 rounded-lg text-xs font-medium bg-amber-500/10 text-amber-700 dark:text-amber-300 border border-amber-500/20">
            <Clock className="w-3.5 h-3.5 shrink-0" />
            <span>{t("metricsStaleNotice", { minutes: staleness.minutesAgo })}</span>
          </span>
        )}

        <span className="inline-flex items-center space-x-1.5 text-[11px] text-zinc-500 dark:text-zinc-400">
          <span
            className={`w-1.5 h-1.5 rounded-full ${
              isDocumentVisible ? "bg-emerald-500" : "bg-zinc-400"
            }`}
          />
          <span>{t("metricsAutoRefreshHint")}</span>
        </span>

        <button
          type="button"
          onClick={handleManualRefresh}
          disabled={loading || refreshing}
          aria-label="Refresh metrics"
          className="p-2 rounded-lg text-zinc-500 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-100 hover:bg-zinc-100 dark:hover:bg-zinc-800 border border-zinc-200 dark:border-zinc-700/50 transition disabled:opacity-50"
        >
          <RefreshCw className={`w-4 h-4 ${refreshing ? "animate-spin" : ""}`} />
        </button>
      </div>
    </div>
  );

  // 3. Initial Loading Skeleton State
  if (loading && !metricsData) {
    return (
      <div className="space-y-4">
        {controlsBar}
        <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
          {[0, 1, 2, 3].map((idx) => (
            <div
              key={idx}
              className="bg-zinc-50/70 dark:bg-zinc-800/40 border border-zinc-200 dark:border-zinc-800 rounded-xl p-4 animate-pulse"
            >
              <div className="flex items-center justify-between mb-4">
                <div className="flex items-center space-x-2.5">
                  <div className="w-8 h-8 rounded-lg bg-zinc-200 dark:bg-zinc-700" />
                  <div className="h-4 w-32 rounded bg-zinc-200 dark:bg-zinc-700" />
                </div>
                <div className="h-4 w-16 rounded bg-zinc-200 dark:bg-zinc-700" />
              </div>
              <div className="h-40 rounded-lg bg-zinc-200/70 dark:bg-zinc-700/50" />
            </div>
          ))}
        </div>
      </div>
    );
  }

  if (!metricsData) {
    return null;
  }

  const cpuSeries = metricsData.series.cpu;
  const memorySeries = metricsData.series.memory;
  const diskSpaceSeries = metricsData.series.disk_space;
  const diskIoSeries = metricsData.series.disk_io;
  const networkSeries = metricsData.series.network;

  return (
    <div className="space-y-4">
      {controlsBar}

      {/* Non-blocking polling error banner */}
      {error && (
        <div className="rounded-xl border border-red-500/25 bg-red-500/10 px-4 py-2.5 flex items-center justify-between gap-3 text-xs text-red-700 dark:text-red-300">
          <div className="flex items-center space-x-2">
            <AlertCircle className="w-4 h-4 text-red-500 shrink-0" />
            <span>{error}</span>
          </div>
          <button
            type="button"
            onClick={handleRetry}
            className="font-medium underline hover:no-underline shrink-0"
          >
            {t("retryBtn")}
          </button>
        </div>
      )}

      {/* 5. Empty State Banner */}
      {isEmpty && (
        <div className="rounded-xl border border-zinc-200 dark:border-zinc-800 bg-zinc-50 dark:bg-zinc-800/40 p-4 flex items-start space-x-3">
          <div className="p-2 rounded-lg bg-blue-500/10 text-blue-600 dark:text-blue-400 shrink-0">
            <BarChart3 className="w-4 h-4" />
          </div>
          <div>
            <p className="text-xs font-semibold text-zinc-900 dark:text-zinc-100">
              {t("metricsEmptyTitle")}
            </p>
            <p className="text-xs text-zinc-500 dark:text-zinc-400 mt-0.5">
              {t("metricsEmptyDesc")}
            </p>
          </div>
        </div>
      )}

      {/* 6. Charts Grid */}
      <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
        {/* Chart 1: CPU Usage */}
        <MetricsSvgChart
          title={t("metricsCpuTitle")}
          badge={t("metricsVcpuCount", { count: metricsData.totals.cpu_cores })}
          icon={Cpu}
          accentColor="blue"
          available={cpuSeries?.available ?? false}
          unavailableReason={cpuSeries?.unavailable_reason}
          series={[
            {
              key: "cpu",
              label: t("metricsCpuTitle"),
              color: "blue",
              points: cpuSeries?.points ?? [],
            },
          ]}
          maxValue={100}
          range={effectiveRange}
          formatValue={(v) => (v === null ? "—" : `${v.toFixed(1)}%`)}
        />

        {/* Chart 2: Memory Usage */}
        <MetricsSvgChart
          title={t("metricsMemoryTitle")}
          badge={t("metricsAllocatedCeiling", {
            total: formatMetricBytes(metricsData.totals.memory_total_bytes),
          })}
          icon={MemoryStick}
          accentColor="purple"
          available={memorySeries?.available ?? false}
          unavailableReason={memorySeries?.unavailable_reason}
          series={[
            {
              key: "memory",
              label: t("metricsMemoryTitle"),
              color: "purple",
              points: memorySeries?.points ?? [],
            },
          ]}
          maxValue={metricsData.totals.memory_total_bytes}
          range={effectiveRange}
          formatValue={formatMetricBytes}
        />

        {/* Chart 3: Root Filesystem Usage */}
        <MetricsSvgChart
          title={t("metricsDiskSpaceTitle")}
          badge={t("metricsAllocatedCeiling", {
            total: formatMetricBytes(metricsData.totals.disk_total_bytes),
          })}
          icon={HardDrive}
          accentColor="emerald"
          available={diskSpaceSeries?.available ?? false}
          unavailableReason={diskSpaceSeries?.unavailable_reason}
          series={[
            {
              key: "disk_space",
              label: t("metricsDiskSpaceTitle"),
              color: "emerald",
              points: diskSpaceSeries?.points ?? [],
            },
          ]}
          maxValue={metricsData.totals.disk_total_bytes}
          range={effectiveRange}
          formatValue={formatMetricBytes}
        />

        {/* Chart 4: Disk I/O Throughput */}
        <MetricsSvgChart
          title={t("metricsDiskIoTitle")}
          icon={Activity}
          accentColor="amber"
          available={diskIoSeries?.available ?? false}
          unavailableReason={diskIoSeries?.unavailable_reason}
          series={[
            {
              key: "disk_read",
              label: t("metricsDiskRead"),
              color: "emerald",
              points: diskIoSeries?.read ?? [],
            },
            {
              key: "disk_write",
              label: t("metricsDiskWrite"),
              color: "amber",
              points: diskIoSeries?.write ?? [],
            },
          ]}
          range={effectiveRange}
          formatValue={formatMetricRateBps}
        />

        {/* Chart 5: Network Traffic (full width) */}
        <div className="lg:col-span-2">
          <MetricsSvgChart
            title={t("metricsNetworkTitle")}
            icon={Network}
            accentColor="blue"
            available={networkSeries?.available ?? false}
            unavailableReason={networkSeries?.unavailable_reason}
            series={[
              {
                key: "net_rx",
                label: t("metricsNetRx"),
                color: "blue",
                points: networkSeries?.rx ?? [],
              },
              {
                key: "net_tx",
                label: t("metricsNetTx"),
                color: "purple",
                points: networkSeries?.tx ?? [],
              },
            ]}
            range={effectiveRange}
            formatValue={formatMetricRateBps}
          />
        </div>
      </div>
    </div>
  );
}
