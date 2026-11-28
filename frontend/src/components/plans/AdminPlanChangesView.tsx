"use client";

import React, { useCallback, useEffect, useState } from "react";
import {
  AlertCircle,
  AlertTriangle,
  CheckCircle2,
  Loader2,
  RefreshCw,
  Wrench,
  X,
  XCircle,
} from "lucide-react";
import {
  AdminVpsPlanChange,
  ResolveAdminPlanChangeAction,
  vpsPlansApi,
} from "@/lib/api";
import { extractPaginationMeta } from "@/lib/api/client";
import { PaginationBar } from "@/components/ui/PaginationBar";
import { useI18n } from "@/lib/i18n/I18nContext";
import { useToast } from "@/lib/toast/ToastContext";
import { useModalEscape } from "@/lib/hooks/useModalEscape";
import {
  extractPlanChangeApiErrorMessage,
  getPlanChangeStatusBadgeStyle,
  isPlanChangePollingActive,
  translatePlanChangeErrorCode,
  translatePlanChangeStage,
  translatePlanChangeStatus,
} from "@/components/vms/ChangePlanModal";

const ADMIN_PLAN_CHANGE_STATUS_OPTIONS = [
  "all",
  "reconciliation_required",
  "pending",
  "awaiting_authorization",
  "applying",
  "completed",
  "failed",
  "cancelled",
] as const;

export function parsePositiveIntFilter(value: string): number | null {
  const trimmed = value.trim();
  if (!trimmed) return null;
  if (!/^\d+$/.test(trimmed)) return null;
  const parsed = Number.parseInt(trimmed, 10);
  return Number.isSafeInteger(parsed) && parsed > 0 ? parsed : null;
}

export function AdminPlanChangesView() {
  const { t } = useI18n();
  const { toast } = useToast();

  const [changes, setChanges] = useState<AdminVpsPlanChange[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const [statusFilter, setStatusFilter] = useState<string>("all");
  const [userIdInput, setUserIdInput] = useState<string>("");
  const [vmIdInput, setVmIdInput] = useState<string>("");
  const [debouncedUserId, setDebouncedUserId] = useState<number | null>(null);
  const [debouncedVmId, setDebouncedVmId] = useState<number | null>(null);

  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(20);
  const [lastPage, setLastPage] = useState(1);
  const [total, setTotal] = useState(0);

  const [resolveTarget, setResolveTarget] = useState<AdminVpsPlanChange | null>(null);
  const [resolveAction, setResolveAction] =
    useState<ResolveAdminPlanChangeAction>("complete");
  const [resolveNote, setResolveNote] = useState("");
  const [resolving, setResolving] = useState(false);
  const [resolveError, setResolveError] = useState<string | null>(null);

  useModalEscape(
    () => {
      if (!resolving) setResolveTarget(null);
    },
    Boolean(resolveTarget)
  );

  useEffect(() => {
    const timer = setTimeout(() => {
      setDebouncedUserId(parsePositiveIntFilter(userIdInput));
      setDebouncedVmId(parsePositiveIntFilter(vmIdInput));
      setPage(1);
    }, 300);
    return () => clearTimeout(timer);
  }, [userIdInput, vmIdInput]);

  const fetchPlanChanges = useCallback(
    async (signal?: AbortSignal) => {
      try {
        setLoading(true);
        setError(null);

        const res = await vpsPlansApi.listAdminPlanChanges(
          {
            status: statusFilter,
            user_id: debouncedUserId,
            vm_id: debouncedVmId,
            page,
            per_page: perPage,
          },
          { signal }
        );
        if (signal?.aborted) return;

        setChanges(res.data);
        const meta = extractPaginationMeta(res, perPage);
        setLastPage(meta.lastPage);
        setTotal(meta.total);
      } catch (err: unknown) {
        if (signal?.aborted) return;
        setError(
          extractPlanChangeApiErrorMessage(err, t, t("vpsPlanChangeAdminLoadError"))
        );
      } finally {
        if (!signal?.aborted) {
          setLoading(false);
        }
      }
    },
    [statusFilter, debouncedUserId, debouncedVmId, page, perPage, t]
  );

  useEffect(() => {
    const controller = new AbortController();
    queueMicrotask(() => {
      if (!controller.signal.aborted) {
        void fetchPlanChanges(controller.signal);
      }
    });
    return () => controller.abort();
  }, [fetchPlanChanges]);

  const openResolveModal = (change: AdminVpsPlanChange) => {
    setResolveTarget(change);
    setResolveAction("complete");
    setResolveNote("");
    setResolveError(null);
  };

  const handleSubmitResolve = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!resolveTarget || resolving) return;

    setResolving(true);
    setResolveError(null);
    try {
      await vpsPlansApi.resolveAdminPlanChange(resolveTarget.public_id, {
        action: resolveAction,
        note: resolveNote.trim() ? resolveNote.trim() : null,
      });
      toast.success(t("vpsPlanChangeResolveSuccess"));
      setResolveTarget(null);
      await fetchPlanChanges();
    } catch (err: unknown) {
      const message = extractPlanChangeApiErrorMessage(
        err,
        t,
        t("vpsPlanChangeResolveFailed")
      );
      setResolveError(message);
      toast.error(message);
    } finally {
      setResolving(false);
    }
  };

  const hasFilters =
    statusFilter !== "all" || userIdInput.trim() !== "" || vmIdInput.trim() !== "";

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
          <h2 className="text-2xl font-extrabold text-gray-900 dark:text-white tracking-tight">
            {t("vpsPlanChangeAdminTitle")}
          </h2>
          <p className="text-xs sm:text-sm text-gray-500 dark:text-gray-400 mt-1">
            {t("vpsPlanChangeAdminSubtitle")}
          </p>
        </div>

        <button
          type="button"
          onClick={() => void fetchPlanChanges()}
          disabled={loading}
          className="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-2xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 hover:bg-gray-50 dark:hover:bg-gray-800 text-xs font-semibold text-gray-700 dark:text-gray-200 transition disabled:opacity-50 self-start sm:self-auto"
        >
          <RefreshCw className={`w-3.5 h-3.5 ${loading ? "animate-spin" : ""}`} />
          <span>{t("vpsPlansRefresh")}</span>
        </button>
      </div>

      {/* Filters */}
      <div className="p-4 rounded-3xl bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 flex flex-wrap items-end gap-3">
        <div className="min-w-[200px] flex-1 sm:flex-none">
          <label className="block text-[11px] font-semibold text-gray-500 dark:text-gray-400 mb-1">
            {t("vpsPlanColStatus")}
          </label>
          <select
            value={statusFilter}
            onChange={(e) => {
              setStatusFilter(e.target.value);
              setPage(1);
            }}
            className="w-full px-3 py-2 rounded-xl border border-gray-200 dark:border-gray-800 bg-gray-50 dark:bg-gray-950 text-xs font-medium text-gray-900 dark:text-gray-100 focus:outline-none focus:border-blue-500"
          >
            {ADMIN_PLAN_CHANGE_STATUS_OPTIONS.map((statusOpt) => (
              <option key={statusOpt} value={statusOpt}>
                {statusOpt === "all"
                  ? t("vpsPlanChangeFilterAllStatuses")
                  : translatePlanChangeStatus(statusOpt, t)}
              </option>
            ))}
          </select>
        </div>

        <div className="w-36">
          <label className="block text-[11px] font-semibold text-gray-500 dark:text-gray-400 mb-1">
            {t("vpsPlanChangeFilterUserId")}
          </label>
          <input
            type="number"
            min={1}
            value={userIdInput}
            onChange={(e) => setUserIdInput(e.target.value)}
            placeholder="e.g. 42"
            className="w-full px-3 py-2 rounded-xl border border-gray-200 dark:border-gray-800 bg-gray-50 dark:bg-gray-950 text-xs font-medium text-gray-900 dark:text-gray-100 focus:outline-none focus:border-blue-500"
          />
        </div>

        <div className="w-36">
          <label className="block text-[11px] font-semibold text-gray-500 dark:text-gray-400 mb-1">
            {t("vpsPlanChangeFilterVmId")}
          </label>
          <input
            type="number"
            min={1}
            value={vmIdInput}
            onChange={(e) => setVmIdInput(e.target.value)}
            placeholder="e.g. 105"
            className="w-full px-3 py-2 rounded-xl border border-gray-200 dark:border-gray-800 bg-gray-50 dark:bg-gray-950 text-xs font-medium text-gray-900 dark:text-gray-100 focus:outline-none focus:border-blue-500"
          />
        </div>

        {hasFilters && (
          <button
            type="button"
            onClick={() => {
              setStatusFilter("all");
              setUserIdInput("");
              setVmIdInput("");
              setDebouncedUserId(null);
              setDebouncedVmId(null);
              setPage(1);
            }}
            className="px-3.5 py-2 rounded-xl border border-gray-200 dark:border-gray-700 text-xs font-semibold text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800 transition"
          >
            {t("vpsPlanChangeClearFilters")}
          </button>
        )}
      </div>

      {/* Table */}
      <div className="rounded-3xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 overflow-hidden shadow-sm">
        {loading ? (
          <div className="py-16 flex flex-col items-center justify-center gap-2 text-xs text-gray-500 dark:text-gray-400">
            <Loader2 className="w-6 h-6 animate-spin text-blue-500" />
            <span>{t("loading")}</span>
          </div>
        ) : error ? (
          <div className="p-6 flex items-center justify-between gap-4 text-xs text-red-600 dark:text-red-400">
            <div className="flex items-center gap-2">
              <AlertCircle className="w-4 h-4 shrink-0" />
              <span>{error}</span>
            </div>
            <button
              type="button"
              onClick={() => void fetchPlanChanges()}
              className="px-3.5 py-1.5 rounded-xl bg-red-600 text-white font-semibold hover:bg-red-500 transition"
            >
              {t("vpsPlansRetry")}
            </button>
          </div>
        ) : changes.length === 0 ? (
          <div className="py-16 px-6 text-center text-xs text-gray-500 dark:text-gray-400">
            {t("vpsPlanChangeAdminEmpty")}
          </div>
        ) : (
          <>
            <div className="overflow-x-auto">
              <table className="w-full text-left text-xs">
                <thead className="bg-gray-50/80 dark:bg-gray-950/40 border-b border-gray-200 dark:border-gray-800 text-[11px] uppercase tracking-wider text-gray-500 dark:text-gray-400">
                  <tr>
                    <th className="px-5 py-3 font-semibold">
                      {t("vpsPlanChangeColOperation")}
                    </th>
                    <th className="px-5 py-3 font-semibold">
                      {t("vpsPlanChangeColVm")}
                    </th>
                    <th className="px-5 py-3 font-semibold">
                      {t("vpsPlanChangeColUser")}
                    </th>
                    <th className="px-5 py-3 font-semibold">
                      {t("vpsPlanChangeColTransition")}
                    </th>
                    <th className="px-5 py-3 font-semibold">
                      {t("vpsPlanChangeColResources")}
                    </th>
                    <th className="px-5 py-3 font-semibold">
                      {t("vpsPlanChangeColStatus")}
                    </th>
                    <th className="px-5 py-3 font-semibold text-right">
                      {t("vpsPlanChangeColActions")}
                    </th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-gray-200 dark:divide-gray-800">
                  {changes.map((change) => {
                    const needsResolution =
                      change.status === "reconciliation_required";
                    return (
                      <tr
                        key={change.public_id}
                        className="hover:bg-gray-50/70 dark:hover:bg-gray-800/20"
                      >
                        <td className="px-5 py-3.5 align-top">
                          <div className="font-mono text-[11px] font-bold text-gray-900 dark:text-gray-100">
                            {change.public_id}
                          </div>
                          <div className="text-[11px] text-gray-400 mt-0.5">
                            {change.requested_at
                              ? new Date(change.requested_at).toLocaleString()
                              : change.created_at
                              ? new Date(change.created_at).toLocaleString()
                              : "—"}
                          </div>
                        </td>

                        <td className="px-5 py-3.5 align-top">
                          <div className="font-semibold text-gray-900 dark:text-gray-100 font-mono">
                            {change.virtual_machine?.name ??
                              `#${change.virtual_machine_id}`}
                          </div>
                          <div className="text-[11px] text-gray-400">
                            VM #{change.virtual_machine_id}
                          </div>
                        </td>

                        <td className="px-5 py-3.5 align-top">
                          <div className="font-semibold text-gray-900 dark:text-gray-100">
                            {change.user?.name ?? `User #${change.user_id}`}
                          </div>
                          <div className="text-[11px] text-gray-400">
                            {change.user?.email
                              ? `${change.user.email} (#${change.user_id})`
                              : `#${change.user_id}`}
                          </div>
                        </td>

                        <td className="px-5 py-3.5 align-top">
                          <div className="font-semibold text-gray-800 dark:text-gray-200">
                            {change.from_version?.plan_name
                              ? `${change.from_version.plan_name} (v${
                                  change.from_version.version ?? "?"
                                })`
                              : t("vpsPlanChangeCustomResourcesLabel")}
                            {" → "}
                            {change.to_version?.plan_name
                              ? `${change.to_version.plan_name} (v${
                                  change.to_version.version ?? "?"
                                })`
                              : "—"}
                          </div>
                        </td>

                        <td className="px-5 py-3.5 align-top text-gray-600 dark:text-gray-300 whitespace-nowrap">
                          <div>
                            {change.old_resources.cpu_cores} vCPU &rarr;{" "}
                            <span className="font-semibold text-gray-900 dark:text-gray-100">
                              {change.new_resources.cpu_cores} vCPU
                            </span>
                          </div>
                          <div>
                            {change.old_resources.ram_mb} &rarr;{" "}
                            <span className="font-semibold text-gray-900 dark:text-gray-100">
                              {change.new_resources.ram_mb} MiB
                            </span>
                          </div>
                          <div>
                            {change.old_resources.disk_gb} &rarr;{" "}
                            <span className="font-semibold text-gray-900 dark:text-gray-100">
                              {change.new_resources.disk_gb} GiB
                            </span>
                          </div>
                        </td>

                        <td className="px-5 py-3.5 align-top space-y-1">
                          <div className="flex items-center gap-1.5 flex-wrap">
                            <span
                              className={`inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[11px] font-semibold ${getPlanChangeStatusBadgeStyle(
                                change.status
                              )}`}
                            >
                              {isPlanChangePollingActive(change.status) && (
                                <Loader2 className="w-3 h-3 animate-spin" />
                              )}
                              {change.status === "completed" && (
                                <CheckCircle2 className="w-3 h-3" />
                              )}
                              {change.status === "failed" && (
                                <XCircle className="w-3 h-3" />
                              )}
                              {needsResolution && (
                                <AlertTriangle className="w-3 h-3" />
                              )}
                              <span>{translatePlanChangeStatus(change.status, t)}</span>
                            </span>
                            {change.stage && (
                              <span className="text-[11px] text-gray-500 dark:text-gray-400">
                                ({translatePlanChangeStage(change.stage, t)})
                              </span>
                            )}
                          </div>

                          {(change.status === "failed" || needsResolution) &&
                            (change.error_message || change.error_code) && (
                              <div className="text-[11px] text-gray-500 dark:text-gray-400 max-w-xs break-words">
                                {change.error_message ||
                                  translatePlanChangeErrorCode(change.error_code, t)}
                              </div>
                            )}
                        </td>

                        <td className="px-5 py-3.5 align-top text-right whitespace-nowrap">
                          {needsResolution ? (
                            <button
                              type="button"
                              onClick={() => openResolveModal(change)}
                              className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-orange-600 hover:bg-orange-500 text-white text-xs font-semibold shadow-sm shadow-orange-600/20 transition"
                            >
                              <Wrench className="w-3.5 h-3.5" />
                              <span>{t("vpsPlanChangeResolveButton")}</span>
                            </button>
                          ) : (
                            <span className="text-gray-400">—</span>
                          )}
                        </td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>

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
          </>
        )}
      </div>

      {/* Resolve Reconciliation Modal */}
      {resolveTarget && (
        <div
          className="fixed inset-0 z-[80] flex items-center justify-center p-4 bg-black/60 backdrop-blur-sm animate-fadeIn"
          onClick={(e) => {
            if (e.target === e.currentTarget && !resolving) {
              setResolveTarget(null);
            }
          }}
        >
          <form
            onSubmit={handleSubmitResolve}
            className="relative w-full max-w-lg bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-3xl shadow-2xl overflow-hidden"
          >
            <div className="px-6 py-4 border-b border-gray-200 dark:border-gray-800 bg-gray-50/70 dark:bg-gray-800/40 flex items-center justify-between gap-3">
              <div className="flex items-center gap-2.5">
                <div className="p-2 rounded-xl bg-orange-500/10 text-orange-600 dark:text-orange-400 border border-orange-500/20">
                  <Wrench className="w-4 h-4" />
                </div>
                <div>
                  <h3 className="text-sm font-bold text-gray-900 dark:text-white">
                    {t("vpsPlanChangeResolveModalTitle")}
                  </h3>
                  <p className="text-[11px] font-mono text-gray-500 dark:text-gray-400">
                    {resolveTarget.public_id}
                  </p>
                </div>
              </div>

              <button
                type="button"
                onClick={() => setResolveTarget(null)}
                disabled={resolving}
                className="p-1.5 rounded-xl text-gray-400 hover:text-gray-700 dark:hover:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-800 transition disabled:opacity-40"
              >
                <X className="w-4 h-4" />
              </button>
            </div>

            <div className="p-6 space-y-4 text-xs">
              <div className="p-3.5 rounded-2xl bg-gray-50 dark:bg-gray-800/50 border border-gray-200 dark:border-gray-800 space-y-1">
                <div className="font-semibold text-gray-900 dark:text-gray-100">
                  {resolveTarget.virtual_machine?.name ??
                    `VM #${resolveTarget.virtual_machine_id}`}
                </div>
                <div className="text-gray-600 dark:text-gray-300">
                  {resolveTarget.old_resources.cpu_cores} vCPU /{" "}
                  {resolveTarget.old_resources.ram_mb} MiB /{" "}
                  {resolveTarget.old_resources.disk_gb} GiB &rarr;{" "}
                  <span className="font-semibold text-gray-900 dark:text-gray-100">
                    {resolveTarget.new_resources.cpu_cores} vCPU /{" "}
                    {resolveTarget.new_resources.ram_mb} MiB /{" "}
                    {resolveTarget.new_resources.disk_gb} GiB
                  </span>
                </div>
                {(resolveTarget.error_message || resolveTarget.error_code) && (
                  <div className="text-orange-600 dark:text-orange-400 pt-1">
                    {resolveTarget.error_message ||
                      translatePlanChangeErrorCode(resolveTarget.error_code, t)}
                  </div>
                )}
              </div>

              <div className="space-y-2">
                <div className="font-semibold text-gray-700 dark:text-gray-300">
                  {t("vpsPlanChangeResolveActionLabel")}
                </div>

                {(
                  [
                    {
                      id: "complete",
                      title: t("vpsPlanChangeResolveActionComplete"),
                      desc: t("vpsPlanChangeResolveActionCompleteDesc"),
                    },
                    {
                      id: "fail",
                      title: t("vpsPlanChangeResolveActionFail"),
                      desc: t("vpsPlanChangeResolveActionFailDesc"),
                    },
                    {
                      id: "retry",
                      title: t("vpsPlanChangeResolveActionRetry"),
                      desc: t("vpsPlanChangeResolveActionRetryDesc"),
                    },
                  ] as const
                ).map((option) => (
                  <label
                    key={option.id}
                    className={`flex items-start gap-3 p-3 rounded-2xl border cursor-pointer transition ${
                      resolveAction === option.id
                        ? "border-blue-500 bg-blue-500/5"
                        : "border-gray-200 dark:border-gray-800 hover:border-gray-300 dark:hover:border-gray-700"
                    }`}
                  >
                    <input
                      type="radio"
                      name="resolve-action"
                      value={option.id}
                      checked={resolveAction === option.id}
                      onChange={() => setResolveAction(option.id)}
                      className="mt-0.5 text-blue-600 focus:ring-blue-500"
                    />
                    <div>
                      <div className="font-semibold text-gray-900 dark:text-gray-100">
                        {option.title}
                      </div>
                      <div className="text-[11px] text-gray-500 dark:text-gray-400 mt-0.5">
                        {option.desc}
                      </div>
                    </div>
                  </label>
                ))}
              </div>

              <div>
                <label className="block font-semibold text-gray-700 dark:text-gray-300 mb-1.5">
                  {t("vpsPlanChangeResolveNoteLabel")}
                </label>
                <textarea
                  rows={3}
                  maxLength={1000}
                  value={resolveNote}
                  onChange={(e) => setResolveNote(e.target.value)}
                  placeholder={t("vpsPlanChangeResolveNotePlaceholder")}
                  className="w-full px-3.5 py-2.5 rounded-2xl border border-gray-200 dark:border-gray-800 bg-gray-50 dark:bg-gray-950 text-xs text-gray-900 dark:text-gray-100 focus:outline-none focus:border-blue-500"
                />
              </div>

              {resolveError && (
                <div className="p-3 rounded-2xl bg-red-500/10 border border-red-500/20 flex items-start gap-2 text-red-700 dark:text-red-300">
                  <AlertCircle className="w-4 h-4 text-red-500 shrink-0 mt-0.5" />
                  <span>{resolveError}</span>
                </div>
              )}
            </div>

            <div className="px-6 py-4 border-t border-gray-200 dark:border-gray-800 bg-gray-50/70 dark:bg-gray-900 flex items-center justify-end gap-3">
              <button
                type="button"
                disabled={resolving}
                onClick={() => setResolveTarget(null)}
                className="px-4 py-2 rounded-xl border border-gray-200 dark:border-gray-700 text-xs font-semibold text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800 transition disabled:opacity-40"
              >
                {t("vpsPlanCancel")}
              </button>

              <button
                type="submit"
                disabled={resolving}
                className="inline-flex items-center gap-2 px-5 py-2 rounded-xl bg-orange-600 hover:bg-orange-500 text-white text-xs font-semibold shadow-sm shadow-orange-600/20 transition disabled:opacity-50"
              >
                {resolving && <Loader2 className="w-3.5 h-3.5 animate-spin" />}
                <span>
                  {resolving
                    ? t("vpsPlanChangeResolving")
                    : t("vpsPlanChangeResolveSubmit")}
                </span>
              </button>
            </div>
          </form>
        </div>
      )}
    </div>
  );
}
