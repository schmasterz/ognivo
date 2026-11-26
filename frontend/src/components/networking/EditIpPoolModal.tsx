"use client";

import React, { useState, useEffect } from "react";
import { X, Network, Server, Loader2, CheckCircle2, AlertTriangle } from "lucide-react";
import { api, ApiError, IpPool } from "@/lib/api";
import { useI18n } from "@/lib/i18n/I18nContext";
import { useModalEscape } from "@/lib/hooks/useModalEscape";
import {
  parseCidrPrefix,
  parseCidrRange,
  getFieldError,
} from "./cidrUtils";
import { IpPoolFormFields } from "./IpPoolFormFields";
import { useHostNetworkDiscovery } from "./useHostNetworkDiscovery";
import { validateIpPoolForm, buildIpPoolPayload } from "./ipPoolSchema";

interface EditIpPoolModalProps {
  pool: IpPool;
  onClose: () => void;
  onSuccess: (updatedPool: IpPool) => void;
}

export function EditIpPoolModal({ pool, onClose, onSuccess }: EditIpPoolModalProps) {
  const { t } = useI18n();

  const [name, setName] = useState(pool.name);
  const [description, setDescription] = useState(pool.description || "");
  const [networkMode, setNetworkMode] = useState<string>(pool.network_mode || "routed");
  const [parentInterface, setParentInterface] = useState<string>(pool.parent_interface ?? "");
  const [isCustomInterface, setIsCustomInterface] = useState(false);
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

  const { discoveredNetworks, discoveringHost } = useHostNetworkDiscovery(
    pool.host_id ?? pool.host?.id,
    { clearOnEmptyHost: false }
  );

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

  const handleModeChange = (mode: string) => {
    setNetworkMode(mode);
    clearFieldError("network_mode");
    clearFieldError("gateway");
    clearFieldError("cidr");
    clearFieldError("parent_interface");

    if (mode !== "manual" && discoveredNetworks.length > 0 && !isCustomInterface) {
      const currentNet = discoveredNetworks.find((n) => n.name === parentInterface);
      if (
        !parentInterface ||
        (currentNet &&
          !currentNet.recommended_modes.includes(mode as "routed" | "bridged" | "macvlan"))
      ) {
        const preferred =
          discoveredNetworks.find((n) =>
            n.recommended_modes.includes(mode as "routed" | "bridged" | "macvlan")
          ) || (parentInterface ? undefined : discoveredNetworks[0]);
        if (preferred) {
          setParentInterface(preferred.name);
        }
      }
    }

    if (mode !== "routed" && !gateway.trim()) {
      const range = parseCidrRange(pool.cidr || pool.subnet || "");
      if (range && range.prefix <= 30) {
        setGateway(range.numToIp(range.network + 1));
      }
    }
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setError(null);
    setFieldErrors({});

    const poolCidr = pool.cidr || pool.subnet || "";
    const values = {
      mode: "edit" as const,
      name,
      cidr: poolCidr,
      firstUsable,
      lastUsable,
      parentInterface,
      networkMode,
      gateway,
      dnsServers,
      excludedIps,
      cooldownSeconds,
      description,
      isPublic,
      hasAllocations,
      isGatewayLocked,
    };
    const validationErrors = validateIpPoolForm(values, t);
    const firstError = Object.values(validationErrors)[0]?.[0];
    if (firstError) {
      setFieldErrors(validationErrors);
      setError(firstError);
      return;
    }

    try {
      setLoading(true);

      const res = await api.adminUpdateIpPool(pool.id, {
        ...buildIpPoolPayload(values),
        active,
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
                onChange={(e) => handleModeChange(e.target.value)}
                className={`w-full px-3.5 py-2.5 rounded-xl bg-gray-50 dark:bg-gray-800/60 border ${
                  networkModeError ? "border-red-500 dark:border-red-500/70" : "border-gray-200 dark:border-gray-700"
                } text-sm text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-teal-500/50 disabled:opacity-60`}
              >
                <option value="routed">{t("modeRoutedOption")}</option>
                <option value="bridged" disabled={cidrPrefix === 31 || cidrPrefix === 32}>
                  {t("modeBridgedOption")}
                </option>
                <option value="macvlan" disabled={cidrPrefix === 31 || cidrPrefix === 32}>
                  {t("modeMacvlanOption")}
                </option>
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

          <IpPoolFormFields
            networkMode={networkMode}
            gateway={gateway}
            onGatewayChange={(v) => {
              setGateway(v);
              clearFieldError("gateway");
            }}
            firstUsable={firstUsable}
            onFirstUsableChange={(v) => {
              setFirstUsable(v);
              clearFieldError("first_usable");
            }}
            lastUsable={lastUsable}
            onLastUsableChange={(v) => {
              setLastUsable(v);
              clearFieldError("last_usable");
            }}
            dnsServers={dnsServers}
            onDnsServersChange={(v) => {
              setDnsServers(v);
              clearFieldError("dns_servers");
            }}
            cooldownSeconds={cooldownSeconds}
            onCooldownSecondsChange={(v) => {
              setCooldownSeconds(v);
              clearFieldError("cooldown_seconds");
            }}
            excludedIps={excludedIps}
            onExcludedIpsChange={(v) => {
              setExcludedIps(v);
              clearFieldError("excluded_ips");
            }}
            description={description}
            onDescriptionChange={(v) => {
              setDescription(v);
              clearFieldError("description");
            }}
            isPublic={isPublic}
            onIsPublicChange={setIsPublic}
            active={active}
            onActiveChange={setActive}
            showActiveToggle
            fieldErrors={fieldErrors}
            isGatewayRequired={isGatewayRequired}
            isGatewayLabelRequired={
              isL2Mode || (networkMode !== "routed" && cidrPrefix !== null && cidrPrefix <= 30)
            }
            isGatewayLocked={isGatewayLocked}
            includeGatewayDisabledStyles
            beforeGatewaySlot={
            <div>
              <label className="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1.5">
                {t("cidrSubnetImmutable")}
              </label>
              <div className="w-full px-3.5 py-2.5 rounded-xl bg-gray-100 dark:bg-gray-800 border border-gray-200 dark:border-gray-700 text-sm font-mono text-gray-600 dark:text-gray-300">
                {pool.cidr || pool.subnet}
              </div>
              {cidrError && <p className="text-[11px] text-red-500 dark:text-red-400 mt-1">{cidrError}</p>}
            </div>
            }
          />

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
