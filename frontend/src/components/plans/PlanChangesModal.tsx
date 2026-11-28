"use client";

import React, { useCallback, useEffect, useState } from "react";
import {
  AlertCircle,
  AlertTriangle,
  ArrowLeftRight,
  CheckCircle2,
  ChevronUp,
  Eye,
  Loader2,
  RefreshCw,
  Wrench,
  X,
  XCircle,
} from "lucide-react";
import {
  type AdminVpsPlan,
  type AdminVpsPlanChange,
  type ResolveAdminPlanChangeAction,
  vpsPlansApi,
} from "@/lib/api";
import { extractPaginationMeta } from "@/lib/api/client";
import { PaginationBar } from "@/components/ui/PaginationBar";
import { RowActions } from "@/components/ui/rowActions/RowActions";
import { StickyActionsTd, StickyActionsTh } from "@/components/ui/rowActions/StickyActionsCell";
import { type RowAction } from "@/components/ui/rowActions/types";
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
import { formatNetworkLimit, formatPlanRam } from "./VpsPlanCard";

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

export type PlanChangeDirection = "incoming" | "outgoing" | "internal";

export function parsePositiveIntFilter(value: string): number | null {
  const trimmed = value.trim();
  if (!trimmed) return null;
  if (!/^\d+$/.test(trimmed)) return null;
  const parsed = Number.parseInt(trimmed, 10);
  return Number.isSafeInteger(parsed) && parsed > 0 ? parsed : null;
}

export function resolvePlanChangeDirection(
  change: Pick<AdminVpsPlanChange, "from_version" | "to_version">,
  planId: number
): PlanChangeDirection {
  const fromMatch = change.from_version?.vps_plan_id === planId;
  const toMatch = change.to_version?.vps_plan_id === planId;
  if (fromMatch && toMatch) {
    return "internal";
  }
  if (fromMatch) {
    return "outgoing";
  }
  return "incoming";
}

function getDirectionBadgeStyle(direction: PlanChangeDirection): string {
  switch (direction) {
    case "incoming":
      return "bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border border-emerald-500/20";
    case "outgoing":
      return "bg-amber-500/10 text-amber-600 dark:text-amber-400 border border-amber-500/20";
    case "internal":
      return "bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 border border-indigo-500/20";
  }
}

function formatTimestamp(iso: string | null | undefined): string {
  if (!iso) return "—";
  return new Date(iso).toLocaleString();
}

export interface PlanChangesModalProps {
  plan: AdminVpsPlan;
  onClose: () => void;
}

export function PlanChangesModal({ plan, onClose }: PlanChangesModalProps) {
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

  const [expandedPublicId, setExpandedPublicId] = useState<string | null>(null);

  const [resolveTarget, setResolveTarget] = useState<AdminVpsPlanChange | null>(null);
  const [resolveAction, setResolveAction] =
    useState<ResolveAdminPlanChangeAction>("complete");
  const [resolveNote, setResolveNote] = useState("");
  const [resolving, setResolving] = useState(false);
  const [resolveError, setResolveError] = useState<string | null>(null);

  useModalEscape(onClose, resolveTarget === null);
  useModalEscape(
    () => {
      if (!resolving) setResolveTarget(null);
    },
    resolveTarget !== null
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
            plan_id: plan.id,
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
    [plan.id, statusFilter, debouncedUserId, debouncedVmId, page, perPage, t]
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

  const formatNet = (mbps: number | null | undefined) =>
    formatNetworkLimit(mbps, t("vpsPlanUnlimited"), (val) =>
      t("vpsPlanMbpsValue", { mbps: val })
    );

  return (
    <div
      className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-sm animate-fadeIn"
      onClick={(e) => {
        if (e.target === e.currentTarget && !resolveTarget) {
          onClose();
        }
      }}
    >
      <div className="relative w-full max-w-6xl bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-3xl p-6 sm:p-8 shadow-2xl overflow-y-auto max-h-[90vh] transition-colors space-y-5">
        {/* Modal Header */}
        <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pb-4 border-b border-gray-100 dark:border-gray-800">
          <div className="flex items-start gap-3 min-w-0">
            <div className="p-2.5 rounded-2xl bg-blue-500/10 text-blue-600 dark:text-blue-400 border border-blue-500/20 shrink-0">
              <ArrowLeftRight className="w-5 h-5" />
            </div>
            <div className="min-w-0">
              <div className="flex items-center flex-wrap gap-2">
                <h2 className="text-xl font-bold text-gray-900 dark:text-white tracking-tight truncate">
                  {t("vpsPlanChangesModalTitle", { plan: plan.name })}
                </h2>
                <span className="px-2 py-0.5 rounded-lg text-[11px] font-mono bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-300">
                  {plan.slug}
                </span>
              </div>
              <p className="text-xs text-gray-500 dark:text-gray-400 mt-1">
                {t("vpsPlanChangesModalSubtitle")}
              </p>
            </div>
          </div>

          <div className="flex items-center gap-2 self-end sm:self-auto shrink-0">
            <button
              type="button"
              onClick={() => void fetchPlanChanges()}
              disabled={loading}
              className="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-2xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 hover:bg-gray-50 dark:hover:bg-gray-800 text-xs font-semibold text-gray-700 dark:text-gray-200 transition disabled:opacity-50"
            >
              <RefreshCw className={`w-3.5 h-3.5 ${loading ? "animate-spin" : ""}`} />
              <span>{t("vpsPlansRefresh")}</span>
            </button>

            <button
              type="button"
              onClick={onClose}
              aria-label={t("vpsPlanClose")}
              className="p-2 rounded-xl text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-800 transition"
            >
              <X className="w-5 h-5" />
            </button>
          </div>
        </div>

        {/* Filters */}
        <div className="p-4 rounded-2xl bg-gray-50/70 dark:bg-gray-950/40 border border-gray-200 dark:border-gray-800 flex flex-wrap items-end gap-3">
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
              className="w-full px-3 py-2 rounded-xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 text-xs font-medium text-gray-900 dark:text-gray-100 focus:outline-none focus:border-blue-500"
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
              className="w-full px-3 py-2 rounded-xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 text-xs font-medium text-gray-900 dark:text-gray-100 focus:outline-none focus:border-blue-500"
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
              className="w-full px-3 py-2 rounded-xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 text-xs font-medium text-gray-900 dark:text-gray-100 focus:outline-none focus:border-blue-500"
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
        <div className="rounded-2xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 overflow-hidden shadow-sm">
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
              <div className="overflow-x-auto relative">
                <table className="w-full text-left text-xs">
                  <thead className="bg-gray-50 dark:bg-gray-950/60 border-b border-gray-200 dark:border-gray-800 text-[11px] uppercase tracking-wider text-gray-500 dark:text-gray-400">
                    <tr>
                      <th scope="col" className="px-5 py-3.5 font-semibold">
                        {t("vpsPlanChangeColOperation")}
                      </th>
                      <th scope="col" className="px-4 py-3.5 font-semibold">
                        {t("vpsPlanChangeColVm")}
                      </th>
                      <th scope="col" className="px-4 py-3.5 font-semibold">
                        {t("vpsPlanChangeColUser")}
                      </th>
                      <th scope="col" className="px-4 py-3.5 font-semibold">
                        {t("vpsPlanChangeColTransition")}
                      </th>
                      <th scope="col" className="px-4 py-3.5 font-semibold">
                        {t("vpsPlanChangeColResources")}
                      </th>
                      <th scope="col" className="px-4 py-3.5 font-semibold">
                        {t("vpsPlanChangeColStatus")}
                      </th>
                      <StickyActionsTh>{t("vpsPlanChangeColActions")}</StickyActionsTh>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-gray-200 dark:divide-gray-800">
                    {changes.map((change) => {
                      const needsResolution =
                        change.status === "reconciliation_required";
                      const isExpanded = expandedPublicId === change.public_id;
                      const direction = resolvePlanChangeDirection(change, plan.id);
                      const directionLabel =
                        direction === "incoming"
                          ? t("vpsPlanChangeDirectionIncoming")
                          : direction === "outgoing"
                          ? t("vpsPlanChangeDirectionOutgoing")
                          : t("vpsPlanChangeDirectionInternal");

                      const rowActions: RowAction[] = [
                        {
                          id: "details",
                          icon: isExpanded ? ChevronUp : Eye,
                          label: isExpanded
                            ? t("vpsPlanChangeHideDetails")
                            : t("vpsPlanChangeViewDetails"),
                          severity: "safe",
                          tone: "info",
                          onSelect: () =>
                            setExpandedPublicId((prev) =>
                              prev === change.public_id ? null : change.public_id
                            ),
                        },
                        {
                          id: "resolve",
                          icon: Wrench,
                          label: t("vpsPlanChangeResolveButton"),
                          severity: "caution",
                          tone: "accent",
                          hidden: !needsResolution,
                          onSelect: () => openResolveModal(change),
                        },
                      ];

                      return (
                        <React.Fragment key={change.public_id}>
                          <tr
                            onClick={() =>
                              setExpandedPublicId((prev) =>
                                prev === change.public_id ? null : change.public_id
                              )
                            }
                            className="hover:bg-gray-50 dark:hover:bg-gray-800/40 transition group cursor-pointer"
                          >
                            <td className="px-5 py-3.5 align-top">
                              <div className="font-mono text-[11px] font-bold text-gray-900 dark:text-gray-100">
                                {change.public_id}
                              </div>
                              <div className="text-[11px] text-gray-400 mt-0.5">
                                {formatTimestamp(change.requested_at ?? change.created_at)}
                              </div>
                            </td>

                            <td className="px-4 py-3.5 align-top">
                              <div className="font-semibold text-gray-900 dark:text-gray-100 font-mono">
                                {change.virtual_machine?.name ??
                                  `#${change.virtual_machine_id}`}
                              </div>
                              <div className="text-[11px] text-gray-400">
                                VM #{change.virtual_machine_id}
                              </div>
                            </td>

                            <td className="px-4 py-3.5 align-top">
                              <div className="font-semibold text-gray-900 dark:text-gray-100">
                                {change.user?.name ?? `User #${change.user_id}`}
                              </div>
                              <div className="text-[11px] text-gray-400">
                                {change.user?.email
                                  ? `${change.user.email} (#${change.user_id})`
                                  : `#${change.user_id}`}
                              </div>
                            </td>

                            <td className="px-4 py-3.5 align-top space-y-1">
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
                              <span
                                className={`inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold ${getDirectionBadgeStyle(
                                  direction
                                )}`}
                              >
                                {directionLabel}
                              </span>
                            </td>

                            <td className="px-4 py-3.5 align-top text-gray-600 dark:text-gray-300 whitespace-nowrap">
                              <div>
                                {change.old_resources.cpu_cores} vCPU &rarr;{" "}
                                <span className="font-semibold text-gray-900 dark:text-gray-100">
                                  {change.new_resources.cpu_cores} vCPU
                                </span>
                              </div>
                              <div>
                                {change.old_resources.ram_mb} MiB &rarr;{" "}
                                <span className="font-semibold text-gray-900 dark:text-gray-100">
                                  {change.new_resources.ram_mb} MiB
                                </span>
                              </div>
                              <div>
                                {change.old_resources.disk_gb} GiB &rarr;{" "}
                                <span className="font-semibold text-gray-900 dark:text-gray-100">
                                  {change.new_resources.disk_gb} GiB
                                </span>
                              </div>
                            </td>

                            <td className="px-4 py-3.5 align-top space-y-1">
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
                                  <span>
                                    {translatePlanChangeStatus(change.status, t)}
                                  </span>
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

                            <StickyActionsTd className="align-top">
                              <RowActions actions={rowActions} />
                            </StickyActionsTd>
                          </tr>

                          {isExpanded && (
                            <tr className="bg-gray-50/80 dark:bg-gray-950/60">
                              <td colSpan={7} className="px-5 py-4">
                                <div className="space-y-4">
                                  <div className="text-[11px] font-bold uppercase tracking-wider text-gray-500 dark:text-gray-400">
                                    {t("vpsPlanChangeDetailsSection")}
                                  </div>

                                  <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 text-xs">
                                    <div className="p-3 rounded-xl bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800">
                                      <div className="text-[11px] text-gray-400">
                                        {t("vpsPlanChangeInitiatorLabel")}
                                      </div>
                                      <div className="font-semibold text-gray-900 dark:text-gray-100 mt-0.5 truncate">
                                        {change.initiator
                                          ? `${change.initiator.name} (${change.initiator.email})`
                                          : change.initiated_by
                                          ? `#${change.initiated_by}`
                                          : "—"}
                                      </div>
                                    </div>

                                    <div className="p-3 rounded-xl bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800">
                                      <div className="text-[11px] text-gray-400">
                                        {t("vpsPlanChangeDriverLabel")}
                                      </div>
                                      <div className="font-mono font-semibold text-gray-900 dark:text-gray-100 mt-0.5 truncate">
                                        {change.driver_name
                                          ? `${change.driver_name}${
                                              change.driver_operation_id
                                                ? ` · ${change.driver_operation_id}`
                                                : ""
                                            }`
                                          : "—"}
                                      </div>
                                    </div>

                                    <div className="p-3 rounded-xl bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800">
                                      <div className="text-[11px] text-gray-400">
                                        {t("vpsPlanChangeAuthRefLabel")}
                                      </div>
                                      <div className="font-mono font-semibold text-gray-900 dark:text-gray-100 mt-0.5 truncate">
                                        {change.authorization_ref ?? "—"}
                                      </div>
                                    </div>

                                    <div className="p-3 rounded-xl bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800">
                                      <div className="text-[11px] text-gray-400">
                                        {t("vpsPlanNetworkLabel")}
                                      </div>
                                      <div className="font-semibold text-gray-900 dark:text-gray-100 mt-0.5">
                                        ↓ {formatNet(change.old_resources.net_download_mbps)} &rarr;{" "}
                                        {formatNet(change.new_resources.net_download_mbps)}
                                      </div>
                                      <div className="text-[11px] text-gray-500 dark:text-gray-400">
                                        ↑ {formatNet(change.old_resources.net_upload_mbps)} &rarr;{" "}
                                        {formatNet(change.new_resources.net_upload_mbps)}
                                      </div>
                                    </div>
                                  </div>

                                  <div className="grid grid-cols-2 sm:grid-cols-4 gap-3 text-xs">
                                    <div className="p-3 rounded-xl bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800">
                                      <div className="text-[11px] text-gray-400">
                                        {t("vpsPlanChangeColRequestedAt")}
                                      </div>
                                      <div className="font-medium text-gray-800 dark:text-gray-200 mt-0.5">
                                        {formatTimestamp(change.requested_at ?? change.created_at)}
                                      </div>
                                    </div>

                                    <div className="p-3 rounded-xl bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800">
                                      <div className="text-[11px] text-gray-400">
                                        {t("vpsPlanChangeStartedAtLabel")}
                                      </div>
                                      <div className="font-medium text-gray-800 dark:text-gray-200 mt-0.5">
                                        {formatTimestamp(change.started_at)}
                                      </div>
                                    </div>

                                    <div className="p-3 rounded-xl bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800">
                                      <div className="text-[11px] text-gray-400">
                                        {change.failed_at
                                          ? t("vpsPlanChangeFailedAtLabel")
                                          : t("vpsPlanChangeCompletedAtLabel")}
                                      </div>
                                      <div className="font-medium text-gray-800 dark:text-gray-200 mt-0.5">
                                        {formatTimestamp(change.failed_at ?? change.completed_at)}
                                      </div>
                                    </div>

                                    <div className="p-3 rounded-xl bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800">
                                      <div className="text-[11px] text-gray-400">
                                        {t("vpsPlanChangeDeadlineLabel")}
                                      </div>
                                      <div className="font-medium text-gray-800 dark:text-gray-200 mt-0.5">
                                        {formatTimestamp(change.apply_deadline)}
                                      </div>
                                    </div>
                                  </div>

                                  <div className="p-3 rounded-xl bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 flex flex-wrap items-center gap-x-6 gap-y-1 text-xs">
                                    <div>
                                      <span className="text-gray-400">{t("vpsPlanRamLabel")}: </span>
                                      <span className="font-medium text-gray-800 dark:text-gray-200">
                                        {formatPlanRam(change.old_resources.ram_mb)} &rarr;{" "}
                                        {formatPlanRam(change.new_resources.ram_mb)}
                                      </span>
                                    </div>
                                    {change.virtual_machine && (
                                      <div>
                                        <span className="text-gray-400">Project: </span>
                                        <span className="font-mono text-gray-800 dark:text-gray-200">
                                          {change.virtual_machine.incus_project} (
                                          {change.virtual_machine.status})
                                        </span>
                                      </div>
                                    )}
                                  </div>

                                  {(change.error_code || change.error_message) && (
                                    <div className="p-3 rounded-xl bg-red-500/10 border border-red-500/20 text-xs text-red-700 dark:text-red-300 space-y-1">
                                      {change.error_code && (
                                        <div className="font-mono font-bold text-[11px]">
                                          {change.error_code}
                                        </div>
                                      )}
                                      <div>
                                        {change.error_message ||
                                          translatePlanChangeErrorCode(change.error_code, t)}
                                      </div>
                                    </div>
                                  )}
                                </div>
                              </td>
                            </tr>
                          )}
                        </React.Fragment>
                      );
                    })}
                  </tbody>
                </table>
              </div>

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
            </>
          )}
        </div>
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
            onSubmit={(e) => void handleSubmitResolve(e)}
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
