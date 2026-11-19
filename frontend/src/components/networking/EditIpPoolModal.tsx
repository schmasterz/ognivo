"use client";

import React, { useState, useEffect } from "react";
import { X, Network, Server, Loader2, CheckCircle2, AlertTriangle } from "lucide-react";
import { api, IpPool, HostDiscoveredNetwork } from "@/lib/api";
import { useI18n } from "@/lib/i18n/I18nContext";
import { useModalEscape } from "@/lib/hooks/useModalEscape";

interface EditIpPoolModalProps {
  pool: IpPool;
  onClose: () => void;
  onSuccess: (updatedPool: IpPool) => void;
}

function parseCidrPrefix(cidr: string): number | null {
  const parts = cidr.trim().split("/");
  if (parts.length !== 2 || !/^\d+$/.test(parts[1])) return null;
  const prefix = parseInt(parts[1], 10);
  if (isNaN(prefix) || prefix < 0 || prefix > 32) return null;
  return prefix;
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

  const hasAllocations = (pool.utilization?.allocated ?? 0) > 0 || (pool.utilization?.cooling ?? 0) > 0;

  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useModalEscape(onClose, !loading);

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

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setError(null);

    const trimmedParentInterface = parentInterface.trim();
    if (networkMode !== "manual" && !trimmedParentInterface) {
      setError(t("parentInterfaceRequired"));
      return;
    }

    const isL2Mode = networkMode === "bridged" || networkMode === "macvlan";
    const cidrPrefix = parseCidrPrefix(pool.cidr || pool.subnet || "");
    if (isL2Mode && (cidrPrefix === 31 || cidrPrefix === 32)) {
      setError(t("l2ModePrefixUnsupported"));
      return;
    }

    if (isL2Mode && !gateway.trim()) {
      setError(t("gatewayRequiredForL2Modes"));
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

      const res = await api.adminUpdateIpPool(pool.id, {
        name: name.trim(),
        description: description.trim() || null,
        dns_servers: parsedDns,
        excluded_ips: parsedExcluded,
        cooldown_seconds: Number(cooldownSeconds) || 300,
        active,
        is_public: isPublic,
        gateway: gateway.trim() || null,
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
      if (err instanceof Error) {
        setError(err.message);
      } else {
        setError(t("failedToUpdatePool"));
      }
    } finally {
      setLoading(false);
    }
  };

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
                onChange={(e) => setName(e.target.value)}
                className="w-full px-3.5 py-2.5 rounded-xl bg-gray-50 dark:bg-gray-800/60 border border-gray-200 dark:border-gray-700 text-sm text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-teal-500/50"
              />
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
                onChange={(e) => setNetworkMode(e.target.value)}
                className="w-full px-3.5 py-2.5 rounded-xl bg-gray-50 dark:bg-gray-800/60 border border-gray-200 dark:border-gray-700 text-sm text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-teal-500/50 disabled:opacity-60"
              >
                <option value="routed">{t("modeRoutedOption")}</option>
                <option value="bridged">{t("modeBridgedOption")}</option>
                <option value="macvlan">{t("modeMacvlanOption")}</option>
                <option value="manual">{t("modeManual")}</option>
              </select>
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
                    if (e.target.value === "__custom__") {
                      setIsCustomInterface(true);
                    } else {
                      setIsCustomInterface(false);
                      setParentInterface(e.target.value);
                    }
                  }}
                  className="w-full px-3.5 py-2.5 rounded-xl bg-gray-50 dark:bg-gray-800/60 border border-gray-200 dark:border-gray-700 text-sm text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-teal-500/50 font-mono"
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
                      onChange={(e) => setParentInterface(e.target.value)}
                      placeholder={t("customInterfacePlaceholder")}
                      className="w-full px-3.5 py-2 rounded-xl bg-gray-50 dark:bg-gray-800/60 border border-gray-200 dark:border-gray-700 text-sm text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-teal-500/50 font-mono"
                      autoFocus
                    />
                  </div>
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
            </div>

            <div>
              <label className="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1.5">
                {t("gatewayIpLabel")}{" "}
                {(networkMode === "bridged" || networkMode === "macvlan") && (
                  <span className="text-red-500">*</span>
                )}
              </label>
              <input
                type="text"
                required={networkMode === "bridged" || networkMode === "macvlan"}
                value={gateway}
                onChange={(e) => setGateway(e.target.value)}
                placeholder="203.0.113.1"
                className="w-full px-3.5 py-2.5 rounded-xl bg-gray-50 dark:bg-gray-800/60 border border-gray-200 dark:border-gray-700 text-sm text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-teal-500/50 font-mono"
              />
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
                onChange={(e) => setFirstUsable(e.target.value)}
                className="w-full px-3.5 py-2.5 rounded-xl bg-gray-50 dark:bg-gray-800/60 border border-gray-200 dark:border-gray-700 text-xs text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-teal-500/50 font-mono"
              />
            </div>

            <div>
              <label className="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1.5">
                {t("lastUsableIp")}
              </label>
              <input
                type="text"
                value={lastUsable}
                onChange={(e) => setLastUsable(e.target.value)}
                className="w-full px-3.5 py-2.5 rounded-xl bg-gray-50 dark:bg-gray-800/60 border border-gray-200 dark:border-gray-700 text-xs text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-teal-500/50 font-mono"
              />
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
                onChange={(e) => setDnsServers(e.target.value)}
                placeholder="1.1.1.1, 8.8.8.8"
                className="w-full px-3.5 py-2.5 rounded-xl bg-gray-50 dark:bg-gray-800/60 border border-gray-200 dark:border-gray-700 text-sm text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-teal-500/50"
              />
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
                onChange={(e) => setCooldownSeconds(Number(e.target.value))}
                className="w-full px-3.5 py-2.5 rounded-xl bg-gray-50 dark:bg-gray-800/60 border border-gray-200 dark:border-gray-700 text-sm text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-teal-500/50"
              />
            </div>
          </div>

          <div>
            <label className="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1.5">
              {t("excludedReservedIps")}
            </label>
            <input
              type="text"
              value={excludedIps}
              onChange={(e) => setExcludedIps(e.target.value)}
              placeholder={t("excludedIpsPlaceholder")}
              className="w-full px-3.5 py-2.5 rounded-xl bg-gray-50 dark:bg-gray-800/60 border border-gray-200 dark:border-gray-700 text-sm text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-teal-500/50"
            />
          </div>

          <div>
            <label className="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1.5">
              {t("description")}
            </label>
            <input
              type="text"
              value={description}
              onChange={(e) => setDescription(e.target.value)}
              className="w-full px-3.5 py-2.5 rounded-xl bg-gray-50 dark:bg-gray-800/60 border border-gray-200 dark:border-gray-700 text-sm text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-teal-500/50"
            />
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
