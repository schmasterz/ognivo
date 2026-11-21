"use client";

import React, { useState, useEffect } from "react";
import { X, Network, Server, Loader2, CheckCircle2, AlertTriangle } from "lucide-react";
import { api, ApiError, IpPool, HostDiscoveredNetwork } from "@/lib/api";
import { useI18n } from "@/lib/i18n/I18nContext";
import { useModalEscape } from "@/lib/hooks/useModalEscape";

interface EditIpPoolModalProps {
  pool: IpPool;
  onClose: () => void;
  onSuccess: (updatedPool: IpPool) => void;
}

function parseIpv4ToNum(ip: string): number | null {
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

function parseCidrPrefix(cidr: string): number | null {
  const parts = cidr.trim().split("/");
  if (parts.length !== 2 || !/^\d+$/.test(parts[1])) return null;
  const prefix = parseInt(parts[1], 10);
  if (isNaN(prefix) || prefix < 0 || prefix > 32) return null;
  return prefix;
}

function parseCidrRange(cidr: string): {
  prefix: number;
  network: number;
  broadcast: number;
} | null {
  const parts = cidr.trim().split("/");
  if (parts.length !== 2 || !/^\d+$/.test(parts[1])) return null;
  const prefix = parseInt(parts[1], 10);
  if (isNaN(prefix) || prefix < 16 || prefix > 32) return null;

  const ipNum = parseIpv4ToNum(parts[0]);
  if (ipNum === null) return null;

  const mask = prefix === 32 ? 0xffffffff : (0xffffffff << (32 - prefix)) >>> 0;
  const network = (ipNum & mask) >>> 0;
  const broadcast = (network | (~mask >>> 0)) >>> 0;

  return { prefix, network, broadcast };
}

function getFieldError(errors: Record<string, string[]>, field: string): string | null {
  if (errors[field] && errors[field].length > 0) {
    return errors[field][0];
  }
  const nestedKey = Object.keys(errors).find((k) => k.startsWith(`${field}.`));
  if (nestedKey && errors[nestedKey]?.length > 0) {
    return errors[nestedKey][0];
  }
  return null;
}

export function EditIpPoolModal({ pool, onClose, onSuccess }: EditIpPoolModalProps) {
  const { t } = useI18n();

  const [name, setName] = useState(pool.name);
  const [description, setDescription] = useState(pool.description || "");
  const [networkMode, setNetworkMode] = useState<string>(pool.network_mode || "routed");
  const [parentInterface, setParentInterface] = useState<string>(pool.parent_interface ?? "");
  const [isCustomInterface, setIsCustomInterface] = useState(false);
  const [discoveredNetworks, setDiscoveredNetworks] = useState<HostDiscoveredNetwork[]>([]);
  const [discoveringHost, setDiscoveringHost] = useState(false);
  const [dnsServers, setDnsServers] = useState(
    pool.dns_servers && pool.dns_servers.length > 0 ? pool.dns_servers.join(", ") : "1.1.1.1, 8.8.8.8"
  );
  const [excludedIps, setExcludedIps] = useState(
    pool.excluded_ips && pool.excluded_ips.length > 0 ? pool.excluded_ips.join(", ") : ""
  );
  const [cooldownSeconds, setCooldownSeconds] = useState(pool.cooldown_seconds ?? 300);
  const [active, setActive] = useState(pool.active ?? true);
  const [isPublic, setIsPublic] = useState(pool.is_public ?? true);
  const [gateway, setGateway] = useState(pool.gateway || "");
  const [firstUsable, setFirstUsable] = useState(pool.first_usable || "");
  const [lastUsable, setLastUsable] = useState(pool.last_usable || "");

  const hasAllocations =
    (pool.utilization?.allocated ?? pool.allocated_ips ?? 0) > 0 ||
    (pool.utilization?.cooling ?? pool.cooling_ips ?? 0) > 0;
  const isL2Mode = networkMode === "bridged" || networkMode === "macvlan";
  const isGatewayLocked = hasAllocations && isL2Mode;

  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({});

  useModalEscape(onClose, !loading);

  const clearFieldError = (field: string) => {
    setFieldErrors((prev) => {
      if (!prev[field] && !Object.keys(prev).some((k) => k.startsWith(`${field}.`))) {
        return prev;
      }
      const next = { ...prev };
      delete next[field];
      for (const k of Object.keys(next)) {
        if (k.startsWith(`${field}.`)) {
          delete next[k];
        }
      }
      return next;
    });
  };

  useEffect(() => {
    queueMicrotask(() => {
      setName(pool.name);
      setDescription(pool.description || "");
      setNetworkMode(pool.network_mode || "routed");
      setParentInterface(pool.parent_interface ?? "");
      setIsCustomInterface(false);
      setDnsServers(pool.dns_servers && pool.dns_servers.length > 0 ? pool.dns_servers.join(", ") : "1.1.1.1, 8.8.8.8");
      setExcludedIps(pool.excluded_ips && pool.excluded_ips.length > 0 ? pool.excluded_ips.join(", ") : "");
      setCooldownSeconds(pool.cooldown_seconds ?? 300);
      setActive(pool.active ?? true);
      setIsPublic(pool.is_public ?? true);
      setGateway(pool.gateway || "");
      setFirstUsable(pool.first_usable || "");
      setLastUsable(pool.last_usable || "");
      setError(null);
      setFieldErrors({});
      setLoading(false);
    });
  }, [pool]);

  useEffect(() => {
    const hostId = pool.host_id ?? pool.host?.id;
    if (!hostId) return;
    let cancelled = false;
    queueMicrotask(() => {
      if (!cancelled) {
        setDiscoveringHost(true);
      }
    });
    api.adminGetHostResources(hostId)
      .then((res) => {
        if (!cancelled) {
          setDiscoveredNetworks(res.networks || []);
        }
      })
      .catch(() => {
        if (!cancelled) setDiscoveredNetworks([]);
      })
      .finally(() => {
        if (!cancelled) setDiscoveringHost(false);
      });
    return () => {
      cancelled = true;
    };
  }, [pool.host_id, pool.host?.id]);

  const validateGatewayField = (gatewayInput: string, cidrInput: string, mode: string): string | null => {
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

      if (range !== null) {
        if (gwNum < range.network || gwNum > range.broadcast) {
          return t("gatewayOutsideCidr");
        }
        if (
          (isL2 || mode === "routed") &&
          range.prefix <= 30 &&
          (gwNum === range.network || gwNum === range.broadcast)
        ) {
          return t("gatewayNetworkOrBroadcast");
        }
      }
    }

    return null;
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setError(null);
    setFieldErrors({});

    const trimmedParentInterface = parentInterface.trim();
    if (networkMode !== "manual" && !trimmedParentInterface) {
      const msg = t("parentInterfaceRequired");
      setFieldErrors({ parent_interface: [msg] });
      setError(msg);
      return;
    }

    const poolCidr = pool.cidr || pool.subnet || "";
    const cidrPrefix = parseCidrPrefix(poolCidr);
    if (isL2Mode && (cidrPrefix === 31 || cidrPrefix === 32)) {
      const msg = t("l2ModePrefixUnsupported");
      setFieldErrors({ cidr: [msg] });
      setError(msg);
      return;
    }

    if (!isGatewayLocked) {
      const gatewayErr = validateGatewayField(gateway, poolCidr, networkMode);
      if (gatewayErr) {
        setFieldErrors({ gateway: [gatewayErr] });
        setError(gatewayErr);
        return;
      }
    }

    try {
      setLoading(true);

      const parsedDns = dnsServers
        .split(",")
        .map((s) => s.trim())
        .filter((s) => s.length > 0);

      const parsedExcluded = excludedIps
        .split(",")
        .map((s) => s.trim())
        .filter((s) => s.length > 0);

      const res = await api.adminUpdateIpPool(pool.id, {
        name: name.trim(),
        description: description.trim() || null,
        dns_servers: parsedDns,
        excluded_ips: parsedExcluded,
        cooldown_seconds: Number(cooldownSeconds) || 300,
        active,
        is_public: isPublic,
        ...(isGatewayLocked ? {} : { gateway: gateway.trim() || null }),
        first_usable: firstUsable.trim() || undefined,
        last_usable: lastUsable.trim() || undefined,
        ...(networkMode !== "manual" ? { parent_interface: trimmedParentInterface } : {}),
        ...(hasAllocations
          ? {}
          : {
              network_mode: networkMode,
            }),
      });

      onSuccess(res.pool);
      onClose();
    } catch (err: unknown) {
      if (err instanceof ApiError) {
        if (err.errors) {
          setFieldErrors(err.errors);
        }
        setError(err.message);
      } else if (err instanceof Error) {
        setError(err.message);
      } else {
        setError(t("failedToUpdatePool"));
      }
    } finally {
      setLoading(false);
    }
  };

  const poolCidr = pool.cidr || pool.subnet || "";
  const cidrPrefix = parseCidrPrefix(poolCidr);
  const isGatewayRequired =
    !isGatewayLocked &&
    (isL2Mode || (networkMode === "manual" && cidrPrefix !== null && cidrPrefix <= 30));

  const nameError = getFieldError(fieldErrors, "name");
  const networkModeError = getFieldError(fieldErrors, "network_mode");
  const parentInterfaceError = getFieldError(fieldErrors, "parent_interface");
  const cidrError = getFieldError(fieldErrors, "cidr");
  const gatewayError = getFieldError(fieldErrors, "gateway");
  const firstUsableError = getFieldError(fieldErrors, "first_usable");
  const lastUsableError = getFieldError(fieldErrors, "last_usable");
  const dnsServersError = getFieldError(fieldErrors, "dns_servers");
  const cooldownError = getFieldError(fieldErrors, "cooldown_seconds");
  const excludedIpsError = getFieldError(fieldErrors, "excluded_ips");
  const descriptionError = getFieldError(fieldErrors, "description");

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-sm animate-fadeIn">
      <div className="relative w-full max-w-xl bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-3xl p-6 sm:p-8 shadow-2xl overflow-y-auto max-h-[90vh] transition-colors">
        <div className="flex items-center justify-between pb-4 mb-6 border-b border-gray-100 dark:border-gray-800">
          <div className="flex items-center space-x-3">
            <div className="p-2.5 rounded-2xl bg-teal-500/10 text-teal-600 dark:text-teal-400">
              <Network className="w-6 h-6" />
            </div>
            <div>
              <h2 className="text-xl font-bold text-gray-900 dark:text-white tracking-tight">{t("editPoolTitle")}</h2>
              <p className="text-xs text-gray-500 dark:text-gray-400">{t("editPoolSubtitle")}</p>
            </div>
          </div>
          <button
            onClick={onClose}
            className="p-2 rounded-xl text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-800 transition"
          >
            <X className="w-5 h-5" />
          </button>
        </div>

        {error && (
          <div className="mb-5 p-3.5 rounded-xl bg-red-500/10 border border-red-500/20 text-xs text-red-600 dark:text-red-400">
            {error}
          </div>
        )}

        {hasAllocations && (
          <div className="mb-5 p-3.5 rounded-xl bg-amber-500/10 border border-amber-500/20 text-xs text-amber-700 dark:text-amber-300 flex items-start space-x-2">
            <AlertTriangle className="w-4 h-4 flex-shrink-0 mt-0.5" />
            <span>
              {t("poolHasAllocationsWarning")}
            </span>
          </div>
        )}

        <form onSubmit={handleSubmit} className="space-y-4">
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <label className="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1.5">
                {t("poolName")}
              </label>
              <input
                type="text"
                required
                value={name}
                onChange={(e) => {
                  setName(e.target.value);
                  clearFieldError("name");
                }}
                className={`w-full px-3.5 py-2.5 rounded-xl bg-gray-50 dark:bg-gray-800/60 border ${
                  nameError ? "border-red-500 dark:border-red-500/70" : "border-gray-200 dark:border-gray-700"
                } text-sm text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-teal-500/50`}
              />
              {nameError && <p className="text-[11px] text-red-500 dark:text-red-400 mt-1">{nameError}</p>}
            </div>

            <div>
              <label className="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1.5 flex items-center space-x-1.5">
                <Server className="w-3.5 h-3.5 text-teal-500" />
                <span>{t("hostNodeLabel")}</span>
              </label>
              <div className="w-full px-3.5 py-2.5 rounded-xl bg-gray-100 dark:bg-gray-800 border border-gray-200 dark:border-gray-700 text-sm text-gray-500 dark:text-gray-400">
                {pool.host?.name || t("globalNode")}
              </div>
            </div>
          </div>

          <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <label className="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1.5">
                {t("networkMode")}
              </label>
              <select
                value={networkMode}
                disabled={hasAllocations}
                onChange={(e) => {
                  setNetworkMode(e.target.value);
                  clearFieldError("network_mode");
                  clearFieldError("gateway");
                  clearFieldError("cidr");
                }}
                className={`w-full px-3.5 py-2.5 rounded-xl bg-gray-50 dark:bg-gray-800/60 border ${
                  networkModeError ? "border-red-500 dark:border-red-500/70" : "border-gray-200 dark:border-gray-700"
                } text-sm text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-teal-500/50 disabled:opacity-60`}
              >
                <option value="routed">{t("modeRoutedOption")}</option>
                <option value="bridged">{t("modeBridgedOption")}</option>
                <option value="macvlan">{t("modeMacvlanOption")}</option>
                <option value="manual">{t("modeManual")}</option>
              </select>
              {networkModeError && (
                <p className="text-[11px] text-red-500 dark:text-red-400 mt-1">{networkModeError}</p>
              )}
              <span className="text-[11px] text-gray-400 mt-1 block">
                {networkMode === "manual"
                  ? t("manualWarning")
                  : networkMode === "bridged"
                    ? t("modeBridgedDesc")
                    : networkMode === "macvlan"
                      ? t("modeMacvlanDesc")
                      : t("modeRoutedDesc")}
              </span>
            </div>

            {networkMode !== "manual" && (
              <div>
                <label className="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1.5 flex items-center justify-between">
                  <span>{t("hostParentInterface")}</span>
                  {discoveringHost && <Loader2 className="w-3 h-3 animate-spin text-teal-500" />}
                </label>
                <select
                  required
                  value={isCustomInterface ? "__custom__" : parentInterface}
                  onChange={(e) => {
                    clearFieldError("parent_interface");
                    if (e.target.value === "__custom__") {
                      setIsCustomInterface(true);
                    } else {
                      setIsCustomInterface(false);
                      setParentInterface(e.target.value);
                    }
                  }}
                  className={`w-full px-3.5 py-2.5 rounded-xl bg-gray-50 dark:bg-gray-800/60 border ${
                    parentInterfaceError ? "border-red-500 dark:border-red-500/70" : "border-gray-200 dark:border-gray-700"
                  } text-sm text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-teal-500/50 font-mono`}
                >
                  {discoveringHost && discoveredNetworks.length === 0 ? (
                    <option value="" disabled>{t("detectingHostInterfaces")}</option>
                  ) : (
                    <option value="" disabled>{t("selectHostInterface")}</option>
                  )}
                  {discoveredNetworks.length === 0 && !discoveringHost && (
                    <>
                      <option value="eno1">eno1</option>
                      <option value="incusbr0">incusbr0</option>
                      <option value="lxdbr0">lxdbr0</option>
                    </>
                  )}
                  {discoveredNetworks.map((net) => (
                    <option key={net.name} value={net.name}>
                      {net.name} ({net.type}{net.addresses && net.addresses.length > 0 ? `: ${net.addresses.join(", ")}` : ""})
                    </option>
                  ))}
                  {parentInterface &&
                    !isCustomInterface &&
                    !discoveredNetworks.some((n) => n.name === parentInterface) && (
                      <option value={parentInterface}>
                        {parentInterface} ({t("currentLabel")})
                      </option>
                    )}
                  <option value="__custom__">{t("customInterfaceOption")}</option>
                </select>

                {isCustomInterface && (
                  <div className="mt-2">
                    <input
                      type="text"
                      required
                      value={parentInterface}
                      onChange={(e) => {
                        setParentInterface(e.target.value);
                        clearFieldError("parent_interface");
                      }}
                      placeholder={t("customInterfacePlaceholder")}
                      className={`w-full px-3.5 py-2 rounded-xl bg-gray-50 dark:bg-gray-800/60 border ${
                        parentInterfaceError ? "border-red-500 dark:border-red-500/70" : "border-gray-200 dark:border-gray-700"
                      } text-sm text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-teal-500/50 font-mono`}
                      autoFocus
                    />
                  </div>
                )}
                {parentInterfaceError && (
                  <p className="text-[11px] text-red-500 dark:text-red-400 mt-1">{parentInterfaceError}</p>
                )}
                {hasAllocations ? (
                  <span className="text-[11px] text-amber-500 mt-1 block">{t("parentInterfaceAllocationsHint")}</span>
                ) : (
                  <span className="text-[11px] text-gray-400 mt-1 block">{t("hostParentInterfaceHint")}</span>
                )}
              </div>
            )}
          </div>

          <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <label className="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1.5">
                {t("cidrSubnetImmutable")}
              </label>
              <div className="w-full px-3.5 py-2.5 rounded-xl bg-gray-100 dark:bg-gray-800 border border-gray-200 dark:border-gray-700 text-sm font-mono text-gray-600 dark:text-gray-300">
                {pool.cidr || pool.subnet}
              </div>
              {cidrError && <p className="text-[11px] text-red-500 dark:text-red-400 mt-1">{cidrError}</p>}
            </div>

            <div>
              <label className="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1.5">
                {isL2Mode ? (
                  <>
                    {t("gatewayIpLabel")} <span className="text-red-500">*</span>
                  </>
                ) : networkMode === "routed" ? (
                  t("gatewayIpOptionalReserved")
                ) : cidrPrefix !== null && cidrPrefix <= 30 ? (
                  <>
                    {t("gatewayIpLabel")} <span className="text-red-500">*</span>
                  </>
                ) : (
                  t("gatewayIpOptionalSmallSubnets")
                )}
              </label>
              <input
                type="text"
                required={isGatewayRequired}
                disabled={isGatewayLocked}
                readOnly={isGatewayLocked}
                value={gateway}
                onChange={(e) => {
                  setGateway(e.target.value);
                  clearFieldError("gateway");
                }}
                placeholder="203.0.113.1"
                className={`w-full px-3.5 py-2.5 rounded-xl bg-gray-50 dark:bg-gray-800/60 border ${
                  gatewayError ? "border-red-500 dark:border-red-500/70" : "border-gray-200 dark:border-gray-700"
                } text-sm text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-teal-500/50 font-mono disabled:opacity-60 disabled:cursor-not-allowed`}
              />
              {gatewayError && (
                <p className="text-[11px] text-red-500 dark:text-red-400 mt-1">{gatewayError}</p>
              )}
              {isGatewayLocked ? (
                <span className="text-[11px] text-amber-500 mt-1 block">{t("gatewayImmutableAllocatedDesc")}</span>
              ) : (
                <span className="text-[11px] text-gray-400 mt-1 block">
                  {networkMode === "routed" ? t("gatewayRoutedHint") : t("gatewayReservedDesc")}
                </span>
              )}
            </div>
          </div>

          <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <label className="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1.5">
                {t("firstUsableIp")}
              </label>
              <input
                type="text"
                value={firstUsable}
                onChange={(e) => {
                  setFirstUsable(e.target.value);
                  clearFieldError("first_usable");
                }}
                className={`w-full px-3.5 py-2.5 rounded-xl bg-gray-50 dark:bg-gray-800/60 border ${
                  firstUsableError ? "border-red-500 dark:border-red-500/70" : "border-gray-200 dark:border-gray-700"
                } text-xs text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-teal-500/50 font-mono`}
              />
              {firstUsableError && (
                <p className="text-[11px] text-red-500 dark:text-red-400 mt-1">{firstUsableError}</p>
              )}
            </div>

            <div>
              <label className="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1.5">
                {t("lastUsableIp")}
              </label>
              <input
                type="text"
                value={lastUsable}
                onChange={(e) => {
                  setLastUsable(e.target.value);
                  clearFieldError("last_usable");
                }}
                className={`w-full px-3.5 py-2.5 rounded-xl bg-gray-50 dark:bg-gray-800/60 border ${
                  lastUsableError ? "border-red-500 dark:border-red-500/70" : "border-gray-200 dark:border-gray-700"
                } text-xs text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-teal-500/50 font-mono`}
              />
              {lastUsableError && (
                <p className="text-[11px] text-red-500 dark:text-red-400 mt-1">{lastUsableError}</p>
              )}
            </div>
          </div>

          <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <label className="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1.5">
                {t("dnsServersLabel")}
              </label>
              <input
                type="text"
                value={dnsServers}
                onChange={(e) => {
                  setDnsServers(e.target.value);
                  clearFieldError("dns_servers");
                }}
                placeholder="1.1.1.1, 8.8.8.8"
                className={`w-full px-3.5 py-2.5 rounded-xl bg-gray-50 dark:bg-gray-800/60 border ${
                  dnsServersError ? "border-red-500 dark:border-red-500/70" : "border-gray-200 dark:border-gray-700"
                } text-sm text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-teal-500/50`}
              />
              {dnsServersError && (
                <p className="text-[11px] text-red-500 dark:text-red-400 mt-1">{dnsServersError}</p>
              )}
            </div>

            <div>
              <label className="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1.5">
                {t("cooldownQuarantineSeconds")}
              </label>
              <input
                type="number"
                min={0}
                max={86400}
                value={cooldownSeconds}
                onChange={(e) => {
                  setCooldownSeconds(Number(e.target.value));
                  clearFieldError("cooldown_seconds");
                }}
                className={`w-full px-3.5 py-2.5 rounded-xl bg-gray-50 dark:bg-gray-800/60 border ${
                  cooldownError ? "border-red-500 dark:border-red-500/70" : "border-gray-200 dark:border-gray-700"
                } text-sm text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-teal-500/50`}
              />
              {cooldownError && (
                <p className="text-[11px] text-red-500 dark:text-red-400 mt-1">{cooldownError}</p>
              )}
            </div>
          </div>

          <div>
            <label className="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1.5">
              {t("excludedReservedIps")}
            </label>
            {gateway.trim() && (
              <div className="mb-2 flex flex-wrap items-center gap-2">
                <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg text-[11px] font-medium bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 border border-indigo-500/20">
                  <span className="font-semibold">{t("gatewayReservedBadgeLabel")}:</span>
                  <span className="font-mono">{gateway.trim()}</span>
                </span>
                <span className="text-[11px] text-gray-400">{t("gatewayReservedDesc")}</span>
              </div>
            )}
            <input
              type="text"
              value={excludedIps}
              onChange={(e) => {
                setExcludedIps(e.target.value);
                clearFieldError("excluded_ips");
              }}
              placeholder={t("excludedIpsPlaceholder")}
              className={`w-full px-3.5 py-2.5 rounded-xl bg-gray-50 dark:bg-gray-800/60 border ${
                excludedIpsError ? "border-red-500 dark:border-red-500/70" : "border-gray-200 dark:border-gray-700"
              } text-sm text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-teal-500/50`}
            />
            {excludedIpsError ? (
              <p className="text-[11px] text-red-500 dark:text-red-400 mt-1">{excludedIpsError}</p>
            ) : (
              <span className="text-[11px] text-gray-400 mt-1 block">{t("excludedIpsHint")}</span>
            )}
          </div>

          <div>
            <label className="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1.5">
              {t("description")}
            </label>
            <input
              type="text"
              value={description}
              onChange={(e) => {
                setDescription(e.target.value);
                clearFieldError("description");
              }}
              className={`w-full px-3.5 py-2.5 rounded-xl bg-gray-50 dark:bg-gray-800/60 border ${
                descriptionError ? "border-red-500 dark:border-red-500/70" : "border-gray-200 dark:border-gray-700"
              } text-sm text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-teal-500/50`}
            />
            {descriptionError && (
              <p className="text-[11px] text-red-500 dark:text-red-400 mt-1">{descriptionError}</p>
            )}
          </div>

          <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div className="p-3.5 rounded-xl bg-gray-50 dark:bg-gray-800/40 border border-gray-200 dark:border-gray-700/60">
              <label className="flex items-center space-x-2.5 cursor-pointer">
                <input
                  type="checkbox"
                  checked={isPublic}
                  onChange={(e) => setIsPublic(e.target.checked)}
                  className="rounded border-gray-300 text-teal-600 focus:ring-teal-500"
                />
                <span className="text-xs font-medium text-gray-800 dark:text-gray-200">{t("isPublic")}</span>
              </label>
            </div>

            <div className="p-3.5 rounded-xl bg-gray-50 dark:bg-gray-800/40 border border-gray-200 dark:border-gray-700/60">
              <label className="flex items-center space-x-2.5 cursor-pointer">
                <input
                  type="checkbox"
                  checked={active}
                  onChange={(e) => setActive(e.target.checked)}
                  className="rounded border-gray-300 text-teal-600 focus:ring-teal-500"
                />
                <span className="text-xs font-medium text-gray-800 dark:text-gray-200">{t("poolEnabledActive")}</span>
              </label>
            </div>
          </div>

          <div className="flex items-center justify-end space-x-3 pt-4 border-t border-gray-100 dark:border-gray-800">
            <button
              type="button"
              onClick={onClose}
              disabled={loading}
              className="px-4 py-2.5 rounded-xl text-xs font-medium text-gray-600 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-800 transition"
            >
              {t("cancel")}
            </button>
            <button
              type="submit"
              disabled={loading}
              className="flex items-center space-x-2 px-5 py-2.5 rounded-xl bg-teal-600 hover:bg-teal-500 text-white text-xs font-semibold shadow-md shadow-teal-500/20 transition disabled:opacity-50"
            >
              {loading ? <Loader2 className="w-4 h-4 animate-spin" /> : <CheckCircle2 className="w-4 h-4" />}
              <span>{t("editPoolAction")}</span>
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}
