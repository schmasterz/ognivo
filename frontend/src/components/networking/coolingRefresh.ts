export interface CoolingIpRow {
  status: string;
  cooldown_remaining_seconds?: number | null;
}

/**
 * Computes the delay (in milliseconds) until the next cooling IP expires,
 * adding a 1-second buffer so the backend sees it as expired.
 * Returns null when no cooling IP has a positive remaining cooldown.
 */
export function nextCoolingRefreshDelayMs(ips: ReadonlyArray<CoolingIpRow>): number | null {
  let minRemainingSeconds: number | null = null;

  for (const ip of ips) {
    if (ip.status !== "cooling") {
      continue;
    }
    const remaining = ip.cooldown_remaining_seconds;
    if (typeof remaining !== "number" || !Number.isFinite(remaining) || remaining <= 0) {
      continue;
    }
    if (minRemainingSeconds === null || remaining < minRemainingSeconds) {
      minRemainingSeconds = remaining;
    }
  }

  if (minRemainingSeconds === null) {
    return null;
  }

  return minRemainingSeconds * 1000 + 1000;
}

/**
 * Computes the absolute timestamp (in ms) when the next cooling IP refresh
 * should run, relative to `nowMs`. Returns null when no row qualifies.
 */
export function nextCoolingDeadlineMs(
  ips: ReadonlyArray<CoolingIpRow>,
  nowMs: number,
): number | null {
  const delay = nextCoolingRefreshDelayMs(ips);
  return delay === null ? null : nowMs + delay;
}
