"use client";

import React, { useCallback, useEffect, useRef, useState } from "react";
import {
  X,
  Network,
  Server,
  Globe,
  Copy,
  Check,
  Search,
  Loader2,
  Clock,
  AlertTriangle,
  ChevronLeft,
  ChevronRight,
  Cpu,
} from "lucide-react";
import {
  api,
  IpPool,
  IpPoolAddressEntry,
  IpPoolAddressState,
  PaginationMeta,
} from "@/lib/api";
import { useI18n } from "@/lib/i18n/I18nContext";
import { useModalEscape } from "@/lib/hooks/useModalEscape";

interface PoolDetailModalProps {
  pool: IpPool;
  onClose: () => void;
}

type StateFilter = "all" | IpPoolAddressState;

const PER_PAGE = 50;

/**
 * Displays IP pool metadata, utilization summary, and a paginated/filterable
 * list of all IP addresses in the pool (free, allocated, cooling, excluded).
 */
export function PoolDetailModal({ pool, onClose }: PoolDetailModalProps) {
  const { t } = useI18n();

  const [fetchedPool, setFetchedPool] = useState<IpPool | null>(null);
  const [entries, setEntries] = useState<IpPoolAddressEntry[]>([]);
  const [meta, setMeta] = useState<PaginationMeta>({
    current_page: 1,
    last_page: 1,
    per_page: PER_PAGE,
    total: 0,
  });
  const [stateFilter, setStateFilter] = useState<StateFilter>("all");
  const [searchInput, setSearchInput] = useState("");
  const [debouncedSearch, setDebouncedSearch] = useState("");
  const [page, setPage] = useState(1);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [copiedIp, setCopiedIp] = useState<string | null>(null);
  const [refreshNonce, setRefreshNonce] = useState(0);

  const copyTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null);

  useModalEscape(onClose, true);

  useEffect(() => {
    const timer = setTimeout(() => {
      setDebouncedSearch(searchInput.trim());
    }, 250);
    return () => clearTimeout(timer);
  }, [searchInput]);

  useEffect(() => {
    return () => {
      if (copyTimerRef.current) {
        clearTimeout(copyTimerRef.current);
      }
    };
  }, []);

  useEffect(() => {
    const controller = new AbortController();
    let ignore = false;

    const load = async () => {
      setLoading(true);
      setError(null);
      try {
        const res = await api.adminGetIpPoolAddresses(
          pool.id,
          {
            state: stateFilter,
            search: debouncedSearch || null,
            page,
            per_page: PER_PAGE,
          },
          { signal: controller.signal }
        );
        if (ignore) return;
        if (res.pool) {
          setFetchedPool(res.pool);
        }
        setEntries(res.data || []);
        setMeta(
          res.meta || {
            current_page: page,
            last_page: 1,
            per_page: PER_PAGE,
            total: res.data?.length ?? 0,
          }
        );
      } catch (err: unknown) {
        if (ignore) return;
        if (err instanceof Error && err.name === "AbortError") return;
        setError(err instanceof Error ? err.message : t("poolDetailError"));
      } finally {
        if (!ignore) {
          setLoading(false);
        }
      }
    };

    void load();

    return () => {
      ignore = true;
      controller.abort();
    };
  }, [pool.id, stateFilter, debouncedSearch, page, refreshNonce, t]);

  const handleStateChange = useCallback((next: StateFilter) => {
    setStateFilter(next);
    setPage(1);
  }, []);

  const handleSearchChange = (value: string) => {
    setSearchInput(value);
    setPage(1);
  };

  const handleCopy = (ip: string) => {
    void navigator.clipboard.writeText(ip);
    setCopiedIp(ip);
    if (copyTimerRef.current) {
      clearTimeout(copyTimerRef.current);
    }
    copyTimerRef.current = setTimeout(() => {
      setCopiedIp(null);
    }, 2000);
  };

  const poolDetail = fetchedPool?.id === pool.id ? fetchedPool : pool;

  const totalCapacity =
    poolDetail.total_usable ?? poolDetail.utilization?.total ?? poolDetail.total_ips ?? 0;
  const allocatedCount =
    poolDetail.allocated_ips ?? poolDetail.utilization?.allocated ?? 0;
  const coolingCount =
    poolDetail.cooling_ips ?? poolDetail.utilization?.cooling ?? 0;
  const freeCount =
    poolDetail.free_ips ?? poolDetail.utilization?.free ?? 0;

  const renderModeBadge = () => {
    if (poolDetail.network_mode === "manual") {
      return (
        <span className="inline-flex items-center space-x-1 text-[10px] px-2 py-0.5 rounded-full font-medium bg-amber-500/10 text-amber-700 dark:text-amber-300 border border-amber-500/30">
          <AlertTriangle className="w-3 h-3 text-amber-500" />
          <span>{t("manualBadge")}</span>
        </span>
      );
    }

    const modeClass =
      poolDetail.network_mode === "bridged"
        ? "bg-blue-500/10 text-blue-600 dark:text-blue-400 border-blue-500/20"
        : poolDetail.network_mode === "macvlan"
          ? "bg-purple-500/10 text-purple-600 dark:text-purple-400 border-purple-500/20"
          : "bg-teal-500/10 text-teal-600 dark:text-teal-400 border-teal-500/20";

    const modeLabel =
      poolDetail.network_mode === "bridged"
        ? t("bridgedBadge")
        : poolDetail.network_mode === "macvlan"
          ? t("macvlanBadge")
          : t("routedBadge");

    return (
      <span className={`text-[10px] px-2 py-0.5 rounded-full font-mono border ${modeClass}`}>
        {modeLabel}
      </span>
    );
  };

  const renderStateBadge = (entry: IpPoolAddressEntry) => {
    let badge: React.ReactNode;

    switch (entry.state) {
      case "free":
        badge = (
          <span className="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border border-emerald-500/20">
            <span className="w-1.5 h-1.5 rounded-full bg-emerald-500 dark:bg-emerald-400 mr-1.5" />
            {t("poolDetailFree")}
          </span>
        );
        break;
      case "allocated":
        badge = (
          <span className="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-500/10 text-blue-600 dark:text-blue-400 border border-blue-500/20">
            <span className="w-1.5 h-1.5 rounded-full bg-blue-500 dark:bg-blue-400 mr-1.5" />
            {t("poolDetailAllocated")}
          </span>
        );
        break;
      case "cooling":
        badge = (
          <span className="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-amber-500/10 text-amber-600 dark:text-amber-400 border border-amber-500/20">
            <Clock className="w-3 h-3 mr-1 text-amber-500" />
            {t("statusCooling", { seconds: entry.cooldown_remaining_seconds })}
          </span>
        );
        break;
      case "excluded":
      default:
        badge = (
          <span className="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-200 dark:bg-gray-800 text-gray-700 dark:text-gray-300 border border-gray-300 dark:border-gray-700">
            {t("poolDetailExcluded")}
          </span>
        );
        break;
    }

    return (
      <div className="flex flex-wrap items-center gap-1.5">
        {badge}
        {entry.is_gateway && (
          <span className="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 border border-indigo-500/20">
            {t("poolDetailGatewayTag")}
          </span>
        )}
      </div>
    );
  };

  const filterTabs: { id: StateFilter; label: string }[] = [
    { id: "all", label: t("poolDetailAll") },
    { id: "free", label: t("poolDetailFree") },
    { id: "allocated", label: t("poolDetailAllocated") },
    { id: "cooling", label: t("poolDetailCooling") },
    { id: "excluded", label: t("poolDetailExcluded") },
  ];

  const usableRangeText =
    poolDetail.first_usable && poolDetail.last_usable
      ? `${poolDetail.first_usable} - ${poolDetail.last_usable}`
      : "—";

  const dnsText =
    poolDetail.dns_servers && poolDetail.dns_servers.length > 0
      ? poolDetail.dns_servers.join(", ")
      : "—";

  const cooldownText =
    poolDetail.cooldown_seconds !== undefined && poolDetail.cooldown_seconds !== null
      ? t("poolDetailSecondsValue", { seconds: poolDetail.cooldown_seconds })
      : "—";

  return (
    <div
      className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-sm animate-fadeIn"
      role="dialog"
      aria-modal="true"
      aria-label={poolDetail.name}
    >
      <div className="relative w-full max-w-4xl bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-3xl p-6 sm:p-7 shadow-2xl flex flex-col max-h-[90vh] transition-colors">
        {/* Header */}
        <div className="flex items-start justify-between pb-4 mb-4 border-b border-gray-100 dark:border-gray-800">
          <div className="flex items-start space-x-3">
            <div className="p-2.5 rounded-2xl bg-teal-500/10 text-teal-600 dark:text-teal-400 flex-shrink-0">
              <Network className="w-6 h-6" />
            </div>
            <div>
              <div className="flex flex-wrap items-center gap-2">
                <h2 className="text-lg sm:text-xl font-bold text-gray-900 dark:text-white tracking-tight">
                  {poolDetail.name}
                </h2>
                {renderModeBadge()}
              </div>
              <div className="flex flex-wrap items-center gap-x-3 gap-y-1 mt-1 text-xs">
                <span className="font-mono font-semibold text-teal-600 dark:text-teal-400">
                  {poolDetail.cidr || poolDetail.subnet}
                </span>
                {poolDetail.host ? (
                  <span className="inline-flex items-center space-x-1 text-indigo-600 dark:text-indigo-400 font-medium">
                    <Server className="w-3.5 h-3.5 flex-shrink-0" />
                    <span>
                      {poolDetail.parent_interface
                        ? `${poolDetail.host.name} (${poolDetail.parent_interface})`
                        : poolDetail.host.name}
                    </span>
                  </span>
                ) : (
                  <span className="inline-flex items-center space-x-1 text-gray-500 dark:text-gray-400">
                    <Globe className="w-3.5 h-3.5 flex-shrink-0" />
                    <span>{t("globalNode")}</span>
                  </span>
                )}
              </div>
            </div>
          </div>
          <button
            type="button"
            onClick={onClose}
            aria-label={t("close")}
            className="p-2 rounded-xl text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-800 transition"
          >
            <X className="w-5 h-5" />
          </button>
        </div>

        {/* Summary Cards */}
        <div className="grid grid-cols-2 sm:grid-cols-4 gap-2.5 mb-3">
          <button
            type="button"
            onClick={() => handleStateChange("all")}
            className={`text-left p-3 rounded-2xl border transition ${
              stateFilter === "all"
                ? "bg-teal-500/10 border-teal-500/40 ring-1 ring-teal-500/30"
                : "bg-gray-50 dark:bg-gray-800/40 border-gray-200 dark:border-gray-800 hover:border-gray-300 dark:hover:border-gray-700"
            }`}
          >
            <div className="text-[11px] font-medium text-gray-500 dark:text-gray-400">
              {t("poolCapacity")}
            </div>
            <div className="text-lg font-bold font-mono text-gray-900 dark:text-white mt-0.5">
              {totalCapacity}
            </div>
          </button>

          <button
            type="button"
            onClick={() => handleStateChange("free")}
            className={`text-left p-3 rounded-2xl border transition ${
              stateFilter === "free"
                ? "bg-emerald-500/10 border-emerald-500/40 ring-1 ring-emerald-500/30"
                : "bg-gray-50 dark:bg-gray-800/40 border-gray-200 dark:border-gray-800 hover:border-emerald-500/30"
            }`}
          >
            <div className="text-[11px] font-medium text-emerald-600 dark:text-emerald-400">
              {t("poolDetailFree")}
            </div>
            <div className="text-lg font-bold font-mono text-emerald-700 dark:text-emerald-300 mt-0.5">
              {freeCount}
            </div>
          </button>

          <button
            type="button"
            onClick={() => handleStateChange("allocated")}
            className={`text-left p-3 rounded-2xl border transition ${
              stateFilter === "allocated"
                ? "bg-blue-500/10 border-blue-500/40 ring-1 ring-blue-500/30"
                : "bg-gray-50 dark:bg-gray-800/40 border-gray-200 dark:border-gray-800 hover:border-blue-500/30"
            }`}
          >
            <div className="text-[11px] font-medium text-blue-600 dark:text-blue-400">
              {t("poolDetailAllocated")}
            </div>
            <div className="text-lg font-bold font-mono text-blue-700 dark:text-blue-300 mt-0.5">
              {allocatedCount}
            </div>
          </button>

          <button
            type="button"
            onClick={() => handleStateChange("cooling")}
            className={`text-left p-3 rounded-2xl border transition ${
              stateFilter === "cooling"
                ? "bg-amber-500/10 border-amber-500/40 ring-1 ring-amber-500/30"
                : "bg-gray-50 dark:bg-gray-800/40 border-gray-200 dark:border-gray-800 hover:border-amber-500/30"
            }`}
          >
            <div className="text-[11px] font-medium text-amber-600 dark:text-amber-400">
              {t("poolDetailCooling")}
            </div>
            <div className="text-lg font-bold font-mono text-amber-700 dark:text-amber-300 mt-0.5">
              {coolingCount}
            </div>
          </button>
        </div>

        {/* Metadata Strip */}
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-2 px-3.5 py-2.5 mb-4 rounded-2xl bg-gray-50 dark:bg-gray-950/50 border border-gray-200/80 dark:border-gray-800 text-xs">
          <div>
            <span className="text-gray-500 dark:text-gray-400 block text-[10px] uppercase tracking-wider font-semibold">
              {t("poolDetailUsableRange")}
            </span>
            <span className="font-mono text-gray-900 dark:text-gray-200">{usableRangeText}</span>
          </div>
          <div>
            <span className="text-gray-500 dark:text-gray-400 block text-[10px] uppercase tracking-wider font-semibold">
              {t("poolGateway")}
            </span>
            <span className="font-mono text-gray-900 dark:text-gray-200">
              {poolDetail.gateway || "—"}
            </span>
          </div>
          <div>
            <span className="text-gray-500 dark:text-gray-400 block text-[10px] uppercase tracking-wider font-semibold">
              {t("dnsServersLabel")}
            </span>
            <span className="font-mono text-gray-900 dark:text-gray-200 break-all">{dnsText}</span>
          </div>
          <div>
            <span className="text-gray-500 dark:text-gray-400 block text-[10px] uppercase tracking-wider font-semibold">
              {t("poolDetailCooldown")}
            </span>
            <span className="font-mono text-gray-900 dark:text-gray-200">{cooldownText}</span>
          </div>
        </div>

        {/* Controls Bar */}
        <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-3">
          <div className="flex flex-wrap items-center gap-1.5">
            {filterTabs.map((tab) => {
              const active = stateFilter === tab.id;
              return (
                <button
                  key={tab.id}
                  type="button"
                  onClick={() => handleStateChange(tab.id)}
                  className={`px-3 py-1.5 rounded-xl text-xs font-semibold transition ${
                    active
                      ? "bg-teal-600 text-white shadow-sm shadow-teal-500/20"
                      : "bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-700"
                  }`}
                >
                  {tab.label}
                </button>
              );
            })}
          </div>

          <div className="relative w-full sm:w-64">
            <Search className="w-3.5 h-3.5 text-gray-400 absolute left-3 top-1/2 -translate-y-1/2 pointer-events-none" />
            <input
              type="text"
              value={searchInput}
              onChange={(e) => handleSearchChange(e.target.value)}
              placeholder={t("poolDetailSearchPlaceholder")}
              className="w-full pl-8 pr-7 py-1.5 rounded-xl bg-gray-50 dark:bg-gray-950/60 border border-gray-200 dark:border-gray-800 text-xs font-mono text-gray-900 dark:text-white placeholder:text-gray-400 placeholder:font-sans focus:outline-none focus:border-teal-500 transition"
            />
            {searchInput && (
              <button
                type="button"
                onClick={() => handleSearchChange("")}
                className="absolute right-2 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600 dark:hover:text-gray-200"
              >
                <X className="w-3.5 h-3.5" />
              </button>
            )}
          </div>
        </div>

        {/* Address List Table */}
        <div className="flex-1 min-h-0 overflow-y-auto border border-gray-200 dark:border-gray-800 rounded-2xl bg-white dark:bg-gray-950/30">
          {error ? (
            <div className="p-8 text-center">
              <p className="text-xs text-red-600 dark:text-red-400 mb-3">{error}</p>
              <button
                type="button"
                onClick={() => setRefreshNonce((n) => n + 1)}
                className="px-3.5 py-1.5 rounded-xl bg-gray-100 dark:bg-gray-800 text-xs font-semibold text-gray-700 dark:text-gray-200 hover:bg-gray-200 dark:hover:bg-gray-700 transition"
              >
                {t("retryBtn")}
              </button>
            </div>
          ) : loading ? (
            <div className="py-12 text-center">
              <Loader2 className="w-7 h-7 text-teal-500 animate-spin mx-auto mb-2.5" />
              <p className="text-xs text-gray-500 dark:text-gray-400">{t("poolDetailLoading")}</p>
            </div>
          ) : entries.length === 0 ? (
            <div className="py-12 text-center px-4">
              <Globe className="w-7 h-7 text-gray-400 mx-auto mb-2.5" />
              <p className="text-xs text-gray-500 dark:text-gray-400">{t("poolDetailEmpty")}</p>
            </div>
          ) : (
            <table className="w-full text-left text-xs text-gray-600 dark:text-gray-300">
              <thead className="sticky top-0 z-10 bg-gray-50 dark:bg-gray-950 text-[11px] uppercase text-gray-500 dark:text-gray-400 border-b border-gray-200 dark:border-gray-800 font-semibold tracking-wider">
                <tr>
                  <th scope="col" className="px-4 py-3">
                    {t("poolDetailColIp")}
                  </th>
                  <th scope="col" className="px-4 py-3">
                    {t("poolDetailColState")}
                  </th>
                  <th scope="col" className="px-4 py-3">
                    {t("poolDetailColInstanceMac")}
                  </th>
                  <th scope="col" className="px-4 py-3">
                    {t("owner")}
                  </th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-100 dark:divide-gray-800/70">
                {entries.map((entry) => {
                  const isCopied = copiedIp === entry.ip;
                  const vm = entry.allocation?.virtual_machine;
                  const mac = entry.allocation?.mac_address;
                  const owner = entry.allocation?.user;

                  return (
                    <tr
                      key={entry.ip}
                      className="hover:bg-gray-50 dark:hover:bg-gray-800/40 transition"
                    >
                      <td className="px-4 py-2.5 whitespace-nowrap">
                        <div className="flex items-center space-x-2">
                          <span className="font-mono font-semibold text-gray-900 dark:text-white">
                            {entry.ip}
                          </span>
                          <button
                            type="button"
                            onClick={() => handleCopy(entry.ip)}
                            title={t("copyIp")}
                            className="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 transition"
                          >
                            {isCopied ? (
                              <Check className="w-3.5 h-3.5 text-emerald-500" />
                            ) : (
                              <Copy className="w-3.5 h-3.5" />
                            )}
                          </button>
                        </div>
                      </td>
                      <td className="px-4 py-2.5 whitespace-nowrap">
                        {renderStateBadge(entry)}
                      </td>
                      <td className="px-4 py-2.5 whitespace-nowrap">
                        {vm || mac ? (
                          <div>
                            {vm ? (
                              <div className="font-semibold text-gray-900 dark:text-white flex items-center space-x-1.5">
                                <Server className="w-3 h-3 text-blue-500 flex-shrink-0" />
                                <span>{vm.name}</span>
                              </div>
                            ) : null}
                            {mac ? (
                              <div className="text-[11px] font-mono text-gray-400 flex items-center space-x-1 mt-0.5">
                                <Cpu className="w-3 h-3 text-gray-400 flex-shrink-0" />
                                <span>{mac}</span>
                              </div>
                            ) : null}
                          </div>
                        ) : (
                          <span className="text-gray-400">—</span>
                        )}
                      </td>
                      <td className="px-4 py-2.5 whitespace-nowrap">
                        {owner ? (
                          <div>
                            <div className="font-semibold text-gray-900 dark:text-white">
                              {owner.name}
                            </div>
                            <div className="text-[11px] text-gray-400">{owner.email}</div>
                          </div>
                        ) : (
                          <span className="text-gray-400">—</span>
                        )}
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          )}
        </div>

        {/* Pagination & Footer */}
        <div className="flex flex-col sm:flex-row items-center justify-between gap-3 pt-4 mt-4 border-t border-gray-100 dark:border-gray-800">
          <div className="flex items-center space-x-3 text-xs text-gray-500 dark:text-gray-400">
            <span>
              {t("poolDetailPageSummary", {
                current: meta.current_page,
                last: Math.max(1, meta.last_page),
                total: meta.total,
              })}
            </span>
          </div>

          <div className="flex items-center space-x-2">
            <button
              type="button"
              onClick={() => setPage((p) => Math.max(1, p - 1))}
              disabled={loading || meta.current_page <= 1}
              className="inline-flex items-center px-3 py-1.5 rounded-xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 text-xs font-medium text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800 disabled:opacity-40 disabled:pointer-events-none transition"
            >
              <ChevronLeft className="w-3.5 h-3.5 mr-1" />
              <span>{t("prevPage")}</span>
            </button>
            <button
              type="button"
              onClick={() => setPage((p) => Math.min(meta.last_page, p + 1))}
              disabled={loading || meta.current_page >= meta.last_page}
              className="inline-flex items-center px-3 py-1.5 rounded-xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 text-xs font-medium text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800 disabled:opacity-40 disabled:pointer-events-none transition"
            >
              <span>{t("nextPage")}</span>
              <ChevronRight className="w-3.5 h-3.5 ml-1" />
            </button>
            <button
              type="button"
              onClick={onClose}
              className="px-4 py-1.5 rounded-xl bg-gray-200 dark:bg-gray-800 text-gray-800 dark:text-gray-200 text-xs font-semibold hover:bg-gray-300 dark:hover:bg-gray-700 transition"
            >
              {t("close")}
            </button>
          </div>
        </div>
      </div>
    </div>
  );
}
