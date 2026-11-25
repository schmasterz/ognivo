import { describe, it } from "node:test";
import assert from "node:assert/strict";
import {
  ALL_METRICS_RANGES,
  buildSvgChartSegments,
  computeStaleness,
  filterSupportedRanges,
  formatMetricBytes,
  formatMetricRateBps,
  formatViewerTimestamp,
  getRefreshPeriodMs,
  type VmMetricSeriesPoint,
} from "../../../src/lib/metrics/vmMetricsHelpers.ts";

describe("vmMetricsHelpers - getRefreshPeriodMs", () => {
  it("returns expected intervals for all ranges with default collection interval", () => {
    assert.equal(getRefreshPeriodMs("1h"), 60_000);
    assert.equal(getRefreshPeriodMs("6h"), 60_000);
    assert.equal(getRefreshPeriodMs("24h"), 300_000);
    assert.equal(getRefreshPeriodMs("7d"), 3_600_000);
    assert.equal(getRefreshPeriodMs("30d"), 3_600_000);
  });

  it("uses custom collectionIntervalSeconds for 1h/6h and clamps to [30, 300] seconds", () => {
    assert.equal(getRefreshPeriodMs("1h", 120), 120_000);
    assert.equal(getRefreshPeriodMs("6h", 120), 120_000);
    assert.equal(getRefreshPeriodMs("1h", 10), 30_000);
    assert.equal(getRefreshPeriodMs("1h", 600), 300_000);
    assert.equal(getRefreshPeriodMs("24h", 120), 300_000);
    assert.equal(getRefreshPeriodMs("7d", 120), 3_600_000);
    assert.equal(getRefreshPeriodMs("30d", 120), 3_600_000);
  });
});

describe("vmMetricsHelpers - filterSupportedRanges", () => {
  it("filters out omitted ranges such as 30d while preserving canonical order", () => {
    assert.deepEqual(
      filterSupportedRanges(["7d", "1h", "24h", "6h"]),
      ["1h", "6h", "24h", "7d"]
    );
  });

  it("returns all ranges when availableRanges is omitted or empty", () => {
    assert.deepEqual(filterSupportedRanges(undefined), [...ALL_METRICS_RANGES]);
    assert.deepEqual(filterSupportedRanges(null), [...ALL_METRICS_RANGES]);
    assert.deepEqual(filterSupportedRanges([]), [...ALL_METRICS_RANGES]);
  });
});

describe("vmMetricsHelpers - computeStaleness", () => {
  it("reports not stale within 2.5x collection interval", () => {
    const nowMs = Date.parse("2026-01-15T12:00:00.000Z");
    const sampleIso = "2026-01-15T11:57:30.000Z"; // 150s ago == 2.5 * 60s
    assert.deepEqual(computeStaleness(sampleIso, nowMs, 60), {
      isStale: false,
      minutesAgo: null,
    });
  });

  it("reports stale with minutesAgo beyond 2.5x collection interval", () => {
    const nowMs = Date.parse("2026-01-15T12:00:00.000Z");
    const sampleIso = "2026-01-15T11:55:00.000Z"; // 300s ago > 150s threshold
    assert.deepEqual(computeStaleness(sampleIso, nowMs, 60), {
      isStale: true,
      minutesAgo: 5,
    });

    const justOverThresholdIso = "2026-01-15T11:57:29.000Z"; // 151s ago, 2 min floored
    assert.deepEqual(computeStaleness(justOverThresholdIso, nowMs, 60), {
      isStale: true,
      minutesAgo: 2,
    });
  });

  it("handles missing or invalid timestamps safely", () => {
    const nowMs = Date.parse("2026-01-15T12:00:00.000Z");
    assert.deepEqual(computeStaleness(null, nowMs), { isStale: false, minutesAgo: null });
    assert.deepEqual(computeStaleness(undefined, nowMs), { isStale: false, minutesAgo: null });
    assert.deepEqual(computeStaleness("not-a-date", nowMs), { isStale: false, minutesAgo: null });
  });
});

describe("vmMetricsHelpers - buildSvgChartSegments", () => {
  it("returns empty geometry for empty points or invalid dimensions", () => {
    assert.deepEqual(buildSvgChartSegments([], 400, 100, 100), {
      linePaths: [],
      areaPaths: [],
      bandPaths: [],
      gapSpans: [],
      unavailableSpans: [],
    });
    assert.deepEqual(
      buildSvgChartSegments(
        [{ ts: 100, avg: 10, min: null, max: null, reason: null }],
        0,
        100,
        100
      ),
      {
        linePaths: [],
        areaPaths: [],
        bandPaths: [],
        gapSpans: [],
        unavailableSpans: [],
      }
    );
  });

  it("builds 1 linePath, 1 areaPath, and 0 gapSpans for contiguous points", () => {
    const points: VmMetricSeriesPoint[] = [
      { ts: 1000, avg: 25, min: null, max: null, reason: null },
      { ts: 1060, avg: 50, min: null, max: null, reason: null },
      { ts: 1120, avg: 75, min: null, max: null, reason: null },
    ];
    const geo = buildSvgChartSegments(points, 200, 100, 100);

    assert.equal(geo.linePaths.length, 1);
    assert.equal(geo.linePaths[0], "M 0 75 L 100 50 L 200 25");
    assert.equal(geo.areaPaths.length, 1);
    assert.equal(geo.areaPaths[0], "M 0 100 L 0 75 L 100 50 L 200 25 L 200 100 Z");
    assert.equal(geo.bandPaths.length, 0);
    assert.equal(geo.gapSpans.length, 0);
    assert.equal(geo.unavailableSpans.length, 0);
  });

  it("splits linePaths across gap points without zero-value y-coordinate drop (AC-16)", () => {
    const points: VmMetricSeriesPoint[] = [
      { ts: 1000, avg: 40, min: null, max: null, reason: null },
      { ts: 1060, avg: 60, min: null, max: null, reason: null },
      { ts: 1120, avg: null, min: null, max: null, reason: "gap" },
      { ts: 1180, avg: null, min: null, max: null, reason: "gap" },
      { ts: 1240, avg: 80, min: null, max: null, reason: null },
    ];
    const geo = buildSvgChartSegments(points, 400, 100, 100);

    assert.equal(geo.linePaths.length, 2);
    assert.equal(geo.linePaths[0], "M 0 60 L 100 40");
    // Isolated point at x=400, y=20 emits a short visible horizontal tick
    assert.equal(geo.linePaths[1], "M 399 20 L 401 20");
    assert.equal(geo.areaPaths.length, 2);
    // Ensure no coordinate pair in any line path drops to baseline y=100 (zero value)
    for (const path of geo.linePaths) {
      const pairs = [...path.matchAll(/[ML]\s+(-?\d+(?:\.\d+)?)\s+(-?\d+(?:\.\d+)?)/g)];
      assert.ok(pairs.length >= 2);
      for (const [, , y] of pairs) {
        assert.notEqual(Number(y), 100);
      }
    }
    assert.deepEqual(geo.gapSpans, [
      { x1: 200, x2: 300, startTs: 1120, endTs: 1180 },
    ]);
    assert.equal(geo.unavailableSpans.length, 0);
  });

  it("records unavailableSpans and emits no linePaths when points are unavailable (AC-13)", () => {
    const points: VmMetricSeriesPoint[] = [
      { ts: 1000, avg: null, min: null, max: null, reason: "unavailable" },
      { ts: 1060, avg: null, min: null, max: null, reason: "unavailable" },
      { ts: 1120, avg: null, min: null, max: null, reason: "unavailable" },
    ];
    const geo = buildSvgChartSegments(points, 300, 100, 100);

    assert.equal(geo.linePaths.length, 0);
    assert.equal(geo.areaPaths.length, 0);
    assert.equal(geo.bandPaths.length, 0);
    assert.equal(geo.gapSpans.length, 0);
    assert.deepEqual(geo.unavailableSpans, [
      { x1: 0, x2: 300, startTs: 1000, endTs: 1120 },
    ]);
  });

  it("builds bandPaths for rollup points where min < max", () => {
    const points: VmMetricSeriesPoint[] = [
      { ts: 1000, avg: 50, min: 30, max: 70, reason: null },
      { ts: 4600, avg: 60, min: 40, max: 80, reason: null },
    ];
    const geo = buildSvgChartSegments(points, 100, 100, 100);

    assert.equal(geo.linePaths.length, 1);
    assert.equal(geo.bandPaths.length, 1);
    assert.equal(geo.bandPaths[0], "M 0 30 L 100 20 L 100 60 L 0 70 Z");
  });
});

describe("vmMetricsHelpers - formatting", () => {
  it("formats byte values with binary IEC units and handles missing/NaN inputs", () => {
    assert.equal(formatMetricBytes(null), "—");
    assert.equal(formatMetricBytes(undefined), "—");
    assert.equal(formatMetricBytes(Number.NaN), "—");
    assert.equal(formatMetricBytes(0), "0 B");
    assert.equal(formatMetricBytes(512), "512 B");
    assert.equal(formatMetricBytes(1536), "1.5 KiB");
    assert.equal(formatMetricBytes(1024 * 1024 * 2.5), "2.5 MiB");
    assert.equal(formatMetricBytes(1024 ** 3 * 1.25), "1.25 GiB");
    assert.equal(formatMetricBytes(1024 ** 4 * 2), "2.00 TiB");
  });

  it("formats throughput rates in B/s with binary IEC units", () => {
    assert.equal(formatMetricRateBps(null), "—");
    assert.equal(formatMetricRateBps(undefined), "—");
    assert.equal(formatMetricRateBps(Number.NaN), "—");
    assert.equal(formatMetricRateBps(500), "500 B/s");
    assert.equal(formatMetricRateBps(2048), "2.0 KiB/s");
    assert.equal(formatMetricRateBps(1024 * 1024), "1.0 MiB/s");
  });

  it("formats timestamps according to selected range", () => {
    const ts = 1768478400; // 2026-01-15T12:00:00Z
    const shortLabel = formatViewerTimestamp(ts, "1h", "en-US");
    const longLabel = formatViewerTimestamp(ts, "7d", "en-US");
    assert.ok(shortLabel.length > 0 && shortLabel !== "—");
    assert.ok(longLabel.length > shortLabel.length);
  });
});
