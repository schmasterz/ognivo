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
export function hostRamMbToGb(mb?: number | null): number {
  return Math.round((mb || HOST_DEFAULTS.ramMbFallback) / 1024);
}

export type HostDriver = "incus" | "lxd" | "fake";
export type HostFormMode = "token" | "cert" | "edit";

export interface HostFormCapacityValues {
  totalCpuCores: number;
  totalRamGb: number;
  totalStorageGb: number;
  cpuOvercommit: number;
  ramOvercommit: number;
  diskOvercommit: number;
}

export interface HostFormCommonValues<TImages = undefined> {
  name: string;
  region: string;
  driver: HostDriver;
  isActive: boolean;
  capacity: HostFormCapacityValues;
  autoAttachImages: boolean;
  images?: TImages;
}

export interface CreateHostWithTokenFormValues<TImages = undefined>
  extends HostFormCommonValues<TImages> {
  mode: "token";
  token: string;
  autoDiscoverCapacity: boolean;
}

export interface CreateHostWithCertFormValues<TImages = undefined>
  extends HostFormCommonValues<TImages> {
  mode: "cert";
  endpointUrl: string;
  expectedFingerprint: string;
  autoDiscoverCapacity: boolean;
}

export interface EditHostFormValues<TImages = undefined>
  extends HostFormCommonValues<TImages> {
  mode: "edit";
  endpointUrl: string;
  token?: string;
  tokenModified?: boolean;
}

export type HostFormValues<TImages = undefined> =
  | CreateHostWithTokenFormValues<TImages>
  | CreateHostWithCertFormValues<TImages>
  | EditHostFormValues<TImages>;

export type HostFormValidationValues =
  | Pick<
      CreateHostWithTokenFormValues,
      "mode" | "name" | "token" | "autoDiscoverCapacity" | "capacity"
    >
  | Pick<
      CreateHostWithCertFormValues,
      | "mode"
      | "name"
      | "endpointUrl"
      | "expectedFingerprint"
      | "autoDiscoverCapacity"
      | "capacity"
    >
  | Pick<EditHostFormValues, "mode" | "name" | "endpointUrl" | "capacity">;

export interface ExistingHostCapacitySnapshot {
  total_cpu_cores?: number | null;
  total_ram_mb?: number | null;
  total_storage_gb?: number | null;
}

export interface SharedHostPayloadFields {
  name: string;
  driver: HostDriver;
  region?: string;
  is_active: boolean;
  cpu_overcommit: number;
  ram_overcommit: number;
  disk_overcommit: number;
  auto_attach_images: boolean;
}

export interface CreateHostBasePayload<TImages = undefined>
  extends SharedHostPayloadFields {
  total_cpu_cores?: number;
  total_ram_mb?: number;
  total_storage_gb?: number;
  images?: TImages;
}

export interface CreateHostWithTokenPayload<TImages = undefined>
  extends CreateHostBasePayload<TImages> {
  token: string;
}

export interface CreateHostWithCertPayload<TImages = undefined>
  extends CreateHostBasePayload<TImages> {
  address: string;
  expected_fingerprint: string;
}

export interface UpdateHostPayload<TImages = undefined>
  extends SharedHostPayloadFields {
  endpoint_url: string;
  total_cpu_cores?: number;
  total_ram_mb?: number;
  total_storage_gb?: number;
  token?: string | null;
  images?: TImages;
}

export type CreateHostWithTokenPayloadInput<TImages = undefined> = Omit<
  CreateHostWithTokenFormValues<TImages>,
  "mode"
> & { mode?: "token" };

export type CreateHostWithCertPayloadInput<TImages = undefined> = Omit<
  CreateHostWithCertFormValues<TImages>,
  "mode"
> & { mode?: "cert" };

export type UpdateHostPayloadInput<TImages = undefined> = Omit<
  EditHostFormValues<TImages>,
  "mode"
> & { mode?: "edit" };

function isFormCapacityValid(
  capacity: HostFormCapacityValues,
  skipTotals: boolean
): boolean {
  return isCapacityValid(
    {
      cpuCores: capacity.totalCpuCores,
      ramGb: capacity.totalRamGb,
      storageGb: capacity.totalStorageGb,
      cpuOvercommit: capacity.cpuOvercommit,
      ramOvercommit: capacity.ramOvercommit,
      diskOvercommit: capacity.diskOvercommit,
    },
    { skipTotals }
  );
}

/**
 * Validates host form fields across token-create, certificate-create, and edit modes.
 * Skips capacity total minimums only when auto-discover is enabled in create modes.
 */
export function validateHostForm(values: HostFormValidationValues): boolean {
  if (!isHostNameValid(values.name)) {
    return false;
  }

  switch (values.mode) {
    case "token":
      return (
        isTokenValid(values.token) &&
        isFormCapacityValid(values.capacity, values.autoDiscoverCapacity)
      );
    case "cert":
      return (
        isValidHttpUrl(values.endpointUrl) &&
        isFingerprintValid(values.expectedFingerprint) &&
        isFormCapacityValid(values.capacity, values.autoDiscoverCapacity)
      );
    case "edit":
      return (
        isValidHttpUrl(values.endpointUrl) &&
        isFormCapacityValid(values.capacity, false)
      );
  }
}

function buildSharedHostPayload<TImages>(
  values: HostFormCommonValues<TImages>
): SharedHostPayloadFields {
  return {
    name: values.name.trim().toLowerCase(),
    driver: values.driver,
    region: values.region.trim() || undefined,
    is_active: values.isActive,
    cpu_overcommit: values.capacity.cpuOvercommit,
    ram_overcommit: values.capacity.ramOvercommit,
    disk_overcommit: values.capacity.diskOvercommit,
    auto_attach_images: values.autoAttachImages,
  };
}

function buildCreateHostBasePayload<TImages>(
  values: HostFormCommonValues<TImages> & { autoDiscoverCapacity: boolean }
): CreateHostBasePayload<TImages> {
  return {
    ...buildSharedHostPayload(values),
    ...(values.autoDiscoverCapacity
      ? {}
      : {
          total_cpu_cores: values.capacity.totalCpuCores,
          total_ram_mb: values.capacity.totalRamGb * 1024,
          total_storage_gb: values.capacity.totalStorageGb,
        }),
    ...(values.images !== undefined ? { images: values.images } : {}),
  };
}

/** Builds the API payload for creating a host via single-use trust token. */
export function buildCreateHostWithTokenPayload<TImages = undefined>(
  values: CreateHostWithTokenPayloadInput<TImages>
): CreateHostWithTokenPayload<TImages> {
  return {
    ...buildCreateHostBasePayload(values),
    token: values.token.trim(),
  };
}

/** Builds the API payload for creating a host via endpoint URL and pinned TLS fingerprint. */
export function buildCreateHostWithCertPayload<TImages = undefined>(
  values: CreateHostWithCertPayloadInput<TImages>
): CreateHostWithCertPayload<TImages> {
  return {
    ...buildCreateHostBasePayload(values),
    address: values.endpointUrl.trim(),
    expected_fingerprint: values.expectedFingerprint.trim().toLowerCase(),
  };
}

/**
 * Builds the API payload for updating an existing host, including capacity total fields
 * only when they differ from the existing host values (or fallback defaults).
 */
export function buildUpdateHostPayload<TImages = undefined>(
  values: UpdateHostPayloadInput<TImages>,
  existingHost: ExistingHostCapacitySnapshot
): UpdateHostPayload<TImages> {
  const payload: UpdateHostPayload<TImages> = {
    ...buildSharedHostPayload(values),
    endpoint_url: values.endpointUrl.trim(),
  };

  if (
    values.capacity.totalCpuCores !==
    (existingHost.total_cpu_cores || HOST_DEFAULTS.cpuCores)
  ) {
    payload.total_cpu_cores = values.capacity.totalCpuCores;
  }
  if (values.capacity.totalRamGb !== hostRamMbToGb(existingHost.total_ram_mb)) {
    payload.total_ram_mb = values.capacity.totalRamGb * 1024;
  }
  if (
    values.capacity.totalStorageGb !==
    (existingHost.total_storage_gb || HOST_DEFAULTS.storageGb)
  ) {
    payload.total_storage_gb = values.capacity.totalStorageGb;
  }

  if (values.tokenModified) {
    payload.token = (values.token ?? "").trim() || null;
  }

  if (values.images !== undefined) {
    payload.images = values.images;
  }

  return payload;
}
