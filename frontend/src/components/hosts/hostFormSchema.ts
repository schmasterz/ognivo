/**
 * Shared validation rules and default values for host create/edit forms.
 * Keeps client-side form constraints aligned across CreateHostModal and EditHostModal.
 */

export const HOST_DEFAULTS = {
  cpuCores: 64,
  ramGb: 256,
  storageGb: 4000,
  ramMbFallback: 262144,
  overcommit: 1.0,
};

export const CPU_OVERCOMMIT_MAX = 16;
export const RAM_OVERCOMMIT_MAX = 4;
export const DISK_OVERCOMMIT_MAX = 4;
export const MIN_STORAGE_GB = 10;

export interface HostCapacityInput {
  cpuCores: number;
  ramGb: number;
  storageGb: number;
  cpuOvercommit: number;
  ramOvercommit: number;
  diskOvercommit: number;
}

export interface HostCapacityValidationOptions {
  skipTotals?: boolean;
}

/** Validates a host slug name (trimmed length >= 2, lowercase alphanumeric and hyphens only). */
export function isHostNameValid(name: string): boolean {
  const trimmed = name.trim();
  return trimmed.length >= 2 && /^[a-z0-9-]+$/.test(trimmed);
}

/** Validates an HTTP or HTTPS URL with a non-empty hostname. */
export function isValidHttpUrl(url: string): boolean {
  try {
    const parsed = new URL(url.trim());
    return (
      (parsed.protocol === "http:" || parsed.protocol === "https:") &&
      Boolean(parsed.hostname)
    );
  } catch {
    return false;
  }
}

/**
 * Validates host capacity totals and overcommit multipliers.
 * When `skipTotals` is true (auto-discover mode), total resource minimums are skipped
 * while overcommit ranges remain enforced.
 */
export function isCapacityValid(
  {
    cpuCores,
    ramGb,
    storageGb,
    cpuOvercommit,
    ramOvercommit,
    diskOvercommit,
  }: HostCapacityInput,
  options: HostCapacityValidationOptions = {}
): boolean {
  const totalsValid =
    Boolean(options.skipTotals) ||
    (cpuCores >= 1 && ramGb >= 1 && storageGb >= MIN_STORAGE_GB);

  const overcommitValid =
    cpuOvercommit >= 1 &&
    cpuOvercommit <= CPU_OVERCOMMIT_MAX &&
    ramOvercommit >= 1 &&
    ramOvercommit <= RAM_OVERCOMMIT_MAX &&
    diskOvercommit >= 1 &&
    diskOvercommit <= DISK_OVERCOMMIT_MAX;

  return totalsValid && overcommitValid;
}

/** Validates a trust token (trimmed length > 10). */
export function isTokenValid(token: string): boolean {
  return token.trim().length > 10;
}

/** Validates a TLS certificate fingerprint (trimmed length >= 32). */
export function isFingerprintValid(fp: string): boolean {
  return fp.trim().length >= 32;
}

/** Converts host RAM from MB to rounded GB, falling back to the default RAM MB when falsy/omitted. */
export function hostRamMbToGb(mb?: number): number {
  return Math.round((mb || HOST_DEFAULTS.ramMbFallback) / 1024);
}
