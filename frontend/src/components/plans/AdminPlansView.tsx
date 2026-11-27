"use client";

import React, { useCallback, useEffect, useState } from "react";
import {
  AlertCircle,
  Archive,
  ArchiveRestore,
  CheckCircle2,
  Edit2,
  EyeOff,
  Loader2,
  Plus,
  RefreshCw,
  Rocket,
  Search,
  Trash2,
} from "lucide-react";
import { AdminVpsPlan, vpsPlansApi } from "@/lib/api";
import { extractPaginationMeta } from "@/lib/api/client";
import { ConfirmModal } from "@/components/ui/ConfirmModal";
import { PaginationBar } from "@/components/ui/PaginationBar";
import { useI18n } from "@/lib/i18n/I18nContext";
import { useToast } from "@/lib/toast/ToastContext";
import {
  PlanEditorModal,
  extractPlanErrorMessage,
  getPlanStatusBadgeStyle,
  getPlanStatusTranslationKey,
} from "./PlanEditorModal";
import { formatNetworkLimit, formatPlanRam } from "./VpsPlanCard";

export function AdminPlansView() {
  const { t } = useI18n();
  const { toast } = useToast();

  const [plans, setPlans] = useState<AdminVpsPlan[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const [statusFilter, setStatusFilter] = useState<string>("all");
  const [searchQuery, setSearchQuery] = useState("");
  const [debouncedSearch, setDebouncedSearch] = useState("");
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(20);
  const [lastPage, setLastPage] = useState(1);
  const [total, setTotal] = useState(0);

  const [editorOpen, setEditorOpen] = useState(false);
  const [editorTarget, setEditorTarget] = useState<AdminVpsPlan | null>(null);
  const [busyPlanId, setBusyPlanId] = useState<number | null>(null);

  const [confirmAction, setConfirmAction] = useState<{
    type: "archive" | "discardDraft";
    plan: AdminVpsPlan;
  } | null>(null);

  useEffect(() => {
    const timer = setTimeout(() => {
      setDebouncedSearch(searchQuery);
      setPage(1);
    }, 300);
    return () => clearTimeout(timer);
  }, [searchQuery]);

  const fetchPlans = useCallback(
    async (signal?: AbortSignal) => {
      try {
        setLoading(true);
        setError(null);
        const res = await vpsPlansApi.listAdminPlans(
          {
            status: statusFilter,
            search: debouncedSearch.trim() || null,
            page,
            per_page: perPage,
          },
          { signal }
        );
        if (signal?.aborted) return;
        setPlans(res.data);
        const meta = extractPaginationMeta(res, perPage);
        setLastPage(meta.lastPage);
        setTotal(meta.total);
      } catch (err: unknown) {
        if (signal?.aborted) return;
        setError(extractPlanErrorMessage(err, t("vpsPlanActionFailed")));
      } finally {
        if (!signal?.aborted) {
          setLoading(false);
        }
      }
    },
    [statusFilter, debouncedSearch, page, perPage, t]
  );

  useEffect(() => {
    const controller = new AbortController();
    queueMicrotask(() => {
      if (!controller.signal.aborted) {
        void fetchPlans(controller.signal);
      }
    });
    return () => controller.abort();
  }, [fetchPlans]);

  const executeRowAction = async (
    planId: number,
    fn: () => Promise<unknown>,
    successMsg: string
  ) => {
    try {
      setBusyPlanId(planId);
      await fn();
      toast.success(successMsg);
      await fetchPlans();
    } catch (err: unknown) {
      toast.error(extractPlanErrorMessage(err, t("vpsPlanActionFailed")));
    } finally {
      setBusyPlanId(null);
      setConfirmAction(null);
    }
  };

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
          <h2 className="text-2xl font-extrabold text-gray-900 dark:text-white tracking-tight">
            {t("vpsPlanAdminTitle")}
          </h2>
          <p className="text-xs sm:text-sm text-gray-500 dark:text-gray-400 mt-1">
            {t("vpsPlanAdminSubtitle")}
          </p>
        </div>

        <div className="flex items-center gap-2 self-start sm:self-auto">
          <button
            type="button"
            onClick={() => void fetchPlans()}
            disabled={loading}
            className="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-2xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 hover:bg-gray-50 dark:hover:bg-gray-800 text-xs font-semibold text-gray-700 dark:text-gray-200 transition disabled:opacity-50"
          >
            <RefreshCw className={`w-3.5 h-3.5 ${loading ? "animate-spin" : ""}`} />
            <span>{t("vpsPlansRefresh")}</span>
          </button>

          <button
            type="button"
            onClick={() => {
              setEditorTarget(null);
              setEditorOpen(true);
            }}
            className="inline-flex items-center gap-1.5 px-4 py-2 rounded-2xl bg-blue-600 hover:bg-blue-500 text-white text-xs font-semibold shadow-md shadow-blue-600/20 transition"
          >
            <Plus className="w-4 h-4" />
            <span>{t("vpsPlanCreateButton")}</span>
          </button>
        </div>
      </div>

      {/* Filters */}
      <div className="flex flex-col sm:flex-row gap-3">
        <div className="relative flex-1">
          <Search className="w-4 h-4 text-gray-400 absolute left-3.5 top-1/2 -translate-y-1/2 pointer-events-none" />
          <input
            type="text"
            value={searchQuery}
            onChange={(e) => setSearchQuery(e.target.value)}
            placeholder={t("vpsPlanSearchPlaceholder")}
            className="w-full pl-10 pr-4 py-2.5 rounded-2xl bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 text-xs text-gray-900 dark:text-white focus:ring-2 focus:ring-blue-500 outline-none transition"
          />
        </div>

        <select
          value={statusFilter}
          onChange={(e) => {
            setStatusFilter(e.target.value);
            setPage(1);
          }}
          className="px-4 py-2.5 rounded-2xl bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 text-xs font-semibold text-gray-700 dark:text-gray-200 focus:ring-2 focus:ring-blue-500 outline-none transition"
        >
          <option value="all">{t("vpsPlanFilterAllStatuses")}</option>
          <option value="draft">{t("vpsPlanDraftBadge")}</option>
          <option value="published">{t("vpsPlanPublishedBadge")}</option>
          <option value="archived">{t("vpsPlanArchivedBadge")}</option>
        </select>
      </div>

      {error && (
        <div className="p-4 rounded-2xl bg-red-500/10 border border-red-500/20 flex items-center justify-between gap-3 text-xs text-red-600 dark:text-red-400">
          <div className="flex items-center gap-2">
            <AlertCircle className="w-4 h-4 flex-shrink-0" />
            <span>{error}</span>
          </div>
          <button
            type="button"
            onClick={() => void fetchPlans()}
            className="px-3 py-1 rounded-xl bg-red-600 text-white font-semibold"
          >
            {t("vpsPlansRetry")}
          </button>
        </div>
      )}

      {/* Plans Table */}
      <div className="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-3xl overflow-hidden shadow-sm">
        {loading && plans.length === 0 ? (
          <div className="flex items-center justify-center py-16 text-xs text-gray-500 dark:text-gray-400 gap-2">
            <Loader2 className="w-5 h-5 animate-spin text-blue-500" />
            <span>{t("vpsPlansLoading")}</span>
          </div>
        ) : plans.length === 0 ? (
          <div className="text-center py-16 px-6 text-xs text-gray-500 dark:text-gray-400">
            {t("vpsPlanAdminEmpty")}
          </div>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-left border-collapse">
              <thead>
                <tr className="border-b border-gray-100 dark:border-gray-800 text-[11px] font-bold uppercase tracking-wider text-gray-400">
                  <th className="py-3.5 px-5">{t("vpsPlanColPlan")}</th>
                  <th className="py-3.5 px-4">{t("vpsPlanColStatus")}</th>
                  <th className="py-3.5 px-4">{t("vpsPlanColSortOrder")}</th>
                  <th className="py-3.5 px-4">{t("vpsPlanColCurrentVersion")}</th>
                  <th className="py-3.5 px-4">{t("vpsPlanColProfile")}</th>
                  <th className="py-3.5 px-5 text-right">{t("vpsPlanColActions")}</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-100 dark:divide-gray-800/80 text-xs">
                {plans.map((plan) => {
                  const activeProfile = plan.current_version ?? plan.draft_version ?? null;
                  const rowBusy = busyPlanId === plan.id;

                  return (
                    <tr
                      key={plan.id}
                      className="hover:bg-gray-50/70 dark:hover:bg-gray-800/30 transition"
                    >
                      <td className="py-4 px-5">
                        <div className="font-bold text-gray-900 dark:text-white">
                          {plan.name}
                        </div>
                        <div className="text-[11px] font-mono text-gray-400">
                          {plan.slug}
                        </div>
                      </td>

                      <td className="py-4 px-4">
                        <div className="flex items-center flex-wrap gap-1.5">
                          <span
                            className={`px-2.5 py-0.5 rounded-full text-[11px] font-semibold ${getPlanStatusBadgeStyle(
                              plan.status
                            )}`}
                          >
                            {t(getPlanStatusTranslationKey(plan.status))}
                          </span>
                          {plan.draft_version && (
                            <span className="px-2 py-0.5 rounded-full text-[10px] font-semibold bg-amber-500/10 text-amber-600 dark:text-amber-400 border border-amber-500/20">
                              {t("vpsPlanHasPendingDraft")}
                            </span>
                          )}
                        </div>
                      </td>

                      <td className="py-4 px-4 font-mono text-gray-600 dark:text-gray-300">
                        {plan.sort_order}
                      </td>

                      <td className="py-4 px-4">
                        {plan.current_version?.version ? (
                          <span className="px-2 py-0.5 rounded-lg font-mono font-semibold bg-gray-100 dark:bg-gray-800 text-gray-800 dark:text-gray-200">
                            {t("vpsPlanVersionLabel", {
                              version: plan.current_version.version,
                            })}
                          </span>
                        ) : (
                          <span className="text-gray-400 italic">
                            {t("vpsPlanNoPublishedVersion")}
                          </span>
                        )}
                      </td>

                      <td className="py-4 px-4">
                        {activeProfile ? (
                          <div className="space-y-0.5">
                            <div className="font-medium text-gray-800 dark:text-gray-200">
                              {activeProfile.cpu_cores} vCPU ·{" "}
                              {formatPlanRam(activeProfile.ram_mb)} ·{" "}
                              {activeProfile.disk_gb} GiB
                            </div>
                            <div className="text-[11px] text-gray-400">
                              {activeProfile.instance_type === "virtual-machine"
                                ? t("vpsPlanInstanceTypeVm")
                                : t("vpsPlanInstanceTypeContainer")}{" "}
                              · ↓{" "}
                              {formatNetworkLimit(
                                activeProfile.net_download_mbps,
                                t("vpsPlanUnlimited"),
                                (mbps) => t("vpsPlanMbpsValue", { mbps })
                              )}{" "}
                              / ↑{" "}
                              {formatNetworkLimit(
                                activeProfile.net_upload_mbps,
                                t("vpsPlanUnlimited"),
                                (mbps) => t("vpsPlanMbpsValue", { mbps })
                              )}
                            </div>
                          </div>
                        ) : (
                          <span className="text-gray-400">—</span>
                        )}
                      </td>

                      <td className="py-4 px-5">
                        <div className="flex items-center justify-end flex-wrap gap-1.5">
                          <button
                            type="button"
                            disabled={rowBusy}
                            onClick={() => {
                              setEditorTarget(plan);
                              setEditorOpen(true);
                            }}
                            className="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-xl bg-gray-100 dark:bg-gray-800 hover:bg-gray-200 dark:hover:bg-gray-700 text-gray-700 dark:text-gray-200 font-semibold transition disabled:opacity-50"
                          >
                            <Edit2 className="w-3.5 h-3.5" />
                            <span>{t("vpsPlanEditManage")}</span>
                          </button>

                          {plan.status === "draft" && (
                            <button
                              type="button"
                              disabled={rowBusy}
                              onClick={() =>
                                void executeRowAction(
                                  plan.id,
                                  () => vpsPlansApi.publishPlan(plan.id),
                                  t("vpsPlanPublishedSuccess")
                                )
                              }
                              title={t("vpsPlanPublish")}
                              className="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-xl bg-emerald-600/10 hover:bg-emerald-600/20 text-emerald-600 dark:text-emerald-400 font-semibold transition disabled:opacity-50"
                            >
                              <CheckCircle2 className="w-3.5 h-3.5" />
                              <span>{t("vpsPlanPublish")}</span>
                            </button>
                          )}

                          {plan.status === "published" && (
                            <button
                              type="button"
                              disabled={rowBusy}
                              onClick={() =>
                                void executeRowAction(
                                  plan.id,
                                  () => vpsPlansApi.unpublishPlan(plan.id),
                                  t("vpsPlanUnpublishedSuccess")
                                )
                              }
                              title={t("vpsPlanUnpublish")}
                              className="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-xl bg-amber-500/10 hover:bg-amber-500/20 text-amber-600 dark:text-amber-400 font-semibold transition disabled:opacity-50"
                            >
                              <EyeOff className="w-3.5 h-3.5" />
                              <span>{t("vpsPlanUnpublish")}</span>
                            </button>
                          )}

                          {plan.draft_version && plan.status !== "archived" && (
                            <button
                              type="button"
                              disabled={rowBusy}
                              onClick={() =>
                                void executeRowAction(
                                  plan.id,
                                  () => vpsPlansApi.publishDraftVersion(plan.id),
                                  t("vpsPlanDraftPublishedSuccess")
                                )
                              }
                              title={t("vpsPlanPublishDraft")}
                              className="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-xl bg-blue-500/10 hover:bg-blue-500/20 text-blue-600 dark:text-blue-400 font-semibold transition disabled:opacity-50"
                            >
                              <Rocket className="w-3.5 h-3.5" />
                              <span>{t("vpsPlanPublishDraft")}</span>
                            </button>
                          )}

                          {plan.draft_version && (
                            <button
                              type="button"
                              disabled={rowBusy}
                              onClick={() =>
                                setConfirmAction({ type: "discardDraft", plan })
                              }
                              title={t("vpsPlanDiscardDraft")}
                              className="p-1.5 rounded-xl text-red-500 hover:bg-red-500/10 transition disabled:opacity-50"
                            >
                              <Trash2 className="w-3.5 h-3.5" />
                            </button>
                          )}

                          {plan.status !== "archived" ? (
                            <button
                              type="button"
                              disabled={rowBusy}
                              onClick={() =>
                                setConfirmAction({ type: "archive", plan })
                              }
                              title={t("vpsPlanArchive")}
                              className="p-1.5 rounded-xl text-gray-500 hover:bg-gray-200 dark:hover:bg-gray-800 transition disabled:opacity-50"
                            >
                              <Archive className="w-3.5 h-3.5" />
                            </button>
                          ) : (
                            <button
                              type="button"
                              disabled={rowBusy}
                              onClick={() =>
                                void executeRowAction(
                                  plan.id,
                                  () => vpsPlansApi.unarchivePlan(plan.id),
                                  t("vpsPlanUnarchivedSuccess")
                                )
                              }
                              title={t("vpsPlanUnarchive")}
                              className="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-xl bg-indigo-500/10 hover:bg-indigo-500/20 text-indigo-600 dark:text-indigo-400 font-semibold transition disabled:opacity-50"
                            >
                              <ArchiveRestore className="w-3.5 h-3.5" />
                              <span>{t("vpsPlanUnarchive")}</span>
                            </button>
                          )}
                        </div>
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        )}

        {total > 0 && (
          <div className="px-5 py-3 border-t border-gray-100 dark:border-gray-800">
            <PaginationBar
              currentPage={page}
              lastPage={lastPage}
              perPage={perPage}
              total={total}
              onPageChange={setPage}
              onPerPageChange={(next) => {
                setPerPage(next);
                setPage(1);
              }}
            />
          </div>
        )}
      </div>

      {editorOpen && (
        <PlanEditorModal
          plan={editorTarget}
          onClose={() => {
            setEditorOpen(false);
            setEditorTarget(null);
          }}
          onSaved={() => void fetchPlans()}
        />
      )}

      <ConfirmModal
        isOpen={confirmAction !== null}
        title={
          confirmAction?.type === "discardDraft"
            ? t("vpsPlanConfirmDiscardDraftTitle")
            : t("vpsPlanConfirmArchiveTitle")
        }
        message={
          confirmAction?.type === "discardDraft"
            ? `${t("vpsPlanConfirmDiscardDraftDesc")} "${confirmAction.plan.name}"?`
            : `${t("vpsPlanConfirmArchiveDesc")} "${confirmAction?.plan.name ?? ""}"?`
        }
        variant={confirmAction?.type === "discardDraft" ? "danger" : "warning"}
        confirmText={
          confirmAction?.type === "discardDraft"
            ? t("vpsPlanDiscardDraft")
            : t("vpsPlanArchive")
        }
        isLoading={busyPlanId !== null}
        onClose={() => setConfirmAction(null)}
        onConfirm={() => {
          if (!confirmAction) return;
          if (confirmAction.type === "discardDraft") {
            return executeRowAction(
              confirmAction.plan.id,
              () => vpsPlansApi.discardDraftVersion(confirmAction.plan.id),
              t("vpsPlanDraftDiscardedSuccess")
            );
          }
          return executeRowAction(
            confirmAction.plan.id,
            () => vpsPlansApi.archivePlan(confirmAction.plan.id),
            t("vpsPlanArchivedSuccess")
          );
        }}
      />
    </div>
  );
}
