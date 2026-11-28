"use client";

import React, { useCallback, useEffect, useState } from "react";
import {
  AlertCircle,
  Archive,
  ArchiveRestore,
  ArrowLeftRight,
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
import { type AdminVpsPlan, vpsPlansApi } from "@/lib/api";
import { extractPaginationMeta } from "@/lib/api/client";
import { ConfirmModal } from "@/components/ui/ConfirmModal";
import { PaginationBar } from "@/components/ui/PaginationBar";
import { RowActions } from "@/components/ui/rowActions/RowActions";
import { StickyActionsTd, StickyActionsTh } from "@/components/ui/rowActions/StickyActionsCell";
import { type RowAction } from "@/components/ui/rowActions/types";
import { useConfirmDialog } from "@/lib/hooks/useConfirmDialog";
import { useI18n } from "@/lib/i18n/I18nContext";
import { useToast } from "@/lib/toast/ToastContext";
import { PlanChangesModal } from "./PlanChangesModal";
import {
  PlanEditorModal,
  extractPlanErrorMessage,
  getPlanStatusBadgeStyle,
  getPlanStatusTranslationKey,
} from "./PlanEditorModal";
import { formatNetworkLimit, formatPlanRam } from "./VpsPlanCard";

export type AdminPlanRowActionId =
  | "edit"
  | "planChanges"
  | "publish"
  | "publishDraft"
  | "unarchive"
  | "unpublish"
  | "archive"
  | "discardDraft";

export interface BuildAdminPlanRowActionsCallbacks {
  onEdit: (plan: AdminVpsPlan) => void;
  onOpenPlanChanges: (plan: AdminVpsPlan) => void;
  onPublish: (plan: AdminVpsPlan) => void;
  onPublishDraft: (plan: AdminVpsPlan) => void;
  onUnarchive: (plan: AdminVpsPlan) => void;
  onUnpublish: (plan: AdminVpsPlan) => void;
  onArchive: (plan: AdminVpsPlan) => void;
  onDiscardDraft: (plan: AdminVpsPlan) => void;
}

export function buildAdminPlanRowActions(
  plan: AdminVpsPlan,
  t: (key: string, params?: Record<string, string | number>) => string,
  busyActionId: AdminPlanRowActionId | null,
  callbacks: BuildAdminPlanRowActionsCallbacks
): RowAction[] {
  const rowBusy = busyActionId !== null;

  return [
    {
      id: "edit",
      icon: Edit2,
      label: t("vpsPlanEditManage"),
      severity: "safe",
      tone: "neutral",
      disabled: rowBusy,
      onSelect: () => callbacks.onEdit(plan),
    },
    {
      id: "planChanges",
      icon: ArrowLeftRight,
      label: t("vpsPlanViewChanges"),
      severity: "safe",
      tone: "info",
      disabled: rowBusy,
      onSelect: () => callbacks.onOpenPlanChanges(plan),
    },
    {
      id: "publish",
      icon: CheckCircle2,
      label: t("vpsPlanPublish"),
      severity: "caution",
      tone: "success",
      hidden: plan.status !== "draft",
      disabled: rowBusy,
      loading: busyActionId === "publish",
      onSelect: () => callbacks.onPublish(plan),
    },
    {
      id: "publishDraft",
      icon: Rocket,
      label: t("vpsPlanPublishDraft"),
      severity: "caution",
      tone: "info",
      hidden: !(plan.draft_version && plan.status !== "archived"),
      disabled: rowBusy,
      loading: busyActionId === "publishDraft",
      onSelect: () => callbacks.onPublishDraft(plan),
    },
    {
      id: "unarchive",
      icon: ArchiveRestore,
      label: t("vpsPlanUnarchive"),
      severity: "caution",
      tone: "accent",
      hidden: plan.status !== "archived",
      disabled: rowBusy,
      loading: busyActionId === "unarchive",
      onSelect: () => callbacks.onUnarchive(plan),
    },
    {
      id: "unpublish",
      icon: EyeOff,
      label: t("vpsPlanUnpublish"),
      severity: "disruptive",
      tone: "neutral",
      hidden: plan.status !== "published",
      disabled: rowBusy,
      loading: busyActionId === "unpublish",
      onSelect: () => callbacks.onUnpublish(plan),
    },
    {
      id: "archive",
      icon: Archive,
      label: t("vpsPlanArchive"),
      severity: "disruptive",
      tone: "neutral",
      hidden: plan.status === "archived",
      disabled: rowBusy,
      loading: busyActionId === "archive",
      onSelect: () => callbacks.onArchive(plan),
    },
    {
      id: "discardDraft",
      icon: Trash2,
      label: t("vpsPlanDiscardDraft"),
      severity: "critical",
      tone: "neutral",
      hidden: !plan.draft_version,
      disabled: rowBusy,
      loading: busyActionId === "discardDraft",
      onSelect: () => callbacks.onDiscardDraft(plan),
    },
  ];
}

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
  const [changesPlanTarget, setChangesPlanTarget] = useState<AdminVpsPlan | null>(null);
  const [busyAction, setBusyAction] = useState<{
    planId: number;
    actionId: AdminPlanRowActionId;
  } | null>(null);

  const { confirmDialog, requestConfirm, closeConfirmDialog } = useConfirmDialog(
    (msg) => toast.error(msg)
  );

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
    actionId: AdminPlanRowActionId,
    fn: () => Promise<unknown>,
    successMsg: string,
    options?: { rethrowOnError?: boolean }
  ) => {
    try {
      setBusyAction({ planId, actionId });
      await fn();
      toast.success(successMsg);
      await fetchPlans();
    } catch (err: unknown) {
      const message = extractPlanErrorMessage(err, t("vpsPlanActionFailed"));
      if (options?.rethrowOnError) {
        throw new Error(message);
      }
      toast.error(message);
    } finally {
      setBusyAction(null);
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
          <div className="overflow-x-auto relative">
            <table className="w-full text-left border-collapse">
              <thead className="bg-gray-50 dark:bg-gray-950/60 border-b border-gray-200 dark:border-gray-800 text-[11px] font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">
                <tr>
                  <th scope="col" className="py-3.5 px-5">
                    {t("vpsPlanColPlan")}
                  </th>
                  <th scope="col" className="py-3.5 px-4">
                    {t("vpsPlanColStatus")}
                  </th>
                  <th scope="col" className="py-3.5 px-4">
                    {t("vpsPlanColSortOrder")}
                  </th>
                  <th scope="col" className="py-3.5 px-4">
                    {t("vpsPlanColCurrentVersion")}
                  </th>
                  <th scope="col" className="py-3.5 px-4">
                    {t("vpsPlanColProfile")}
                  </th>
                  <StickyActionsTh>{t("vpsPlanColActions")}</StickyActionsTh>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-200 dark:divide-gray-800/80 text-xs">
                {plans.map((plan) => {
                  const activeProfile = plan.current_version ?? plan.draft_version ?? null;
                  const rowBusyActionId =
                    busyAction?.planId === plan.id ? busyAction.actionId : null;

                  const actions = buildAdminPlanRowActions(plan, t, rowBusyActionId, {
                    onEdit: (target) => {
                      setEditorTarget(target);
                      setEditorOpen(true);
                    },
                    onOpenPlanChanges: (target) => {
                      setChangesPlanTarget(target);
                    },
                    onPublish: (target) => {
                      void executeRowAction(
                        target.id,
                        "publish",
                        () => vpsPlansApi.publishPlan(target.id),
                        t("vpsPlanPublishedSuccess")
                      );
                    },
                    onPublishDraft: (target) => {
                      void executeRowAction(
                        target.id,
                        "publishDraft",
                        () => vpsPlansApi.publishDraftVersion(target.id),
                        t("vpsPlanDraftPublishedSuccess")
                      );
                    },
                    onUnarchive: (target) => {
                      void executeRowAction(
                        target.id,
                        "unarchive",
                        () => vpsPlansApi.unarchivePlan(target.id),
                        t("vpsPlanUnarchivedSuccess")
                      );
                    },
                    onUnpublish: (target) => {
                      requestConfirm({
                        title: t("vpsPlanConfirmUnpublishTitle"),
                        message: `${t("vpsPlanConfirmUnpublishDesc")} "${target.name}"?`,
                        variant: "warning",
                        confirmText: t("vpsPlanUnpublish"),
                        cancelText: t("vpsPlanCancel"),
                        onConfirm: () =>
                          executeRowAction(
                            target.id,
                            "unpublish",
                            () => vpsPlansApi.unpublishPlan(target.id),
                            t("vpsPlanUnpublishedSuccess"),
                            { rethrowOnError: true }
                          ),
                      });
                    },
                    onArchive: (target) => {
                      requestConfirm({
                        title: t("vpsPlanConfirmArchiveTitle"),
                        message: `${t("vpsPlanConfirmArchiveDesc")} "${target.name}"?`,
                        variant: "warning",
                        confirmText: t("vpsPlanArchive"),
                        cancelText: t("vpsPlanCancel"),
                        onConfirm: () =>
                          executeRowAction(
                            target.id,
                            "archive",
                            () => vpsPlansApi.archivePlan(target.id),
                            t("vpsPlanArchivedSuccess"),
                            { rethrowOnError: true }
                          ),
                      });
                    },
                    onDiscardDraft: (target) => {
                      requestConfirm({
                        title: t("vpsPlanConfirmDiscardDraftTitle"),
                        message: `${t("vpsPlanConfirmDiscardDraftDesc")} "${target.name}"?`,
                        variant: "danger",
                        confirmText: t("vpsPlanDiscardDraft"),
                        cancelText: t("vpsPlanCancel"),
                        onConfirm: () =>
                          executeRowAction(
                            target.id,
                            "discardDraft",
                            () => vpsPlansApi.discardDraftVersion(target.id),
                            t("vpsPlanDraftDiscardedSuccess"),
                            { rethrowOnError: true }
                          ),
                      });
                    },
                  });

                  return (
                    <tr
                      key={plan.id}
                      className="hover:bg-gray-50 dark:hover:bg-gray-800/40 transition group"
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

                      <StickyActionsTd>
                        <RowActions actions={actions} />
                      </StickyActionsTd>
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

      {changesPlanTarget && (
        <PlanChangesModal
          plan={changesPlanTarget}
          onClose={() => setChangesPlanTarget(null)}
        />
      )}

      <ConfirmModal
        isOpen={confirmDialog.isOpen}
        title={confirmDialog.title}
        message={confirmDialog.message}
        variant={confirmDialog.variant}
        confirmText={confirmDialog.confirmText}
        cancelText={confirmDialog.cancelText}
        isLoading={confirmDialog.isLoading}
        onClose={closeConfirmDialog}
        onConfirm={confirmDialog.onConfirm}
      />
    </div>
  );
}
