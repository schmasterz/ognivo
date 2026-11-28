"use client";

import React, { useCallback, useEffect, useMemo, useRef, useState } from "react";
import {
  AlertCircle,
  AlertTriangle,
  Cpu,
  HardDrive,
  History,
  Layers,
  Loader2,
  Network,
  Package,
  RefreshCw,
  XCircle,
} from "lucide-react";
import { VpsPlanChange, vmsApi } from "@/lib/api";
import { extractPaginationMeta } from "@/lib/api/client";
import { useI18n } from "@/lib/i18n/I18nContext";
import { useToast } from "@/lib/toast/ToastContext";
import { ConfirmModal } from "@/components/ui/ConfirmModal";
import { PaginationBar } from "@/components/ui/PaginationBar";
import type { VmTabComponentProps } from "@/lib/registry/vmRegistry";
import { formatNetworkLimit, formatPlanRam } from "@/components/plans/VpsPlanCard";
import {
  ChangePlanModal,
  PLAN_CHANGE_POLL_INTERVAL_MS,
  extractPlanChangeApiErrorMessage,
  getPlanChangeStatusBadgeStyle,
  isPlanChangeCancellableStatus,
  isPlanChangePollingActive,
  translatePlanChangeErrorCode,
  translatePlanChangeStage,
  translatePlanChangeStatus,
} from "./ChangePlanModal";

export function VmPlanTab({ vm, onVmUpdated }: VmTabComponentProps) {
  const { t } = useI18n();
  const { toast } = useToast();

  const [changes, setChanges] = useState<VpsPlanChange[]>([]);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(10);
  const [lastPage, setLastPage] = useState(1);
  const [total, setTotal] = useState(0);

  const [modalOpen, setModalOpen] = useState(false);
  const [modalInitialChange, setModalInitialChange] = useState<VpsPlanChange | null>(null);
  const [cancelTarget, setCancelTarget] = useState<VpsPlanChange | null>(null);
  const [cancelling, setCancelling] = useState(false);

  const pollingRef = useRef<ReturnType<typeof setInterval> | null>(null);

  const fetchChanges = useCallback(
    async (showLoading = true, signal?: AbortSignal) => {
      try {
        if (showLoading) setLoading(true);
        else setRefreshing(true);
        setError(null);

        const res = await vmsApi.getPlanChanges(
          vm.id,
          { page, per_page: perPage },
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
          extractPlanChangeApiErrorMessage(err, t, t("vpsPlanChangeHistoryLoadError"))
        );
      } finally {
        if (!signal?.aborted) {
          setLoading(false);
          setRefreshing(false);
        }
      }
    },
    [vm.id, page, perPage, t]
  );

  useEffect(() => {
    const controller = new AbortController();
    queueMicrotask(() => {
      if (!controller.signal.aborted) {
        void fetchChanges(true, controller.signal);
      }
    });
    return () => controller.abort();
  }, [fetchChanges]);

  const hasPollingChange = useMemo(
    () => changes.some((item) => isPlanChangePollingActive(item.status)),
    [changes]
  );

  useEffect(() => {
    if (hasPollingChange) {
      pollingRef.current = setInterval(() => {
        void fetchChanges(false);
      }, PLAN_CHANGE_POLL_INTERVAL_MS);
    } else if (pollingRef.current) {
      clearInterval(pollingRef.current);
      pollingRef.current = null;
    }

    return () => {
      if (pollingRef.current) {
        clearInterval(pollingRef.current);
        pollingRef.current = null;
      }
    };
  }, [hasPollingChange, fetchChanges]);

  const handleConfirmCancel = async () => {
    if (!cancelTarget || cancelling) return;
    setCancelling(true);
    try {
      const updated = await vmsApi.cancelPlanChange(vm.id, cancelTarget.public_id);
      setChanges((prev) =>
        prev.map((item) => (item.public_id === updated.public_id ? updated : item))
      );
      toast.success(t("vpsPlanChangeCancelSuccess"));
      setCancelTarget(null);
    } catch (err: unknown) {
      toast.error(
        extractPlanChangeApiErrorMessage(err, t, t("vpsPlanChangeCancelError"))
      );
    } finally {
      setCancelling(false);
    }
  };

  const formatMbps = (value: number) => t("vpsPlanMbpsValue", { mbps: value });
  const networkLimits = vm.network_limits;
  const isNetworkApplicable = networkLimits ? networkLimits.applicable !== false : true;
  const activeChange = changes.find((item) => isPlanChangePollingActive(item.status)) ?? null;

  return (
    <div className="space-y-6">
      {/* Current Plan & Resource Allocation Card */}
      <div className="rounded-2xl border border-zinc-200 dark:border-zinc-800 bg-zinc-50/50 dark:bg-zinc-800/30 overflow-hidden">
        <div className="px-5 py-4 bg-zinc-100/70 dark:bg-zinc-800/60 border-b border-zinc-200 dark:border-zinc-800 flex flex-wrap items-center justify-between gap-3">
          <div className="flex items-center gap-2.5 min-w-0">
            <div className="p-2 rounded-xl bg-blue-500/10 text-blue-600 dark:text-blue-400">
              <Package className="w-4 h-4" />
            </div>
            <div className="min-w-0">
              <div className="text-[11px] font-medium text-zinc-500 dark:text-zinc-400">
                {t("vpsPlanChangeCurrentPlanLabel")}
              </div>
              <div className="flex items-center gap-2 flex-wrap">
                <span className="text-sm font-bold text-zinc-900 dark:text-zinc-100">
                  {vm.vps_plan ? vm.vps_plan.name : t("vpsPlanChangeNoPlanAssigned")}
                </span>
                {vm.vps_plan && (
                  <span className="px-2 py-0.5 rounded-full text-[10px] font-mono font-semibold bg-zinc-200/80 dark:bg-zinc-700 text-zinc-700 dark:text-zinc-200">
                    {t("vpsPlanVersionLabel", { version: vm.vps_plan.version })}
                  </span>
                )}
                {vm.vps_plan && !vm.vps_plan.is_current_version && (
                  <span className="px-2 py-0.5 rounded-full text-[10px] font-semibold bg-amber-500/10 text-amber-600 dark:text-amber-400 border border-amber-500/20">
                    {t("vpsPlanChangeOutdatedVersionBadge")}
                  </span>
                )}
              </div>
            </div>
          </div>

          <div className="flex items-center gap-2">
            {activeChange && (
              <button
                type="button"
                onClick={() => {
                  setModalInitialChange(activeChange);
                  setModalOpen(true);
                }}
                className="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl border border-blue-500/30 bg-blue-500/10 text-blue-600 dark:text-blue-400 hover:bg-blue-500/20 text-xs font-semibold transition"
              >
                <Loader2 className="w-3.5 h-3.5 animate-spin" />
                <span>{t("vpsPlanChangeViewProgressButton")}</span>
              </button>
            )}

            <button
              type="button"
              onClick={() => {
                setModalInitialChange(null);
                setModalOpen(true);
              }}
              className="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-blue-600 hover:bg-blue-500 text-white text-xs font-semibold shadow-sm shadow-blue-600/20 transition"
            >
              <Package className="w-3.5 h-3.5" />
              <span>{t("vpsPlanChangeButton")}</span>
            </button>
          </div>
        </div>

        <div className="p-5 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 text-xs">
          <div className="p-3.5 rounded-xl bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 flex items-center gap-3">
            <Cpu className="w-4 h-4 text-blue-500 shrink-0" />
            <div>
              <div className="text-[11px] text-zinc-500 dark:text-zinc-400">
                {t("vpsPlanCpuLabel")}
              </div>
              <div className="font-bold text-zinc-900 dark:text-zinc-100 mt-0.5">
                {t("vpsPlanCpuValue", { count: vm.cpu_cores })}
              </div>
            </div>
          </div>

          <div className="p-3.5 rounded-xl bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 flex items-center gap-3">
            <Layers className="w-4 h-4 text-purple-500 shrink-0" />
            <div>
              <div className="text-[11px] text-zinc-500 dark:text-zinc-400">
                {t("vpsPlanRamLabel")}
              </div>
              <div className="font-bold text-zinc-900 dark:text-zinc-100 mt-0.5">
                {formatPlanRam(vm.ram_mb)}
              </div>
            </div>
          </div>

          <div className="p-3.5 rounded-xl bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 flex items-center gap-3">
            <HardDrive className="w-4 h-4 text-emerald-500 shrink-0" />
            <div>
              <div className="text-[11px] text-zinc-500 dark:text-zinc-400">
                {t("vpsPlanDiskLabel")}
              </div>
              <div className="font-bold text-zinc-900 dark:text-zinc-100 mt-0.5">
                {t("vpsPlanDiskValue", { gb: vm.disk_gb })}
              </div>
            </div>
          </div>

          <div className="p-3.5 rounded-xl bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 flex items-center gap-3">
            <Network className="w-4 h-4 text-amber-500 shrink-0" />
            <div className="min-w-0">
              <div className="text-[11px] text-zinc-500 dark:text-zinc-400">
                {t("vpsPlanChangeNetworkLimitsLabel")}
              </div>
              {isNetworkApplicable ? (
                <div className="font-bold text-zinc-900 dark:text-zinc-100 mt-0.5 truncate">
                  {formatNetworkLimit(
                    networkLimits?.download_mbps,
                    t("vpsPlanUnlimited"),
                    formatMbps
                  )}{" "}
                  /{" "}
                  {formatNetworkLimit(
                    networkLimits?.upload_mbps,
                    t("vpsPlanUnlimited"),
                    formatMbps
                  )}
                </div>
              ) : (
                <div
                  className="font-semibold text-amber-600 dark:text-amber-400 mt-0.5"
                  title={t("vpsPlanChangeNetworkNotApplicableHint")}
                >
                  {t("vpsPlanChangeNetworkNotApplicable")}
                </div>
              )}
            </div>
          </div>
        </div>
      </div>

      {/* Plan Change History Table */}
      <div className="rounded-2xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 overflow-hidden">
        <div className="px-5 py-3.5 bg-zinc-50 dark:bg-zinc-800/40 border-b border-zinc-200 dark:border-zinc-800 flex items-center justify-between">
          <div className="flex items-center gap-2 text-xs font-semibold text-zinc-900 dark:text-zinc-100">
            <History className="w-4 h-4 text-blue-500" />
            <span>{t("vpsPlanChangeHistoryTitle")}</span>
          </div>

          <button
            type="button"
            onClick={() => void fetchChanges(false)}
            disabled={loading || refreshing}
            className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 text-[11px] font-semibold text-zinc-700 dark:text-zinc-300 hover:bg-zinc-100 dark:hover:bg-zinc-800 transition disabled:opacity-50"
          >
            <RefreshCw
              className={`w-3 h-3 ${loading || refreshing ? "animate-spin" : ""}`}
            />
            <span>{t("vpsPlansRefresh")}</span>
          </button>
        </div>

        {loading ? (
          <div className="py-12 flex items-center justify-center gap-2 text-xs text-zinc-500 dark:text-zinc-400">
            <Loader2 className="w-5 h-5 animate-spin text-blue-500" />
            <span>{t("loading")}</span>
          </div>
        ) : error ? (
          <div className="p-5 flex items-center justify-between gap-3 text-xs text-red-600 dark:text-red-400">
            <div className="flex items-center gap-2">
              <AlertCircle className="w-4 h-4 shrink-0" />
              <span>{error}</span>
            </div>
            <button
              type="button"
              onClick={() => void fetchChanges(true)}
              className="px-3 py-1 rounded-lg bg-red-600 text-white font-semibold hover:bg-red-500 transition"
            >
              {t("vpsPlansRetry")}
            </button>
          </div>
        ) : changes.length === 0 ? (
          <div className="py-10 px-5 text-center text-xs text-zinc-500 dark:text-zinc-400">
            {t("vpsPlanChangeHistoryEmpty")}
          </div>
        ) : (
          <>
            <div className="overflow-x-auto">
              <table className="w-full text-left text-xs">
                <thead className="bg-zinc-50/70 dark:bg-zinc-800/40 border-b border-zinc-200 dark:border-zinc-800 text-[11px] uppercase tracking-wider text-zinc-500 dark:text-zinc-400">
                  <tr>
                    <th className="px-4 py-2.5 font-semibold">
                      {t("vpsPlanChangeColOperation")}
                    </th>
                    <th className="px-4 py-2.5 font-semibold">
                      {t("vpsPlanChangeColTransition")}
                    </th>
                    <th className="px-4 py-2.5 font-semibold">
                      {t("vpsPlanChangeColResources")}
                    </th>
                    <th className="px-4 py-2.5 font-semibold">
                      {t("vpsPlanChangeColStatus")}
                    </th>
                    <th className="px-4 py-2.5 font-semibold text-right">
                      {t("vpsPlanChangeColActions")}
                    </th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-zinc-200 dark:divide-zinc-800">
                  {changes.map((change) => {
                    const cancellable = isPlanChangeCancellableStatus(change.status);
                    return (
                      <tr key={change.public_id} className="hover:bg-zinc-50/70 dark:hover:bg-zinc-800/20">
                        <td className="px-4 py-3 align-top">
                          <div className="font-mono text-[11px] font-semibold text-zinc-800 dark:text-zinc-200">
                            {change.public_id}
                          </div>
                          <div className="text-[11px] text-zinc-400 mt-0.5">
                            {change.requested_at
                              ? new Date(change.requested_at).toLocaleString()
                              : change.created_at
                              ? new Date(change.created_at).toLocaleString()
                              : "—"}
                          </div>
                        </td>

                        <td className="px-4 py-3 align-top">
                          <div className="font-semibold text-zinc-800 dark:text-zinc-200">
                            {change.from?.plan_name
                              ? `${change.from.plan_name} (v${change.from.version ?? "?"})`
                              : t("vpsPlanChangeCustomResourcesLabel")}
                            {" → "}
                            {change.to?.plan_name
                              ? `${change.to.plan_name} (v${change.to.version ?? "?"})`
                              : "—"}
                          </div>
                        </td>

                        <td className="px-4 py-3 align-top text-zinc-600 dark:text-zinc-300 whitespace-nowrap">
                          <div>
                            {change.old_resources.cpu_cores} vCPU &rarr;{" "}
                            <span className="font-semibold text-zinc-900 dark:text-zinc-100">
                              {change.new_resources.cpu_cores} vCPU
                            </span>
                          </div>
                          <div>
                            {change.old_resources.ram_mb} &rarr;{" "}
                            <span className="font-semibold text-zinc-900 dark:text-zinc-100">
                              {change.new_resources.ram_mb} MiB
                            </span>
                          </div>
                          <div>
                            {change.old_resources.disk_gb} &rarr;{" "}
                            <span className="font-semibold text-zinc-900 dark:text-zinc-100">
                              {change.new_resources.disk_gb} GiB
                            </span>
                          </div>
                        </td>

                        <td className="px-4 py-3 align-top space-y-1">
                          <div className="flex items-center gap-1.5 flex-wrap">
                            <span
                              className={`inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[11px] font-semibold ${getPlanChangeStatusBadgeStyle(
                                change.status
                              )}`}
                            >
                              {isPlanChangePollingActive(change.status) && (
                                <Loader2 className="w-3 h-3 animate-spin" />
                              )}
                              <span>{translatePlanChangeStatus(change.status, t)}</span>
                            </span>
                            {change.stage && (
                              <span className="text-[11px] text-zinc-500 dark:text-zinc-400">
                                ({translatePlanChangeStage(change.stage, t)})
                              </span>
                            )}
                          </div>

                          {change.status === "failed" && (
                            <div className="text-[11px] text-red-600 dark:text-red-400 flex items-start gap-1 max-w-xs">
                              <XCircle className="w-3.5 h-3.5 shrink-0 mt-0.5" />
                              <span>
                                {change.error_message ||
                                  translatePlanChangeErrorCode(change.error_code, t)}
                              </span>
                            </div>
                          )}

                          {change.status === "reconciliation_required" && (
                            <div className="text-[11px] text-orange-600 dark:text-orange-400 flex items-start gap-1 max-w-xs">
                              <AlertTriangle className="w-3.5 h-3.5 shrink-0 mt-0.5" />
                              <span>{t("vpsPlanChangeReconciliationNotice")}</span>
                            </div>
                          )}
                        </td>

                        <td className="px-4 py-3 align-top text-right whitespace-nowrap space-x-2">
                          <button
                            type="button"
                            onClick={() => {
                              setModalInitialChange(change);
                              setModalOpen(true);
                            }}
                            className="px-2.5 py-1 rounded-lg border border-zinc-200 dark:border-zinc-700 text-[11px] font-semibold text-zinc-700 dark:text-zinc-300 hover:bg-zinc-100 dark:hover:bg-zinc-800 transition"
                          >
                            {t("vpsPlanChangeViewProgressButton")}
                          </button>

                          {cancellable && (
                            <button
                              type="button"
                              onClick={() => setCancelTarget(change)}
                              className="px-2.5 py-1 rounded-lg border border-red-500/30 bg-red-500/10 text-red-600 dark:text-red-400 hover:bg-red-500/20 text-[11px] font-semibold transition"
                            >
                              {t("vpsPlanChangeCancelButton")}
                            </button>
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

      {/* Change Plan Wizard / Progress Modal */}
      <ChangePlanModal
        isOpen={modalOpen}
        onClose={() => {
          setModalOpen(false);
          setModalInitialChange(null);
        }}
        vm={vm}
        initialPlanChange={modalInitialChange}
        onSuccess={() => {
          void fetchChanges(false);
        }}
        onVmUpdated={onVmUpdated}
      />

      {/* Cancel Confirmation Modal */}
      <ConfirmModal
        isOpen={cancelTarget !== null}
        title={t("vpsPlanChangeConfirmCancelTitle")}
        message={t("vpsPlanChangeConfirmCancelDesc")}
        confirmText={t("vpsPlanChangeCancelButton")}
        cancelText={t("vpsPlanClose")}
        variant="danger"
        isLoading={cancelling}
        onConfirm={handleConfirmCancel}
        onClose={() => {
          if (!cancelling) setCancelTarget(null);
        }}
      />
    </div>
  );
}
