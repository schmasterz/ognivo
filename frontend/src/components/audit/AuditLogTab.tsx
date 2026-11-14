"use client";

import React, { useState, useEffect, useCallback, useRef } from "react";
import {
  ShieldCheck,
  ShieldAlert,
  Download,
  RefreshCw,
  FileSpreadsheet,
  FileText,
  ChevronDown,
  X,
} from "lucide-react";
import {
  AdminAuditEntry,
  AuditEntry,
  AuditFilters,
  AuditVerifyResponse,
  CursorPage,
  api,
} from "@/lib/api";
import { useI18n } from "@/lib/i18n/I18nContext";
import { useToast } from "@/lib/toast/ToastContext";
import { AuditTable } from "./AuditTable";
import { AuditFiltersBar } from "./AuditFiltersBar";
import { AuditDetailDrawer } from "./AuditDetailDrawer";
import {
  parseFiltersFromUrl,
  syncFiltersToUrl,
  computeCursorPaginationMeta,
} from "./auditUrl";

export interface AuditLogTabProps {
  isAdmin: boolean;
  onNavigate?: (section: "vms" | "networking" | "users", id?: string | null) => void;
}

/**
 * Unified Audit Log & Activity Tab component for both tenant users and platform administrators.
 * Uses keyset cursor pagination mapped onto page-by-page navigation via PaginationBar.
 */
export function AuditLogTab({ isAdmin, onNavigate }: AuditLogTabProps) {
  const { t } = useI18n();
  const { toast } = useToast();

  const [filters, setFilters] = useState<AuditFilters>(parseFiltersFromUrl);
  const [perPage, setPerPage] = useState<number>(() => {
    const initial = parseFiltersFromUrl().per_page;
    return initial && [5, 10, 25, 50].includes(initial) ? initial : 25;
  });
  const [currentPage, setCurrentPage] = useState<number>(1);
  const [lastPage, setLastPage] = useState<number>(1);
  const [total, setTotal] = useState<number>(0);
  const [entries, setEntries] = useState<(AuditEntry | AdminAuditEntry)[]>([]);
  const [loading, setLoading] = useState<boolean>(true);

  // Keyset cursor map indexed by 1-based page number (Page 1 always uses null cursor)
  const pageCursorsRef = useRef<Record<number, string | null>>({ 1: null });

  // Detail Drawer state
  const [selectedEntry, setSelectedEntry] = useState<AuditEntry | AdminAuditEntry | null>(null);
  const [drawerOpen, setDrawerOpen] = useState(false);

  // Admin Export dropdown state
  const [exportOpen, setExportOpen] = useState(false);
  const [exporting, setExporting] = useState(false);
  const exportDropdownRef = useRef<HTMLDivElement>(null);

  // Admin Verification state
  const [verifying, setVerifying] = useState(false);
  const [verifyResult, setVerifyResult] = useState<AuditVerifyResponse | null>(null);

  // Close export dropdown when clicking outside
  useEffect(() => {
    if (!isAdmin) return;
    function handleClickOutside(event: MouseEvent) {
      if (
        exportDropdownRef.current &&
        !exportDropdownRef.current.contains(event.target as Node)
      ) {
        setExportOpen(false);
      }
    }
    document.addEventListener("mousedown", handleClickOutside);
    return () => document.removeEventListener("mousedown", handleClickOutside);
  }, [isAdmin]);

  // Synchronize filter state to URL query string whenever filters or perPage change
  const initialMount = useRef(true);
  useEffect(() => {
    if (initialMount.current) {
      initialMount.current = false;
      return;
    }
    syncFiltersToUrl({
      ...filters,
      per_page: perPage !== 25 ? perPage : undefined,
    });
  }, [filters, perPage]);

  // Execute API call for a single cursor page depending on role
  const requestCursorPage = useCallback(
    async (
      activeFilters: AuditFilters,
      cursor: string | null
    ): Promise<CursorPage<AuditEntry | AdminAuditEntry>> => {
      const effectiveFilters: AuditFilters = {
        ...activeFilters,
        per_page: perPage,
      };
      if (isAdmin) {
        return api.getAdminAudit(effectiveFilters, cursor);
      }
      return api.getAudit(effectiveFilters, cursor);
    },
    [isAdmin, perPage]
  );

  // Fetch a specific 1-based target page using cached or sequentially traversed keyset cursors
  const fetchPage = useCallback(
    async (targetPage: number) => {
      const safeTarget = Math.max(1, targetPage);
      setLoading(true);
      try {
        // Determine highest page number for which we already have a keyset cursor
        let cursorPage = safeTarget;
        while (cursorPage > 1 && !(cursorPage in pageCursorsRef.current)) {
          cursorPage--;
        }

        let currentRes: CursorPage<AuditEntry | AdminAuditEntry> | null = null;

        // Walk forward from the nearest known cursor up to safeTarget without using SQL OFFSET
        for (let p = cursorPage; p <= safeTarget; p++) {
          const cursorForPage = p === 1 ? null : (pageCursorsRef.current[p] ?? null);
          if (p > 1 && cursorForPage === null) {
            // Reached the end of available keyset pages before safeTarget
            break;
          }

          const res = await requestCursorPage(filters, cursorForPage);
          currentRes = res;

          if (res.next_cursor) {
            pageCursorsRef.current[p + 1] = res.next_cursor;
          } else {
            delete pageCursorsRef.current[p + 1];
            if (p < safeTarget) {
              // No further pages exist beyond page p
              const meta = computeCursorPaginationMeta({
                currentPage: p,
                perPage,
                itemsCount: res.data.length,
                nextCursor: null,
                reportedTotal: res.total,
              });
              setEntries(res.data);
              setCurrentPage(p);
              setTotal(meta.total);
              setLastPage(meta.lastPage);
              return;
            }
          }
        }

        if (currentRes) {
          const meta = computeCursorPaginationMeta({
            currentPage: safeTarget,
            perPage,
            itemsCount: currentRes.data.length,
            nextCursor: currentRes.next_cursor,
            reportedTotal: currentRes.total,
          });
          setEntries(currentRes.data);
          setCurrentPage(safeTarget);
          setTotal(meta.total);
          setLastPage(meta.lastPage);
        }
      } catch (err: unknown) {
        console.error("Error fetching audit entries:", err);
        toast.error(isAdmin ? "Failed to load audit logs" : "Failed to load activity log");
      } finally {
        setLoading(false);
      }
    },
    [filters, isAdmin, perPage, requestCursorPage, toast]
  );

  // Reset cursor history and reload Page 1 whenever filters, perPage, or role change
  useEffect(() => {
    pageCursorsRef.current = { 1: null };
    let ignore = false;
    queueMicrotask(() => {
      if (!ignore) {
        void fetchPage(1);
      }
    });
    return () => {
      ignore = true;
    };
  }, [fetchPage]);

  const handleFilterChange = (newFilters: AuditFilters) => {
    pageCursorsRef.current = { 1: null };
    setCurrentPage(1);
    setFilters(newFilters);
  };

  const handleResetFilters = () => {
    pageCursorsRef.current = { 1: null };
    setCurrentPage(1);
    setFilters({});
  };

  const handlePageChange = (nextPage: number) => {
    if (nextPage < 1 || nextPage > lastPage || nextPage === currentPage || loading) {
      return;
    }
    void fetchPage(nextPage);
  };

  const handlePerPageChange = (newPerPage: number) => {
    pageCursorsRef.current = { 1: null };
    setPerPage(newPerPage);
    setCurrentPage(1);
  };

  const handleSelectEntry = (entry: AuditEntry | AdminAuditEntry) => {
    setSelectedEntry(entry);
    setDrawerOpen(true);
  };

  const handleFilterRelated = (requestId: string) => {
    pageCursorsRef.current = { 1: null };
    setCurrentPage(1);
    setFilters((prev) => ({
      ...prev,
      request_id: requestId,
    }));
  };

  // Admin Export handler
  const handleExport = async (format: "ndjson" | "csv") => {
    setExportOpen(false);
    setExporting(true);
    try {
      await api.exportAdminAudit(filters, format);
      toast.success(`Audit log export (${format.toUpperCase()}) downloaded successfully.`);
    } catch (err: unknown) {
      console.error("Export error:", err);
      toast.error(err instanceof Error ? err.message : "Failed to export audit logs");
    } finally {
      setExporting(false);
    }
  };

  // Admin Verify integrity handler
  const handleVerify = async () => {
    setVerifying(true);
    try {
      const res = await api.verifyAdminAudit();
      setVerifyResult(res);
      if (res.ok) {
        toast.success(t("verifySuccess").replace(":count", String(res.checked)));
      } else {
        toast.error(t("verifyFailed").replace(":id", String(res.first_failed_id ?? "unknown")));
      }
    } catch (err: unknown) {
      console.error("Integrity verification error:", err);
      toast.error(err instanceof Error ? err.message : "Verification request failed");
    } finally {
      setVerifying(false);
    }
  };

  return (
    <div className="space-y-6">
      {/* Tab Header with Role-Aware Badge and Action Controls */}
      <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
          <div className="flex items-center space-x-2">
            <h1 className="text-xl font-extrabold text-gray-900 dark:text-white tracking-tight">
              {isAdmin ? t("adminAuditTitle") : t("auditTitle")}
            </h1>
            {isAdmin ? (
              <span className="px-2 py-0.5 rounded-full text-[10px] font-bold bg-purple-500/10 text-purple-600 dark:text-purple-400 border border-purple-500/20">
                Admin
              </span>
            ) : (
              <span className="px-2 py-0.5 rounded-full text-[10px] font-bold bg-blue-500/10 text-blue-600 dark:text-blue-400">
                Activity
              </span>
            )}
          </div>
          <p className="text-xs text-gray-500 dark:text-gray-400 mt-1">
            {t("auditSubtitle")}
          </p>
        </div>

        {/* Action Controls */}
        <div className="flex items-center flex-wrap gap-2.5 self-start sm:self-auto">
          {isAdmin && (
            <>
              {/* Verify Integrity Button */}
              <button
                onClick={handleVerify}
                disabled={verifying}
                className="inline-flex items-center space-x-2 px-3 py-2 bg-emerald-50 dark:bg-emerald-950/40 text-emerald-700 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800/80 rounded-xl hover:bg-emerald-100 dark:hover:bg-emerald-900/60 font-semibold text-xs transition shadow-sm disabled:opacity-50"
                title="Verify cryptographic seals and hash chain linkage"
              >
                <ShieldCheck className={`w-4 h-4 ${verifying ? "animate-spin" : ""}`} />
                <span>{verifying ? t("verifying") : t("btnVerifyIntegrity")}</span>
              </button>

              {/* Export Dropdown */}
              <div className="relative" ref={exportDropdownRef}>
                <button
                  onClick={() => setExportOpen((prev) => !prev)}
                  disabled={exporting}
                  className="inline-flex items-center space-x-1.5 px-3 py-2 bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-200 border border-gray-200 dark:border-gray-700/80 rounded-xl hover:bg-gray-50 dark:hover:bg-gray-750 font-semibold text-xs transition shadow-sm disabled:opacity-50"
                >
                  <Download className={`w-3.5 h-3.5 ${exporting ? "animate-bounce" : ""}`} />
                  <span>{exporting ? t("exporting") : t("btnExport")}</span>
                  <ChevronDown className="w-3.5 h-3.5 opacity-60" />
                </button>

                {exportOpen && (
                  <div className="absolute right-0 mt-1 w-44 bg-white dark:bg-[#131b2e] border border-gray-200 dark:border-gray-700/80 rounded-xl shadow-xl py-1 z-30 animate-fadeIn text-xs">
                    <button
                      onClick={() => handleExport("ndjson")}
                      className="w-full text-left px-3 py-2 hover:bg-gray-50 dark:hover:bg-gray-800 flex items-center space-x-2 text-gray-700 dark:text-gray-200 transition"
                    >
                      <FileText className="w-3.5 h-3.5 text-blue-500" />
                      <span>{t("btnExportNdjson")}</span>
                    </button>
                    <button
                      onClick={() => handleExport("csv")}
                      className="w-full text-left px-3 py-2 hover:bg-gray-50 dark:hover:bg-gray-800 flex items-center space-x-2 text-gray-700 dark:text-gray-200 transition"
                    >
                      <FileSpreadsheet className="w-3.5 h-3.5 text-emerald-500" />
                      <span>{t("btnExportCsv")}</span>
                    </button>
                  </div>
                )}
              </div>
            </>
          )}

          {/* Refresh Button */}
          <button
            onClick={() => void fetchPage(currentPage)}
            disabled={loading}
            className="p-2.5 text-gray-500 dark:text-gray-400 hover:text-gray-900 dark:hover:text-white bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700/80 rounded-xl hover:bg-gray-50 dark:hover:bg-gray-700 transition shadow-sm"
            title={isAdmin ? "Refresh audit log" : "Refresh activity log"}
          >
            <RefreshCw className={`w-4 h-4 ${loading ? "animate-spin text-blue-500" : ""}`} />
          </button>
        </div>
      </div>

      {/* Admin Verification Result Banner */}
      {isAdmin && verifyResult && (
        <div
          className={`flex items-start justify-between p-4 rounded-2xl border transition ${
            verifyResult.ok
              ? "bg-emerald-500/10 border-emerald-500/30 text-emerald-900 dark:text-emerald-200"
              : "bg-red-500/10 border-red-500/30 text-red-900 dark:text-red-200"
          }`}
        >
          <div className="flex items-start space-x-3">
            {verifyResult.ok ? (
              <ShieldCheck className="w-5 h-5 text-emerald-500 mt-0.5 flex-shrink-0" />
            ) : (
              <ShieldAlert className="w-5 h-5 text-red-500 mt-0.5 flex-shrink-0" />
            )}
            <div>
              <h4 className="text-sm font-bold">
                {verifyResult.ok
                  ? "Cryptographic Seals Verified"
                  : "Integrity Verification Failed"}
              </h4>
              <p className="text-xs mt-0.5 opacity-90">
                {verifyResult.ok
                  ? t("verifySuccess").replace(":count", String(verifyResult.checked))
                  : t("verifyFailed").replace(
                      ":id",
                      String(verifyResult.first_failed_id ?? "unknown")
                    )}
              </p>
            </div>
          </div>
          <button
            onClick={() => setVerifyResult(null)}
            className="p-1 rounded-lg opacity-70 hover:opacity-100 hover:bg-black/5 dark:hover:bg-white/5 transition"
            title="Dismiss banner"
          >
            <X className="w-4 h-4" />
          </button>
        </div>
      )}

      {/* Filter Toolbar */}
      <AuditFiltersBar
        filters={filters}
        isAdmin={isAdmin}
        onFilterChange={handleFilterChange}
        onReset={handleResetFilters}
      />

      {/* Main Table with Keyset Cursor Pagination Bar */}
      <AuditTable
        entries={entries}
        isAdmin={isAdmin}
        loading={loading}
        pagination={{
          currentPage,
          lastPage,
          perPage,
          total,
          onPageChange: handlePageChange,
          onPerPageChange: handlePerPageChange,
        }}
        onSelectEntry={handleSelectEntry}
      />

      {/* Slide-over Detail Drawer */}
      <AuditDetailDrawer
        entry={selectedEntry}
        isAdmin={isAdmin}
        isOpen={drawerOpen}
        onClose={() => setDrawerOpen(false)}
        onFilterRelated={handleFilterRelated}
        onNavigate={onNavigate}
      />
    </div>
  );
}
