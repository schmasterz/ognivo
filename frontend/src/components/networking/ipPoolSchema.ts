import {
  type PoolNetworkMode,
  parseCidrPrefix,
  parseCidrRange,
  validateGatewayField,
} from "./cidrUtils.ts";
import type { TranslationKey } from "../../lib/i18n/dictionaries.ts";

export type IpPoolFormMode = "create" | "edit";

export interface IpPoolFormValues {
  /**
   * Form context:
   * - "create" (default): requires hostId and validates/submits cidr; preserves Create payload semantics.
   * - "edit": treats host_id and cidr as immutable; skips gateway validation when locked and preserves Edit payload semantics.
   */
  mode?: IpPoolFormMode;
  name: string;
  hostId?: number | null;
  cidr: string;
  firstUsable?: string;
  lastUsable?: string;
  parentInterface: string;
  networkMode: PoolNetworkMode | string;
  gateway: string;
  dnsServers: string;
  excludedIps: string;
  cooldownSeconds: number;
  description?: string;
  isPublic?: boolean;
  /** Edit-only flag: true when pool has active or cooling allocations. */
  hasAllocations?: boolean;
  /** Edit-only flag: true when gateway cannot be changed (hasAllocations && L2 mode). */
  isGatewayLocked?: boolean;
}

export interface CreateIpPoolCommonPayload {
  name: string;
  first_usable?: string;
  last_usable?: string;
  parent_interface?: string;
  network_mode: string;
  gateway?: string;
  dns_servers?: string[];
  excluded_ips?: string[];
  cooldown_seconds: number;
  is_public: boolean;
  description?: string;
}

export interface EditIpPoolCommonPayload {
  name: string;
  first_usable?: string;
  last_usable?: string;
  parent_interface: string;
  network_mode?: string;
  gateway?: string | null;
  dns_servers: string[];
  excluded_ips: string[];
  cooldown_seconds: number;
  is_public: boolean;
  description: string | null;
}

export type IpPoolCommonPayload = CreateIpPoolCommonPayload | EditIpPoolCommonPayload;

function parseCommaSeparatedList(input: string): string[] {
  return input
    .split(",")
    .map((s) => s.trim())
    .filter((s) => s.length > 0);
}

/**
 * Parse a comma-separated DNS server list into trimmed, non-empty strings.
 */
export function parseDnsServers(str: string): string[] {
  return parseCommaSeparatedList(str);
}

/**
 * Parse a comma-separated excluded IP list into trimmed, non-empty strings.
 */
export function parseExcludedIps(str: string): string[] {
  return parseCommaSeparatedList(str);
}

/**
 * Validate IP pool form fields and return a field-error map compatible with modal `fieldErrors`.
 *
 * Documented differences between CreateIpPoolModal and EditIpPoolModal (not silently unified):
 * 1. `host_id`: Create requires `selectedHostId`; Edit treats host node as immutable and omits it.
 * 2. `cidr`: Create has an editable required `cidr` input; Edit reads immutable `pool.cidr || pool.subnet`.
 *    (Neither modal's handleSubmit currently checks `parseCidrRange` directly, relying on backend validation;
 *    this schema validates `parseCidrPrefix`/`parseCidrRange` when CIDR is provided or required in create mode.)
 * 3. `gateway`: Create always calls `validateGatewayField`; Edit skips it when `isGatewayLocked`
 *    (`hasAllocations && isL2Mode`).
 * 4. `name` and `cooldown_seconds`: Currently enforced only via HTML input attributes (`required`, `min={0}`)
 *    in both modals rather than inside `handleSubmit`; collected here for consistent validation.
 * 5. Execution flow: Both modals currently short-circuit on the first JS validation failure, whereas
 *    `validateIpPoolForm` collects all field errors into `Record<string, string[]>`.
 */
export function validateIpPoolForm(
  values: IpPoolFormValues,
  t: (key: TranslationKey) => string
): Record<string, string[]> {
  const errors: Record<string, string[]> = {};
  const formMode: IpPoolFormMode = values.mode ?? "create";

  if (!values.name.trim()) {
    errors.name = [t("poolNameRequired")];
  }

  // Difference: host_id is required only in Create; Edit treats host node as immutable.
  if (formMode !== "edit" && (values.mode === "create" || values.hostId === null) && !values.hostId) {
    errors.host_id = [t("selectTargetHostNode")];
  }

  const trimmedParentInterface = values.parentInterface.trim();
  if (values.networkMode !== "manual" && !trimmedParentInterface) {
    errors.parent_interface = [t("parentInterfaceRequired")];
  }

  const trimmedCidr = values.cidr.trim();
  const isL2Mode = values.networkMode === "bridged" || values.networkMode === "macvlan";
  const cidrPrefix = parseCidrPrefix(trimmedCidr);
  const cidrRange = parseCidrRange(trimmedCidr);

  // Difference: Create treats CIDR as an editable required field; Edit reads immutable pool.cidr/subnet.
  if (!trimmedCidr) {
    if (formMode === "create") {
      errors.cidr = [t("validIpv4PrefixHint")];
    }
  } else if (cidrPrefix === null || cidrRange === null) {
    errors.cidr = [t("validIpv4PrefixHint")];
  } else if (isL2Mode && (cidrPrefix === 31 || cidrPrefix === 32)) {
    errors.cidr = [t("l2ModePrefixUnsupported")];
  }

  // Difference: Edit skips gateway validation when isGatewayLocked (hasAllocations && isL2Mode).
  const isGatewayLocked =
    values.isGatewayLocked ??
    (formMode === "edit" && Boolean(values.hasAllocations) && isL2Mode);

  if (!isGatewayLocked) {
    const gatewayErr = validateGatewayField(
      values.gateway,
      values.cidr,
      values.networkMode,
      t
    );
    if (gatewayErr) {
      errors.gateway = [gatewayErr];
    }
  }

  const cooldown = Number(values.cooldownSeconds);
  if (!Number.isFinite(cooldown) || cooldown < 0) {
    errors.cooldown_seconds = [t("cooldownSecondsInvalid")];
  }

  return errors;
}

/**
 * Build the common subset of API payload fields shared by CreateIpPoolModal and EditIpPoolModal.
 * Modal-exclusive fields (`host_id` and `cidr` on Create; `active` on Edit) are omitted.
 *
 * Documented Create vs Edit payload differences preserved via `values.mode`:
 * 1. `name`: both Create and Edit send `name.trim()`.
 * 2. `parent_interface`: Create omits the field when `networkMode === "manual"`; Edit sends `""`.
 * 3. `network_mode`: Create always sends `networkMode`; Edit omits it when `hasAllocations` is true.
 * 4. `gateway`: Create sends `undefined` when empty; Edit omits when `isGatewayLocked` and sends `null` when empty.
 * 5. `dns_servers` / `excluded_ips`: Create sends `undefined` for empty lists; Edit always sends arrays (including `[]`).
 * 6. `description`: Create sends `undefined` when empty; Edit sends `null` when empty.
 * 7. `cooldown_seconds`: `0` is preserved; invalid or negative values fall back to 300.
 */
export function buildIpPoolPayload(
  values: IpPoolFormValues & { mode?: "create" }
): CreateIpPoolCommonPayload;
export function buildIpPoolPayload(
  values: IpPoolFormValues & { mode: "edit" }
): EditIpPoolCommonPayload;
export function buildIpPoolPayload(values: IpPoolFormValues): IpPoolCommonPayload;
export function buildIpPoolPayload(values: IpPoolFormValues): IpPoolCommonPayload {
  const formMode: IpPoolFormMode = values.mode ?? "create";
  const trimmedParentInterface = values.parentInterface.trim();
  const parsedDns = parseDnsServers(values.dnsServers);
  const parsedExcluded = parseExcludedIps(values.excludedIps);
  const firstUsable = values.firstUsable?.trim() || undefined;
  const lastUsable = values.lastUsable?.trim() || undefined;
  const trimmedGateway = values.gateway.trim();
  const trimmedDescription = values.description?.trim() ?? "";
  // 0 is a valid cooldown; only invalid or negative values fall back to the default.
  const rawCooldown = Number(values.cooldownSeconds);
  const cooldownSeconds = Number.isFinite(rawCooldown) && rawCooldown >= 0 ? rawCooldown : 300;
  const isPublic = values.isPublic ?? true;

  if (formMode === "edit") {
    const isL2Mode = values.networkMode === "bridged" || values.networkMode === "macvlan";
    const isGatewayLocked =
      values.isGatewayLocked ?? (Boolean(values.hasAllocations) && isL2Mode);

    return {
      name: values.name.trim(),
      description: trimmedDescription || null,
      dns_servers: parsedDns,
      excluded_ips: parsedExcluded,
      cooldown_seconds: cooldownSeconds,
      is_public: isPublic,
      ...(isGatewayLocked ? {} : { gateway: trimmedGateway || null }),
      first_usable: firstUsable,
      last_usable: lastUsable,
      parent_interface: values.networkMode === "manual" ? "" : trimmedParentInterface,
      ...(values.hasAllocations ? {} : { network_mode: values.networkMode }),
    };
  }

  return {
    name: values.name.trim(),
    first_usable: firstUsable,
    last_usable: lastUsable,
    ...(values.networkMode !== "manual" ? { parent_interface: trimmedParentInterface } : {}),
    network_mode: values.networkMode,
    gateway: trimmedGateway || undefined,
    dns_servers: parsedDns.length > 0 ? parsedDns : undefined,
    excluded_ips: parsedExcluded.length > 0 ? parsedExcluded : undefined,
    cooldown_seconds: cooldownSeconds,
    is_public: isPublic,
    description: trimmedDescription || undefined,
  };
}
