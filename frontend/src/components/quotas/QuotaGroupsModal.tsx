"use client";

import React, { useCallback, useEffect, useState } from "react";
import { X, Layers, Plus, Edit2, Trash2, Sparkles, Users, RefreshCw, AlertCircle } from "lucide-react";
import { QuotaGroup, api } from "@/lib/api";
import { extractPaginationMeta } from "@/lib/api/client";
import { PaginationBar } from "@/components/ui/PaginationBar";
import { useI18n } from "@/lib/i18n/I18nContext";
import { useToast } from "@/lib/toast/ToastContext";

interface QuotaGroupsModalProps {
  quotaGroups: QuotaGroup[];
  onClose: () => void;
  onCreateGroup: () => void;
  onEditGroup: (group: QuotaGroup) => void;
  onRequestDeleteGroup?: (group: QuotaGroup) => void;
  onGroupDeleted: () => void;
}

export function QuotaGroupsModal({
  quotaGroups,
  onClose,
  onCreateGroup,
  onEditGroup,
  onRequestDeleteGroup,
  onGroupDeleted,
}: QuotaGroupsModalProps) {
  const { t } = useI18n();
  const { toast } = useToast();
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(10);
  const [lastPage, setLastPage] = useState(1);
  const [total, setTotal] = useState(quotaGroups.length);
  const [groups, setGroups] = useState<QuotaGroup[]>(quotaGroups);
  const [loading, setLoading] = useState(false);
  const [deletingId, setDeletingId] = useState<number | null>(null);
  const [error, setError] = useState<string | null>(null);

  const fetchGroups = useCallback(async () => {
    try {
      setLoading(true);
      const res = await api.getQuotaGroups({ page, per_page: perPage });
      setGroups(res.data);
      const meta = extractPaginationMeta(res, perPage);
      setLastPage(meta.lastPage);
      setTotal(meta.total);
      if (page > meta.lastPage && meta.lastPage >= 1) {
        setPage(meta.lastPage);
      }
    } catch {
      setGroups(quotaGroups.slice((page - 1) * perPage, page * perPage));
      setTotal(quotaGroups.length);
      setLastPage(Math.max(1, Math.ceil(quotaGroups.length / perPage)));
    } finally {
      setLoading(false);
    }
  }, [page, perPage, quotaGroups]);

  useEffect(() => {
    fetchGroups();
  }, [fetchGroups]);

  const formatQuotaLimit = (val: number, unit = "") => {
    if (val === -1) return "∞";
    return `${val} ${unit}`.trim();
  };

  const handleDelete = async (group: QuotaGroup) => {
    if (group.is_default) {
      toast.warning("The default quota group cannot be deleted. Set another group as default first.");
      return;
    }

    if (onRequestDeleteGroup) {
      onRequestDeleteGroup(group);
      return;
    }

    try {
      setDeletingId(group.id);
      setError(null);
      await api.deleteQuotaGroup(group.id);
      toast.success(t("quotaGroupDeletedNotice"));
      await fetchGroups();
      onGroupDeleted();
    } catch (err: unknown) {
      if (err instanceof Error) {
        setError(err.message);
        toast.error(err.message);
      } else {
        setError("Failed to delete quota group.");
      }
    } finally {
      setDeletingId(null);
    }
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-sm animate-fadeIn">
      <div className="relative w-full max-w-4xl bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-3xl p-6 sm:p-8 shadow-2xl overflow-y-auto max-h-[90vh] transition-colors">
        {/* Header */}
        <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6 pb-4 border-b border-gray-100 dark:border-gray-800">
          <div className="flex items-center space-x-3">
            <div className="p-2.5 rounded-2xl bg-indigo-500/10 text-indigo-600 dark:text-indigo-400">
              <Layers className="w-6 h-6" />
            </div>
            <div>
              <h2 className="text-xl font-bold text-gray-900 dark:text-white tracking-tight">
                {t("manageQuotaGroups")}
              </h2>
              <p className="text-xs text-gray-500 dark:text-gray-400">
                {t("quotaGroupsSubtitle")}
              </p>
            </div>
          </div>

          <div className="flex items-center space-x-2 self-start sm:self-auto">
            <button
              onClick={onCreateGroup}
              className="flex items-center space-x-1.5 px-4 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-semibold shadow-md shadow-indigo-600/20 transition"
            >
              <Plus className="w-4 h-4" />
              <span>{t("createQuotaGroup")}</span>
            </button>
            <button
              onClick={onClose}
              className="p-2 rounded-xl text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-800 transition"
            >
              <X className="w-5 h-5" />
            </button>
          </div>
        </div>

        {error && (
          <div className="mb-5 p-3.5 rounded-xl bg-red-500/10 border border-red-500/20 text-xs text-red-600 dark:text-red-400 flex items-center space-x-2">
            <AlertCircle className="w-4 h-4 flex-shrink-0" />
            <span>{error}</span>
          </div>
        )}

        {/* Groups Grid / Cards */}
        {loading && groups.length === 0 ? (
          <div className="flex flex-col items-center justify-center py-12 text-gray-400 space-y-2">
            <RefreshCw className="w-6 h-6 animate-spin text-indigo-500" />
            <span className="text-xs">Loading quota groups...</span>
          </div>
        ) : (
        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
          {groups.map((group) => {
            return (
              <div
                key={group.id}
                className="p-5 rounded-2xl bg-gray-50 dark:bg-gray-800/40 border border-gray-200 dark:border-gray-800 flex flex-col justify-between hover:border-gray-300 dark:hover:border-gray-700 transition"
              >
                <div>
                  <div className="flex items-start justify-between gap-2 mb-2">
                    <div>
                      <div className="flex items-center space-x-2">
                        <span className="font-bold text-base text-gray-900 dark:text-white">
                          {group.name}
                        </span>
                        {group.is_default && (
                          <span className="inline-flex items-center space-x-1 px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-amber-500/10 text-amber-600 dark:text-amber-400 border border-amber-500/20">
                            <Sparkles className="w-3 h-3" />
                            <span>{t("defaultGroupBadge")}</span>
                          </span>
                        )}
                      </div>
                      {group.description && (
                        <p className="text-xs text-gray-500 dark:text-gray-400 mt-1">
                          {group.description}
                        </p>
                      )}
                    </div>

                    <div className="flex items-center space-x-1">
                      <button
                        onClick={() => onEditGroup(group)}
                        title={t("editQuotaGroup")}
                        className="p-1.5 rounded-lg bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-300 hover:text-indigo-600 dark:hover:text-indigo-400 transition"
                      >
                        <Edit2 className="w-3.5 h-3.5" />
                      </button>

                      <button
                        onClick={() => handleDelete(group)}
                        disabled={group.is_default || deletingId === group.id}
                        title={group.is_default ? "Default tier cannot be deleted" : t("deleteQuotaGroup")}
                        className="p-1.5 rounded-lg bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-300 hover:text-red-500 hover:border-red-300 transition disabled:opacity-30 disabled:pointer-events-none"
                      >
                        {deletingId === group.id ? (
                          <RefreshCw className="w-3.5 h-3.5 animate-spin" />
                        ) : (
                          <Trash2 className="w-3.5 h-3.5" />
                        )}
                      </button>
                    </div>
                  </div>

                  {/* Limits table/badges */}
                  <div className="grid grid-cols-2 sm:grid-cols-3 gap-2 mt-4 pt-4 border-t border-gray-200 dark:border-gray-800/80 text-xs">
                    <div className="p-2 rounded-xl bg-white dark:bg-gray-800/80 border border-gray-200 dark:border-gray-700/60">
                      <span className="text-[10px] text-gray-400 uppercase tracking-wider block font-semibold">
                        {t("instances")}
                      </span>
                      <span className="font-mono font-bold text-gray-900 dark:text-white">
                        {formatQuotaLimit(group.max_vms)}
                      </span>
                    </div>

                    <div className="p-2 rounded-xl bg-white dark:bg-gray-800/80 border border-gray-200 dark:border-gray-700/60">
                      <span className="text-[10px] text-gray-400 uppercase tracking-wider block font-semibold">
                        {t("vCpuCores")}
                      </span>
                      <span className="font-mono font-bold text-gray-900 dark:text-white">
                        {formatQuotaLimit(group.max_cpu_cores)}
                      </span>
                    </div>

                    <div className="p-2 rounded-xl bg-white dark:bg-gray-800/80 border border-gray-200 dark:border-gray-700/60">
                      <span className="text-[10px] text-gray-400 uppercase tracking-wider block font-semibold">
                        {t("allocatedRam")}
                      </span>
                      <span className="font-mono font-bold text-gray-900 dark:text-white">
                        {group.max_ram_mb === -1 ? "∞" : `${Math.round(group.max_ram_mb / 1024)} GB`}
                      </span>
                    </div>

                    <div className="p-2 rounded-xl bg-white dark:bg-gray-800/80 border border-gray-200 dark:border-gray-700/60">
                      <span className="text-[10px] text-gray-400 uppercase tracking-wider block font-semibold">
                        {t("storage")}
                      </span>
                      <span className="font-mono font-bold text-gray-900 dark:text-white">
                        {formatQuotaLimit(group.max_storage_gb, "GB")}
                      </span>
                    </div>

                    <div className="p-2 rounded-xl bg-white dark:bg-gray-800/80 border border-gray-200 dark:border-gray-700/60">
                      <span className="text-[10px] text-gray-400 uppercase tracking-wider block font-semibold">
                        Public IPs
                      </span>
                      <span className="font-mono font-bold text-gray-900 dark:text-white">
                        {formatQuotaLimit(group.max_ips)}
                      </span>
                    </div>

                    <div className="p-2 rounded-xl bg-white dark:bg-gray-800/80 border border-gray-200 dark:border-gray-700/60">
                      <span className="text-[10px] text-gray-400 uppercase tracking-wider block font-semibold">
                        {t("maxSnapshots")}
                      </span>
                      <span className="font-mono font-bold text-gray-900 dark:text-white">
                        {formatQuotaLimit(group.max_snapshots)}
                      </span>
                    </div>
                  </div>
                </div>

                {/* Footer with assigned users count */}
                <div className="flex items-center justify-between mt-4 pt-3 border-t border-gray-200 dark:border-gray-800/60 text-xs text-gray-500 dark:text-gray-400">
                  <span className="flex items-center space-x-1.5">
                    <Users className="w-3.5 h-3.5 text-gray-400" />
                    <span>
                      {t("assignedUsers")}:{" "}
                      <strong className="text-gray-900 dark:text-white">
                        {group.users_count ?? 0}
                      </strong>
                    </span>
                  </span>
                  <span className="text-[10px] text-gray-400">
                    ID: #{group.id}
                  </span>
                </div>
              </div>
            );
          })}
        </div>
        )}

        {/* Pagination Bar */}
        {groups.length > 0 && (
          <div className="mt-4 bg-white dark:bg-gray-900/60 border border-gray-200 dark:border-gray-800 rounded-2xl overflow-hidden">
            <PaginationBar
              currentPage={page}
              lastPage={lastPage}
              perPage={perPage}
              total={total > 0 ? total : groups.length}
              onPageChange={setPage}
              onPerPageChange={(newPerPage) => {
                setPerPage(newPerPage);
                setPage(1);
              }}
            />
          </div>
        )}
      </div>
    </div>
  );
}
