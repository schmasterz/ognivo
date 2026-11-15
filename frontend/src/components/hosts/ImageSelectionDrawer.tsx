"use client";

import React, { useCallback, useEffect, useMemo, useRef, useState } from "react";
import {
  X,
  Search,
  RotateCcw,
  CheckSquare,
  Square,
  Loader2,
  AlertTriangle,
  Layers,
} from "lucide-react";
import { useVirtualizer } from "@tanstack/react-virtual";
import { api, ImageItem, ImageCatalogCounts, ImageCatalogFilter } from "@/lib/api";
import {
  SelectionFlow,
  StagedImageSelection,
  createInitialSelection,
  normalizeFilter,
  isEmptyFilter,
  isImageSelected,
  toggleImage,
  selectAllMatching,
  clearAllMatching,
  hasStagedChanges,
  estimateSelectedCount,
} from "@/lib/images/imageSelection";
import { useI18n } from "@/lib/i18n/I18nContext";
import { useToast } from "@/lib/toast/ToastContext";
import { useModalEscape } from "@/lib/hooks/useModalEscape";

export interface ImageSelectionDrawerProps {
  isOpen: boolean;
  onClose: () => void;
  flow: SelectionFlow;
  hostId?: number | null;
  selection: StagedImageSelection;
  onChange: (next: StagedImageSelection) => void;
  onCountsUpdate?: (counts: ImageCatalogCounts) => void;
}

const PER_PAGE = 50;

export function ImageSelectionDrawer({
  isOpen,
  onClose,
  flow,
  hostId,
  selection,
  onChange,
  onCountsUpdate,
}: ImageSelectionDrawerProps) {
  const { t } = useI18n();
  const { toast } = useToast();

  useModalEscape(onClose, isOpen);

  // Search & Filter State
  const [search, setSearch] = useState("");
  const [debouncedSearch, setDebouncedSearch] = useState("");
  const [osTypeFilter, setOsTypeFilter] = useState<
    "all" | "linux" | "windows" | "bsd" | "other"
  >("all");
  const [protocolFilter, setProtocolFilter] = useState<
    "all" | "simplestreams" | "lxd" | "incus" | "oci"
  >("all");
  const [enabledFilter, setEnabledFilter] = useState<"all" | "true" | "false">("all");
  const [tab, setTab] = useState<"all" | "selected" | "unselected">("all");

  // Data fetching state
  const [items, setItems] = useState<ImageItem[]>([]);
  const [nextCursor, setNextCursor] = useState<string | null>(null);
  const [counts, setCounts] = useState<ImageCatalogCounts | null>(null);
  const [loading, setLoading] = useState(false);
  const [loadingMore, setLoadingMore] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const abortControllerRef = useRef<AbortController | null>(null);
  const parentRef = useRef<HTMLDivElement | null>(null);

  // Debounce search input (~300ms)
  useEffect(() => {
    const timer = setTimeout(() => {
      setDebouncedSearch(search);
    }, 300);
    return () => clearTimeout(timer);
  }, [search]);

  // Active filter object
  const currentFilter: ImageCatalogFilter = useMemo(
    () =>
      normalizeFilter({
        search: debouncedSearch,
        os_type: osTypeFilter === "all" ? undefined : osTypeFilter,
        protocol: protocolFilter === "all" ? undefined : protocolFilter,
        enabled: enabledFilter === "all" ? undefined : enabledFilter === "true",
      }),
    [debouncedSearch, osTypeFilter, protocolFilter, enabledFilter]
  );

  // Determine whether server attachment param can be used
  const canUseServerAttachment = Boolean(
    hostId !== undefined &&
    hostId !== null &&
    selection.base.kind === "current" &&
    selection.filterOps.length === 0 &&
    selection.includeIds.size === 0 &&
    selection.excludeIds.size === 0
  );

  // Determine server attachment query param
  const serverAttachment: "all" | "attached" | "detached" | undefined = useMemo(() => {
    if (hostId === undefined || hostId === null) return undefined;
    if (!canUseServerAttachment) return "all";
    if (tab === "selected") return "attached";
    if (tab === "unselected") return "detached";
    return "all";
  }, [hostId, canUseServerAttachment, tab]);

  // Fetch page of images
  const fetchPage = useCallback(
    async (cursor: string | null = null, isMore = false) => {
      if (!isOpen) return;

      if (abortControllerRef.current) {
        abortControllerRef.current.abort();
      }
      const controller = new AbortController();
      abortControllerRef.current = controller;

      if (isMore) {
        setLoadingMore(true);
      } else {
        setLoading(true);
        setError(null);
      }

      try {
        const res = await api.adminGetImages(
          {
            search: currentFilter.search,
            os_type: currentFilter.os_type,
            protocol: currentFilter.protocol,
            enabled: currentFilter.enabled,
            host_id: hostId ?? undefined,
            attachment: serverAttachment,
            cursor,
            per_page: PER_PAGE,
          },
          { signal: controller.signal }
        );

        if (controller.signal.aborted) return;

        if (isMore) {
          setItems((prev) => {
            const seen = new Set(prev.map((i) => i.id));
            const newItems = res.data.filter((i) => !seen.has(i.id));
            return [...prev, ...newItems];
          });
        } else {
          setItems(res.data);
        }
        setNextCursor(res.next_cursor);
        setCounts(res.counts);
        onCountsUpdate?.(res.counts);
        setError(null);
      } catch (err: unknown) {
        if (controller.signal.aborted) return;
        if (err instanceof Error && (err.name === "AbortError" || err.message.includes("aborted"))) {
          return;
        }
        setError(err instanceof Error ? err.message : "Failed to load images");
      } finally {
        if (!controller.signal.aborted) {
          if (isMore) {
            setLoadingMore(false);
          } else {
            setLoading(false);
          }
        }
      }
    },
    [isOpen, currentFilter, hostId, serverAttachment, onCountsUpdate]
  );

  // Trigger initial fetch when filters or server attachment change
  useEffect(() => {
    if (!isOpen) return;
    fetchPage(null, false);

    return () => {
      if (abortControllerRef.current) {
        abortControllerRef.current.abort();
      }
    };
  }, [isOpen, fetchPage]);

  // Trigger loadMore
  const loadMore = useCallback(() => {
    if (nextCursor !== null && !loading && !loadingMore && !error) {
      fetchPage(nextCursor, true);
    }
  }, [nextCursor, loading, loadingMore, error, fetchPage]);

  // Filter items client-side if server attachment parameter is not usable
  const visibleItems = useMemo(() => {
    if (canUseServerAttachment) {
      return items;
    }
    return items.filter((item) => {
      if (tab === "all") return true;
      const selected = isImageSelected(selection, item);
      return tab === "selected" ? selected : !selected;
    });
  }, [items, tab, canUseServerAttachment, selection]);

  // If client-side tab filtering leaves fewer than 20 items, auto loadMore until filled
  useEffect(() => {
    if (
      !canUseServerAttachment &&
      tab !== "all" &&
      visibleItems.length < 20 &&
      nextCursor !== null &&
      !loading &&
      !loadingMore &&
      !error
    ) {
      loadMore();
    }
  }, [
    canUseServerAttachment,
    tab,
    visibleItems.length,
    nextCursor,
    loading,
    loadingMore,
    error,
    loadMore,
  ]);

  // Virtualizer configuration
  // eslint-disable-next-line react-hooks/incompatible-library
  const rowVirtualizer = useVirtualizer({
    count: visibleItems.length,
    getScrollElement: () => parentRef.current,
    estimateSize: () => 64,
    overscan: 10,
  });

  // Load more on virtualizer scroll near end
  const virtualItems = rowVirtualizer.getVirtualItems();
  const lastVirtualItem = virtualItems[virtualItems.length - 1];

  useEffect(() => {
    if (!lastVirtualItem) return;
    if (
      lastVirtualItem.index >= visibleItems.length - 10 &&
      nextCursor !== null &&
      !loading &&
      !loadingMore &&
      !error
    ) {
      loadMore();
    }
  }, [
    lastVirtualItem,
    visibleItems.length,
    nextCursor,
    loading,
    loadingMore,
    error,
    loadMore,
  ]);

  const handleScroll = (e: React.UIEvent<HTMLDivElement>) => {
    const target = e.currentTarget;
    if (target.scrollHeight - target.scrollTop - target.clientHeight < 300) {
      if (nextCursor !== null && !loading && !loadingMore && !error) {
        loadMore();
      }
    }
  };

  // Tabs count calculation
  const { allCount, selectedCount, unselectedCount } = useMemo(() => {
    if (isEmptyFilter(currentFilter)) {
      const all = counts?.overall.catalog ?? 0;
      const selected = estimateSelectedCount(selection, counts).selected;
      const unselected = Math.max(0, all - selected);
      return { allCount: all, selectedCount: selected, unselectedCount: unselected };
    } else {
      const all = counts?.filtered.catalog ?? 0;
      let selected: number;
      if (selection.base.kind === "all" && selection.filterOps.length === 0) {
        selected = Math.max(0, all - selection.excludeIds.size);
      } else if (selection.base.kind === "none" && selection.filterOps.length === 0) {
        selected = Math.min(all, selection.includeIds.size);
      } else if (selection.base.kind === "current" && selection.filterOps.length === 0) {
        selected = counts?.filtered.attached ?? 0;
      } else {
        selected = estimateSelectedCount(selection, counts, { filter: currentFilter }).selected;
      }
      const unselected = Math.max(0, all - selected);
      return { allCount: all, selectedCount: selected, unselectedCount: unselected };
    }
  }, [currentFilter, counts, selection]);

  // Bulk actions
  const handleSelectAll = () => {
    const res = selectAllMatching(selection, currentFilter, flow);
    if (res.limitReached) {
      toast.error(t("selectionFilterOpLimitReached"));
      return;
    }
    onChange(res.state);
  };

  const handleClearAll = () => {
    const res = clearAllMatching(selection, currentFilter, flow);
    if (res.limitReached) {
      toast.error(t("selectionFilterOpLimitReached"));
      return;
    }
    onChange(res.state);
  };

  const handleToggleItem = (item: ImageItem) => {
    const res = toggleImage(selection, item);
    if (res.limitReached) {
      toast.error(t("selectionOverrideLimitReached"));
      return;
    }
    onChange(res.state);
  };

  // Footer summary text
  const summaryText = useMemo(() => {
    const est = estimateSelectedCount(
      selection,
      counts,
      isEmptyFilter(currentFilter) ? undefined : { filter: currentFilter }
    );

    if (est.total === 0 && !counts) {
      if (selection.base.kind === "all" && selection.excludeIds.size === 0) {
        return t("allImagesSelectedDefault");
      }
      return t("imagesSelectedSummary")
        .replace("{selected}", "0")
        .replace("{total}", "0");
    }

    if (est.total > 0 && est.selected === est.total && !est.isApproximate) {
      return t("allImagesSelectedSummary").replace("{total}", String(est.total));
    }

    if (est.isApproximate) {
      return t("imagesSelectedApproxSummary")
        .replace("{selected}", String(est.selected))
        .replace("{total}", String(est.total));
    }

    return t("imagesSelectedSummary")
      .replace("{selected}", String(est.selected))
      .replace("{total}", String(est.total));
  }, [selection, counts, currentFilter, t]);

  if (!isOpen) {
    return null;
  }

  return (
    <div
      className="fixed inset-0 z-[60] overflow-hidden"
      aria-labelledby="manage-node-images-title"
      role="dialog"
      aria-modal="true"
    >
      {/* Backdrop */}
      <div
        className="fixed inset-0 bg-black/50 backdrop-blur-sm transition-opacity"
        onClick={onClose}
        aria-hidden="true"
      />

      <div className="fixed inset-y-0 right-0 max-w-full flex pl-10">
        <div className="w-screen max-w-2xl bg-white dark:bg-[#0e131f] border-l border-gray-200 dark:border-gray-800 shadow-2xl flex flex-col justify-between">
          {/* Header */}
          <div className="p-6 border-b border-gray-200 dark:border-gray-800 flex items-start justify-between bg-gray-50/50 dark:bg-gray-900/20">
            <div className="flex items-start space-x-3">
              <div className="p-2.5 rounded-xl bg-indigo-50 dark:bg-indigo-950/40 border border-indigo-100 dark:border-indigo-900/40 text-indigo-600 dark:text-indigo-400">
                <Layers className="w-5 h-5" />
              </div>
              <div>
                <h2
                  id="manage-node-images-title"
                  className="text-base font-bold text-gray-900 dark:text-white leading-snug"
                >
                  {t("manageNodeImagesTitle")}
                </h2>
                <p className="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                  {t("manageNodeImagesSubtitle")}
                </p>
              </div>
            </div>

            <button
              type="button"
              onClick={onClose}
              className="p-2 text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-800 transition"
              aria-label={t("closeMenu") || "Close"}
            >
              <X className="w-5 h-5" />
            </button>
          </div>

          {/* Controls: Search, Filters, Tabs & Bulk Actions */}
          <div className="p-4 border-b border-gray-200 dark:border-gray-800 space-y-3 bg-gray-50/30 dark:bg-gray-900/10">
            {/* Search Input */}
            <div className="relative">
              <Search className="w-4 h-4 text-gray-400 absolute left-3 top-1/2 -translate-y-1/2" />
              <input
                type="text"
                value={search}
                onChange={(e) => setSearch(e.target.value)}
                placeholder={t("searchImagesPlaceholder")}
                className="w-full pl-9 pr-8 py-2 rounded-xl bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 text-xs text-gray-900 dark:text-white placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition"
              />
              {search && (
                <button
                  type="button"
                  onClick={() => setSearch("")}
                  className="absolute right-2.5 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600 dark:hover:text-gray-200"
                  aria-label="Clear search"
                >
                  <X className="w-3.5 h-3.5" />
                </button>
              )}
            </div>

            {/* Filter Dropdowns */}
            <div className="grid grid-cols-3 gap-2">
              <select
                value={osTypeFilter}
                onChange={(e) =>
                  setOsTypeFilter(
                    e.target.value as "all" | "linux" | "windows" | "bsd" | "other"
                  )
                }
                className="w-full px-2.5 py-1.5 rounded-lg bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 text-xs text-gray-800 dark:text-gray-200 focus:outline-none focus:ring-2 focus:ring-indigo-500 cursor-pointer"
              >
                <option value="all">{t("filterAllOsTypes")}</option>
                <option value="linux">Linux</option>
                <option value="windows">Windows</option>
                <option value="bsd">BSD</option>
                <option value="other">Other</option>
              </select>

              <select
                value={protocolFilter}
                onChange={(e) =>
                  setProtocolFilter(
                    e.target.value as "all" | "simplestreams" | "lxd" | "incus" | "oci"
                  )
                }
                className="w-full px-2.5 py-1.5 rounded-lg bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 text-xs text-gray-800 dark:text-gray-200 focus:outline-none focus:ring-2 focus:ring-indigo-500 cursor-pointer"
              >
                <option value="all">{t("filterAllProtocols")}</option>
                <option value="simplestreams">simplestreams</option>
                <option value="lxd">lxd</option>
                <option value="incus">incus</option>
                <option value="oci">oci</option>
              </select>

              <select
                value={enabledFilter}
                onChange={(e) =>
                  setEnabledFilter(e.target.value as "all" | "true" | "false")
                }
                className="w-full px-2.5 py-1.5 rounded-lg bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 text-xs text-gray-800 dark:text-gray-200 focus:outline-none focus:ring-2 focus:ring-indigo-500 cursor-pointer"
              >
                <option value="all">{t("filterAllStatuses")}</option>
                <option value="true">{t("filterStatusEnabled")}</option>
                <option value="false">{t("filterStatusDisabled")}</option>
              </select>
            </div>

            {/* Tabs & Bulk Actions */}
            <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-2 pt-1">
              {/* Tabs */}
              <div className="flex items-center gap-1 p-0.5 bg-gray-100 dark:bg-gray-800/80 rounded-xl">
                {(["all", "selected", "unselected"] as const).map((tabKey) => {
                  const label =
                    tabKey === "all"
                      ? t("tabAllImages")
                      : tabKey === "selected"
                      ? t("tabSelectedImages")
                      : t("tabUnselectedImages");
                  const count =
                    tabKey === "all"
                      ? allCount
                      : tabKey === "selected"
                      ? selectedCount
                      : unselectedCount;
                  const isActive = tab === tabKey;
                  return (
                    <button
                      key={tabKey}
                      type="button"
                      onClick={() => setTab(tabKey)}
                      className={`flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium transition ${
                        isActive
                          ? "bg-white dark:bg-gray-700 text-gray-900 dark:text-white shadow-sm"
                          : "text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-white"
                      }`}
                    >
                      <span>{label}</span>
                      <span
                        className={`px-1.5 py-0.2 rounded-full text-[10px] font-semibold ${
                          isActive
                            ? "bg-indigo-100 dark:bg-indigo-950/60 text-indigo-700 dark:text-indigo-300"
                            : "bg-gray-200 dark:bg-gray-700/60 text-gray-600 dark:text-gray-400"
                        }`}
                      >
                        {count}
                      </span>
                    </button>
                  );
                })}
              </div>

              {/* Bulk Actions */}
              <div className="flex items-center gap-1.5">
                <button
                  type="button"
                  onClick={handleSelectAll}
                  className="flex items-center gap-1.5 px-2.5 py-1.5 rounded-lg text-xs font-medium text-indigo-600 dark:text-indigo-400 hover:bg-indigo-50 dark:hover:bg-indigo-950/30 transition border border-indigo-200 dark:border-indigo-800/60"
                >
                  <CheckSquare className="w-3.5 h-3.5" />
                  <span>
                    {isEmptyFilter(currentFilter)
                      ? t("selectAllInCatalog")
                      : t("selectAllMatchingFilter")}
                  </span>
                </button>
                <button
                  type="button"
                  onClick={handleClearAll}
                  className="flex items-center gap-1.5 px-2.5 py-1.5 rounded-lg text-xs font-medium text-gray-600 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-800 transition border border-gray-200 dark:border-gray-700"
                >
                  <Square className="w-3.5 h-3.5" />
                  <span>
                    {isEmptyFilter(currentFilter)
                      ? t("clearAllInCatalog")
                      : t("clearAllMatchingFilter")}
                  </span>
                </button>
              </div>
            </div>
          </div>

          {/* Virtualized List Container */}
          <div
            ref={parentRef}
            onScroll={handleScroll}
            className="flex-1 overflow-y-auto p-4 space-y-2"
          >
            {loading && items.length === 0 ? (
              <div className="flex flex-col items-center justify-center h-64 text-gray-400">
                <Loader2 className="w-8 h-8 animate-spin text-indigo-600 mb-2" />
                <span className="text-xs">{t("loadingImages")}</span>
              </div>
            ) : error && items.length === 0 ? (
              <div className="flex flex-col items-center justify-center h-64 text-rose-500">
                <AlertTriangle className="w-8 h-8 mb-2" />
                <span className="text-xs font-medium mb-3">{error || t("errorLoadingImages")}</span>
                <button
                  type="button"
                  onClick={() => fetchPage(null, false)}
                  className="px-3 py-1.5 rounded-lg bg-gray-100 dark:bg-gray-800 text-xs font-medium hover:bg-gray-200 dark:hover:bg-gray-700 text-gray-800 dark:text-gray-200 transition"
                >
                  {t("retryBtn")}
                </button>
              </div>
            ) : visibleItems.length === 0 ? (
              <div className="flex flex-col items-center justify-center h-64 text-gray-400">
                <Layers className="w-8 h-8 mb-2 opacity-40" />
                <span className="text-xs">{t("noImagesFound")}</span>
              </div>
            ) : (
              <div
                style={{
                  height: `${rowVirtualizer.getTotalSize()}px`,
                  width: "100%",
                  position: "relative",
                }}
              >
                {rowVirtualizer.getVirtualItems().map((virtualRow) => {
                  const item = visibleItems[virtualRow.index];
                  if (!item) return null;
                  const isSelected = isImageSelected(selection, item);

                  return (
                    <div
                      key={virtualRow.key}
                      data-index={virtualRow.index}
                      ref={rowVirtualizer.measureElement}
                      style={{
                        position: "absolute",
                        top: 0,
                        left: 0,
                        width: "100%",
                        transform: `translateY(${virtualRow.start}px)`,
                      }}
                      className="pb-2"
                    >
                      <div
                        role="checkbox"
                        aria-checked={isSelected}
                        tabIndex={0}
                        onClick={() => handleToggleItem(item)}
                        onKeyDown={(e) => {
                          if (e.key === " " || e.key === "Enter") {
                            e.preventDefault();
                            handleToggleItem(item);
                          }
                        }}
                        className={`flex items-start gap-3 p-3 rounded-xl border cursor-pointer transition select-none ${
                          isSelected
                            ? "bg-indigo-50/60 dark:bg-indigo-950/20 border-indigo-200 dark:border-indigo-800/60"
                            : "bg-white dark:bg-gray-800/40 border-gray-200 dark:border-gray-800 hover:bg-gray-50 dark:hover:bg-gray-800/70"
                        } ${!item.enabled ? "opacity-75" : ""}`}
                      >
                        <input
                          type="checkbox"
                          checked={isSelected}
                          onChange={() => {}} // Click handled by parent container
                          className="mt-0.5 rounded border-gray-300 dark:border-gray-600 text-indigo-600 focus:ring-indigo-500 cursor-pointer"
                        />
                        <div className="flex-1 min-w-0">
                          <div className="flex items-center gap-2 flex-wrap">
                            <span className="font-mono text-xs font-bold text-gray-900 dark:text-white truncate">
                              {item.name}
                            </span>
                            <span
                              className={`px-1.5 py-0.5 rounded text-[10px] font-bold uppercase tracking-wider ${
                                item.os_type === "linux"
                                  ? "bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border border-emerald-500/20"
                                  : item.os_type === "windows"
                                  ? "bg-sky-500/10 text-sky-600 dark:text-sky-400 border border-sky-500/20"
                                  : item.os_type === "bsd"
                                  ? "bg-purple-500/10 text-purple-600 dark:text-purple-400 border border-purple-500/20"
                                  : "bg-gray-500/10 text-gray-600 dark:text-gray-400 border border-gray-500/20"
                              }`}
                            >
                              {item.os_type}
                            </span>
                            <span className="px-1.5 py-0.5 rounded text-[10px] font-mono bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-300 border border-gray-200 dark:border-gray-700">
                              {item.protocol}
                            </span>
                            {!item.enabled && (
                              <span className="px-1.5 py-0.5 rounded text-[10px] font-semibold bg-rose-500/10 text-rose-600 dark:text-rose-400 border border-rose-500/20">
                                {t("filterStatusDisabled")}
                              </span>
                            )}
                          </div>
                          <div className="text-[11px] text-gray-500 dark:text-gray-400 font-mono truncate mt-1">
                            {item.source}
                          </div>
                        </div>
                      </div>
                    </div>
                  );
                })}
              </div>
            )}

            {loadingMore && (
              <div className="flex items-center justify-center py-3 text-gray-400 text-xs">
                <Loader2 className="w-4 h-4 animate-spin text-indigo-600 mr-2" />
                <span>{t("loadingMoreImages")}</span>
              </div>
            )}
          </div>

          {/* Footer */}
          <div className="p-4 border-t border-gray-200 dark:border-gray-800 flex items-center justify-between bg-gray-50/50 dark:bg-gray-900/20">
            <div className="flex items-center gap-3">
              <div className="px-3 py-1.5 rounded-lg bg-gray-100 dark:bg-gray-800 text-xs font-medium text-gray-700 dark:text-gray-300">
                {summaryText}
              </div>
              {hasStagedChanges(selection, flow) && (
                <button
                  type="button"
                  onClick={() => onChange(createInitialSelection(flow))}
                  className="flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium text-gray-600 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-800 border border-gray-200 dark:border-gray-700 transition"
                >
                  <RotateCcw className="w-3.5 h-3.5" />
                  <span>{t("resetChangesBtn")}</span>
                </button>
              )}
            </div>

            <button
              type="button"
              onClick={onClose}
              className="px-5 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-semibold shadow-md shadow-indigo-500/20 transition"
            >
              {t("doneBtn")}
            </button>
          </div>
        </div>
      </div>
    </div>
  );
}
