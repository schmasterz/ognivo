"use client";

import React, { useCallback, useEffect, useMemo, useState } from "react";
import {
  AlertCircle,
  Archive,
  ArchiveRestore,
  CheckCircle2,
  EyeOff,
  History,
  Info,
  Loader2,
  Rocket,
  Save,
  Trash2,
  X,
} from "lucide-react";
import {
  AdminVpsPlan,
  ApiError,
  VpsPlanInstanceType,
  VpsPlanUsageResponse,
  VpsPlanVersion,
  vpsPlansApi,
} from "@/lib/api";
import { ConfirmModal } from "@/components/ui/ConfirmModal";
import { useI18n } from "@/lib/i18n/I18nContext";
import { useToast } from "@/lib/toast/ToastContext";
import { useModalEscape } from "@/lib/hooks/useModalEscape";
import { formatNetworkLimit, formatPlanRam } from "./VpsPlanCard";

export interface PlanEditorModalProps {
  plan: AdminVpsPlan | null;
  onClose: () => void;
  onSaved: () => void;
}

export const SLUG_PATTERN = /^[a-z0-9]+(?:-[a-z0-9]+)*$/;

export const PLAN_BOUNDS = {
  CPU_MIN: 1,
  CPU_MAX: 64,
  RAM_MB_MIN: 512,
  RAM_MB_MAX: 131072,
  DISK_GB_MIN: 5,
  DISK_GB_MAX: 2000,
  NET_MBPS_MIN: 1,
  NET_MBPS_MAX: 100000,
} as const;

export function extractPlanErrorMessage(err: unknown, fallback: string): string {
  if (err instanceof ApiError) {
    if (err.errors && typeof err.errors === "object") {
      const details = Object.values(err.errors)
        .flat()
        .filter((msg): msg is string => typeof msg === "string" && msg.trim().length > 0);
      if (details.length > 0) {
        return details.join(" ");
      }
    }
    return err.message || fallback;
  }
  if (err instanceof Error) {
    return err.message || fallback;
  }
  return fallback;
}

export function getPlanStatusBadgeStyle(status: string): string {
  switch (status) {
    case "published":
      return "bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border border-emerald-500/20";
    case "draft":
      return "bg-amber-500/10 text-amber-600 dark:text-amber-400 border border-amber-500/20";
    case "superseded":
      return "bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 border border-indigo-500/20";
    case "archived":
    default:
      return "bg-gray-500/10 text-gray-600 dark:text-gray-400 border border-gray-500/20";
  }
}

export function getPlanStatusTranslationKey(status: string): string {
  switch (status) {
    case "published":
      return "vpsPlanPublishedBadge";
    case "draft":
      return "vpsPlanDraftBadge";
    case "superseded":
      return "vpsPlanSupersededBadge";
    case "archived":
    default:
      return "vpsPlanArchivedBadge";
  }
}

export function isPlanSlugLocked(
  plan: AdminVpsPlan | null,
  versions: VpsPlanVersion[]
): boolean {
  if (!plan) return false;
  return (
    plan.status === "published" ||
    plan.current_version_id !== null ||
    versions.some((v) => v.version !== null)
  );
}

interface ProfileFormState {
  instanceType: VpsPlanInstanceType;
  cpuCores: string;
  ramMb: string;
  diskGb: string;
  netDownloadUnlimited: boolean;
  netDownloadMbps: string;
  netUploadUnlimited: boolean;
  netUploadMbps: string;
  changeSummary: string;
}

function buildInitialProfileState(plan: AdminVpsPlan | null): ProfileFormState {
  const source = plan?.draft_version ?? plan?.current_version ?? null;
  if (!source) {
    return {
      instanceType: "virtual-machine",
      cpuCores: "2",
      ramMb: "2048",
      diskGb: "20",
      netDownloadUnlimited: true,
      netDownloadMbps: "1000",
      netUploadUnlimited: true,
      netUploadMbps: "1000",
      changeSummary: "",
    };
  }
  return {
    instanceType: source.instance_type,
    cpuCores: String(source.cpu_cores),
    ramMb: String(source.ram_mb),
    diskGb: String(source.disk_gb),
    netDownloadUnlimited: source.net_download_mbps === null,
    netDownloadMbps:
      source.net_download_mbps !== null ? String(source.net_download_mbps) : "1000",
    netUploadUnlimited: source.net_upload_mbps === null,
    netUploadMbps:
      source.net_upload_mbps !== null ? String(source.net_upload_mbps) : "1000",
    changeSummary: source.change_summary ?? "",
  };
}

export function PlanEditorModal({ plan, onClose, onSaved }: PlanEditorModalProps) {
  const { t } = useI18n();
  const { toast } = useToast();
  const isEditMode = plan !== null;

  const [currentPlan, setCurrentPlan] = useState<AdminVpsPlan | null>(plan);
  const [versions, setVersions] = useState<VpsPlanVersion[]>([]);
  const [usage, setUsage] = useState<VpsPlanUsageResponse | null>(null);
  const [loadingDetails, setLoadingDetails] = useState(isEditMode);

  // Metadata state
  const [name, setName] = useState(plan?.name ?? "");
  const [slug, setSlug] = useState(plan?.slug ?? "");
  const [description, setDescription] = useState(plan?.description ?? "");
  const [sortOrder, setSortOrder] = useState(String(plan?.sort_order ?? 0));

  // Profile state
  const [profile, setProfile] = useState<ProfileFormState>(() =>
    buildInitialProfileState(plan)
  );

  const [submittingAction, setSubmittingAction] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [confirmState, setConfirmState] = useState<{
    type: "discardDraft" | "archive";
  } | null>(null);

  const busy = submittingAction !== null;
  useModalEscape(onClose, !busy && confirmState === null);

  const loadPlanDetails = useCallback(
    async (planId: number, signal?: AbortSignal) => {
      try {
        setLoadingDetails(true);
        const [freshPlan, versionList, usageData] = await Promise.all([
          vpsPlansApi.getAdminPlan(planId, { signal }),
          vpsPlansApi.listVersions(planId, { signal }),
          vpsPlansApi.getPlanUsage(planId, { signal }),
        ]);
        if (signal?.aborted) return;

        setCurrentPlan(freshPlan);
        setVersions(versionList);
        setUsage(usageData);

        setName(freshPlan.name);
        setSlug(freshPlan.slug);
        setDescription(freshPlan.description ?? "");
        setSortOrder(String(freshPlan.sort_order));
        setProfile(buildInitialProfileState(freshPlan));
      } catch (err: unknown) {
        if (signal?.aborted) return;
        setError(extractPlanErrorMessage(err, t("vpsPlanActionFailed")));
      } finally {
        if (!signal?.aborted) {
          setLoadingDetails(false);
        }
      }
    },
    [t]
  );

  useEffect(() => {
    if (!plan) return;
    const controller = new AbortController();
    queueMicrotask(() => {
      if (!controller.signal.aborted) {
        void loadPlanDetails(plan.id, controller.signal);
      }
    });
    return () => controller.abort();
  }, [plan, loadPlanDetails]);

  const slugLocked = useMemo(
    () => isPlanSlugLocked(currentPlan, versions),
    [currentPlan, versions]
  );

  const usageByVersionId = useMemo(() => {
    const map = new Map<number, number>();
    if (usage) {
      for (const item of usage.versions) {
        map.set(item.vps_plan_version_id, item.active_vms_count);
      }
    }
    return map;
  }, [usage]);

  const ramPreview = useMemo(() => {
    const parsed = Number.parseInt(profile.ramMb, 10);
    if (!Number.isFinite(parsed) || parsed <= 0) return null;
    return formatPlanRam(parsed);
  }, [profile.ramMb]);

  const validateMetadataFields = (): {
    valid: boolean;
    message?: string;
    parsedSortOrder: number;
  } => {
    const trimmedName = name.trim();
    if (!trimmedName) {
      return {
        valid: false,
        message: t("vpsPlanValidationNameRequired"),
        parsedSortOrder: 0,
      };
    }

    const trimmedSlug = slug.trim();
    if (trimmedSlug && !SLUG_PATTERN.test(trimmedSlug)) {
      return {
        valid: false,
        message: t("vpsPlanValidationSlugInvalid"),
        parsedSortOrder: 0,
      };
    }

    const parsedSortOrder = Number.parseInt(sortOrder, 10);
    return {
      valid: true,
      parsedSortOrder: Number.isFinite(parsedSortOrder) && parsedSortOrder >= 0 ? parsedSortOrder : 0,
    };
  };

  const validateProfileFields = () => {
    const cpu = Number.parseInt(profile.cpuCores, 10);
    if (
      !Number.isFinite(cpu) ||
      cpu < PLAN_BOUNDS.CPU_MIN ||
      cpu > PLAN_BOUNDS.CPU_MAX
    ) {
      return { valid: false as const, message: t("vpsPlanValidationCpuRange") };
    }

    const ram = Number.parseInt(profile.ramMb, 10);
    if (
      !Number.isFinite(ram) ||
      ram < PLAN_BOUNDS.RAM_MB_MIN ||
      ram > PLAN_BOUNDS.RAM_MB_MAX
    ) {
      return { valid: false as const, message: t("vpsPlanValidationRamRange") };
    }

    const disk = Number.parseInt(profile.diskGb, 10);
    if (
      !Number.isFinite(disk) ||
      disk < PLAN_BOUNDS.DISK_GB_MIN ||
      disk > PLAN_BOUNDS.DISK_GB_MAX
    ) {
      return { valid: false as const, message: t("vpsPlanValidationDiskRange") };
    }

    let netDownload: number | null = null;
    if (!profile.netDownloadUnlimited) {
      const dl = Number.parseInt(profile.netDownloadMbps, 10);
      if (
        !Number.isFinite(dl) ||
        dl < PLAN_BOUNDS.NET_MBPS_MIN ||
        dl > PLAN_BOUNDS.NET_MBPS_MAX
      ) {
        return { valid: false as const, message: t("vpsPlanValidationNetRange") };
      }
      netDownload = dl;
    }

    let netUpload: number | null = null;
    if (!profile.netUploadUnlimited) {
      const ul = Number.parseInt(profile.netUploadMbps, 10);
      if (
        !Number.isFinite(ul) ||
        ul < PLAN_BOUNDS.NET_MBPS_MIN ||
        ul > PLAN_BOUNDS.NET_MBPS_MAX
      ) {
        return { valid: false as const, message: t("vpsPlanValidationNetRange") };
      }
      netUpload = ul;
    }

    return {
      valid: true as const,
      payload: {
        cpu_cores: cpu,
        ram_mb: ram,
        disk_gb: disk,
        instance_type: profile.instanceType,
        net_download_mbps: netDownload,
        net_upload_mbps: netUpload,
        change_summary: profile.changeSummary.trim() || null,
      },
    };
  };

  const handleCreateSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setError(null);

    const metaCheck = validateMetadataFields();
    if (!metaCheck.valid) {
      setError(metaCheck.message ?? t("vpsPlanActionFailed"));
      return;
    }
    const profileCheck = validateProfileFields();
    if (!profileCheck.valid) {
      setError(profileCheck.message);
      return;
    }

    try {
      setSubmittingAction("create");
      const trimmedSlug = slug.trim();
      await vpsPlansApi.createPlan({
        name: name.trim(),
        ...(trimmedSlug ? { slug: trimmedSlug } : {}),
        description: description.trim() || null,
        sort_order: metaCheck.parsedSortOrder,
        ...profileCheck.payload,
      });
      toast.success(t("vpsPlanCreatedSuccess"));
      onSaved();
      onClose();
    } catch (err: unknown) {
      const msg = extractPlanErrorMessage(err, t("vpsPlanActionFailed"));
      setError(msg);
      toast.error(msg);
    } finally {
      setSubmittingAction(null);
    }
  };

  const handleSaveMetadata = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!currentPlan) return;
    setError(null);

    const metaCheck = validateMetadataFields();
    if (!metaCheck.valid) {
      setError(metaCheck.message ?? t("vpsPlanActionFailed"));
      return;
    }

    try {
      setSubmittingAction("metadata");
      const trimmedSlug = slug.trim();
      await vpsPlansApi.updatePlan(currentPlan.id, {
        name: name.trim(),
        ...(!slugLocked && trimmedSlug ? { slug: trimmedSlug } : {}),
        description: description.trim() || null,
        sort_order: metaCheck.parsedSortOrder,
      });
      toast.success(t("vpsPlanUpdatedSuccess"));
      await loadPlanDetails(currentPlan.id);
      onSaved();
    } catch (err: unknown) {
      const msg = extractPlanErrorMessage(err, t("vpsPlanActionFailed"));
      setError(msg);
      toast.error(msg);
    } finally {
      setSubmittingAction(null);
    }
  };

  const handleSaveDraftProfile = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!currentPlan) return;
    setError(null);

    const profileCheck = validateProfileFields();
    if (!profileCheck.valid) {
      setError(profileCheck.message);
      return;
    }

    try {
      setSubmittingAction("draft");
      await vpsPlansApi.upsertDraftVersion(currentPlan.id, profileCheck.payload);
      toast.success(t("vpsPlanDraftSavedSuccess"));
      await loadPlanDetails(currentPlan.id);
      onSaved();
    } catch (err: unknown) {
      const msg = extractPlanErrorMessage(err, t("vpsPlanActionFailed"));
      setError(msg);
      toast.error(msg);
    } finally {
      setSubmittingAction(null);
    }
  };

  const runPlanLifecycleAction = async (
    actionKey: string,
    fn: () => Promise<unknown>,
    successMsg: string
  ) => {
    if (!currentPlan) return;
    setError(null);
    try {
      setSubmittingAction(actionKey);
      await fn();
      toast.success(successMsg);
      await loadPlanDetails(currentPlan.id);
      onSaved();
    } catch (err: unknown) {
      const msg = extractPlanErrorMessage(err, t("vpsPlanActionFailed"));
      setError(msg);
      toast.error(msg);
    } finally {
      setSubmittingAction(null);
      setConfirmState(null);
    }
  };

  const renderProfileFields = () => (
    <div className="space-y-4">
      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <div>
          <label className="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1.5">
            {t("vpsPlanFieldInstanceType")}
          </label>
          <select
            value={profile.instanceType}
            onChange={(e) =>
              setProfile((prev) => ({
                ...prev,
                instanceType: e.target.value as VpsPlanInstanceType,
              }))
            }
            disabled={busy}
            className="w-full px-3.5 py-2.5 rounded-xl bg-gray-50 dark:bg-gray-950 border border-gray-200 dark:border-gray-800 text-xs text-gray-900 dark:text-white focus:ring-2 focus:ring-blue-500 outline-none transition"
          >
            <option value="virtual-machine">{t("vpsPlanInstanceTypeVm")}</option>
            <option value="container">{t("vpsPlanInstanceTypeContainer")}</option>
          </select>
        </div>

        <div>
          <label className="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1.5">
            {t("vpsPlanFieldCpuCores")}
          </label>
          <input
            type="number"
            min={PLAN_BOUNDS.CPU_MIN}
            max={PLAN_BOUNDS.CPU_MAX}
            value={profile.cpuCores}
            onChange={(e) =>
              setProfile((prev) => ({ ...prev, cpuCores: e.target.value }))
            }
            disabled={busy}
            className="w-full px-3.5 py-2.5 rounded-xl bg-gray-50 dark:bg-gray-950 border border-gray-200 dark:border-gray-800 text-xs text-gray-900 dark:text-white focus:ring-2 focus:ring-blue-500 outline-none transition"
          />
        </div>

        <div>
          <label className="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1.5">
            {t("vpsPlanFieldRamMb")}
          </label>
          <input
            type="number"
            min={PLAN_BOUNDS.RAM_MB_MIN}
            max={PLAN_BOUNDS.RAM_MB_MAX}
            step={128}
            value={profile.ramMb}
            onChange={(e) =>
              setProfile((prev) => ({ ...prev, ramMb: e.target.value }))
            }
            disabled={busy}
            className="w-full px-3.5 py-2.5 rounded-xl bg-gray-50 dark:bg-gray-950 border border-gray-200 dark:border-gray-800 text-xs text-gray-900 dark:text-white focus:ring-2 focus:ring-blue-500 outline-none transition"
          />
          {ramPreview && (
            <p className="mt-1 text-[11px] text-gray-400">{ramPreview}</p>
          )}
        </div>

        <div>
          <label className="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1.5">
            {t("vpsPlanFieldDiskGb")}
          </label>
          <input
            type="number"
            min={PLAN_BOUNDS.DISK_GB_MIN}
            max={PLAN_BOUNDS.DISK_GB_MAX}
            value={profile.diskGb}
            onChange={(e) =>
              setProfile((prev) => ({ ...prev, diskGb: e.target.value }))
            }
            disabled={busy}
            className="w-full px-3.5 py-2.5 rounded-xl bg-gray-50 dark:bg-gray-950 border border-gray-200 dark:border-gray-800 text-xs text-gray-900 dark:text-white focus:ring-2 focus:ring-blue-500 outline-none transition"
          />
        </div>
      </div>

      <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div className="p-3.5 rounded-2xl bg-gray-50 dark:bg-gray-950/60 border border-gray-200 dark:border-gray-800 space-y-2">
          <div className="flex items-center justify-between gap-2">
            <label className="text-xs font-semibold text-gray-700 dark:text-gray-300">
              {t("vpsPlanFieldNetDownloadMbps")}
            </label>
            <label className="inline-flex items-center gap-1.5 text-xs font-medium text-gray-600 dark:text-gray-400 cursor-pointer">
              <input
                type="checkbox"
                checked={profile.netDownloadUnlimited}
                onChange={(e) =>
                  setProfile((prev) => ({
                    ...prev,
                    netDownloadUnlimited: e.target.checked,
                  }))
                }
                disabled={busy}
                className="rounded border-gray-300 dark:border-gray-700 text-blue-600 focus:ring-blue-500"
              />
              <span>{t("vpsPlanUnlimited")}</span>
            </label>
          </div>
          <input
            type="number"
            min={PLAN_BOUNDS.NET_MBPS_MIN}
            max={PLAN_BOUNDS.NET_MBPS_MAX}
            value={profile.netDownloadMbps}
            onChange={(e) =>
              setProfile((prev) => ({ ...prev, netDownloadMbps: e.target.value }))
            }
            disabled={busy || profile.netDownloadUnlimited}
            placeholder={t("vpsPlanUnlimited")}
            className="w-full px-3.5 py-2 rounded-xl bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 text-xs text-gray-900 dark:text-white disabled:opacity-50 focus:ring-2 focus:ring-blue-500 outline-none transition"
          />
        </div>

        <div className="p-3.5 rounded-2xl bg-gray-50 dark:bg-gray-950/60 border border-gray-200 dark:border-gray-800 space-y-2">
          <div className="flex items-center justify-between gap-2">
            <label className="text-xs font-semibold text-gray-700 dark:text-gray-300">
              {t("vpsPlanFieldNetUploadMbps")}
            </label>
            <label className="inline-flex items-center gap-1.5 text-xs font-medium text-gray-600 dark:text-gray-400 cursor-pointer">
              <input
                type="checkbox"
                checked={profile.netUploadUnlimited}
                onChange={(e) =>
                  setProfile((prev) => ({
                    ...prev,
                    netUploadUnlimited: e.target.checked,
                  }))
                }
                disabled={busy}
                className="rounded border-gray-300 dark:border-gray-700 text-blue-600 focus:ring-blue-500"
              />
              <span>{t("vpsPlanUnlimited")}</span>
            </label>
          </div>
          <input
            type="number"
            min={PLAN_BOUNDS.NET_MBPS_MIN}
            max={PLAN_BOUNDS.NET_MBPS_MAX}
            value={profile.netUploadMbps}
            onChange={(e) =>
              setProfile((prev) => ({ ...prev, netUploadMbps: e.target.value }))
            }
            disabled={busy || profile.netUploadUnlimited}
            placeholder={t("vpsPlanUnlimited")}
            className="w-full px-3.5 py-2 rounded-xl bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 text-xs text-gray-900 dark:text-white disabled:opacity-50 focus:ring-2 focus:ring-blue-500 outline-none transition"
          />
        </div>
      </div>

      <div className="p-3 rounded-2xl bg-blue-500/10 border border-blue-500/20 flex items-start gap-2.5 text-xs text-blue-700 dark:text-blue-300">
        <Info className="w-4 h-4 flex-shrink-0 mt-0.5" />
        <span>{t("vpsPlanNetworkPoolNote")}</span>
      </div>

      <div>
        <label className="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1.5">
          {t("vpsPlanFieldChangeSummary")}
        </label>
        <input
          type="text"
          value={profile.changeSummary}
          onChange={(e) =>
            setProfile((prev) => ({ ...prev, changeSummary: e.target.value }))
          }
          disabled={busy}
          placeholder={t("vpsPlanFieldChangeSummaryPlaceholder")}
          className="w-full px-3.5 py-2.5 rounded-xl bg-gray-50 dark:bg-gray-950 border border-gray-200 dark:border-gray-800 text-xs text-gray-900 dark:text-white focus:ring-2 focus:ring-blue-500 outline-none transition"
        />
      </div>
    </div>
  );

  return (
    <div
      className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-sm animate-fadeIn"
      onClick={(e) => {
        if (e.target === e.currentTarget && !busy) {
          onClose();
        }
      }}
    >
      <div className="relative w-full max-w-4xl bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-3xl p-6 sm:p-8 shadow-2xl overflow-y-auto max-h-[90vh] transition-colors space-y-6">
        {/* Header */}
        <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pb-4 border-b border-gray-100 dark:border-gray-800">
          <div className="flex items-center gap-3 min-w-0">
            <h2 className="text-xl font-bold text-gray-900 dark:text-white tracking-tight truncate">
              {isEditMode ? `${t("vpsPlanEditModalTitle")}: ${currentPlan?.name ?? ""}` : t("vpsPlanCreateModalTitle")}
            </h2>
            {isEditMode && currentPlan && (
              <span
                className={`px-2.5 py-0.5 rounded-full text-[11px] font-semibold ${getPlanStatusBadgeStyle(
                  currentPlan.status
                )}`}
              >
                {t(getPlanStatusTranslationKey(currentPlan.status))}
              </span>
            )}
          </div>

          <div className="flex items-center flex-wrap gap-2">
            {isEditMode && currentPlan && (
              <>
                {currentPlan.status === "draft" && (
                  <button
                    type="button"
                    disabled={busy}
                    onClick={() =>
                      void runPlanLifecycleAction(
                        "publish",
                        () => vpsPlansApi.publishPlan(currentPlan.id),
                        t("vpsPlanPublishedSuccess")
                      )
                    }
                    className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white text-xs font-semibold transition disabled:opacity-50"
                  >
                    <CheckCircle2 className="w-3.5 h-3.5" />
                    <span>{t("vpsPlanPublish")}</span>
                  </button>
                )}

                {currentPlan.status === "published" && (
                  <button
                    type="button"
                    disabled={busy}
                    onClick={() =>
                      void runPlanLifecycleAction(
                        "unpublish",
                        () => vpsPlansApi.unpublishPlan(currentPlan.id),
                        t("vpsPlanUnpublishedSuccess")
                      )
                    }
                    className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-amber-600 hover:bg-amber-500 text-white text-xs font-semibold transition disabled:opacity-50"
                  >
                    <EyeOff className="w-3.5 h-3.5" />
                    <span>{t("vpsPlanUnpublish")}</span>
                  </button>
                )}

                {currentPlan.status !== "archived" ? (
                  <button
                    type="button"
                    disabled={busy}
                    onClick={() => setConfirmState({ type: "archive" })}
                    className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl border border-gray-200 dark:border-gray-700 hover:bg-gray-100 dark:hover:bg-gray-800 text-xs font-semibold text-gray-700 dark:text-gray-200 transition disabled:opacity-50"
                  >
                    <Archive className="w-3.5 h-3.5" />
                    <span>{t("vpsPlanArchive")}</span>
                  </button>
                ) : (
                  <button
                    type="button"
                    disabled={busy}
                    onClick={() =>
                      void runPlanLifecycleAction(
                        "unarchive",
                        () => vpsPlansApi.unarchivePlan(currentPlan.id),
                        t("vpsPlanUnarchivedSuccess")
                      )
                    }
                    className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-semibold transition disabled:opacity-50"
                  >
                    <ArchiveRestore className="w-3.5 h-3.5" />
                    <span>{t("vpsPlanUnarchive")}</span>
                  </button>
                )}
              </>
            )}

            <button
              type="button"
              onClick={onClose}
              disabled={busy}
              aria-label={t("vpsPlanClose")}
              className="p-2 rounded-xl text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-800 transition disabled:opacity-40"
            >
              <X className="w-5 h-5" />
            </button>
          </div>
        </div>

        {error && (
          <div className="p-3.5 rounded-2xl bg-red-500/10 border border-red-500/20 text-xs text-red-600 dark:text-red-400 flex items-start gap-2.5">
            <AlertCircle className="w-4 h-4 flex-shrink-0 mt-0.5" />
            <span>{error}</span>
          </div>
        )}

        {!isEditMode ? (
          <form onSubmit={(e) => void handleCreateSubmit(e)} className="space-y-6">
            <section className="space-y-4">
              <h3 className="text-xs font-bold uppercase tracking-wider text-gray-400">
                {t("vpsPlanMetadataSection")}
              </h3>
              <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div>
                  <label className="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1.5">
                    {t("vpsPlanFieldName")}
                  </label>
                  <input
                    type="text"
                    required
                    value={name}
                    onChange={(e) => setName(e.target.value)}
                    disabled={busy}
                    placeholder={t("vpsPlanFieldNamePlaceholder")}
                    className="w-full px-3.5 py-2.5 rounded-xl bg-gray-50 dark:bg-gray-950 border border-gray-200 dark:border-gray-800 text-xs text-gray-900 dark:text-white focus:ring-2 focus:ring-blue-500 outline-none transition"
                  />
                </div>

                <div>
                  <label className="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1.5">
                    {t("vpsPlanFieldSlug")}
                  </label>
                  <input
                    type="text"
                    value={slug}
                    onChange={(e) => setSlug(e.target.value)}
                    disabled={busy}
                    placeholder={t("vpsPlanFieldSlugPlaceholder")}
                    className="w-full px-3.5 py-2.5 rounded-xl bg-gray-50 dark:bg-gray-950 border border-gray-200 dark:border-gray-800 text-xs font-mono text-gray-900 dark:text-white focus:ring-2 focus:ring-blue-500 outline-none transition"
                  />
                </div>

                <div>
                  <label className="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1.5">
                    {t("vpsPlanFieldSortOrder")}
                  </label>
                  <input
                    type="number"
                    min={0}
                    value={sortOrder}
                    onChange={(e) => setSortOrder(e.target.value)}
                    disabled={busy}
                    className="w-full px-3.5 py-2.5 rounded-xl bg-gray-50 dark:bg-gray-950 border border-gray-200 dark:border-gray-800 text-xs text-gray-900 dark:text-white focus:ring-2 focus:ring-blue-500 outline-none transition"
                  />
                </div>
              </div>

              <div>
                <label className="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1.5">
                  {t("vpsPlanFieldDescription")}
                </label>
                <textarea
                  rows={2}
                  value={description}
                  onChange={(e) => setDescription(e.target.value)}
                  disabled={busy}
                  placeholder={t("vpsPlanFieldDescriptionPlaceholder")}
                  className="w-full px-3.5 py-2.5 rounded-xl bg-gray-50 dark:bg-gray-950 border border-gray-200 dark:border-gray-800 text-xs text-gray-900 dark:text-white focus:ring-2 focus:ring-blue-500 outline-none transition"
                />
              </div>
            </section>

            <section className="space-y-4 pt-4 border-t border-gray-100 dark:border-gray-800">
              <h3 className="text-xs font-bold uppercase tracking-wider text-gray-400">
                {t("vpsPlanProfileSection")}
              </h3>
              {renderProfileFields()}
            </section>

            <div className="flex items-center justify-end gap-3 pt-4 border-t border-gray-100 dark:border-gray-800">
              <button
                type="button"
                onClick={onClose}
                disabled={busy}
                className="px-4 py-2 rounded-xl border border-gray-200 dark:border-gray-700 text-xs font-semibold text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800 transition"
              >
                {t("vpsPlanCancel")}
              </button>
              <button
                type="submit"
                disabled={busy}
                className="inline-flex items-center gap-2 px-5 py-2 rounded-xl bg-blue-600 hover:bg-blue-500 text-white text-xs font-semibold shadow-md shadow-blue-600/20 transition disabled:opacity-50"
              >
                {submittingAction === "create" && (
                  <Loader2 className="w-3.5 h-3.5 animate-spin" />
                )}
                <span>
                  {submittingAction === "create"
                    ? t("vpsPlanSaving")
                    : t("vpsPlanCreateSubmit")}
                </span>
              </button>
            </div>
          </form>
        ) : loadingDetails ? (
          <div className="flex items-center justify-center py-12 text-xs text-gray-500 dark:text-gray-400 gap-2">
            <Loader2 className="w-5 h-5 animate-spin text-blue-500" />
            <span>{t("vpsPlansLoading")}</span>
          </div>
        ) : (
          <div className="space-y-8">
            {/* Metadata Form */}
            <form
              onSubmit={(e) => void handleSaveMetadata(e)}
              className="space-y-4 p-5 rounded-2xl bg-gray-50/70 dark:bg-gray-950/40 border border-gray-200 dark:border-gray-800"
            >
              <div className="flex items-center justify-between gap-3">
                <h3 className="text-xs font-bold uppercase tracking-wider text-gray-500 dark:text-gray-400">
                  {t("vpsPlanMetadataSection")}
                </h3>
                <button
                  type="submit"
                  disabled={busy}
                  className="inline-flex items-center gap-1.5 px-4 py-1.5 rounded-xl bg-blue-600 hover:bg-blue-500 text-white text-xs font-semibold transition disabled:opacity-50"
                >
                  {submittingAction === "metadata" ? (
                    <Loader2 className="w-3.5 h-3.5 animate-spin" />
                  ) : (
                    <Save className="w-3.5 h-3.5" />
                  )}
                  <span>{t("vpsPlanSaveMetadata")}</span>
                </button>
              </div>

              <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div>
                  <label className="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1.5">
                    {t("vpsPlanFieldName")}
                  </label>
                  <input
                    type="text"
                    required
                    value={name}
                    onChange={(e) => setName(e.target.value)}
                    disabled={busy}
                    className="w-full px-3.5 py-2.5 rounded-xl bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 text-xs text-gray-900 dark:text-white focus:ring-2 focus:ring-blue-500 outline-none transition"
                  />
                </div>

                <div>
                  <label className="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1.5">
                    {t("vpsPlanFieldSlug")}
                  </label>
                  <input
                    type="text"
                    value={slug}
                    onChange={(e) => setSlug(e.target.value)}
                    disabled={busy || slugLocked}
                    className="w-full px-3.5 py-2.5 rounded-xl bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 text-xs font-mono text-gray-900 dark:text-white disabled:opacity-60 focus:ring-2 focus:ring-blue-500 outline-none transition"
                  />
                  {slugLocked && (
                    <p className="mt-1 text-[11px] text-gray-400">
                      {t("vpsPlanSlugLockedHint")}
                    </p>
                  )}
                </div>

                <div>
                  <label className="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1.5">
                    {t("vpsPlanFieldSortOrder")}
                  </label>
                  <input
                    type="number"
                    min={0}
                    value={sortOrder}
                    onChange={(e) => setSortOrder(e.target.value)}
                    disabled={busy}
                    className="w-full px-3.5 py-2.5 rounded-xl bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 text-xs text-gray-900 dark:text-white focus:ring-2 focus:ring-blue-500 outline-none transition"
                  />
                </div>
              </div>

              <div>
                <label className="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1.5">
                  {t("vpsPlanFieldDescription")}
                </label>
                <textarea
                  rows={2}
                  value={description}
                  onChange={(e) => setDescription(e.target.value)}
                  disabled={busy}
                  className="w-full px-3.5 py-2.5 rounded-xl bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 text-xs text-gray-900 dark:text-white focus:ring-2 focus:ring-blue-500 outline-none transition"
                />
              </div>
            </form>

            {/* Draft Profile Form */}
            <form
              onSubmit={(e) => void handleSaveDraftProfile(e)}
              className="space-y-4 p-5 rounded-2xl bg-gray-50/70 dark:bg-gray-950/40 border border-gray-200 dark:border-gray-800"
            >
              <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                <div className="flex items-center gap-2">
                  <h3 className="text-xs font-bold uppercase tracking-wider text-gray-500 dark:text-gray-400">
                    {t("vpsPlanProfileSection")}
                  </h3>
                  {currentPlan?.draft_version && (
                    <span className="px-2 py-0.5 rounded-full text-[10px] font-semibold bg-amber-500/10 text-amber-600 dark:text-amber-400 border border-amber-500/20">
                      {t("vpsPlanHasPendingDraft")}
                    </span>
                  )}
                </div>

                <div className="flex items-center flex-wrap gap-2">
                  <button
                    type="submit"
                    disabled={busy}
                    className="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-semibold transition disabled:opacity-50"
                  >
                    {submittingAction === "draft" ? (
                      <Loader2 className="w-3.5 h-3.5 animate-spin" />
                    ) : (
                      <Save className="w-3.5 h-3.5" />
                    )}
                    <span>{t("vpsPlanSaveDraft")}</span>
                  </button>

                  {currentPlan?.draft_version && (
                    <>
                      <button
                        type="button"
                        disabled={busy || currentPlan.status === "archived"}
                        onClick={() =>
                          void runPlanLifecycleAction(
                            "publishDraft",
                            () => vpsPlansApi.publishDraftVersion(currentPlan.id),
                            t("vpsPlanDraftPublishedSuccess")
                          )
                        }
                        className="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white text-xs font-semibold transition disabled:opacity-50"
                      >
                        <Rocket className="w-3.5 h-3.5" />
                        <span>{t("vpsPlanPublishDraft")}</span>
                      </button>

                      <button
                        type="button"
                        disabled={busy}
                        onClick={() => setConfirmState({ type: "discardDraft" })}
                        className="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-xl bg-red-600/10 hover:bg-red-600/20 text-red-600 dark:text-red-400 text-xs font-semibold transition disabled:opacity-50"
                      >
                        <Trash2 className="w-3.5 h-3.5" />
                        <span>{t("vpsPlanDiscardDraft")}</span>
                      </button>
                    </>
                  )}
                </div>
              </div>

              {renderProfileFields()}
            </form>

            {/* Version History & Usage */}
            <section className="space-y-3">
              <div className="flex items-center justify-between gap-3">
                <div className="flex items-center gap-2">
                  <History className="w-4 h-4 text-gray-400" />
                  <h3 className="text-xs font-bold uppercase tracking-wider text-gray-500 dark:text-gray-400">
                    {t("vpsPlanVersionsSection")}
                  </h3>
                </div>
                {usage && (
                  <span className="px-2.5 py-1 rounded-full text-xs font-semibold bg-blue-500/10 text-blue-600 dark:text-blue-400">
                    {t("vpsPlanTotalActiveVms")}: {usage.total_active_vms}
                  </span>
                )}
              </div>

              {versions.length === 0 ? (
                <p className="text-xs text-gray-500 dark:text-gray-400 py-4">
                  {t("vpsPlanNoVersionsYet")}
                </p>
              ) : (
                <div className="space-y-2">
                  {versions.map((ver) => {
                    const activeCount =
                      usageByVersionId.get(ver.id) ?? ver.active_vms_count ?? 0;
                    const versionLabel =
                      ver.version !== null
                        ? t("vpsPlanVersionLabel", { version: ver.version })
                        : t("vpsPlanDraftBadge");
                    const dl = formatNetworkLimit(
                      ver.net_download_mbps,
                      t("vpsPlanUnlimited"),
                      (mbps) => t("vpsPlanMbpsValue", { mbps })
                    );
                    const ul = formatNetworkLimit(
                      ver.net_upload_mbps,
                      t("vpsPlanUnlimited"),
                      (mbps) => t("vpsPlanMbpsValue", { mbps })
                    );

                    return (
                      <div
                        key={ver.id}
                        className="p-3.5 rounded-2xl bg-gray-50 dark:bg-gray-950/60 border border-gray-200 dark:border-gray-800 flex flex-col sm:flex-row sm:items-center justify-between gap-3"
                      >
                        <div className="space-y-1 min-w-0">
                          <div className="flex items-center flex-wrap gap-2">
                            <span className="text-xs font-mono font-bold text-gray-900 dark:text-white">
                              {versionLabel}
                            </span>
                            <span
                              className={`px-2 py-0.5 rounded-full text-[10px] font-semibold ${getPlanStatusBadgeStyle(
                                ver.status
                              )}`}
                            >
                              {t(getPlanStatusTranslationKey(ver.status))}
                            </span>
                            <span className="text-xs text-gray-700 dark:text-gray-300 font-medium">
                              {ver.cpu_cores} vCPU · {formatPlanRam(ver.ram_mb)} ·{" "}
                              {ver.disk_gb} GiB ·{" "}
                              {ver.instance_type === "virtual-machine"
                                ? t("vpsPlanInstanceTypeVm")
                                : t("vpsPlanInstanceTypeContainer")}
                            </span>
                          </div>
                          <div className="text-[11px] text-gray-500 dark:text-gray-400 flex flex-wrap gap-x-3 gap-y-1">
                            <span>
                              {t("vpsPlanDownloadLabel")}: {dl} / {t("vpsPlanUploadLabel")}: {ul}
                            </span>
                            {ver.change_summary && (
                              <span className="italic truncate max-w-md">
                                &ldquo;{ver.change_summary}&rdquo;
                              </span>
                            )}
                          </div>
                        </div>

                        <div className="flex items-center gap-3 flex-shrink-0">
                          <span className="px-2.5 py-1 rounded-xl text-[11px] font-semibold bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 text-gray-700 dark:text-gray-300">
                            {t("vpsPlanActiveVmsCount", { count: activeCount })}
                          </span>
                        </div>
                      </div>
                    );
                  })}
                </div>
              )}
            </section>
          </div>
        )}
      </div>

      <ConfirmModal
        isOpen={confirmState !== null}
        title={
          confirmState?.type === "discardDraft"
            ? t("vpsPlanConfirmDiscardDraftTitle")
            : t("vpsPlanConfirmArchiveTitle")
        }
        message={
          confirmState?.type === "discardDraft"
            ? `${t("vpsPlanConfirmDiscardDraftDesc")} "${currentPlan?.name ?? ""}"?`
            : `${t("vpsPlanConfirmArchiveDesc")} "${currentPlan?.name ?? ""}"?`
        }
        variant={confirmState?.type === "discardDraft" ? "danger" : "warning"}
        confirmText={
          confirmState?.type === "discardDraft"
            ? t("vpsPlanDiscardDraft")
            : t("vpsPlanArchive")
        }
        isLoading={
          submittingAction === "discardDraft" || submittingAction === "archive"
        }
        onClose={() => setConfirmState(null)}
        onConfirm={() => {
          if (!currentPlan || !confirmState) return;
          if (confirmState.type === "discardDraft") {
            return runPlanLifecycleAction(
              "discardDraft",
              () => vpsPlansApi.discardDraftVersion(currentPlan.id),
              t("vpsPlanDraftDiscardedSuccess")
            );
          }
          return runPlanLifecycleAction(
            "archive",
            () => vpsPlansApi.archivePlan(currentPlan.id),
            t("vpsPlanArchivedSuccess")
          );
        }}
      />
    </div>
  );
}
