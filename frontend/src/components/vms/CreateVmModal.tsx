"use client";

import React, { useState, useEffect, useCallback } from "react";
import {
  X,
  Server,
  Cpu,
  Layers,
  HardDrive,
  AlertCircle,
  Loader2,
  Globe,
  Check,
  Network,
  MemoryStick,
  SlidersHorizontal,
} from "lucide-react";
import {
  ApiError,
  CreateVmCustomPayload,
  CreateVmPayload,
  CreateVmPlanPayload,
  HostNode,
  ImageItem,
  IpPool,
  QuotaResponse,
  VpsPlanCatalogItem,
  api,
  vpsPlansApi,
} from "@/lib/api";
import { extractPaginationMeta } from "@/lib/api/client";
import { useAuth } from "@/lib/auth/AuthContext";
import { useI18n } from "@/lib/i18n/I18nContext";
import { useModalEscape } from "@/lib/hooks/useModalEscape";
import { formatNetworkLimit, formatPlanRam } from "../plans/VpsPlanCard";

interface CreateVmModalProps {
  isOpen: boolean;
  onClose: () => void;
  quotaData: QuotaResponse | null;
  systemSettings?: {
    allow_virtual_machines?: boolean;
    allow_containers?: boolean;
  } | null;
  existingVms?: { name: string; host_id?: number | null; status?: string }[];
  existingVmNames?: string[];
  onSuccess: () => void;
}

/**
 * Returns true if the host has no known max_instance limits or can fit minimum instance requirements (1 vCPU, 1024 MB RAM, 5 GB Disk).
 */
function hostFitsMinimum(host: HostNode): boolean {
  if (host.max_instance === undefined) return true;
  return (
    host.max_instance.cpu_cores >= 1 &&
    host.max_instance.ram_mb >= 1024 &&
    host.max_instance.disk_gb >= 5
  );
}

/**
 * Returns true when a catalog plan defines a download or upload bandwidth limit.
 */
export function planHasNetworkLimits(plan: VpsPlanCatalogItem): boolean {
  const version = plan.current_version;
  if (!version) return false;
  return (
    (version.net_download_mbps !== null && version.net_download_mbps !== undefined) ||
    (version.net_upload_mbps !== null && version.net_upload_mbps !== undefined)
  );
}

/**
 * Computes a map of hostId -> true for hosts whose active free IP pools with known network modes are all "manual".
 */
export function computeHostManualOnlyMap(pools: IpPool[]): Record<number, boolean> {
  const tracker: Record<number, { hasManual: boolean; hasNonManual: boolean }> = {};

  for (const pool of pools) {
    if (!pool.host_id || pool.active === false || !pool.network_mode) {
      continue;
    }

    const hasFree =
      typeof pool.free === "boolean"
        ? pool.free
        : (pool.utilization?.free ?? pool.free_ips ?? 0) > 0;

    if (!hasFree) {
      continue;
    }

    if (!tracker[pool.host_id]) {
      tracker[pool.host_id] = { hasManual: false, hasNonManual: false };
    }

    if (pool.network_mode === "manual") {
      tracker[pool.host_id].hasManual = true;
    } else {
      tracker[pool.host_id].hasNonManual = true;
    }
  }

  const result: Record<number, boolean> = {};
  for (const [hostIdKey, state] of Object.entries(tracker)) {
    result[Number(hostIdKey)] = state.hasManual && !state.hasNonManual;
  }

  return result;
}

/**
 * Determines whether the currently selected host (or all eligible auto-select hosts) only has manual-mode IP pools.
 */
export function isHostSelectionManualOnly(
  selectedHostId: number | null,
  hosts: HostNode[],
  hostHasFreeMap: Record<number, boolean>,
  hostManualOnlyMap: Record<number, boolean>
): boolean {
  if (selectedHostId !== null) {
    return Boolean(hostManualOnlyMap[selectedHostId]);
  }

  const hasFreeEntries = Object.keys(hostHasFreeMap).length > 0;
  const candidateHosts = hosts.filter(
    (h) => (!hasFreeEntries || hostHasFreeMap[h.id]) && hostManualOnlyMap[h.id] !== undefined
  );

  if (candidateHosts.length === 0) {
    return false;
  }

  return candidateHosts.every((h) => hostManualOnlyMap[h.id] === true);
}

/**
 * Detects whether an API error indicates that network bandwidth limits are unsupported on manual-mode IP pools.
 */
export function isNetworkLimitNotSupportedError(err: unknown): boolean {
  if (!err || typeof err !== "object") return false;
  const maybeCode = (err as { code?: unknown }).code;
  if (maybeCode === "NETWORK_LIMIT_NOT_SUPPORTED_MODE") {
    return true;
  }

  if (err instanceof ApiError && err.status === 422) {
    const ipPoolMessages = err.errors?.ip_pool ?? [];
    if (ipPoolMessages.some((msg) => msg.toLowerCase().includes("manual"))) {
      return true;
    }
    const lowerMsg = err.message.toLowerCase();
    return lowerMsg.includes("manual-mode") || lowerMsg.includes("network bandwidth limits");
  }

  return false;
}

/**
 * Builds the provisioning payload, ensuring non-admin requests never include custom resource override fields.
 */
export function buildCreateVmPayload(input: {
  isAdmin: boolean;
  useCustomResources: boolean;
  name: string;
  osImage: string;
  selectedPlanId: number | null;
  selectedHostId: number | null;
  instanceType: "virtual-machine" | "container";
  cpuCores: number;
  ramGb: number;
  diskGb: number;
}): CreateVmPayload {
  if (input.isAdmin && input.useCustomResources) {
    const payload: CreateVmCustomPayload = {
      name: input.name,
      instance_type: input.instanceType,
      os_image: input.osImage,
      cpu_cores: Number(input.cpuCores),
      ram_mb: Number(input.ramGb) * 1024,
      disk_gb: Number(input.diskGb),
    };
    if (input.selectedHostId !== null) {
      payload.host_id = input.selectedHostId;
    }
    return payload;
  }

  if (input.selectedPlanId === null) {
    throw new Error("A VPS plan must be selected.");
  }

  const payload: CreateVmPlanPayload = {
    name: input.name,
    os_image: input.osImage,
    vps_plan_id: input.selectedPlanId,
  };
  if (input.selectedHostId !== null) {
    payload.host_id = input.selectedHostId;
  }
  return payload;
}

function generateIdempotencyKey(): string {
  if (typeof globalThis.crypto !== "undefined" && typeof globalThis.crypto.randomUUID === "function") {
    return globalThis.crypto.randomUUID();
  }
  return `${Date.now()}-${Math.random().toString(36).slice(2, 11)}`;
}

export function CreateVmModal({
  isOpen,
  onClose,
  quotaData,
  systemSettings,
  existingVms,
  existingVmNames,
  onSuccess,
}: CreateVmModalProps) {
  const { t } = useI18n();
  const { isAdmin } = useAuth();

  const allowVm = systemSettings?.allow_virtual_machines ?? true;
  const allowContainers = systemSettings?.allow_containers ?? true;
  const bothEnabled = allowVm && allowContainers;

  const [name, setName] = useState("");
  const [useCustomResources, setUseCustomResources] = useState(false);
  const [instanceType, setInstanceType] = useState<"virtual-machine" | "container">("virtual-machine");
  const [plans, setPlans] = useState<VpsPlanCatalogItem[]>([]);
  const [plansLoading, setPlansLoading] = useState(false);
  const [plansError, setPlansError] = useState<string | null>(null);
  const [selectedPlanId, setSelectedPlanId] = useState<number | null>(null);

  const [availableImages, setAvailableImages] = useState<ImageItem[]>([]);
  const [imagesLoading, setImagesLoading] = useState(false);
  const [osImage, setOsImage] = useState("ubuntu/24.04");
  const [cpuCores, setCpuCores] = useState(1);
  const [ramGb, setRamGb] = useState(2);
  const [diskGb, setDiskGb] = useState(20);
  const [hosts, setHosts] = useState<HostNode[]>([]);
  const [selectedHostId, setSelectedHostId] = useState<number | null>(null);
  const [hostHasFreeMap, setHostHasFreeMap] = useState<Record<number, boolean>>({});
  const [hostManualOnlyMap, setHostManualOnlyMap] = useState<Record<number, boolean>>({});
  const [manualOnlyOverrides, setManualOnlyOverrides] = useState<Record<number, boolean>>({});
  const [autoSelectManualOnlyError, setAutoSelectManualOnlyError] = useState(false);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const isCustomMode = isAdmin && useCustomResources;

  const isNameDuplicate = Boolean(
    name.trim() &&
    (
      existingVms && selectedHostId !== null
        ? existingVms.some(
            (vm) =>
              (vm.status ? vm.status.toLowerCase() !== "deleted" : true) &&
              vm.name.toLowerCase() === name.trim().toLowerCase() &&
              (vm.host_id === selectedHostId || (!vm.host_id && hosts.length > 0 && hosts[0].id === selectedHostId))
          )
        : existingVmNames?.some((existingName) => existingName.toLowerCase() === name.trim().toLowerCase())
    )
  );

  useModalEscape(onClose, isOpen && !loading);

  useEffect(() => {
    if (isOpen) {
      queueMicrotask(() => {
        setName("");
        setError(null);
        setLoading(false);
        setUseCustomResources(false);
        setSelectedPlanId(null);
        setPlansError(null);
        setManualOnlyOverrides({});
        setAutoSelectManualOnlyError(false);
        setCpuCores(1);
        setRamGb(2);
        setDiskGb(20);
        if (allowVm && !allowContainers) {
          setInstanceType("virtual-machine");
        } else if (!allowVm && allowContainers) {
          setInstanceType("container");
        } else {
          setInstanceType("virtual-machine");
        }
      });
    }
  }, [isOpen, allowVm, allowContainers]);

  const loadPlans = useCallback(async (signal?: AbortSignal) => {
    setPlansLoading(true);
    setPlansError(null);
    try {
      const catalog = await vpsPlansApi.listCatalog({ signal });
      if (signal?.aborted) return;
      setPlans(catalog);
    } catch (err: unknown) {
      if (signal?.aborted || (err instanceof Error && err.name === "AbortError")) {
        return;
      }
      setPlansError(err instanceof Error ? err.message : t("vpsPlansLoadError"));
    } finally {
      if (!signal?.aborted) {
        setPlansLoading(false);
      }
    }
  }, [t]);

  useEffect(() => {
    if (!isOpen) return;
    const controller = new AbortController();
    queueMicrotask(() => {
      if (!controller.signal.aborted) {
        void loadPlans(controller.signal);
      }
    });
    return () => {
      controller.abort();
    };
  }, [isOpen, loadPlans]);

  useEffect(() => {
    if (!isOpen) return;
    let cancelled = false;

    const loadHostsAndPools = async () => {
      try {
        const [hostsRes, firstPoolsRes] = await Promise.all([
          api.getHosts(),
          api.getIpPools({ page: 1, per_page: 100 }),
        ]);

        const allPools = [...firstPoolsRes.data];
        const { lastPage } = extractPaginationMeta(firstPoolsRes, 100);

        if (lastPage > 1) {
          const maxPage = Math.min(lastPage, 5);
          const extraPagePromises = [];
          for (let p = 2; p <= maxPage; p++) {
            extraPagePromises.push(api.getIpPools({ page: p, per_page: 100 }));
          }
          const extraPages = await Promise.all(extraPagePromises);
          for (const pageRes of extraPages) {
            allPools.push(...pageRes.data);
          }
        }

        if (cancelled) return;

        setHosts(hostsRes.data);
        const freeMap: Record<number, boolean> = {};
        for (const pool of allPools) {
          if (pool.host_id) {
            const hasFree =
              typeof pool.free === "boolean"
                ? pool.free
                : (pool.utilization?.free ?? pool.free_ips ?? 0) > 0;
            if (hasFree) {
              freeMap[pool.host_id] = true;
            }
          }
        }
        setHostHasFreeMap(freeMap);
        setHostManualOnlyMap(computeHostManualOnlyMap(allPools));

        if (hostsRes.data.length > 0) {
          const candidate =
            hostsRes.data.find((h) => freeMap[h.id] && hostFitsMinimum(h)) ||
            hostsRes.data.find((h) => freeMap[h.id]) ||
            hostsRes.data[0];
          setSelectedHostId((prev) => prev ?? candidate.id);
        }
      } catch (err) {
        console.error(err);
      }
    };

    loadHostsAndPools();

    return () => {
      cancelled = true;
    };
  }, [isOpen]);

  const effectiveHostManualOnlyMap = {
    ...hostManualOnlyMap,
    ...manualOnlyOverrides,
  };

  const isSelectedHostManualOnly =
    isHostSelectionManualOnly(
      selectedHostId,
      hosts,
      hostHasFreeMap,
      effectiveHostManualOnlyMap
    ) || (selectedHostId === null && autoSelectManualOnlyError);

  const visiblePlans = plans.filter((plan) => {
    const version = plan.current_version;
    if (!version) return false;
    if (version.instance_type === "virtual-machine" && !allowVm) return false;
    if (version.instance_type === "container" && !allowContainers) return false;
    return true;
  });

  const eligiblePlans = visiblePlans.filter(
    (plan) => !(isSelectedHostManualOnly && planHasNetworkLimits(plan))
  );

  const effectiveSelectedPlan =
    eligiblePlans.find((plan) => plan.id === selectedPlanId) ?? eligiblePlans[0] ?? null;
  const effectiveSelectedPlanId = effectiveSelectedPlan?.id ?? null;

  const effectiveInstanceType: "virtual-machine" | "container" = isCustomMode
    ? instanceType
    : (effectiveSelectedPlan?.current_version?.instance_type ??
      (allowVm ? "virtual-machine" : "container"));

  useEffect(() => {
    if (isOpen) {
      let cancelled = false;
      queueMicrotask(() => {
        if (!cancelled) {
          setImagesLoading(true);
        }
      });
      api
        .getImages({
          host_id: selectedHostId,
          instance_type: effectiveInstanceType,
        })
        .then((res) => {
          if (cancelled) return;
          const linuxImages = res.data.filter((img) => img.os_type === "linux");
          setAvailableImages(linuxImages);
          if (linuxImages.length > 0) {
            setOsImage((prev) => (linuxImages.some((img) => img.name === prev) ? prev : linuxImages[0].name));
          } else {
            setOsImage("");
          }
        })
        .catch(console.error)
        .finally(() => {
          if (!cancelled) setImagesLoading(false);
        });

      return () => {
        cancelled = true;
      };
    }
  }, [isOpen, selectedHostId, effectiveInstanceType]);

  const available = quotaData?.available;

  let nodeMaxCpu: number | null = null;
  let nodeMaxRamGb: number | null = null;
  let nodeMaxDiskGb: number | null = null;

  if (selectedHostId !== null) {
    const selectedHost = hosts.find((h) => h.id === selectedHostId);
    if (selectedHost?.max_instance) {
      nodeMaxCpu = selectedHost.max_instance.cpu_cores;
      nodeMaxRamGb = Math.floor(selectedHost.max_instance.ram_mb / 1024);
      nodeMaxDiskGb = selectedHost.max_instance.disk_gb;
    }
  } else {
    const eligibleHosts = hosts.filter(
      (h) => (hostHasFreeMap[h.id] ?? true) && h.max_instance && h.max_instance.cpu_cores >= 1
    );
    if (eligibleHosts.length > 0) {
      nodeMaxCpu = Math.max(...eligibleHosts.map((h) => h.max_instance!.cpu_cores));
      nodeMaxRamGb = Math.max(...eligibleHosts.map((h) => Math.floor(h.max_instance!.ram_mb / 1024)));
      nodeMaxDiskGb = Math.max(...eligibleHosts.map((h) => h.max_instance!.disk_gb));
    }
  }

  const quotaMaxCpu = available?.cpu_cores !== undefined && available.cpu_cores > 0 ? available.cpu_cores : 64;
  const quotaMaxRamGb = available?.ram_mb !== undefined && available.ram_mb > 0 ? Math.floor(available.ram_mb / 1024) : 128;
  const quotaMaxDiskGb = available?.disk_gb !== undefined && available.disk_gb > 0 ? available.disk_gb : 2000;

  const effectiveMaxCpu = Math.max(1, Math.min(64, quotaMaxCpu, ...(nodeMaxCpu !== null && nodeMaxCpu > 0 ? [nodeMaxCpu] : [])));
  const effectiveMaxRamGb = Math.max(1, Math.min(128, quotaMaxRamGb, ...(nodeMaxRamGb !== null && nodeMaxRamGb > 0 ? [nodeMaxRamGb] : [])));
  const effectiveMaxDiskGb = Math.max(5, Math.min(2000, quotaMaxDiskGb, ...(nodeMaxDiskGb !== null && nodeMaxDiskGb > 0 ? [nodeMaxDiskGb] : [])));

  useEffect(() => {
    if (!isOpen) return;
    queueMicrotask(() => {
      setCpuCores((prev) => Math.min(prev, effectiveMaxCpu));
      setRamGb((prev) => Math.min(prev, effectiveMaxRamGb));
      setDiskGb((prev) => Math.min(prev, effectiveMaxDiskGb));
    });
  }, [isOpen, effectiveMaxCpu, effectiveMaxRamGb, effectiveMaxDiskGb]);

  if (!isOpen) return null;

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setError(null);

    if (isNameDuplicate) {
      setError(t("duplicateVmName") || "You already have an instance with this name on this compute node.");
      return;
    }

    if (selectedHostId !== null && hostHasFreeMap[selectedHostId] === false && Object.keys(hostHasFreeMap).length > 0) {
      setError("Selected compute node has no available IP addresses in its routed pool. Please select another node.");
      return;
    }

    if (!isCustomMode && effectiveSelectedPlanId === null) {
      setError(t("vpsPlanNoCompatiblePlan"));
      return;
    }

    setLoading(true);

    try {
      const payload = buildCreateVmPayload({
        isAdmin,
        useCustomResources,
        name,
        osImage,
        selectedPlanId: effectiveSelectedPlanId,
        selectedHostId,
        instanceType,
        cpuCores,
        ramGb,
        diskGb,
      });

      await api.createVm(payload, {
        idempotencyKey: generateIdempotencyKey(),
      });

      onSuccess();
      onClose();
    } catch (err: unknown) {
      if (isNetworkLimitNotSupportedError(err)) {
        if (selectedHostId !== null) {
          setManualOnlyOverrides((prev) => ({ ...prev, [selectedHostId]: true }));
        } else {
          setAutoSelectManualOnlyError(true);
        }
        setError(t("vpsPlanManualPoolSubmitError"));
      } else if (err instanceof Error) {
        setError(err.message);
      } else {
        setError("Failed to provision instance.");
      }
    } finally {
      setLoading(false);
    }
  };

  const canSubmit =
    !loading &&
    Boolean(name) &&
    !isNameDuplicate &&
    Boolean(osImage) &&
    (isCustomMode || (!plansLoading && effectiveSelectedPlanId !== null));

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 dark:bg-black/80 backdrop-blur-sm animate-in fade-in duration-200">
      <div className="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-3xl w-full max-w-2xl max-h-[90vh] flex flex-col overflow-hidden shadow-2xl relative transition-colors">
        {/* Header */}
        <div className="flex items-center justify-between px-6 py-4 border-b border-gray-200 dark:border-gray-800 flex-shrink-0">
          <div className="flex items-center space-x-2.5">
            <div className="p-2 bg-blue-500/10 border border-blue-500/20 text-blue-600 dark:text-blue-400 rounded-xl">
              <Server className="w-5 h-5" />
            </div>
            <div>
              <h2 className="text-base font-semibold text-gray-900 dark:text-white">{t("deployTitle")}</h2>
              <p className="text-xs text-gray-500 dark:text-gray-400">{t("deploySubtitle")}</p>
            </div>
          </div>
          <button
            type="button"
            onClick={onClose}
            className="p-1.5 rounded-lg text-gray-400 hover:text-gray-700 dark:hover:text-white hover:bg-gray-100 dark:hover:bg-gray-800 transition"
          >
            <X className="w-5 h-5" />
          </button>
        </div>

        {/* Form */}
        <form onSubmit={handleSubmit} className="flex flex-col flex-1 min-h-0">
          <div className="p-6 space-y-5 overflow-y-auto flex-1">
            {error && (
              <div className="p-3 bg-red-500/10 border border-red-500/30 rounded-xl text-red-600 dark:text-red-400 text-xs flex items-center space-x-2">
                <AlertCircle className="w-4 h-4 flex-shrink-0" />
                <span>{error}</span>
              </div>
            )}

            {/* Instance Name */}
            <div>
              <label className="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1.5">
                {t("instanceName")}
              </label>
              <input
                type="text"
                required
                value={name}
                onChange={(e) => setName(e.target.value.toLowerCase().replace(/[^a-z0-9-]/g, ""))}
                placeholder="e.g. app-web-01"
                className={`w-full bg-gray-50 dark:bg-gray-950 border rounded-xl px-3.5 py-2 text-sm text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-gray-600 focus:outline-none focus:ring-1 font-mono transition ${
                  isNameDuplicate
                    ? "border-red-500 focus:border-red-500 focus:ring-red-500"
                    : "border-gray-200 dark:border-gray-800 focus:border-blue-500 focus:ring-blue-500"
                }`}
              />
              {isNameDuplicate ? (
                <p className="text-[11px] text-red-500 font-medium mt-1">
                  {t("duplicateVmName") || "You already have an instance with this name on this compute node."}
                </p>
              ) : (
                <p className="text-[11px] text-gray-500 dark:text-gray-400 mt-1">{t("nameHelper")}</p>
              )}
            </div>

            {/* Admin-only Custom Resources Toggle */}
            {isAdmin && (
              <div
                data-testid="create-vm-mode-toggle"
                className="flex items-center justify-between gap-3 p-1.5 bg-gray-100 dark:bg-gray-950 border border-gray-200 dark:border-gray-800 rounded-2xl"
              >
                <button
                  type="button"
                  data-testid="create-vm-mode-plan"
                  onClick={() => setUseCustomResources(false)}
                  className={`flex-1 flex items-center justify-center gap-1.5 px-3 py-2 rounded-xl text-xs font-semibold transition ${
                    !useCustomResources
                      ? "bg-white dark:bg-gray-800 text-gray-900 dark:text-white shadow-sm"
                      : "text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-white"
                  }`}
                >
                  <Server className="w-3.5 h-3.5" />
                  <span>{t("vpsPlanProvisionModePlan")}</span>
                </button>
                <button
                  type="button"
                  data-testid="create-vm-mode-custom"
                  onClick={() => setUseCustomResources(true)}
                  className={`flex-1 flex items-center justify-center gap-1.5 px-3 py-2 rounded-xl text-xs font-semibold transition ${
                    useCustomResources
                      ? "bg-white dark:bg-gray-800 text-gray-900 dark:text-white shadow-sm"
                      : "text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-white"
                  }`}
                >
                  <SlidersHorizontal className="w-3.5 h-3.5" />
                  <span>{t("vpsPlanProvisionModeCustom")}</span>
                </button>
              </div>
            )}

            {/* Target Host Node / Region */}
            {hosts.length > 0 && (
              <div>
                <label className="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1.5 flex items-center space-x-1.5">
                  <Globe className="w-3.5 h-3.5 text-blue-500" />
                  <span>{t("hostNode")}</span>
                </label>
                <select
                  value={selectedHostId ?? ""}
                  onChange={(e) => {
                    const nextId = e.target.value ? Number(e.target.value) : null;
                    setSelectedHostId(nextId);
                    setAutoSelectManualOnlyError(false);
                  }}
                  className="w-full bg-gray-50 dark:bg-gray-950 border border-gray-200 dark:border-gray-800 rounded-xl px-3.5 py-2 text-xs text-gray-900 dark:text-white focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500 font-mono transition"
                >
                  <option value="">Auto-select (Recommended)</option>
                  {hosts.map((h) => {
                    const hasFree = hostHasFreeMap[h.id] ?? false;
                    const isExhausted = !hasFree;
                    const noCapacity = h.max_instance !== undefined && !hostFitsMinimum(h);
                    return (
                      <option key={h.id} value={h.id} disabled={isExhausted || noCapacity}>
                        {h.name}{h.region ? ` (${h.region})` : ""}{isExhausted ? " (Exhausted)" : ""}{noCapacity ? ` ${t("noCapacityBadge")}` : ""}
                      </option>
                    );
                  })}
                </select>
              </div>
            )}

            {/* Plan Selector (Default) vs Custom Resources (Admin Toggle) */}
            {!isCustomMode ? (
              <div className="space-y-3">
                <label className="block text-xs font-medium text-gray-700 dark:text-gray-300">
                  {t("vpsPlanSelectLabel")}
                </label>

                {isSelectedHostManualOnly && (
                  <div
                    data-testid="create-vm-manual-pool-notice"
                    className="p-3 bg-amber-500/10 border border-amber-500/30 rounded-xl text-amber-700 dark:text-amber-300 text-xs flex items-start space-x-2"
                  >
                    <AlertCircle className="w-4 h-4 flex-shrink-0 mt-0.5" />
                    <span>{t("vpsPlanManualPoolNotice")}</span>
                  </div>
                )}

                {plansLoading ? (
                  <div className="flex items-center justify-center py-6 text-xs text-gray-400 space-x-2">
                    <Loader2 className="w-4 h-4 animate-spin text-blue-500" />
                    <span>{t("vpsPlansLoading")}</span>
                  </div>
                ) : plansError ? (
                  <div className="p-3 bg-red-500/10 border border-red-500/20 rounded-xl text-xs text-red-600 dark:text-red-400 flex items-center justify-between gap-3">
                    <span>{plansError}</span>
                    <button
                      type="button"
                      onClick={() => void loadPlans()}
                      className="px-2.5 py-1 rounded-lg bg-red-500/10 hover:bg-red-500/20 font-semibold transition flex-shrink-0"
                    >
                      {t("vpsPlansRetry")}
                    </button>
                  </div>
                ) : visiblePlans.length === 0 ? (
                  <div className="p-4 bg-gray-50 dark:bg-gray-950/60 border border-gray-200 dark:border-gray-800 rounded-2xl text-center">
                    <div className="text-xs font-semibold text-gray-800 dark:text-gray-200">
                      {t("vpsPlansEmptyTitle")}
                    </div>
                    <div className="text-[11px] text-gray-500 dark:text-gray-400 mt-1">
                      {t("vpsPlansEmptyDesc")}
                    </div>
                  </div>
                ) : (
                  <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    {visiblePlans.map((plan) => {
                      const version = plan.current_version!;
                      const hasLimits = planHasNetworkLimits(plan);
                      const isDisabled = isSelectedHostManualOnly && hasLimits;
                      const isSelected = !isDisabled && effectiveSelectedPlanId === plan.id;
                      const isVm = version.instance_type === "virtual-machine";

                      return (
                        <button
                          key={plan.id}
                          type="button"
                          data-testid={`create-vm-plan-${plan.slug}`}
                          aria-pressed={isSelected}
                          disabled={isDisabled}
                          onClick={() => {
                            if (!isDisabled) {
                              setSelectedPlanId(plan.id);
                            }
                          }}
                          className={`p-3.5 rounded-2xl border text-left transition flex flex-col justify-between gap-2.5 ${
                            isDisabled
                              ? "opacity-50 cursor-not-allowed bg-gray-50 dark:bg-gray-950/40 border-gray-200 dark:border-gray-800"
                              : isSelected
                                ? "bg-blue-50/80 dark:bg-blue-600/15 border-blue-500 ring-1 ring-blue-500"
                                : "bg-gray-50 dark:bg-gray-950/60 border-gray-200 dark:border-gray-800 hover:border-gray-300 dark:hover:border-gray-700"
                          }`}
                        >
                          <div className="space-y-1.5 w-full">
                            <div className="flex items-start justify-between gap-2">
                              <div className="min-w-0">
                                <div className="text-sm font-bold text-gray-900 dark:text-white truncate">
                                  {plan.name}
                                </div>
                                <div className="text-[10px] font-mono text-gray-400 truncate">
                                  {t("vpsPlanVersionLabel", { version: version.version })}
                                </div>
                              </div>
                              <div className="flex items-center gap-1 flex-shrink-0">
                                <span
                                  className={`inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-semibold ${
                                    isVm
                                      ? "bg-blue-500/10 text-blue-600 dark:text-blue-400"
                                      : "bg-indigo-500/10 text-indigo-600 dark:text-indigo-400"
                                  }`}
                                >
                                  {isVm ? <Server className="w-2.5 h-2.5" /> : <Layers className="w-2.5 h-2.5" />}
                                  <span>
                                    {isVm ? t("vpsPlanInstanceTypeVm") : t("vpsPlanInstanceTypeContainer")}
                                  </span>
                                </span>
                                {isSelected && (
                                  <span className="inline-flex items-center justify-center w-5 h-5 rounded-full bg-blue-600 text-white" title={t("vpsPlanSelectedBadge")}>
                                    <Check className="w-3 h-3" />
                                  </span>
                                )}
                              </div>
                            </div>

                            {plan.description && (
                              <p className="text-[11px] text-gray-500 dark:text-gray-400 line-clamp-2">
                                {plan.description}
                              </p>
                            )}
                          </div>

                          <div className="grid grid-cols-2 gap-1.5 text-[11px] pt-1 border-t border-gray-200/70 dark:border-gray-800/70 w-full">
                            <div className="flex items-center gap-1 text-gray-700 dark:text-gray-300">
                              <Cpu className="w-3 h-3 text-blue-500 flex-shrink-0" />
                              <span>{t("vpsPlanCpuValue", { count: version.cpu_cores })}</span>
                            </div>
                            <div className="flex items-center gap-1 text-gray-700 dark:text-gray-300">
                              <MemoryStick className="w-3 h-3 text-emerald-500 flex-shrink-0" />
                              <span className="truncate">{formatPlanRam(version.ram_mb)}</span>
                            </div>
                            <div className="flex items-center gap-1 text-gray-700 dark:text-gray-300">
                              <HardDrive className="w-3 h-3 text-amber-500 flex-shrink-0" />
                              <span>{t("vpsPlanDiskValue", { gb: version.disk_gb })}</span>
                            </div>
                            <div className="flex items-center gap-1 text-gray-700 dark:text-gray-300">
                              <Network className="w-3 h-3 text-purple-500 flex-shrink-0" />
                              <span className="truncate">
                                {formatNetworkLimit(
                                  version.net_download_mbps,
                                  t("vpsPlanUnlimited"),
                                  (mbps) => t("vpsPlanMbpsValue", { mbps })
                                )}
                                {" / "}
                                {formatNetworkLimit(
                                  version.net_upload_mbps,
                                  t("vpsPlanUnlimited"),
                                  (mbps) => t("vpsPlanMbpsValue", { mbps })
                                )}
                              </span>
                            </div>
                          </div>

                          {isDisabled && (
                            <div className="text-[10px] font-medium text-amber-600 dark:text-amber-400">
                              {t("vpsPlanManualPoolDisabledBadge")}
                            </div>
                          )}
                        </button>
                      );
                    })}
                  </div>
                )}

                {!plansLoading && !plansError && visiblePlans.length > 0 && eligiblePlans.length === 0 && (
                  <p className="text-[11px] text-amber-600 dark:text-amber-400 font-medium">
                    {t("vpsPlanNoCompatiblePlan")}
                  </p>
                )}
              </div>
            ) : (
              <>
                {/* Type Selector (VM vs Container) - only shown when both virtualization modes are enabled */}
                {bothEnabled && (
                  <div>
                    <label className="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1.5">
                      {t("virtualizationMode")}
                    </label>
                    <div className="grid grid-cols-2 gap-3">
                      <button
                        type="button"
                        onClick={() => setInstanceType("virtual-machine")}
                        className={`p-3 rounded-xl border text-left transition ${
                          instanceType === "virtual-machine"
                            ? "bg-blue-50 dark:bg-blue-600/15 border-blue-500 text-blue-900 dark:text-white"
                            : "bg-gray-50 dark:bg-gray-950/60 border-gray-200 dark:border-gray-800 text-gray-600 dark:text-gray-400 hover:border-gray-300 dark:hover:border-gray-700"
                        }`}
                      >
                        <div className="font-semibold text-xs text-gray-900 dark:text-white">{t("fullVm")}</div>
                        <div className="text-[11px] text-gray-500 dark:text-gray-400 mt-0.5">{t("fullVmDesc")}</div>
                      </button>

                      <button
                        type="button"
                        onClick={() => setInstanceType("container")}
                        className={`p-3 rounded-xl border text-left transition ${
                          instanceType === "container"
                            ? "bg-blue-50 dark:bg-blue-600/15 border-blue-500 text-blue-900 dark:text-white"
                            : "bg-gray-50 dark:bg-gray-950/60 border-gray-200 dark:border-gray-800 text-gray-600 dark:text-gray-400 hover:border-gray-300 dark:hover:border-gray-700"
                        }`}
                      >
                        <div className="font-semibold text-xs text-gray-900 dark:text-white">{t("lxcContainer")}</div>
                        <div className="text-[11px] text-gray-500 dark:text-gray-400 mt-0.5">{t("lxcDesc")}</div>
                      </button>
                    </div>
                  </div>
                )}

                {/* Hardware Specifications */}
                <div className="grid grid-cols-3 gap-3 pt-1">
                  <div>
                    <label className="text-xs font-medium text-gray-700 dark:text-gray-300 flex items-center justify-between mb-1">
                      <span>{t("cpuCores")}</span>
                      <span
                        className="text-[10px] text-gray-400"
                        title={nodeMaxCpu !== null ? `${t("nodeMax")}: ${nodeMaxCpu}` : undefined}
                      >
                        {t("avail")}:{" "}
                        {nodeMaxCpu !== null &&
                        (available?.cpu_cores === -1 ||
                          available?.cpu_cores === undefined ||
                          nodeMaxCpu < available.cpu_cores)
                          ? effectiveMaxCpu
                          : available?.cpu_cores === -1
                            ? "∞"
                            : (available?.cpu_cores ?? "-")}
                      </span>
                    </label>
                    <div className="relative">
                      <input
                        type="number"
                        min={1}
                        max={effectiveMaxCpu}
                        value={cpuCores}
                        onChange={(e) => setCpuCores(Math.min(effectiveMaxCpu, Math.max(1, parseInt(e.target.value, 10) || 1)))}
                        className="w-full bg-gray-50 dark:bg-gray-950 border border-gray-200 dark:border-gray-800 rounded-xl px-3 py-2 text-sm text-gray-900 dark:text-white focus:outline-none focus:border-blue-500 pl-8"
                      />
                      <Cpu className="w-4 h-4 text-emerald-500 absolute left-2.5 top-2.5" />
                    </div>
                  </div>

                  <div>
                    <label className="text-xs font-medium text-gray-700 dark:text-gray-300 flex items-center justify-between mb-1">
                      <span>{t("ramGb")}</span>
                      <span
                        className="text-[10px] text-gray-400"
                        title={nodeMaxRamGb !== null ? `${t("nodeMax")}: ${nodeMaxRamGb}` : undefined}
                      >
                        {t("avail")}:{" "}
                        {nodeMaxRamGb !== null &&
                        (available?.ram_mb === -1 ||
                          available?.ram_mb === undefined ||
                          nodeMaxRamGb < Math.floor(available.ram_mb / 1024))
                          ? effectiveMaxRamGb
                          : available?.ram_mb === -1
                            ? "∞"
                            : available
                              ? Math.floor(available.ram_mb / 1024)
                              : "-"}
                      </span>
                    </label>
                    <div className="relative">
                      <input
                        type="number"
                        min={1}
                        max={effectiveMaxRamGb}
                        value={ramGb}
                        onChange={(e) => setRamGb(Math.min(effectiveMaxRamGb, Math.max(1, parseInt(e.target.value, 10) || 1)))}
                        className="w-full bg-gray-50 dark:bg-gray-950 border border-gray-200 dark:border-gray-800 rounded-xl px-3 py-2 text-sm text-gray-900 dark:text-white focus:outline-none focus:border-blue-500 pl-8"
                      />
                      <Layers className="w-4 h-4 text-purple-500 absolute left-2.5 top-2.5" />
                    </div>
                  </div>

                  <div>
                    <label className="text-xs font-medium text-gray-700 dark:text-gray-300 flex items-center justify-between mb-1">
                      <span>{t("diskGb")}</span>
                      <span
                        className="text-[10px] text-gray-400"
                        title={nodeMaxDiskGb !== null ? `${t("nodeMax")}: ${nodeMaxDiskGb}` : undefined}
                      >
                        {t("avail")}:{" "}
                        {nodeMaxDiskGb !== null &&
                        (available?.disk_gb === -1 ||
                          available?.disk_gb === undefined ||
                          nodeMaxDiskGb < available.disk_gb)
                          ? effectiveMaxDiskGb
                          : available?.disk_gb === -1
                            ? "∞"
                            : (available?.disk_gb ?? "-")}
                      </span>
                    </label>
                    <div className="relative">
                      <input
                        type="number"
                        min={5}
                        max={effectiveMaxDiskGb}
                        value={diskGb}
                        onChange={(e) => setDiskGb(Math.min(effectiveMaxDiskGb, Math.max(5, parseInt(e.target.value, 10) || 5)))}
                        className="w-full bg-gray-50 dark:bg-gray-950 border border-gray-200 dark:border-gray-800 rounded-xl px-3 py-2 text-sm text-gray-900 dark:text-white focus:outline-none focus:border-blue-500 pl-8"
                      />
                      <HardDrive className="w-4 h-4 text-amber-500 absolute left-2.5 top-2.5" />
                    </div>
                  </div>
                </div>
              </>
            )}

            {/* OS Image */}
            <div>
              <label className="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1.5">
                {t("osImage")}
              </label>
              <div className="space-y-2 max-h-48 overflow-y-auto pr-1">
                {imagesLoading ? (
                  <div className="flex items-center justify-center py-6 text-xs text-gray-400 space-x-2">
                    <Loader2 className="w-4 h-4 animate-spin text-blue-500" />
                    <span>Loading compatible OS images...</span>
                  </div>
                ) : (() => {
                  const linuxImages = availableImages.filter((img) => img.os_type === "linux");
                  if (linuxImages.length === 0) {
                    return (
                      <div className="p-3 bg-amber-500/10 border border-amber-500/20 rounded-xl text-xs text-amber-600 dark:text-amber-400">
                        {effectiveInstanceType === "container"
                          ? "No Linux container images available on this compute node."
                          : "No approved Linux images available on this compute node."}
                      </div>
                    );
                  }
                  return linuxImages.map((os) => (
                    <label
                      key={os.id}
                      className={`flex items-center justify-between p-3 rounded-xl border cursor-pointer transition ${
                        osImage === os.name
                          ? "bg-blue-50 dark:bg-blue-600/10 border-blue-500 text-blue-900 dark:text-white"
                          : "bg-gray-50 dark:bg-gray-950/50 border-gray-200 dark:border-gray-800 text-gray-700 dark:text-gray-300 hover:border-gray-300 dark:hover:border-gray-700"
                      }`}
                    >
                      <div className="flex items-center space-x-3">
                        <input
                          type="radio"
                          name="osImage"
                          value={os.name}
                          checked={osImage === os.name}
                          onChange={() => setOsImage(os.name)}
                          className="text-blue-600 focus:ring-blue-500"
                        />
                        <div>
                          <div className="text-xs font-semibold text-gray-900 dark:text-white flex items-center space-x-1.5">
                            <span>{os.alias || os.name}</span>
                            <span className="font-mono text-[10px] text-gray-400 font-normal">({os.name})</span>
                          </div>
                          <div className="text-[10px] text-gray-400 font-mono truncate max-w-xs">{os.source}</div>
                        </div>
                      </div>
                      <span
                        className={`text-[10px] px-2 py-0.5 rounded-full font-bold uppercase tracking-wider ${
                          os.os_type === "linux"
                            ? "bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border border-emerald-500/20"
                            : "bg-sky-500/10 text-sky-600 dark:text-sky-400 border border-sky-500/20"
                        }`}
                      >
                        {os.os_type}
                      </span>
                    </label>
                  ));
                })()}
              </div>
            </div>
          </div>

          {/* Footer Action Buttons */}
          <div className="px-6 py-4 flex items-center justify-end space-x-3 border-t border-gray-200 dark:border-gray-800 flex-shrink-0">
            <button
              type="button"
              onClick={onClose}
              disabled={loading}
              className="px-4 py-2 rounded-xl text-xs font-medium text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800 transition"
            >
              {t("cancel")}
            </button>

            <button
              type="submit"
              disabled={!canSubmit}
              className="px-5 py-2 rounded-xl text-xs font-semibold text-white bg-blue-600 hover:bg-blue-500 disabled:opacity-50 transition flex items-center space-x-1.5 shadow-lg shadow-blue-600/20"
            >
              {loading ? (
                <>
                  <Loader2 className="w-3.5 h-3.5 animate-spin" />
                  <span>{t("provisioningInProgress")}</span>
                </>
              ) : (
                <span>{t("launchInstance")}</span>
              )}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}
