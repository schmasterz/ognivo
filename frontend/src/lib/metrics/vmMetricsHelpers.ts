export type VmMetricsRange = "1h" | "6h" | "24h" | "7d" | "30d";
export type VmMetricNullReason = "gap" | "unavailable" | null;

export interface VmMetricSeriesPoint {
  ts: number;
  avg: number | null;
  min: number | null;
  max: number | null;
  reason: VmMetricNullReason;
}

export interface SvgChartSpan {
  x1: number;
  x2: number;
  startTs: number;
  endTs: number;
}

export interface SvgChartGeometry {
  linePaths: string[];
  areaPaths: string[];
  bandPaths: string[];
  gapSpans: SvgChartSpan[];
  unavailableSpans: SvgChartSpan[];
}

export const ALL_METRICS_RANGES: readonly VmMetricsRange[] = [
  "1h",
  "6h",
  "24h",
  "7d",
  "30d",
] as const;

type PointKind = "valid" | "unavailable" | "gap" | "other";

function roundCoord(value: number): number {
  return Number(value.toFixed(2));
}

function classifyPoint(point: VmMetricSeriesPoint): PointKind {
  if (point.avg !== null && point.reason === null) {
    return "valid";
  }
  if (point.avg === null && point.reason === "unavailable") {
    return "unavailable";
  }
  if (point.avg === null && (point.reason === "gap" || point.reason === null)) {
    return "gap";
  }
  return "other";
}

/**
 * Returns the UI auto-refresh interval in milliseconds for a given metrics range.
 */
export function getRefreshPeriodMs(
  range: VmMetricsRange,
  collectionIntervalSeconds = 60
): number {
  if (range === "1h" || range === "6h") {
    const clampedSeconds = Math.max(
      30,
      Math.min(300, Math.round(collectionIntervalSeconds || 60))
    );
    return clampedSeconds * 1000;
  }
  if (range === "24h") {
    return 300_000;
  }
  return 3_600_000;
}

/**
 * Filters ALL_METRICS_RANGES to only ranges supported by the backend capabilities.
 */
export function filterSupportedRanges(
  availableRanges?: readonly string[] | null
): VmMetricsRange[] {
  if (!availableRanges || availableRanges.length === 0) {
    return [...ALL_METRICS_RANGES];
  }
  return ALL_METRICS_RANGES.filter((range) => availableRanges.includes(range));
}

/**
 * Computes whether metrics are stale relative to the collection interval (> 2.5x).
 */
export function computeStaleness(
  lastSampleAtIso: string | null | undefined,
  nowMs: number,
  collectionIntervalSeconds = 60
): { isStale: boolean; minutesAgo: number | null } {
  if (!lastSampleAtIso) {
    return { isStale: false, minutesAgo: null };
  }
  const parsedMs = Date.parse(lastSampleAtIso);
  if (Number.isNaN(parsedMs)) {
    return { isStale: false, minutesAgo: null };
  }

  const elapsedSeconds = Math.max(0, (nowMs - parsedMs) / 1000);
  const thresholdSeconds = Math.max(30, collectionIntervalSeconds) * 2.5;
  const isStale = elapsedSeconds > thresholdSeconds;
  const minutesAgo = isStale ? Math.max(1, Math.floor(elapsedSeconds / 60)) : null;

  return { isStale, minutesAgo };
}

/**
 * Builds segmented SVG paths and gap/unavailable spans without zero-filling nulls.
 */
export function buildSvgChartSegments(
  points: readonly VmMetricSeriesPoint[],
  width: number,
  height: number,
  maxValue: number
): SvgChartGeometry {
  const geometry: SvgChartGeometry = {
    linePaths: [],
    areaPaths: [],
    bandPaths: [],
    gapSpans: [],
    unavailableSpans: [],
  };

  if (points.length === 0 || width <= 0 || height <= 0) {
    return geometry;
  }

  const count = points.length;
  const safeMax = maxValue > 0 ? maxValue : 1;
  const bottomY = roundCoord(height);

  const xForIndex = (index: number): number =>
    count === 1
      ? roundCoord(width / 2)
      : roundCoord((index / (count - 1)) * width);

  const yForValue = (val: number): number =>
    roundCoord(height - (Math.max(0, Math.min(safeMax, val)) / safeMax) * height);

  const flushSegment = (kind: PointKind, startIdx: number, endIdx: number): void => {
    if (kind === "unavailable") {
      geometry.unavailableSpans.push({
        x1: xForIndex(startIdx),
        x2: xForIndex(endIdx),
        startTs: points[startIdx].ts,
        endTs: points[endIdx].ts,
      });
      return;
    }

    if (kind === "gap") {
      geometry.gapSpans.push({
        x1: xForIndex(startIdx),
        x2: xForIndex(endIdx),
        startTs: points[startIdx].ts,
        endTs: points[endIdx].ts,
      });
      return;
    }

    if (kind !== "valid") {
      return;
    }

    const mapped: Array<{ x: number; y: number; yMin: number; yMax: number }> = [];
    let hasBand = false;

    for (let i = startIdx; i <= endIdx; i++) {
      const point = points[i];
      const avg = point.avg as number;
      const x = xForIndex(i);
      const y = yForValue(avg);
      const minVal = point.min ?? avg;
      const maxVal = point.max ?? avg;
      if (point.min !== null && point.max !== null && point.max > point.min) {
        hasBand = true;
      }
      mapped.push({
        x,
        y,
        yMin: yForValue(minVal),
        yMax: yForValue(maxVal),
      });
    }

    const first = mapped[0];
    const last = mapped[mapped.length - 1];

    if (mapped.length === 1) {
      geometry.linePaths.push(
        `M ${roundCoord(first.x - 1)} ${first.y} L ${roundCoord(first.x + 1)} ${first.y}`
      );
    } else {
      geometry.linePaths.push(
        mapped.map((p, idx) => `${idx === 0 ? "M" : "L"} ${p.x} ${p.y}`).join(" ")
      );
    }

    const areaBody = mapped.map((p) => `L ${p.x} ${p.y}`).join(" ");
    geometry.areaPaths.push(`M ${first.x} ${bottomY} ${areaBody} L ${last.x} ${bottomY} Z`);

    if (hasBand) {
      const topEdge = mapped
        .map((p, idx) => `${idx === 0 ? "M" : "L"} ${p.x} ${p.yMax}`)
        .join(" ");
      const bottomEdge: string[] = [];
      for (let i = mapped.length - 1; i >= 0; i--) {
        bottomEdge.push(`L ${mapped[i].x} ${mapped[i].yMin}`);
      }
      geometry.bandPaths.push(`${topEdge} ${bottomEdge.join(" ")} Z`);
    }
  };

  let runKind = classifyPoint(points[0]);
  let runStart = 0;

  for (let i = 1; i < count; i++) {
    const nextKind = classifyPoint(points[i]);
    if (nextKind !== runKind) {
      flushSegment(runKind, runStart, i - 1);
      runKind = nextKind;
      runStart = i;
    }
  }
  flushSegment(runKind, runStart, count - 1);

  return geometry;
}

/**
 * Formats byte quantities with binary IEC units (B, KiB, MiB, GiB, TiB).
 */
export function formatMetricBytes(bytes: number | null | undefined): string {
  if (bytes === null || bytes === undefined || !Number.isFinite(bytes)) {
    return "—";
  }

  const sign = bytes < 0 ? "-" : "";
  const abs = Math.abs(bytes);
  const kib = 1024;
  const mib = kib * 1024;
  const gib = mib * 1024;
  const tib = gib * 1024;

  if (abs < kib) {
    return `${sign}${Math.round(abs)} B`;
  }
  if (abs < mib) {
    return `${sign}${(abs / kib).toFixed(1)} KiB`;
  }
  if (abs < gib) {
    return `${sign}${(abs / mib).toFixed(1)} MiB`;
  }
  if (abs < tib) {
    return `${sign}${(abs / gib).toFixed(2)} GiB`;
  }
  return `${sign}${(abs / tib).toFixed(2)} TiB`;
}

/**
 * Formats byte-per-second throughput rates with binary IEC units.
 */
export function formatMetricRateBps(bps: number | null | undefined): string {
  if (bps === null || bps === undefined || !Number.isFinite(bps)) {
    return "—";
  }
  return `${formatMetricBytes(bps)}/s`;
}

/**
 * Formats an epoch-second timestamp in the viewer's local timezone according to range.
 */
export function formatViewerTimestamp(
  epochSeconds: number,
  range: VmMetricsRange,
  locale = "en"
): string {
  if (!Number.isFinite(epochSeconds)) {
    return "—";
  }
  const options: Intl.DateTimeFormatOptions =
    range === "7d" || range === "30d"
      ? { month: "short", day: "numeric", hour: "2-digit", minute: "2-digit" }
      : { hour: "2-digit", minute: "2-digit" };

  return new Intl.DateTimeFormat(locale, options).format(new Date(epochSeconds * 1000));
}
