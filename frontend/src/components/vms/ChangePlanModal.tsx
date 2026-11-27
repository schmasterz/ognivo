"use client";

import React, { useCallback, useEffect, useRef, useState } from "react";
import {
  AlertCircle,
  AlertTriangle,
  ArrowRight,
  CheckCircle2,
  Cpu,
  HardDrive,
  Info,
  Layers,
  Loader2,
  Network,
  Package,
  RefreshCw,
  X,
  XCircle,
} from "lucide-react";
import {
  ApiError,
  VirtualMachine,
  VpsPlanChange,
  VpsPlanChangeBlockingReason,
  VpsPlanChangePreview,
  VpsPlanChangeTarget,
  vmsApi,
} from "@/lib/api";
import { useI18n } from "@/lib/i18n/I18nContext";
import { useToast } from "@/lib/toast/ToastContext";
import { useModalEscape } from "@/lib/hooks/useModalEscape";
import { getSlotComponents } from "@/lib/registry/slotRegistry";
import { formatNetworkLimit, formatPlanRam } from "@/components/plans/VpsPlanCard";

export type ChangePlanStep = "select" | "preview" | "confirm" | "progress";

export interface ChangePlanModalProps {
  isOpen: boolean;
  onClose: () => void;
  vm: VirtualMachine;
  initialPlanChange?: VpsPlanChange | null;
  onSuccess?: (planChange: VpsPlanChange) => void;
  onVmUpdated?: (vm: VirtualMachine) => void;
}

type TranslateFn = (key: string, params?: Record<string, string | number>) => string;

export const PLAN_CHANGE_POLL_INTERVAL_MS = 2500;

export const PLAN_CHANGE_CODE_KEYS: Record<string, string> = {
  VM_STATE_NOT_SUPPORTED: "vpsPlanChangeCode_VM_STATE_NOT_SUPPORTED",
  ANOTHER_CHANGE_IN_PROGRESS: "vpsPlanChangeCode_ANOTHER_CHANGE_IN_PROGRESS",
  PLAN_NOT_AVAILABLE: "vpsPlanChangeCode_PLAN_NOT_AVAILABLE",
  VERSION_NOT_AVAILABLE: "vpsPlanChangeCode_VERSION_NOT_AVAILABLE",
  NO_CHANGE: "vpsPlanChangeCode_NO_CHANGE",
  INSTANCE_TYPE_MISMATCH: "vpsPlanChangeCode_INSTANCE_TYPE_MISMATCH",
  DISK_SHRINK_NOT_SUPPORTED: "vpsPlanChangeCode_DISK_SHRINK_NOT_SUPPORTED",
  QUOTA_EXCEEDED: "vpsPlanChangeCode_QUOTA_EXCEEDED",
  HOST_UNREACHABLE: "vpsPlanChangeCode_HOST_UNREACHABLE",
  HOST_CAPACITY_EXCEEDED: "vpsPlanChangeCode_HOST_CAPACITY_EXCEEDED",
  NETWORK_LIMIT_NOT_SUPPORTED_MODE: "vpsPlanChangeCode_NETWORK_LIMIT_NOT_SUPPORTED_MODE",
  REQUIRES_SHUTDOWN_NOT_SUPPORTED: "vpsPlanChangeCode_REQUIRES_SHUTDOWN_NOT_SUPPORTED",
  NETWORK_LIMIT_NO_NIC: "vpsPlanChangeCode_NETWORK_LIMIT_NO_NIC",
  DRIVER_OPERATION_UNSUPPORTED: "vpsPlanChangeCode_DRIVER_OPERATION_UNSUPPORTED",
  EXTENSION_AUTHORIZATION_DENIED: "vpsPlanChangeCode_EXTENSION_AUTHORIZATION_DENIED",
  EXTENSION_AUTHORIZATION_UNAVAILABLE: "vpsPlanChangeCode_EXTENSION_AUTHORIZATION_UNAVAILABLE",
  RESOURCE_OVERRIDE_NOT_ALLOWED: "vpsPlanChangeCode_RESOURCE_OVERRIDE_NOT_ALLOWED",
  PLAN_CHANGE_BLOCKED: "vpsPlanChangeCode_PLAN_CHANGE_BLOCKED",
  PLAN_CHANGE_CANNOT_BE_CANCELLED: "vpsPlanChangeCode_PLAN_CHANGE_CANNOT_BE_CANCELLED",
  VM_NOT_FOUND: "vpsPlanChangeCode_VM_NOT_FOUND",
  AUTHORIZATION_TIMEOUT: "vpsPlanChangeCode_AUTHORIZATION_TIMEOUT",
  APPLY_JOB_FAILED: "vpsPlanChangeCode_APPLY_JOB_FAILED",
  APPLY_TIMEOUT: "vpsPlanChangeCode_APPLY_TIMEOUT",
  EMPTY_DRIVER_RESULT: "vpsPlanChangeCode_EMPTY_DRIVER_RESULT",
  DRIVER_UNAVAILABLE: "vpsPlanChangeCode_DRIVER_UNAVAILABLE",
  HYPERVISOR_EXCEPTION: "vpsPlanChangeCode_HYPERVISOR_EXCEPTION",
  HYPERVISOR_OPERATION_FAILED: "vpsPlanChangeCode_HYPERVISOR_OPERATION_FAILED",
  HYPERVISOR_ERROR: "vpsPlanChangeCode_HYPERVISOR_ERROR",
  RESOURCE_UPDATE_REJECTED: "vpsPlanChangeCode_RESOURCE_UPDATE_REJECTED",
  RESOURCE_UPDATE_FAILED: "vpsPlanChangeCode_RESOURCE_UPDATE_FAILED",
  CONCURRENT_MODIFICATION: "vpsPlanChangeCode_CONCURRENT_MODIFICATION",
  PROJECT_LIMIT_EXCEEDED: "vpsPlanChangeCode_PROJECT_LIMIT_EXCEEDED",
  INVALID_RESOURCE_SPEC: "vpsPlanChangeCode_INVALID_RESOURCE_SPEC",
  INSTANCE_FETCH_FAILED: "vpsPlanChangeCode_INSTANCE_FETCH_FAILED",
  CONNECTION_ERROR: "vpsPlanChangeCode_CONNECTION_ERROR",
  INVALID_OPERATION_ID: "vpsPlanChangeCode_INVALID_OPERATION_ID",
  OPERATION_QUERY_ERROR: "vpsPlanChangeCode_OPERATION_QUERY_ERROR",
  OPERATION_NOT_FOUND: "vpsPlanChangeCode_OPERATION_NOT_FOUND",
  PARTIAL_APPLY: "vpsPlanChangeCode_PARTIAL_APPLY",
  STORAGE_QUOTA_UNSUPPORTED: "vpsPlanChangeCode_STORAGE_QUOTA_UNSUPPORTED",
  NIC_TYPE_UNSUPPORTED: "vpsPlanChangeCode_NIC_TYPE_UNSUPPORTED",
  DISK_SHRINK: "vpsPlanChangeCode_DISK_SHRINK",
  VERIFY_HOST_UNREACHABLE: "vpsPlanChangeCode_VERIFY_HOST_UNREACHABLE",
  VERIFY_RESOURCE_MISMATCH: "vpsPlanChangeCode_VERIFY_RESOURCE_MISMATCH",
  RECONCILE_RESOURCE_MISMATCH: "vpsPlanChangeCode_RECONCILE_RESOURCE_MISMATCH",
  NOT_APPLIED: "vpsPlanChangeCode_NOT_APPLIED",
  RECONCILED_FAILED: "vpsPlanChangeCode_RECONCILED_FAILED",
};

const QUOTA_KIND_KEYS: Record<string, string> = {
  cpu: "vpsPlanChangeCode_QUOTA_EXCEEDED_CPU",
  ram: "vpsPlanChangeCode_QUOTA_EXCEEDED_RAM",
  disk: "vpsPlanChangeCode_QUOTA_EXCEEDED_DISK",
};

export function translatePlanChangeErrorCode(
  code: string | null | undefined,
  t: TranslateFn,
  kind?: string | null
): string {
  if (!code) {
    return t("vpsPlanChangeCodeFallback");
  }
  const normalized = code.trim().toUpperCase();
  if (normalized === "QUOTA_EXCEEDED" && kind) {
    const kindKey = QUOTA_KIND_KEYS[kind.trim().toLowerCase()];
    if (kindKey) {
      return t(kindKey);
    }
  }
  const key = PLAN_CHANGE_CODE_KEYS[normalized];
  return key ? t(key) : t("vpsPlanChangeCodeFallback");
}

export function translatePlanChangeBlockingReason(
  reason: VpsPlanChangeBlockingReason,
  t: TranslateFn
): string {
  return translatePlanChangeErrorCode(reason.code, t, reason.kind);
}

export function translatePlanChangeWarning(code: string, t: TranslateFn): string {
  switch (code.trim().toUpperCase()) {
    case "DISK_GUEST_RESIZE_MAY_BE_REQUIRED":
      return t("vpsPlanChangeWarningDiskGuestResize");
    default:
      return t("vpsPlanChangeWarningGeneric");
  }
}

export function translatePlanChangeStatus(status: string, t: TranslateFn): string {
  switch (status.toLowerCase()) {
    case "pending":
      return t("vpsPlanChangeStatusPending");
    case "awaiting_authorization":
      return t("vpsPlanChangeStatusAwaitingAuthorization");
    case "applying":
      return t("vpsPlanChangeStatusApplying");
    case "completed":
      return t("vpsPlanChangeStatusCompleted");
    case "failed":
      return t("vpsPlanChangeStatusFailed");
    case "cancelled":
      return t("vpsPlanChangeStatusCancelled");
    case "reconciliation_required":
      return t("vpsPlanChangeStatusReconciliationRequired");
    default:
      return t("vpsPlanChangeStatusUnknown");
  }
}

export function translatePlanChangeStage(
  stage: string | null | undefined,
  t: TranslateFn
): string {
  if (!stage) {
    return t("vpsPlanChangeStageUnknown");
  }
  switch (stage.toLowerCase()) {
    case "authorization":
      return t("vpsPlanChangeStageAuthorization");
    case "validating":
      return t("vpsPlanChangeStageValidating");
    case "applying":
      return t("vpsPlanChangeStageApplying");
    case "verifying":
      return t("vpsPlanChangeStageVerifying");
    case "committing":
      return t("vpsPlanChangeStageCommitting");
    default:
      return t("vpsPlanChangeStageUnknown");
  }
}

export function translatePlanChangeDirection(
  direction: string | undefined,
  t: TranslateFn
): string {
  switch ((direction ?? "unchanged").toLowerCase()) {
    case "increase":
      return t("vpsPlanChangeDirIncrease");
    case "decrease":
      return t("vpsPlanChangeDirDecrease");
    case "set":
      return t("vpsPlanChangeDirSet");
    case "remove":
      return t("vpsPlanChangeDirRemove");
    case "unchanged":
    default:
      return t("vpsPlanChangeDirUnchanged");
  }
}

export function getPlanChangeStatusBadgeStyle(status: string): string {
  switch (status.toLowerCase()) {
    case "completed":
      return "bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border border-emerald-500/20";
    case "pending":
    case "awaiting_authorization":
      return "bg-amber-500/10 text-amber-600 dark:text-amber-400 border border-amber-500/20";
    case "applying":
      return "bg-blue-500/10 text-blue-600 dark:text-blue-400 border border-blue-500/20";
    case "reconciliation_required":
      return "bg-orange-500/10 text-orange-600 dark:text-orange-400 border border-orange-500/20";
    case "failed":
      return "bg-red-500/10 text-red-600 dark:text-red-400 border border-red-500/20";
    case "cancelled":
    default:
      return "bg-gray-500/10 text-gray-600 dark:text-gray-400 border border-gray-500/20";
  }
}

export function getDirectionBadgeStyle(direction: string | undefined): string {
  switch ((direction ?? "unchanged").toLowerCase()) {
    case "increase":
    case "set":
      return "bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border border-emerald-500/20";
    case "decrease":
    case "remove":
      return "bg-amber-500/10 text-amber-600 dark:text-amber-400 border border-amber-500/20";
    case "unchanged":
    default:
      return "bg-gray-100 dark:bg-gray-800 text-gray-500 dark:text-gray-400 border border-gray-200 dark:border-gray-700";
  }
}

export function isPlanChangePollingActive(status: string | undefined): boolean {
  if (!status) return false;
  return ["pending", "awaiting_authorization", "applying"].includes(status.toLowerCase());
}

export function isPlanChangeCancellableStatus(status: string | undefined): boolean {
  if (!status) return false;
  return ["pending", "awaiting_authorization"].includes(status.toLowerCase());
}

export function isPlanChangeActiveStatus(status: string | undefined): boolean {
  if (!status) return false;
  return [
    "pending",
    "awaiting_authorization",
    "applying",
    "reconciliation_required",
  ].includes(status.toLowerCase());
}

export function generatePlanChangeIdempotencyKey(): string {
  if (typeof crypto !== "undefined" && typeof crypto.randomUUID === "function") {
    return crypto.randomUUID();
  }
  return `pc-${Date.now()}-${Math.random().toString(36).slice(2, 10)}`;
}

export function extractPlanChangeApiErrorMessage(
  err: unknown,
  t: TranslateFn,
  fallback: string
): string {
  if (err instanceof ApiError) {
    if (err.errors && typeof err.errors === "object") {
      for (const [field, messages] of Object.entries(err.errors)) {
        const upperField = field.toUpperCase();
        if (PLAN_CHANGE_CODE_KEYS[upperField]) {
          return translatePlanChangeErrorCode(upperField, t);
        }
        if (QUOTA_KIND_KEYS[field.toLowerCase()]) {
          return translatePlanChangeErrorCode("QUOTA_EXCEEDED", t, field);
        }
        if (field === "authorization") {
          return translatePlanChangeErrorCode("EXTENSION_AUTHORIZATION_DENIED", t);
        }
        if (field === "vps_plan_version_id" && err.status === 422) {
          const joined = Array.isArray(messages) ? messages.join(" ") : "";
          if (joined.toLowerCase().includes("restricted to administrators")) {
            return translatePlanChangeErrorCode("RESOURCE_OVERRIDE_NOT_ALLOWED", t);
          }
        }
      }

      const details = Object.values(err.errors)
        .flat()
        .filter((msg): msg is string => typeof msg === "string" && msg.trim().length > 0);
      if (details.length > 0) {
        return details.join(" ");
      }
    }

    if (err.status === 409) {
      return translatePlanChangeErrorCode("ANOTHER_CHANGE_IN_PROGRESS", t);
    }
    if (err.status === 503) {
      return translatePlanChangeErrorCode("HOST_UNREACHABLE", t);
    }

    return err.message || fallback;
  }

  if (err instanceof Error && err.message) {
    return err.message;
  }

  return fallback;
}

export function ChangePlanModal({
  isOpen,
  onClose,
  vm,
  initialPlanChange = null,
  onSuccess,
  onVmUpdated,
}: ChangePlanModalProps) {
  const { t } = useI18n();
  const { toast } = useToast();

  const [step, setStep] = useState<ChangePlanStep>(
    initialPlanChange ? "progress" : "select"
  );
  const [targets, setTargets] = useState<VpsPlanChangeTarget[]>([]);
  const [targetsLoading, setTargetsLoading] = useState(false);
  const [targetsError, setTargetsError] = useState<string | null>(null);
  const [selectedPlanId, setSelectedPlanId] = useState<number | null>(null);

  const [preview, setPreview] = useState<VpsPlanChangePreview | null>(null);
  const [previewLoading, setPreviewLoading] = useState(false);
  const [previewError, setPreviewError] = useState<string | null>(null);

  const [submitting, setSubmitting] = useState(false);
  const [submitError, setSubmitError] = useState<string | null>(null);

  const [activeChange, setActiveChange] = useState<VpsPlanChange | null>(initialPlanChange);
  const [pollingRefreshing, setPollingRefreshing] = useState(false);
  const [cancelling, setCancelling] = useState(false);

  const idempotencyKeyRef = useRef<string | null>(null);
  const completedNotifiedRef = useRef<string | null>(null);

  useModalEscape(onClose, isOpen && !submitting && !cancelling);

  const loadTargets = useCallback(
    async (signal?: AbortSignal) => {
      try {
        setTargetsLoading(true);
        setTargetsError(null);
        const list = await vmsApi.getPlanChangeTargets(vm.id, { signal });
        if (signal?.aborted) return;
        setTargets(list);
        const firstEligible = list.find((item) => item.selectable);
        setSelectedPlanId((prev) => {
          if (prev !== null && list.some((item) => item.id === prev && item.selectable)) {
            return prev;
          }
          return firstEligible?.id ?? null;
        });
      } catch (err: unknown) {
        if (signal?.aborted) return;
        setTargetsError(
          extractPlanChangeApiErrorMessage(err, t, t("vpsPlanChangeTargetsError"))
        );
      } finally {
        if (!signal?.aborted) {
          setTargetsLoading(false);
        }
      }
    },
    [vm.id, t]
  );

  // Initialize or reset modal state whenever it opens
  useEffect(() => {
    if (!isOpen) return;

    const controller = new AbortController();
    idempotencyKeyRef.current = null;
    completedNotifiedRef.current = null;

    if (initialPlanChange) {
      queueMicrotask(() => {
        setActiveChange(initialPlanChange);
        setStep("progress");
        setSubmitError(null);
      });
      return () => controller.abort();
    }

    queueMicrotask(() => {
      setActiveChange(null);
      setPreview(null);
      setPreviewError(null);
      setSubmitError(null);
      setStep("select");
      void loadTargets(controller.signal);
    });

    return () => controller.abort();
  }, [isOpen, initialPlanChange, loadTargets]);

  const loadPreview = useCallback(
    async (planId: number) => {
      try {
        setPreviewLoading(true);
        setPreviewError(null);
        const data = await vmsApi.previewPlanChange(vm.id, { vps_plan_id: planId });
        setPreview(data);
        idempotencyKeyRef.current = null;
        setStep("preview");
      } catch (err: unknown) {
        setPreviewError(
          extractPlanChangeApiErrorMessage(err, t, t("vpsPlanChangePreviewError"))
        );
      } finally {
        setPreviewLoading(false);
      }
    },
    [vm.id, t]
  );

  const handleSubmitPlanChange = async () => {
    if (!selectedPlanId || submitting) return;
    if (preview && !preview.can_submit) return;

    setSubmitting(true);
    setSubmitError(null);

    if (!idempotencyKeyRef.current) {
      idempotencyKeyRef.current = generatePlanChangeIdempotencyKey();
    }

    try {
      const created = await vmsApi.requestPlanChange(
        vm.id,
        { vps_plan_id: selectedPlanId },
        { idempotencyKey: idempotencyKeyRef.current }
      );
      idempotencyKeyRef.current = null;
      setActiveChange(created);
      setStep("progress");
      toast.success(t("vpsPlanChangeSubmitSuccess"));
      onSuccess?.(created);
    } catch (err: unknown) {
      const message = extractPlanChangeApiErrorMessage(
        err,
        t,
        t("vpsPlanChangeSubmitError")
      );
      setSubmitError(message);
      toast.error(message);
    } finally {
      setSubmitting(false);
    }
  };

  const refreshPlanChangeStatus = useCallback(
    async (publicId: string) => {
      try {
        setPollingRefreshing(true);
        const updated = await vmsApi.getPlanChange(vm.id, publicId);
        setActiveChange(updated);

        if (
          updated.status === "completed" &&
          completedNotifiedRef.current !== updated.public_id
        ) {
          completedNotifiedRef.current = updated.public_id;
          onSuccess?.(updated);
          if (onVmUpdated) {
            onVmUpdated({
              ...vm,
              cpu_cores: updated.new_resources.cpu_cores,
              ram_mb: updated.new_resources.ram_mb,
              disk_gb: updated.new_resources.disk_gb,
              vps_plan: updated.to
                ? {
                    id: updated.to.vps_plan_id,
                    name: updated.to.plan_name ?? vm.vps_plan?.name ?? "",
                    slug: updated.to.plan_slug ?? vm.vps_plan?.slug ?? "",
                    version_id: updated.to.vps_plan_version_id,
                    version: updated.to.version ?? 1,
                    is_current_version: true,
                  }
                : vm.vps_plan,
              network_limits: vm.network_limits
                ? {
                    ...vm.network_limits,
                    download_mbps: updated.new_resources.net_download_mbps,
                    upload_mbps: updated.new_resources.net_upload_mbps,
                  }
                : vm.network_limits,
            });
          }
        }
      } catch (err: unknown) {
        console.error("Failed to poll plan change status:", err);
      } finally {
        setPollingRefreshing(false);
      }
    },
    [vm, onSuccess, onVmUpdated]
  );

  // Poll every 2.5s while the plan-change operation is actively progressing
  useEffect(() => {
    if (!isOpen || step !== "progress" || !activeChange) return;
    if (!isPlanChangePollingActive(activeChange.status)) return;

    const timer = setInterval(() => {
      void refreshPlanChangeStatus(activeChange.public_id);
    }, PLAN_CHANGE_POLL_INTERVAL_MS);

    return () => clearInterval(timer);
  }, [isOpen, step, activeChange, refreshPlanChangeStatus]);

  const handleCancelActiveChange = async () => {
    if (!activeChange || cancelling) return;
    setCancelling(true);
    try {
      const updated = await vmsApi.cancelPlanChange(vm.id, activeChange.public_id);
      setActiveChange(updated);
      toast.success(t("vpsPlanChangeCancelSuccess"));
      onSuccess?.(updated);
    } catch (err: unknown) {
      toast.error(
        extractPlanChangeApiErrorMessage(err, t, t("vpsPlanChangeCancelError"))
      );
    } finally {
      setCancelling(false);
    }
  };

  if (!isOpen) return null;

  const previewSlots = preview
    ? getSlotComponents<{ vm: VirtualMachine; preview: VpsPlanChangePreview }>(
        "vps.plan.change.preview"
      )
    : [];

  const steps: { id: ChangePlanStep; label: string }[] = [
    { id: "select", label: t("vpsPlanChangeStepSelect") },
    { id: "preview", label: t("vpsPlanChangeStepPreview") },
    { id: "confirm", label: t("vpsPlanChangeStepConfirm") },
    { id: "progress", label: t("vpsPlanChangeStepProgress") },
  ];
  const currentStepIndex = steps.findIndex((s) => s.id === step);

  const formatMbps = (value: number) => t("vpsPlanMbpsValue", { mbps: value });

  return (
    <div
      className="fixed inset-0 z-[70] flex items-center justify-center p-4 bg-black/60 backdrop-blur-sm animate-fadeIn"
      onClick={(e) => {
        if (e.target === e.currentTarget && !submitting && !cancelling) {
          onClose();
        }
      }}
    >
      <div className="relative w-full max-w-3xl max-h-[90vh] flex flex-col bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 rounded-3xl shadow-2xl overflow-hidden">
        {/* Header */}
        <div className="px-6 py-4 border-b border-zinc-200 dark:border-zinc-800 bg-zinc-50/70 dark:bg-zinc-800/40 flex items-center justify-between gap-4">
          <div className="flex items-center gap-3 min-w-0">
            <div className="p-2.5 rounded-xl bg-blue-500/10 text-blue-600 dark:text-blue-400 border border-blue-500/20">
              <Package className="w-5 h-5" />
            </div>
            <div className="min-w-0">
              <h3 className="text-base font-bold text-zinc-900 dark:text-zinc-100 truncate">
                {t("vpsPlanChangeModalTitle")}
              </h3>
              <p className="text-xs text-zinc-500 dark:text-zinc-400 font-mono truncate">
                {vm.name}
                {vm.vps_plan
                  ? ` • ${vm.vps_plan.name} (${t("vpsPlanVersionLabel", {
                      version: vm.vps_plan.version,
                    })})`
                  : ` • ${t("vpsPlanChangeNoPlanAssigned")}`}
              </p>
            </div>
          </div>

          <button
            type="button"
            onClick={onClose}
            disabled={submitting || cancelling}
            aria-label={t("vpsPlanClose")}
            className="p-1.5 rounded-xl text-zinc-400 hover:text-zinc-700 dark:hover:text-zinc-200 hover:bg-zinc-100 dark:hover:bg-zinc-800 transition disabled:opacity-40"
          >
            <X className="w-5 h-5" />
          </button>
        </div>

        {/* Step Indicator */}
        <div className="px-6 py-3 border-b border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900">
          <ol className="flex items-center justify-between gap-2 text-xs">
            {steps.map((s, idx) => {
              const isCurrent = idx === currentStepIndex;
              const isDone = idx < currentStepIndex;
              return (
                <li key={s.id} className="flex items-center gap-2 flex-1 min-w-0">
                  <span
                    className={`w-6 h-6 rounded-full flex items-center justify-center text-[11px] font-bold flex-shrink-0 transition ${
                      isCurrent
                        ? "bg-blue-600 text-white shadow-sm shadow-blue-600/30"
                        : isDone
                        ? "bg-emerald-500/15 text-emerald-600 dark:text-emerald-400 border border-emerald-500/30"
                        : "bg-zinc-100 dark:bg-zinc-800 text-zinc-500 dark:text-zinc-400"
                    }`}
                  >
                    {isDone ? "✓" : idx + 1}
                  </span>
                  <span
                    className={`truncate font-semibold ${
                      isCurrent
                        ? "text-zinc-900 dark:text-zinc-100"
                        : isDone
                        ? "text-emerald-600 dark:text-emerald-400"
                        : "text-zinc-400 dark:text-zinc-500"
                    }`}
                  >
                    {s.label}
                  </span>
                  {idx < steps.length - 1 && (
                    <div className="hidden sm:block flex-1 h-px bg-zinc-200 dark:bg-zinc-800 ml-1" />
                  )}
                </li>
              );
            })}
          </ol>
        </div>

        {/* Body */}
        <div className="flex-1 overflow-y-auto p-6 space-y-5">
          {/* STEP 1: Select Target Plan */}
          {step === "select" && (
            <div className="space-y-4">
              {targetsLoading ? (
                <div className="py-12 flex flex-col items-center justify-center text-zinc-500 dark:text-zinc-400 gap-2 text-xs">
                  <Loader2 className="w-6 h-6 animate-spin text-blue-500" />
                  <span>{t("vpsPlanChangeTargetsLoading")}</span>
                </div>
              ) : targetsError ? (
                <div className="p-4 rounded-2xl bg-red-500/10 border border-red-500/20 flex items-start justify-between gap-3 text-xs text-red-700 dark:text-red-300">
                  <div className="flex items-start gap-2.5">
                    <AlertCircle className="w-4 h-4 text-red-500 shrink-0 mt-0.5" />
                    <span>{targetsError}</span>
                  </div>
                  <button
                    type="button"
                    onClick={() => void loadTargets()}
                    className="px-3 py-1 rounded-lg bg-red-600 text-white font-semibold hover:bg-red-500 transition shrink-0"
                  >
                    {t("vpsPlansRetry")}
                  </button>
                </div>
              ) : targets.length === 0 ? (
                <div className="py-10 text-center text-xs text-zinc-500 dark:text-zinc-400">
                  {t("vpsPlanChangeTargetsEmpty")}
                </div>
              ) : (
                <div className="space-y-3">
                  {targets.map((target) => {
                    const ver = target.current_version;
                    const isSelected = selectedPlanId === target.id;
                    const isCurrent =
                      vm.vps_plan?.id === target.id &&
                      vm.vps_plan?.version_id === ver?.id;

                    return (
                      <button
                        key={target.id}
                        type="button"
                        disabled={!target.selectable}
                        aria-disabled={!target.selectable}
                        onClick={() => {
                          if (!target.selectable) return;
                          setSelectedPlanId(target.id);
                          setPreviewError(null);
                        }}
                        className={`w-full text-left p-4 rounded-2xl border transition ${
                          !target.selectable
                            ? "opacity-60 cursor-not-allowed bg-zinc-50 dark:bg-zinc-900/60 border-zinc-200 dark:border-zinc-800"
                            : isSelected
                            ? "border-blue-500 bg-blue-500/5 ring-2 ring-blue-500/20"
                            : "border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 hover:border-blue-400 dark:hover:border-blue-500/50"
                        }`}
                      >
                        <div className="flex flex-wrap items-start justify-between gap-3">
                          <div className="min-w-0">
                            <div className="flex items-center gap-2 flex-wrap">
                              <span className="text-sm font-bold text-zinc-900 dark:text-zinc-100">
                                {target.name}
                              </span>
                              {ver && (
                                <span className="px-2 py-0.5 rounded-full text-[10px] font-mono font-semibold bg-zinc-100 dark:bg-zinc-800 text-zinc-600 dark:text-zinc-300">
                                  {t("vpsPlanVersionLabel", { version: ver.version })}
                                </span>
                              )}
                              {isCurrent && (
                                <span className="px-2 py-0.5 rounded-full text-[10px] font-semibold bg-blue-500/10 text-blue-600 dark:text-blue-400 border border-blue-500/20">
                                  {t("vpsPlanChangeCurrentPlanBadge")}
                                </span>
                              )}
                            </div>
                            {target.description && (
                              <p className="text-xs text-zinc-500 dark:text-zinc-400 mt-1">
                                {target.description}
                              </p>
                            )}
                          </div>

                          <span
                            className={`px-2.5 py-0.5 rounded-full text-[11px] font-semibold ${
                              target.selectable
                                ? "bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border border-emerald-500/20"
                                : "bg-zinc-200/70 dark:bg-zinc-800 text-zinc-600 dark:text-zinc-400 border border-zinc-300 dark:border-zinc-700"
                            }`}
                          >
                            {target.selectable
                              ? isSelected
                                ? t("vpsPlanSelectedBadge")
                                : t("vpsPlanChangeEligibleBadge")
                              : t("vpsPlanChangeIneligibleBadge")}
                          </span>
                        </div>

                        {ver && (
                          <div className="mt-3 grid grid-cols-2 sm:grid-cols-4 gap-2 text-xs">
                            <div className="px-2.5 py-1.5 rounded-xl bg-zinc-50 dark:bg-zinc-800/60 flex items-center gap-1.5">
                              <Cpu className="w-3.5 h-3.5 text-blue-500 shrink-0" />
                              <span className="font-semibold text-zinc-800 dark:text-zinc-200">
                                {t("vpsPlanCpuValue", { count: ver.cpu_cores })}
                              </span>
                            </div>
                            <div className="px-2.5 py-1.5 rounded-xl bg-zinc-50 dark:bg-zinc-800/60 flex items-center gap-1.5">
                              <Layers className="w-3.5 h-3.5 text-purple-500 shrink-0" />
                              <span className="font-semibold text-zinc-800 dark:text-zinc-200">
                                {formatPlanRam(ver.ram_mb)}
                              </span>
                            </div>
                            <div className="px-2.5 py-1.5 rounded-xl bg-zinc-50 dark:bg-zinc-800/60 flex items-center gap-1.5">
                              <HardDrive className="w-3.5 h-3.5 text-emerald-500 shrink-0" />
                              <span className="font-semibold text-zinc-800 dark:text-zinc-200">
                                {t("vpsPlanDiskValue", { gb: ver.disk_gb })}
                              </span>
                            </div>
                            <div className="px-2.5 py-1.5 rounded-xl bg-zinc-50 dark:bg-zinc-800/60 flex items-center gap-1.5">
                              <Network className="w-3.5 h-3.5 text-amber-500 shrink-0" />
                              <span className="font-semibold text-zinc-800 dark:text-zinc-200 truncate">
                                {formatNetworkLimit(
                                  ver.net_download_mbps,
                                  t("vpsPlanUnlimited"),
                                  formatMbps
                                )}{" "}
                                /{" "}
                                {formatNetworkLimit(
                                  ver.net_upload_mbps,
                                  t("vpsPlanUnlimited"),
                                  formatMbps
                                )}
                              </span>
                            </div>
                          </div>
                        )}

                        {!target.selectable && target.blocking_reasons.length > 0 && (
                          <ul className="mt-3 space-y-1 text-xs text-red-600 dark:text-red-400">
                            {target.blocking_reasons.map((reason, rIdx) => (
                              <li
                                key={`${target.id}-reason-${rIdx}`}
                                className="flex items-start gap-1.5"
                              >
                                <AlertCircle className="w-3.5 h-3.5 shrink-0 mt-0.5" />
                                <span>{translatePlanChangeBlockingReason(reason, t)}</span>
                              </li>
                            ))}
                          </ul>
                        )}
                      </button>
                    );
                  })}
                </div>
              )}

              {previewError && (
                <div className="p-3.5 rounded-2xl bg-red-500/10 border border-red-500/20 flex items-start gap-2.5 text-xs text-red-700 dark:text-red-300">
                  <AlertCircle className="w-4 h-4 text-red-500 shrink-0 mt-0.5" />
                  <span>{previewError}</span>
                </div>
              )}
            </div>
          )}

          {/* STEP 2: Preview Resource Comparison, Warnings, Blocking Reasons & Extension Slot */}
          {step === "preview" && preview && (
            <div className="space-y-4">
              {/* Transition Header */}
              <div className="p-4 rounded-2xl bg-zinc-50 dark:bg-zinc-800/40 border border-zinc-200 dark:border-zinc-800 flex flex-wrap items-center justify-between gap-3 text-xs">
                <div>
                  <div className="text-zinc-500 dark:text-zinc-400">
                    {t("vpsPlanChangeConfirmFromLabel")}
                  </div>
                  <div className="font-bold text-zinc-900 dark:text-zinc-100 mt-0.5">
                    {preview.from.plan_name
                      ? `${preview.from.plan_name} (${t("vpsPlanVersionLabel", {
                          version: preview.from.version ?? 1,
                        })})`
                      : t("vpsPlanChangeNoPlanAssigned")}
                  </div>
                </div>
                <ArrowRight className="w-4 h-4 text-zinc-400 shrink-0" />
                <div className="text-right">
                  <div className="text-zinc-500 dark:text-zinc-400">
                    {t("vpsPlanChangeConfirmToLabel")}
                  </div>
                  <div className="font-bold text-blue-600 dark:text-blue-400 mt-0.5">
                    {preview.to?.plan_name
                      ? `${preview.to.plan_name} (${t("vpsPlanVersionLabel", {
                          version: preview.to.version ?? 1,
                        })})`
                      : "—"}
                  </div>
                </div>
              </div>

              {/* Per-resource Comparison Table */}
              <div className="rounded-2xl border border-zinc-200 dark:border-zinc-800 overflow-hidden">
                <table className="w-full text-left text-xs">
                  <thead className="bg-zinc-50 dark:bg-zinc-800/60 border-b border-zinc-200 dark:border-zinc-800 text-[11px] uppercase tracking-wider text-zinc-500 dark:text-zinc-400">
                    <tr>
                      <th className="px-4 py-2.5 font-semibold">
                        {t("vpsPlanChangeResourceCol")}
                      </th>
                      <th className="px-4 py-2.5 font-semibold">
                        {t("vpsPlanChangeCurrentCol")}
                      </th>
                      <th className="px-4 py-2.5 font-semibold">
                        {t("vpsPlanChangeTargetCol")}
                      </th>
                      <th className="px-4 py-2.5 font-semibold text-right">
                        {t("vpsPlanChangeDeltaCol")}
                      </th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-zinc-200 dark:divide-zinc-800">
                    {[
                      {
                        key: "cpu",
                        label: t("vpsPlanCpuLabel"),
                        fromVal: t("vpsPlanCpuValue", { count: preview.from.cpu_cores }),
                        toVal: preview.to
                          ? t("vpsPlanCpuValue", { count: preview.to.cpu_cores })
                          : "—",
                        dir: preview.changes.cpu,
                      },
                      {
                        key: "ram",
                        label: t("vpsPlanRamLabel"),
                        fromVal: formatPlanRam(preview.from.ram_mb),
                        toVal: preview.to ? formatPlanRam(preview.to.ram_mb) : "—",
                        dir: preview.changes.ram,
                      },
                      {
                        key: "disk",
                        label: t("vpsPlanDiskLabel"),
                        fromVal: t("vpsPlanDiskValue", { gb: preview.from.disk_gb }),
                        toVal: preview.to
                          ? t("vpsPlanDiskValue", { gb: preview.to.disk_gb })
                          : "—",
                        dir: preview.changes.disk,
                      },
                      {
                        key: "net_download",
                        label: t("vpsPlanDownloadLabel"),
                        fromVal: formatNetworkLimit(
                          preview.from.net_download_mbps,
                          t("vpsPlanUnlimited"),
                          formatMbps
                        ),
                        toVal: preview.to
                          ? formatNetworkLimit(
                              preview.to.net_download_mbps,
                              t("vpsPlanUnlimited"),
                              formatMbps
                            )
                          : "—",
                        dir: preview.changes.net_download,
                      },
                      {
                        key: "net_upload",
                        label: t("vpsPlanUploadLabel"),
                        fromVal: formatNetworkLimit(
                          preview.from.net_upload_mbps,
                          t("vpsPlanUnlimited"),
                          formatMbps
                        ),
                        toVal: preview.to
                          ? formatNetworkLimit(
                              preview.to.net_upload_mbps,
                              t("vpsPlanUnlimited"),
                              formatMbps
                            )
                          : "—",
                        dir: preview.changes.net_upload,
                      },
                    ].map((row) => (
                      <tr key={row.key} className="bg-white dark:bg-zinc-900">
                        <td className="px-4 py-2.5 font-semibold text-zinc-800 dark:text-zinc-200">
                          {row.label}
                        </td>
                        <td className="px-4 py-2.5 text-zinc-600 dark:text-zinc-400">
                          {row.fromVal}
                        </td>
                        <td className="px-4 py-2.5 font-semibold text-zinc-900 dark:text-zinc-100">
                          {row.toVal}
                        </td>
                        <td className="px-4 py-2.5 text-right">
                          <span
                            className={`inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-semibold ${getDirectionBadgeStyle(
                              row.dir
                            )}`}
                          >
                            {translatePlanChangeDirection(row.dir, t)}
                          </span>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>

              {/* Warnings */}
              {preview.warnings.length > 0 && (
                <div className="space-y-2">
                  {preview.warnings.map((warning, idx) => (
                    <div
                      key={`warn-${idx}`}
                      className="p-3.5 rounded-2xl bg-amber-500/10 border border-amber-500/20 flex items-start gap-2.5 text-xs text-amber-800 dark:text-amber-300"
                    >
                      <AlertTriangle className="w-4 h-4 text-amber-500 shrink-0 mt-0.5" />
                      <span>{translatePlanChangeWarning(warning, t)}</span>
                    </div>
                  ))}
                </div>
              )}

              {/* Blocking Reasons */}
              {preview.blocking_reasons.length > 0 && (
                <div className="p-4 rounded-2xl bg-red-500/10 border border-red-500/20 space-y-2 text-xs text-red-700 dark:text-red-300">
                  <div className="font-semibold">
                    {t("vpsPlanChangePreviewBlockedNotice")}
                  </div>
                  <ul className="space-y-1">
                    {preview.blocking_reasons.map((reason, idx) => (
                      <li key={`block-${idx}`} className="flex items-start gap-2">
                        <AlertCircle className="w-3.5 h-3.5 text-red-500 shrink-0 mt-0.5" />
                        <span>{translatePlanChangeBlockingReason(reason, t)}</span>
                      </li>
                    ))}
                  </ul>
                </div>
              )}

              {/* @experimental vps.plan.change.preview extension slot */}
              {previewSlots.length > 0 && (
                <div
                  data-testid="vps-plan-change-preview-slot"
                  className="space-y-2 pt-2 border-t border-zinc-200 dark:border-zinc-800"
                >
                  {previewSlots.map((item) => (
                    <item.component key={item.id} vm={vm} preview={preview} />
                  ))}
                </div>
              )}
            </div>
          )}

          {/* STEP 3: Confirm Submission */}
          {step === "confirm" && preview && (
            <div className="space-y-4">
              <div className="p-4 rounded-2xl bg-blue-500/10 border border-blue-500/20 flex items-start gap-3 text-xs text-blue-800 dark:text-blue-300">
                <Info className="w-4 h-4 text-blue-500 shrink-0 mt-0.5" />
                <span>{t("vpsPlanChangeConfirmSummary")}</span>
              </div>

              <div className="grid grid-cols-1 sm:grid-cols-2 gap-4 text-xs">
                <div className="p-4 rounded-2xl bg-zinc-50 dark:bg-zinc-800/40 border border-zinc-200 dark:border-zinc-800 space-y-1">
                  <div className="text-zinc-500 dark:text-zinc-400">
                    {t("vpsPlanChangeConfirmFromLabel")}
                  </div>
                  <div className="font-bold text-sm text-zinc-900 dark:text-zinc-100">
                    {preview.from.plan_name ?? t("vpsPlanChangeNoPlanAssigned")}
                  </div>
                  <div className="text-zinc-600 dark:text-zinc-300">
                    {t("vpsPlanCpuValue", { count: preview.from.cpu_cores })} &bull;{" "}
                    {formatPlanRam(preview.from.ram_mb)} &bull;{" "}
                    {t("vpsPlanDiskValue", { gb: preview.from.disk_gb })}
                  </div>
                </div>

                <div className="p-4 rounded-2xl bg-blue-500/5 border border-blue-500/30 space-y-1">
                  <div className="text-blue-600 dark:text-blue-400 font-medium">
                    {t("vpsPlanChangeConfirmToLabel")}
                  </div>
                  <div className="font-bold text-sm text-zinc-900 dark:text-zinc-100">
                    {preview.to?.plan_name ?? "—"}
                    {preview.to?.version !== null && preview.to?.version !== undefined
                      ? ` (${t("vpsPlanVersionLabel", { version: preview.to.version })})`
                      : ""}
                  </div>
                  {preview.to && (
                    <div className="text-zinc-700 dark:text-zinc-200">
                      {t("vpsPlanCpuValue", { count: preview.to.cpu_cores })} &bull;{" "}
                      {formatPlanRam(preview.to.ram_mb)} &bull;{" "}
                      {t("vpsPlanDiskValue", { gb: preview.to.disk_gb })}
                    </div>
                  )}
                </div>
              </div>

              {preview.warnings.length > 0 && (
                <div className="space-y-2">
                  {preview.warnings.map((warning, idx) => (
                    <div
                      key={`confirm-warn-${idx}`}
                      className="p-3 rounded-2xl bg-amber-500/10 border border-amber-500/20 flex items-start gap-2.5 text-xs text-amber-800 dark:text-amber-300"
                    >
                      <AlertTriangle className="w-4 h-4 text-amber-500 shrink-0 mt-0.5" />
                      <span>{translatePlanChangeWarning(warning, t)}</span>
                    </div>
                  ))}
                </div>
              )}

              {submitError && (
                <div className="p-3.5 rounded-2xl bg-red-500/10 border border-red-500/20 flex items-start gap-2.5 text-xs text-red-700 dark:text-red-300">
                  <AlertCircle className="w-4 h-4 text-red-500 shrink-0 mt-0.5" />
                  <span>{submitError}</span>
                </div>
              )}
            </div>
          )}

          {/* STEP 4: Status & Stage Progress with Polling */}
          {step === "progress" && activeChange && (
            <div className="space-y-4">
              <div className="p-5 rounded-2xl bg-zinc-50 dark:bg-zinc-800/40 border border-zinc-200 dark:border-zinc-800 space-y-4">
                <div className="flex flex-wrap items-center justify-between gap-3">
                  <div>
                    <div className="text-[11px] font-medium text-zinc-500 dark:text-zinc-400">
                      {t("vpsPlanChangeReferenceLabel")}
                    </div>
                    <div className="font-mono text-xs font-bold text-zinc-900 dark:text-zinc-100 mt-0.5">
                      {activeChange.public_id}
                    </div>
                  </div>

                  <div className="flex items-center gap-2">
                    <span
                      className={`inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold ${getPlanChangeStatusBadgeStyle(
                        activeChange.status
                      )}`}
                    >
                      {isPlanChangePollingActive(activeChange.status) && (
                        <Loader2 className="w-3.5 h-3.5 animate-spin" />
                      )}
                      {activeChange.status === "completed" && (
                        <CheckCircle2 className="w-3.5 h-3.5" />
                      )}
                      {activeChange.status === "failed" && (
                        <XCircle className="w-3.5 h-3.5" />
                      )}
                      {activeChange.status === "reconciliation_required" && (
                        <AlertTriangle className="w-3.5 h-3.5" />
                      )}
                      <span>{translatePlanChangeStatus(activeChange.status, t)}</span>
                    </span>

                    <button
                      type="button"
                      onClick={() => void refreshPlanChangeStatus(activeChange.public_id)}
                      disabled={pollingRefreshing}
                      title={t("vpsPlansRefresh")}
                      className="p-1.5 rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 text-zinc-600 dark:text-zinc-300 hover:bg-zinc-100 dark:hover:bg-zinc-800 transition disabled:opacity-50"
                    >
                      <RefreshCw
                        className={`w-3.5 h-3.5 ${pollingRefreshing ? "animate-spin" : ""}`}
                      />
                    </button>
                  </div>
                </div>

                {activeChange.stage && (
                  <div className="flex items-center justify-between text-xs pt-3 border-t border-zinc-200 dark:border-zinc-700/60">
                    <span className="text-zinc-500 dark:text-zinc-400">
                      {t("vpsPlanChangeStageLabel")}
                    </span>
                    <span className="font-semibold text-zinc-900 dark:text-zinc-100">
                      {translatePlanChangeStage(activeChange.stage, t)}
                    </span>
                  </div>
                )}

                {activeChange.to && (
                  <div className="flex items-center justify-between text-xs pt-3 border-t border-zinc-200 dark:border-zinc-700/60">
                    <span className="text-zinc-500 dark:text-zinc-400">
                      {t("vpsPlanChangeColTransition")}
                    </span>
                    <span className="font-semibold text-zinc-900 dark:text-zinc-100">
                      {activeChange.from?.plan_name ?? t("vpsPlanChangeCustomResourcesLabel")}{" "}
                      &rarr; {activeChange.to.plan_name ?? "—"} (
                      {t("vpsPlanVersionLabel", { version: activeChange.to.version ?? 1 })})
                    </span>
                  </div>
                )}
              </div>

              {isPlanChangePollingActive(activeChange.status) && (
                <div className="flex items-center gap-2 text-xs text-zinc-500 dark:text-zinc-400">
                  <Loader2 className="w-3.5 h-3.5 animate-spin text-blue-500" />
                  <span>{t("vpsPlanChangePollingNotice")}</span>
                </div>
              )}

              {activeChange.status === "completed" && (
                <div className="p-4 rounded-2xl bg-emerald-500/10 border border-emerald-500/20 flex items-start gap-2.5 text-xs text-emerald-700 dark:text-emerald-300">
                  <CheckCircle2 className="w-4 h-4 text-emerald-500 shrink-0 mt-0.5" />
                  <span>{t("vpsPlanChangeCompletedNotice")}</span>
                </div>
              )}

              {activeChange.status === "cancelled" && (
                <div className="p-4 rounded-2xl bg-zinc-500/10 border border-zinc-500/20 flex items-start gap-2.5 text-xs text-zinc-700 dark:text-zinc-300">
                  <Info className="w-4 h-4 text-zinc-500 shrink-0 mt-0.5" />
                  <span>{t("vpsPlanChangeCancelledNotice")}</span>
                </div>
              )}

              {activeChange.status === "failed" && (
                <div className="p-4 rounded-2xl bg-red-500/10 border border-red-500/20 space-y-1.5 text-xs text-red-700 dark:text-red-300">
                  <div className="flex items-start gap-2.5 font-semibold">
                    <XCircle className="w-4 h-4 text-red-500 shrink-0 mt-0.5" />
                    <span>
                      {activeChange.error_code
                        ? translatePlanChangeErrorCode(activeChange.error_code, t)
                        : t("vpsPlanChangeFailedNotice")}
                    </span>
                  </div>
                  {activeChange.error_message && (
                    <p className="pl-6 text-[11px] text-red-600/90 dark:text-red-300/90 break-words">
                      {activeChange.error_message}
                    </p>
                  )}
                </div>
              )}

              {activeChange.status === "reconciliation_required" && (
                <div className="p-4 rounded-2xl bg-orange-500/10 border border-orange-500/25 space-y-1.5 text-xs text-orange-800 dark:text-orange-300">
                  <div className="flex items-start gap-2.5 font-semibold">
                    <AlertTriangle className="w-4 h-4 text-orange-500 shrink-0 mt-0.5" />
                    <span>{t("vpsPlanChangeReconciliationNotice")}</span>
                  </div>
                  <div className="pl-6 font-mono text-[11px] text-orange-700 dark:text-orange-200">
                    {t("vpsPlanChangeReferenceLabel")}: {activeChange.public_id}
                  </div>
                </div>
              )}
            </div>
          )}
        </div>

        {/* Footer */}
        <div className="px-6 py-4 border-t border-zinc-200 dark:border-zinc-800 bg-zinc-50/70 dark:bg-zinc-900 flex items-center justify-between gap-3">
          {step === "select" && (
            <>
              <button
                type="button"
                onClick={onClose}
                className="px-4 py-2 rounded-xl border border-zinc-200 dark:border-zinc-700 text-xs font-semibold text-zinc-700 dark:text-zinc-300 hover:bg-zinc-100 dark:hover:bg-zinc-800 transition"
              >
                {t("vpsPlanCancel")}
              </button>
              <button
                type="button"
                disabled={!selectedPlanId || previewLoading || targetsLoading}
                onClick={() => selectedPlanId && void loadPreview(selectedPlanId)}
                className="inline-flex items-center gap-2 px-5 py-2 rounded-xl bg-blue-600 hover:bg-blue-500 text-white text-xs font-semibold shadow-sm shadow-blue-600/20 transition disabled:opacity-50"
              >
                {previewLoading && <Loader2 className="w-3.5 h-3.5 animate-spin" />}
                <span>{t("vpsPlanChangeNextButton")}</span>
              </button>
            </>
          )}

          {step === "preview" && (
            <>
              <button
                type="button"
                onClick={() => setStep("select")}
                className="px-4 py-2 rounded-xl border border-zinc-200 dark:border-zinc-700 text-xs font-semibold text-zinc-700 dark:text-zinc-300 hover:bg-zinc-100 dark:hover:bg-zinc-800 transition"
              >
                {t("vpsPlanChangeBackButton")}
              </button>
              <button
                type="button"
                disabled={!preview?.can_submit}
                onClick={() => {
                  setSubmitError(null);
                  setStep("confirm");
                }}
                className="inline-flex items-center gap-2 px-5 py-2 rounded-xl bg-blue-600 hover:bg-blue-500 text-white text-xs font-semibold shadow-sm shadow-blue-600/20 transition disabled:opacity-50"
              >
                <span>{t("vpsPlanChangeNextButton")}</span>
              </button>
            </>
          )}

          {step === "confirm" && (
            <>
              <button
                type="button"
                disabled={submitting}
                onClick={() => setStep("preview")}
                className="px-4 py-2 rounded-xl border border-zinc-200 dark:border-zinc-700 text-xs font-semibold text-zinc-700 dark:text-zinc-300 hover:bg-zinc-100 dark:hover:bg-zinc-800 transition disabled:opacity-50"
              >
                {t("vpsPlanChangeBackButton")}
              </button>
              <button
                type="button"
                disabled={submitting || !preview?.can_submit}
                onClick={() => void handleSubmitPlanChange()}
                className="inline-flex items-center gap-2 px-5 py-2 rounded-xl bg-blue-600 hover:bg-blue-500 text-white text-xs font-semibold shadow-sm shadow-blue-600/20 transition disabled:opacity-50"
              >
                {submitting && <Loader2 className="w-3.5 h-3.5 animate-spin" />}
                <span>
                  {submitting
                    ? t("vpsPlanChangeSubmittingButton")
                    : t("vpsPlanChangeConfirmSubmitButton")}
                </span>
              </button>
            </>
          )}

          {step === "progress" && (
            <>
              <div>
                {activeChange && isPlanChangeCancellableStatus(activeChange.status) && (
                  <button
                    type="button"
                    disabled={cancelling}
                    onClick={() => void handleCancelActiveChange()}
                    className="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl border border-red-500/30 bg-red-500/10 text-red-600 dark:text-red-400 hover:bg-red-500/20 text-xs font-semibold transition disabled:opacity-50"
                  >
                    {cancelling && <Loader2 className="w-3.5 h-3.5 animate-spin" />}
                    <span>{t("vpsPlanChangeCancelButton")}</span>
                  </button>
                )}
              </div>
              <button
                type="button"
                onClick={onClose}
                className="px-5 py-2 rounded-xl bg-zinc-900 dark:bg-zinc-100 text-white dark:text-zinc-900 hover:opacity-90 text-xs font-semibold transition"
              >
                {t("vpsPlanChangeDoneButton")}
              </button>
            </>
          )}
        </div>
      </div>
    </div>
  );
}
