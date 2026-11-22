"use client";

import React, { useState, useEffect, useRef } from "react";
import { X, Network, Server, Loader2, CheckCircle2 } from "lucide-react";
import { api, ApiError, IpPool, HostNode, HostDiscoveredNetwork } from "@/lib/api";
import { useI18n } from "@/lib/i18n/I18nContext";
import { useModalEscape } from "@/lib/hooks/useModalEscape";
import {
  PoolNetworkMode,
  parseCidrPrefix,
  calculateUsableRange,
  getFieldError,
  validateGatewayField,
} from "./cidrUtils";

interface CreateIpPoolModalProps {
  onClose: () => void;
  onSuccess: (newPool: IpPool) => void;
}

export function CreateIpPoolModal({ onClose, onSuccess }: CreateIpPoolModalProps) {
  const { t } = useI18n();

  const [name, setName] = useState("");
  const [cidr, setCidr] = useState("");
  const [firstUsable, setFirstUsable] = useState("");
  const [lastUsable, setLastUsable] = useState("");
  const [parentInterface, setParentInterface] = useState("");
  const [isCustomInterface, setIsCustomInterface] = useState(false);
  const [networkMode, setNetworkMode] = useState<"routed" | "bridged" | "macvlan" | "manual">("routed");
  const [gateway, setGateway] = useState("");
  const [dnsServers, setDnsServers] = useState("1.1.1.1, 8.8.8.8");
  const [excludedIps, setExcludedIps] = useState("");
  const [cooldownSeconds, setCooldownSeconds] = useState(300);
  const [description, setDescription] = useState("");
  const [isPublic, setIsPublic] = useState(true);
  const [hosts, setHosts] = useState<HostNode[]>([]);
  const [selectedHostId, setSelectedHostId] = useState<number | null>(null);
  const [discoveredNetworks, setDiscoveredNetworks] = useState<HostDiscoveredNetwork[]>([]);
  const [discoveringHost, setDiscoveringHost] = useState(false);

  const networkModeRef = useRef(networkMode);
  const parentInterfaceRef = useRef(parentInterface);
  const lastAutoRangeRef = useRef<{ first: string; last: string; gateway: string } | null>(null);
  useEffect(() => {
    networkModeRef.current = networkMode;
    parentInterfaceRef.current = parentInterface;
  });

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
    api.getHosts().then((res) => {
      setHosts(res.data);
      if (res.data.length > 0 && selectedHostId === null) {
        setSelectedHostId(res.data[0].id);
      }
    }).catch(console.error);
  }, [selectedHostId]);

  useEffect(() => {
    if (!selectedHostId) {
      queueMicrotask(() => {
        setDiscoveredNetworks([]);
      });
      return;
    }
    let cancelled = false;
    queueMicrotask(() => {
      if (!cancelled) {
        setDiscoveringHost(true);
      }
    });
    api.adminGetHostResources(selectedHostId)
      .then((res) => {
        if (cancelled) return;
        const nets = res.networks || [];
        setDiscoveredNetworks(nets);
        if (nets.length > 0) {
          const currentMode = networkModeRef.current;
          const currentIface = parentInterfaceRef.current;
          const preferred = nets.find((n) => n.recommended_modes.includes(currentMode)) || nets[0];
          if (preferred && (currentIface === "" || !nets.some((n) => n.name === currentIface))) {
            setParentInterface(preferred.name);
            setIsCustomInterface(false);
          }
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
  }, [selectedHostId]);

  const handleModeChange = (mode: PoolNetworkMode) => {
    const prevMode = networkMode;
    setNetworkMode(mode);
    clearFieldError("network_mode");
    clearFieldError("gateway");
    clearFieldError("cidr");

    const prevCalc = calculateUsableRange(cidr, prevMode);
    const nextCalc = calculateUsableRange(cidr, mode);
    if (prevCalc && nextCalc) {
      if (firstUsable === prevCalc.first) {
        setFirstUsable(nextCalc.first);
      }
      if (mode === "routed") {
        if (gateway === prevCalc.gateway) {
          setGateway("");
        }
      } else if (!gateway.trim()) {
        setGateway(nextCalc.gateway);
      }
    }
    lastAutoRangeRef.current = nextCalc ?? lastAutoRangeRef.current;

    if (discoveredNetworks.length > 0 && mode !== "manual" && !isCustomInterface) {
      const preferred = discoveredNetworks.find((n) => n.recommended_modes.includes(mode));
      if (preferred) {
        setParentInterface(preferred.name);
      }
    }
  };

  const handleCidrChange = (val: string) => {
    const prevAuto = lastAutoRangeRef.current ?? calculateUsableRange(cidr, networkMode);
    setCidr(val);
    clearFieldError("cidr");
    clearFieldError("gateway");
    const calculated = calculateUsableRange(val, networkMode);
    if (calculated) {
      if (
        !firstUsable ||
        (prevAuto && firstUsable === prevAuto.first) ||
        firstUsable === calculated.first
      ) {
        setFirstUsable(calculated.first);
      }
      if (
        !lastUsable ||
        (prevAuto && lastUsable === prevAuto.last) ||
        lastUsable === calculated.last
      ) {
        setLastUsable(calculated.last);
      }
      if (networkMode !== "routed" && (!gateway || (prevAuto && gateway === prevAuto.gateway))) {
        setGateway(calculated.gateway);
      }
      lastAutoRangeRef.current = calculated;
    }
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setError(null);
    setFieldErrors({});

    if (!selectedHostId) {
      const msg = t("selectTargetHostNode");
      setFieldErrors({ host_id: [msg] });
      setError(msg);
      return;
    }

    const trimmedParentInterface = parentInterface.trim();
    if (networkMode !== "manual" && !trimmedParentInterface) {
      const msg = t("parentInterfaceRequired");
      setFieldErrors({ parent_interface: [msg] });
      setError(msg);
      return;
    }

    const isL2Mode = networkMode === "bridged" || networkMode === "macvlan";
    const cidrPrefix = parseCidrPrefix(cidr);
    if (isL2Mode && (cidrPrefix === 31 || cidrPrefix === 32)) {
      const msg = t("l2ModePrefixUnsupported");
      setFieldErrors({ cidr: [msg] });
      setError(msg);
      return;
    }

    const gatewayErr = validateGatewayField(gateway, cidr, networkMode, t);
    if (gatewayErr) {
      setFieldErrors({ gateway: [gatewayErr] });
      setError(gatewayErr);
      return;
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

      const res = await api.adminCreateIpPool({
        name,
        host_id: selectedHostId,
        cidr: cidr.trim(),
        first_usable: firstUsable.trim() || undefined,
        last_usable: lastUsable.trim() || undefined,
        ...(networkMode !== "manual" ? { parent_interface: trimmedParentInterface } : {}),
        network_mode: networkMode,
        gateway: gateway.trim() || undefined,
        dns_servers: parsedDns.length > 0 ? parsedDns : undefined,
        excluded_ips: parsedExcluded.length > 0 ? parsedExcluded : undefined,
        cooldown_seconds: Number(cooldownSeconds) || 300,
        is_public: isPublic,
        description: description.trim() || undefined,
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
        setError(t("failedToCreatePool"));
      }
    } finally {
      setLoading(false);
    }
  };

  const cidrPrefix = parseCidrPrefix(cidr);
  const isL2Mode = networkMode === "bridged" || networkMode === "macvlan";
  const isGatewayRequired =
    isL2Mode || (networkMode === "manual" && cidrPrefix !== null && cidrPrefix <= 30);

  const nameError = getFieldError(fieldErrors, "name");
  const hostError = getFieldError(fieldErrors, "host_id");
  const cidrError = getFieldError(fieldErrors, "cidr");
  const networkModeError = getFieldError(fieldErrors, "network_mode");
  const parentInterfaceError = getFieldError(fieldErrors, "parent_interface");
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
              <h2 className="text-xl font-bold text-gray-900 dark:text-white tracking-tight">{t("addPool")}</h2>
              <p className="text-xs text-gray-500 dark:text-gray-400">{t("createPoolSubtitle")}</p>
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
                placeholder={t("poolNamePlaceholder")}
                className={`w-full px-3.5 py-2.5 rounded-xl bg-gray-50 dark:bg-gray-800/60 border ${
                  nameError ? "border-red-500 dark:border-red-500/70" : "border-gray-200 dark:border-gray-700"
                } text-sm text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-teal-500/50`}
              />
              {nameError && <p className="text-[11px] text-red-500 dark:text-red-400 mt-1">{nameError}</p>}
            </div>

            <div>
              <label className="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1.5 flex items-center space-x-1.5">
                <Server className="w-3.5 h-3.5 text-teal-500" />
                <span>{t("targetNodeRequired")}</span>
              </label>
              <select
                required
                value={selectedHostId ?? ""}
                onChange={(e) => {
                  setSelectedHostId(Number(e.target.value));
                  clearFieldError("host_id");
                }}
                className={`w-full px-3.5 py-2.5 rounded-xl bg-gray-50 dark:bg-gray-800/60 border ${
                  hostError ? "border-red-500 dark:border-red-500/70" : "border-gray-200 dark:border-gray-700"
                } text-sm text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-teal-500/50`}
              >
                <option value="" disabled>{t("selectComputeNodeOption")}</option>
                {hosts.map((h) => (
                  <option key={h.id} value={h.id}>
                    {h.name} {h.region ? `(${h.region})` : ""}
                  </option>
                ))}
              </select>
              {hostError && <p className="text-[11px] text-red-500 dark:text-red-400 mt-1">{hostError}</p>}
            </div>
          </div>

          <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <label className="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1.5">
                {t("cidrSubnetBlock")}
              </label>
              <input
                type="text"
                required
                value={cidr}
                onChange={(e) => handleCidrChange(e.target.value)}
                placeholder="203.0.113.0/24"
                className={`w-full px-3.5 py-2.5 rounded-xl bg-gray-50 dark:bg-gray-800/60 border ${
                  cidrError ? "border-red-500 dark:border-red-500/70" : "border-gray-200 dark:border-gray-700"
                } text-sm text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-teal-500/50`}
              />
              {cidrError ? (
                <p className="text-[11px] text-red-500 dark:text-red-400 mt-1">{cidrError}</p>
              ) : (
                <span className="text-[11px] text-gray-400 mt-1 block">{t("validIpv4PrefixHint")}</span>
              )}
            </div>

            <div>
              <label className="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1.5">
                {t("networkMode")}
              </label>
              <select
                value={networkMode}
                onChange={(e) => handleModeChange(e.target.value as PoolNetworkMode)}
                className={`w-full px-3.5 py-2.5 rounded-xl bg-gray-50 dark:bg-gray-800/60 border ${
                  networkModeError ? "border-red-500 dark:border-red-500/70" : "border-gray-200 dark:border-gray-700"
                } text-sm text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-teal-500/50`}
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
          </div>

          <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
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
                    !discoveredNetworks.some((n) => n.name === parentInterface) &&
                    (discoveredNetworks.length > 0 ||
                      discoveringHost ||
                      !["eno1", "incusbr0", "lxdbr0"].includes(parentInterface)) && (
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
                {parentInterfaceError ? (
                  <p className="text-[11px] text-red-500 dark:text-red-400 mt-1">{parentInterfaceError}</p>
                ) : (
                  <span className="text-[11px] text-gray-400 mt-1 block">{t("hostParentInterfaceHint")}</span>
                )}
              </div>
            )}

            <div>
              <label className="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1.5">
                {isGatewayRequired ? (
                  <>
                    {t("gatewayIpLabel")} <span className="text-red-500">*</span>
                  </>
                ) : networkMode === "routed" ? (
                  t("gatewayIpOptionalReserved")
                ) : (
                  t("gatewayIpOptionalSmallSubnets")
                )}
              </label>
              <input
                type="text"
                required={isGatewayRequired}
                value={gateway}
                onChange={(e) => {
                  setGateway(e.target.value);
                  clearFieldError("gateway");
                }}
                placeholder="203.0.113.1"
                className={`w-full px-3.5 py-2.5 rounded-xl bg-gray-50 dark:bg-gray-800/60 border ${
                  gatewayError ? "border-red-500 dark:border-red-500/70" : "border-gray-200 dark:border-gray-700"
                } text-sm text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-teal-500/50 font-mono`}
              />
              {gatewayError && (
                <p className="text-[11px] text-red-500 dark:text-red-400 mt-1">{gatewayError}</p>
              )}
              <span className="text-[11px] text-gray-400 mt-1 block">
                {networkMode === "routed" ? t("gatewayRoutedHint") : t("gatewayReservedDesc")}
              </span>
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
                placeholder="203.0.113.1"
                className={`w-full px-3 py-2 rounded-xl bg-gray-50 dark:bg-gray-800/60 border ${
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
                placeholder="203.0.113.254"
                className={`w-full px-3 py-2 rounded-xl bg-gray-50 dark:bg-gray-800/60 border ${
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
                placeholder="300"
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
              placeholder={t("poolDescriptionPlaceholder")}
              className={`w-full px-3.5 py-2.5 rounded-xl bg-gray-50 dark:bg-gray-800/60 border ${
                descriptionError ? "border-red-500 dark:border-red-500/70" : "border-gray-200 dark:border-gray-700"
              } text-sm text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-teal-500/50`}
            />
            {descriptionError && (
              <p className="text-[11px] text-red-500 dark:text-red-400 mt-1">{descriptionError}</p>
            )}
          </div>

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
              <span>{t("createPoolAction")}</span>
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}
