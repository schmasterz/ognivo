"use client";

import React, { useId, useMemo, useState } from "react";
import { AlertCircle } from "lucide-react";
import { VmMetricPointDTO } from "@/lib/api/types";
import {
  VmMetricsRange,
  buildSvgChartSegments,
  formatViewerTimestamp,
} from "@/lib/metrics/vmMetricsHelpers";
import { useI18n } from "@/lib/i18n/I18nContext";

export interface ChartSeriesInput {
  key: string;
  label: string;
  color: "blue" | "purple" | "emerald" | "amber" | "rose";
  points: VmMetricPointDTO[];
}

export interface MetricsSvgChartProps {
  title: string;
  subtitle?: string;
  badge?: string;
  icon: React.ComponentType<{ className?: string }>;
  accentColor: "blue" | "purple" | "emerald" | "amber";
  available: boolean;
  unavailableReason?: string | null;
  series: ChartSeriesInput[];
  maxValue?: number | null;
  range: VmMetricsRange;
  formatValue: (val: number | null) => string;
}

const SVG_WIDTH = 600;
const SVG_HEIGHT = 160;
const GRID_RATIOS = [1, 0.75, 0.5, 0.25, 0] as const;

const ACCENT_PILL_CLASSES: Record<MetricsSvgChartProps["accentColor"], string> = {
  blue: "bg-blue-500/10 text-blue-600 dark:text-blue-400",
  purple: "bg-purple-500/10 text-purple-600 dark:text-purple-400",
  emerald: "bg-emerald-500/10 text-emerald-600 dark:text-emerald-400",
  amber: "bg-amber-500/10 text-amber-600 dark:text-amber-400",
};

const SERIES_PALETTE: Record<
  ChartSeriesInput["color"],
  { stroke: string; dotClass: string; textClass: string }
> = {
  blue: {
    stroke: "#3b82f6",
    dotClass: "bg-blue-500",
    textClass: "text-blue-600 dark:text-blue-400",
  },
  purple: {
    stroke: "#a855f7",
    dotClass: "bg-purple-500",
    textClass: "text-purple-600 dark:text-purple-400",
  },
  emerald: {
    stroke: "#10b981",
    dotClass: "bg-emerald-500",
    textClass: "text-emerald-600 dark:text-emerald-400",
  },
  amber: {
    stroke: "#f59e0b",
    dotClass: "bg-amber-500",
    textClass: "text-amber-600 dark:text-amber-400",
  },
  rose: {
    stroke: "#f43f5e",
    dotClass: "bg-rose-500",
    textClass: "text-rose-600 dark:text-rose-400",
  },
};

function findLatestNonNull(points: readonly VmMetricPointDTO[]): number | null {
  for (let i = points.length - 1; i >= 0; i--) {
    if (points[i].avg !== null) {
      return points[i].avg;
    }
  }
  return null;
}

function deduplicateSpans(
  spans: Array<{ x1: number; x2: number; startTs: number; endTs: number }>
): Array<{ x1: number; x2: number; startTs: number; endTs: number }> {
  const seen = new Set<string>();
  const result: Array<{ x1: number; x2: number; startTs: number; endTs: number }> = [];
  for (const span of spans) {
    const key = `${span.startTs}:${span.endTs}`;
    if (!seen.has(key)) {
      seen.add(key);
      result.push(span);
    }
  }
  return result;
}

export function MetricsSvgChart({
  title,
  subtitle,
  badge,
  icon: Icon,
  accentColor,
  available,
  unavailableReason,
  series,
  maxValue,
  range,
  formatValue,
}: MetricsSvgChartProps) {
  const { t, locale } = useI18n();
  const rawId = useId();
  const safeIdPrefix = rawId.replace(/[^a-zA-Z0-9_-]/g, "");
  const [hoverIdx, setHoverIdx] = useState<number | null>(null);

  const primaryPoints = useMemo(() => {
    const withPoints = series.find((s) => s.points.length > 0);
    return withPoints?.points ?? [];
  }, [series]);

  const peakObserved = useMemo(() => {
    let peak = 0;
    for (const s of series) {
      for (const p of s.points) {
        const candidate = p.max ?? p.avg;
        if (candidate !== null && Number.isFinite(candidate) && candidate > peak) {
          peak = candidate;
        }
      }
    }
    return peak;
  }, [series]);

  const computedMax = useMemo(() => {
    if (maxValue && maxValue > 0) {
      return Math.max(maxValue, peakObserved);
    }
    return peakObserved > 0 ? peakObserved * 1.15 : 100;
  }, [maxValue, peakObserved]);

  const latestSummaries = useMemo(
    () =>
      series.map((s) => ({
        key: s.key,
        label: s.label,
        color: s.color,
        value: findLatestNonNull(s.points),
      })),
    [series]
  );

  const geometries = useMemo(
    () =>
      series.map((s) => ({
        ...s,
        geo: buildSvgChartSegments(s.points, SVG_WIDTH, SVG_HEIGHT, computedMax),
      })),
    [series, computedMax]
  );

  const gapSpans = useMemo(
    () => deduplicateSpans(geometries.flatMap((g) => g.geo.gapSpans)),
    [geometries]
  );

  const unavailableSpans = useMemo(
    () => deduplicateSpans(geometries.flatMap((g) => g.geo.unavailableSpans)),
    [geometries]
  );

  const pointCount = primaryPoints.length;

  const handleMouseMove = (e: React.MouseEvent<SVGSVGElement>) => {
    if (pointCount === 0) return;
    const rect = e.currentTarget.getBoundingClientRect();
    if (rect.width <= 0) return;
    const ratio = Math.max(0, Math.min(1, (e.clientX - rect.left) / rect.width));
    const idx = pointCount === 1 ? 0 : Math.round(ratio * (pointCount - 1));
    setHoverIdx(idx);
  };

  const handleMouseLeave = () => {
    setHoverIdx(null);
  };

  const activeHoverIdx =
    hoverIdx !== null && pointCount > 0 ? Math.min(hoverIdx, pointCount - 1) : null;
  const hoverPoint = activeHoverIdx !== null ? primaryPoints[activeHoverIdx] : null;
  const hoverXPercent =
    activeHoverIdx !== null && pointCount > 0
      ? pointCount === 1
        ? 50
        : (activeHoverIdx / (pointCount - 1)) * 100
      : 0;
  const hoverSvgX =
    activeHoverIdx !== null && pointCount > 0
      ? pointCount === 1
        ? SVG_WIDTH / 2
        : Number(((activeHoverIdx / (pointCount - 1)) * SVG_WIDTH).toFixed(2))
      : 0;

  const hoverSeriesPoints =
    activeHoverIdx !== null
      ? series.map((s) => ({
          key: s.key,
          label: s.label,
          color: s.color,
          point: s.points[activeHoverIdx] ?? null,
        }))
      : [];

  const allHoveredGap =
    hoverSeriesPoints.length > 0 &&
    hoverSeriesPoints.every((sp) => sp.point?.avg === null && sp.point?.reason === "gap");
  const allHoveredUnavailable =
    hoverSeriesPoints.length > 0 &&
    hoverSeriesPoints.every((sp) => sp.point?.avg === null && sp.point?.reason === "unavailable");

  const xAxisMarkers = useMemo(() => {
    if (pointCount === 0) return [];
    if (pointCount === 1) {
      return [formatViewerTimestamp(primaryPoints[0].ts, range, locale)];
    }
    const midIdx = Math.floor((pointCount - 1) / 2);
    return [
      formatViewerTimestamp(primaryPoints[0].ts, range, locale),
      formatViewerTimestamp(primaryPoints[midIdx].ts, range, locale),
      formatViewerTimestamp(primaryPoints[pointCount - 1].ts, range, locale),
    ];
  }, [pointCount, primaryPoints, range, locale]);

  const unavailableDescription =
    unavailableReason === "guest_agent_not_running" || !unavailableReason
      ? t("metricsUnavailableAgent")
      : t("metricsUnavailableAgent");

  return (
    <div className="bg-zinc-50/70 dark:bg-zinc-800/40 border border-zinc-200 dark:border-zinc-800 rounded-xl p-4 flex flex-col">
      {/* Header */}
      <div className="flex items-start justify-between gap-3 mb-3">
        <div className="flex items-center space-x-2.5 min-w-0">
          <div className={`p-2 rounded-lg shrink-0 ${ACCENT_PILL_CLASSES[accentColor]}`}>
            <Icon className="w-4 h-4" />
          </div>
          <div className="min-w-0">
            <div className="flex items-center flex-wrap gap-1.5">
              <h4 className="text-sm font-semibold text-zinc-900 dark:text-zinc-100 truncate">
                {title}
              </h4>
              {badge && (
                <span className="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-medium bg-zinc-200/70 dark:bg-zinc-700/70 text-zinc-600 dark:text-zinc-300">
                  {badge}
                </span>
              )}
            </div>
            {subtitle && (
              <p className="text-[11px] text-zinc-500 dark:text-zinc-400 truncate mt-0.5">
                {subtitle}
              </p>
            )}
          </div>
        </div>

        {/* Latest Value & Peak Summary */}
        {available && (
          <div className="text-right shrink-0">
            {latestSummaries.length === 1 ? (
              <div className="text-sm font-semibold font-mono text-zinc-900 dark:text-zinc-100">
                {formatValue(latestSummaries[0].value)}
              </div>
            ) : (
              <div className="flex items-center justify-end flex-wrap gap-x-2.5 gap-y-0.5">
                {latestSummaries.map((item) => (
                  <span
                    key={item.key}
                    className="inline-flex items-center space-x-1 text-[11px] font-mono text-zinc-800 dark:text-zinc-200"
                  >
                    <span
                      className={`w-2 h-2 rounded-full ${SERIES_PALETTE[item.color].dotClass}`}
                    />
                    <span className="text-zinc-500 dark:text-zinc-400 font-sans">
                      {item.label}:
                    </span>
                    <span className="font-semibold">{formatValue(item.value)}</span>
                  </span>
                ))}
              </div>
            )}
            {peakObserved > 0 && (
              <div className="mt-0.5">
                <span className="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-mono bg-zinc-200/60 dark:bg-zinc-800 text-zinc-600 dark:text-zinc-400">
                  Peak {formatValue(peakObserved)}
                </span>
              </div>
            )}
          </div>
        )}
      </div>

      {/* Body */}
      {!available ? (
        <div className="h-44 flex flex-col items-center justify-center rounded-lg border border-dashed border-zinc-300 dark:border-zinc-700 bg-zinc-100/60 dark:bg-zinc-900/40 px-4 text-center">
          <AlertCircle className="w-5 h-5 text-amber-500 mb-1.5" />
          <p className="text-xs font-semibold text-zinc-800 dark:text-zinc-200">
            {t("metricsUnavailableTitle")}
          </p>
          <p className="text-[11px] text-zinc-500 dark:text-zinc-400 mt-1 max-w-xs">
            {unavailableDescription}
          </p>
        </div>
      ) : (
        <div className="flex flex-col">
          {/* Chart Area with Y-axis + SVG */}
          <div className="flex items-stretch">
            {/* Y-axis labels */}
            <div className="w-16 shrink-0 flex flex-col justify-between text-right pr-2 py-0.5 text-[10px] font-mono text-zinc-400 dark:text-zinc-500 h-40 leading-none select-none">
              {GRID_RATIOS.map((ratio) => (
                <span key={ratio} className="truncate">
                  {formatValue(computedMax * ratio)}
                </span>
              ))}
            </div>

            {/* SVG Container */}
            <div className="relative flex-1 h-40 min-w-0">
              <svg
                viewBox={`0 0 ${SVG_WIDTH} ${SVG_HEIGHT}`}
                preserveAspectRatio="none"
                onMouseMove={handleMouseMove}
                onMouseLeave={handleMouseLeave}
                className="w-full h-full overflow-visible cursor-crosshair block"
                role="img"
                aria-label={title}
              >
                <defs>
                  {series.length === 1 &&
                    geometries.map((g) => (
                      <linearGradient
                        key={g.key}
                        id={`${safeIdPrefix}-grad-${g.key}`}
                        x1="0"
                        y1="0"
                        x2="0"
                        y2="1"
                      >
                        <stop
                          offset="0%"
                          stopColor={SERIES_PALETTE[g.color].stroke}
                          stopOpacity={0.28}
                        />
                        <stop
                          offset="100%"
                          stopColor={SERIES_PALETTE[g.color].stroke}
                          stopOpacity={0.02}
                        />
                      </linearGradient>
                    ))}
                </defs>

                {/* Horizontal Grid Lines (0%, 25%, 50%, 75%, 100%) */}
                {GRID_RATIOS.map((ratio) => {
                  const y = Number((SVG_HEIGHT - ratio * SVG_HEIGHT).toFixed(2));
                  return (
                    <line
                      key={ratio}
                      x1={0}
                      y1={y}
                      x2={SVG_WIDTH}
                      y2={y}
                      stroke="currentColor"
                      strokeWidth={1}
                      strokeDasharray={ratio === 0 ? undefined : "3 3"}
                      vectorEffect="non-scaling-stroke"
                      className="text-zinc-200 dark:text-zinc-700/70"
                    />
                  );
                })}

                {/* Gap Spans */}
                {gapSpans.map((span, idx) => {
                  const width = Math.max(4, span.x2 - span.x1);
                  const x = Math.min(SVG_WIDTH - width, Math.max(0, span.x1));
                  return (
                    <rect
                      key={`gap-${span.startTs}-${idx}`}
                      x={x}
                      y={0}
                      width={width}
                      height={SVG_HEIGHT}
                      className="fill-zinc-400/10 dark:fill-zinc-600/15"
                    />
                  );
                })}

                {/* Unavailable Spans */}
                {unavailableSpans.map((span, idx) => {
                  const width = Math.max(4, span.x2 - span.x1);
                  const x = Math.min(SVG_WIDTH - width, Math.max(0, span.x1));
                  return (
                    <rect
                      key={`unavail-${span.startTs}-${idx}`}
                      x={x}
                      y={0}
                      width={width}
                      height={SVG_HEIGHT}
                      className="fill-amber-500/10"
                    />
                  );
                })}

                {/* Min/Max Rollup Bands */}
                {geometries.map((g) =>
                  g.geo.bandPaths.map((d, idx) => (
                    <path
                      key={`band-${g.key}-${idx}`}
                      d={d}
                      fill={SERIES_PALETTE[g.color].stroke}
                      opacity={0.2}
                    />
                  ))
                )}

                {/* Area Fills (Single-series only) */}
                {series.length === 1 &&
                  geometries.map((g) =>
                    g.geo.areaPaths.map((d, idx) => (
                      <path
                        key={`area-${g.key}-${idx}`}
                        d={d}
                        fill={`url(#${safeIdPrefix}-grad-${g.key})`}
                      />
                    ))
                  )}

                {/* Series Lines */}
                {geometries.map((g) =>
                  g.geo.linePaths.map((d, idx) => (
                    <path
                      key={`line-${g.key}-${idx}`}
                      d={d}
                      fill="none"
                      stroke={SERIES_PALETTE[g.color].stroke}
                      strokeWidth={2}
                      strokeLinecap="round"
                      strokeLinejoin="round"
                      vectorEffect="non-scaling-stroke"
                    />
                  ))
                )}

                {/* Hover Crosshair */}
                {activeHoverIdx !== null && (
                  <line
                    x1={hoverSvgX}
                    y1={0}
                    x2={hoverSvgX}
                    y2={SVG_HEIGHT}
                    stroke="currentColor"
                    strokeWidth={1}
                    strokeDasharray="3 3"
                    vectorEffect="non-scaling-stroke"
                    className="text-zinc-400 dark:text-zinc-500 pointer-events-none"
                  />
                )}
              </svg>

              {/* Circular Hover Marker Dots */}
              {activeHoverIdx !== null &&
                hoverSeriesPoints.map((sp) => {
                  if (sp.point?.avg === null || sp.point?.avg === undefined) return null;
                  const clampedRatio = Math.max(
                    0,
                    Math.min(1, sp.point.avg / (computedMax > 0 ? computedMax : 1))
                  );
                  const topPercent = (1 - clampedRatio) * 100;
                  return (
                    <span
                      key={`dot-${sp.key}`}
                      className={`pointer-events-none absolute w-2.5 h-2.5 rounded-full border-2 border-white dark:border-zinc-900 -translate-x-1/2 -translate-y-1/2 ${SERIES_PALETTE[sp.color].dotClass}`}
                      style={{
                        left: `${hoverXPercent}%`,
                        top: `${topPercent}%`,
                      }}
                    />
                  );
                })}

              {/* Hover Tooltip */}
              {hoverPoint && (
                <div
                  className={`pointer-events-none absolute top-1 z-20 rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white/95 dark:bg-zinc-900/95 backdrop-blur-sm shadow-lg px-2.5 py-1.5 text-[11px] min-w-[160px] max-w-[240px] ${
                    hoverXPercent > 55 ? "left-2" : "right-2"
                  }`}
                >
                  <div className="font-mono font-semibold text-zinc-800 dark:text-zinc-100 border-b border-zinc-200/80 dark:border-zinc-800 pb-1 mb-1">
                    {formatViewerTimestamp(hoverPoint.ts, range, locale)}
                  </div>
                  {allHoveredGap ? (
                    <div className="text-zinc-500 dark:text-zinc-400">
                      {t("metricsGapTooltip")}
                    </div>
                  ) : allHoveredUnavailable ? (
                    <div className="text-amber-600 dark:text-amber-400">
                      {t("metricsUnavailableTitle")}
                    </div>
                  ) : (
                    <div className="space-y-0.5">
                      {hoverSeriesPoints.map((sp) => {
                        const pt = sp.point;
                        if (!pt) return null;
                        if (pt.avg === null && pt.reason === "gap") {
                          return (
                            <div
                              key={sp.key}
                              className="flex items-center justify-between gap-2 text-zinc-500 dark:text-zinc-400"
                            >
                              <span className="truncate">{sp.label}</span>
                              <span>{t("metricsGapTooltip")}</span>
                            </div>
                          );
                        }
                        if (pt.avg === null && pt.reason === "unavailable") {
                          return (
                            <div
                              key={sp.key}
                              className="flex items-center justify-between gap-2 text-amber-600 dark:text-amber-400"
                            >
                              <span className="truncate">{sp.label}</span>
                              <span>{t("metricsUnavailableTitle")}</span>
                            </div>
                          );
                        }
                        const hasMinMax =
                          pt.min !== null && pt.max !== null && pt.max > pt.min;
                        return (
                          <div key={sp.key} className="space-y-0.5">
                            <div className="flex items-center justify-between gap-2">
                              <span className="inline-flex items-center space-x-1.5 text-zinc-600 dark:text-zinc-300 truncate">
                                <span
                                  className={`w-2 h-2 rounded-full shrink-0 ${SERIES_PALETTE[sp.color].dotClass}`}
                                />
                                <span className="truncate">{sp.label}</span>
                              </span>
                              <span className="font-mono font-semibold text-zinc-900 dark:text-zinc-100 shrink-0">
                                {formatValue(pt.avg)}
                              </span>
                            </div>
                            {hasMinMax && (
                              <div className="text-[10px] font-mono text-zinc-500 dark:text-zinc-400 text-right">
                                {formatValue(pt.min)} – {formatValue(pt.max)}
                              </div>
                            )}
                          </div>
                        );
                      })}
                    </div>
                  )}
                </div>
              )}
            </div>
          </div>

          {/* X-axis Timestamps & Legend */}
          <div className="pl-16 mt-2 flex items-center justify-between text-[10px] font-mono text-zinc-400 dark:text-zinc-500">
            {xAxisMarkers.length > 0 ? (
              xAxisMarkers.map((label, idx) => <span key={idx}>{label}</span>)
            ) : (
              <span>—</span>
            )}
          </div>

          {series.length > 1 && (
            <div className="pl-16 mt-2 flex items-center flex-wrap gap-3 text-[11px] text-zinc-600 dark:text-zinc-400">
              {series.map((s) => (
                <span key={s.key} className="inline-flex items-center space-x-1.5">
                  <span
                    className={`w-2.5 h-2.5 rounded-full ${SERIES_PALETTE[s.color].dotClass}`}
                  />
                  <span>{s.label}</span>
                </span>
              ))}
            </div>
          )}
        </div>
      )}
    </div>
  );
}
