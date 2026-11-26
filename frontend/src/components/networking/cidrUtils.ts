import type { TranslationKey } from "@/lib/i18n/I18nContext";

export type PoolNetworkMode = "routed" | "bridged" | "macvlan" | "manual";

/**
 * Parse a dotted-quad IPv4 string into an unsigned 32-bit integer, or null if invalid.
 */
export function parseIpv4ToNum(ip: string): number | null {
  const trimmed = ip.trim();
  const octets = trimmed.split(".");
  if (octets.length !== 4) return null;
  const nums: number[] = [];
  for (const part of octets) {
    if (!/^\d+$/.test(part)) return null;
    if (part.length > 1 && part.startsWith("0")) return null;
    const n = Number(part);
    if (n < 0 || n > 255) return null;
    nums.push(n);
  }
  return ((nums[0] << 24) | (nums[1] << 16) | (nums[2] << 8) | nums[3]) >>> 0;
}

/**
 * Extract the prefix length (0-32) from a CIDR string, or null if invalid.
 */
export function parseCidrPrefix(cidr: string): number | null {
  const parts = cidr.trim().split("/");
  if (parts.length !== 2 || !/^\d+$/.test(parts[1])) return null;
  const prefix = parseInt(parts[1], 10);
  if (isNaN(prefix) || prefix < 0 || prefix > 32) return null;
  return prefix;
}

/**
 * Parse a /16-/32 IPv4 CIDR string into network/broadcast bounds and a formatter helper.
 */
export function parseCidrRange(cidr: string): {
  ipStr: string;
  prefix: number;
  network: number;
  broadcast: number;
  numToIp: (n: number) => string;
} | null {
  const parts = cidr.trim().split("/");
  if (parts.length !== 2 || !/^\d+$/.test(parts[1])) return null;
  const ipStr = parts[0].trim();
  const prefix = parseInt(parts[1], 10);
  if (isNaN(prefix) || prefix < 16 || prefix > 32) return null;

  const ipNum = parseIpv4ToNum(ipStr);
  if (ipNum === null) return null;

  const mask = prefix === 32 ? 0xffffffff : (0xffffffff << (32 - prefix)) >>> 0;
  const network = (ipNum & mask) >>> 0;
  const broadcast = (network | (~mask >>> 0)) >>> 0;

  const numToIp = (n: number) =>
    [(n >>> 24) & 255, (n >>> 16) & 255, (n >>> 8) & 255, n & 255].join(".");

  return { ipStr, prefix, network, broadcast, numToIp };
}

/**
 * Calculate default first/last usable IPs and gateway for a CIDR and network mode.
 */
export function calculateUsableRange(
  cidr: string,
  networkMode: PoolNetworkMode
): { first: string; last: string; gateway: string } | null {
  const range = parseCidrRange(cidr);
  if (!range) return null;

  const { ipStr, prefix, network, broadcast, numToIp } = range;

  if (prefix === 32) {
    return {
      gateway: "",
      first: ipStr,
      last: ipStr,
    };
  }

  if (prefix === 31) {
    return {
      gateway: "",
      first: numToIp(network),
      last: numToIp(broadcast),
    };
  }

  if (networkMode === "routed") {
    return {
      gateway: "",
      first: numToIp(network + 1),
      last: numToIp(broadcast - 1),
    };
  }

  return {
    gateway: numToIp(network + 1),
    first: numToIp(network + 1),
    last: numToIp(broadcast - 1),
  };
}

/**
 * Return the first validation error message for a field (including nested dotted keys).
 */
export function getFieldError(errors: Record<string, string[]>, field: string): string | null {
  if (errors[field] && errors[field].length > 0) {
    return errors[field][0];
  }
  const nestedKey = Object.keys(errors).find((k) => k.startsWith(`${field}.`));
  if (nestedKey && errors[nestedKey]?.length > 0) {
    return errors[nestedKey][0];
  }
  return null;
}

/**
 * Validate a pool gateway input against the pool CIDR and network mode.
 */
export function validateGatewayField(
  gatewayInput: string,
  cidrInput: string,
  mode: string,
  t: (key: TranslationKey) => string
): string | null {
  const trimmedGw = gatewayInput.trim();
  const isL2 = mode === "bridged" || mode === "macvlan";
  const range = parseCidrRange(cidrInput);
  const prefix = range?.prefix ?? parseCidrPrefix(cidrInput);

  if (isL2 && !trimmedGw) {
    return t("gatewayRequiredForL2Modes");
  }

  if (mode === "manual" && prefix !== null && prefix <= 30 && !trimmedGw) {
    return t("gatewayRequiredForManualSubnets");
  }

  if (trimmedGw !== "") {
    const gwNum = parseIpv4ToNum(trimmedGw);
    if (gwNum === null) {
      return t("gatewayInvalidIpv4");
    }

    if ((gwNum >>> 16) === 0xa9fe) {
      return "Link-local addresses (169.254.0.0/16) cannot be used as pool CIDR, gateway, or excluded IPs.";
    }

    if (range !== null) {
      if (gwNum < range.network || gwNum > range.broadcast) {
        return t("gatewayOutsideCidr");
      }
      if (
        range.prefix <= 30 &&
        (gwNum === range.network || gwNum === range.broadcast)
      ) {
        return t("gatewayNetworkOrBroadcast");
      }
    }
  }

  return null;
}
