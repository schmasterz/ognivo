"use client";

import React, { useState, useEffect } from "react";
import { X, Server, Cpu, Layers, HardDrive, AlertCircle, Loader2, Globe } from "lucide-react";
import { QuotaResponse, HostNode, ImageItem, api } from "@/lib/api";
import { extractPaginationMeta } from "@/lib/api/client";
import { useI18n } from "@/lib/i18n/I18nContext";
import { useModalEscape } from "@/lib/hooks/useModalEscape";

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
  const allowVm = systemSettings?.allow_virtual_machines ?? true;
  const allowContainers = systemSettings?.allow_containers ?? true;
  const bothEnabled = allowVm && allowContainers;

  const [name, setName] = useState("");
  const [instanceType, setInstanceType] = useState<"virtual-machine" | "container">("virtual-machine");
  const [availableImages, setAvailableImages] = useState<ImageItem[]>([]);
  const [imagesLoading, setImagesLoading] = useState(false);
  const [osImage, setOsImage] = useState("ubuntu/24.04");
  const [cpuCores, setCpuCores] = useState(1);
  const [ramGb, setRamGb] = useState(2);
  const [diskGb, setDiskGb] = useState(20);
  const [hosts, setHosts] = useState<HostNode[]>([]);
  const [selectedHostId, setSelectedHostId] = useState<number | null>(null);
  const [hostHasFreeMap, setHostHasFreeMap] = useState<Record<number, boolean>>({});
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

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

        if (hostsRes.data.length > 0) {
          const candidate =
            hostsRes.data.find((h) => freeMap[h.id]) || hostsRes.data[0];
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
          instance_type: instanceType,
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
  }, [isOpen, selectedHostId, instanceType]);

  if (!isOpen) return null;

  const available = quotaData?.available;

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

    setLoading(true);

    try {
      await api.createVm({
        name,
        instance_type: instanceType,
        os_image: osImage,
        cpu_cores: Number(cpuCores),
        ram_mb: Number(ramGb) * 1024,
        disk_gb: Number(diskGb),
        host_id: selectedHostId,
      });

      onSuccess();
      onClose();
    } catch (err: unknown) {
      if (err instanceof Error) {
        setError(err.message);
      } else {
        setError("Failed to provision instance.");
      }
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 dark:bg-black/80 backdrop-blur-sm animate-in fade-in duration-200">
      <div className="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-3xl w-full max-w-xl overflow-hidden shadow-2xl relative transition-colors">
        {/* Header */}
        <div className="flex items-center justify-between px-6 py-4 border-b border-gray-200 dark:border-gray-800">
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
            onClick={onClose}
            className="p-1.5 rounded-lg text-gray-400 hover:text-gray-700 dark:hover:text-white hover:bg-gray-100 dark:hover:bg-gray-800 transition"
          >
            <X className="w-5 h-5" />
          </button>
        </div>

        {/* Form */}
        <form onSubmit={handleSubmit} className="p-6 space-y-5">
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

          {/* Target Host Node / Region */}
          {hosts.length > 0 && (
            <div>
              <label className="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1.5 flex items-center space-x-1.5">
                <Globe className="w-3.5 h-3.5 text-blue-500" />
                <span>{t("hostNode")}</span>
              </label>
              <select
                value={selectedHostId ?? ""}
                onChange={(e) => setSelectedHostId(e.target.value ? Number(e.target.value) : null)}
                className="w-full bg-gray-50 dark:bg-gray-950 border border-gray-200 dark:border-gray-800 rounded-xl px-3.5 py-2 text-xs text-gray-900 dark:text-white focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500 font-mono transition"
              >
                <option value="">Auto-select (Recommended)</option>
                {hosts.map((h) => {
                  const hasFree = hostHasFreeMap[h.id] ?? false;
                  const isExhausted = !hasFree;
                  return (
                    <option key={h.id} value={h.id} disabled={isExhausted}>
                      {h.name} {h.region ? `(${h.region})` : ""} {isExhausted ? "(Exhausted)" : ""}
                    </option>
                  );
                })}
              </select>
            </div>
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
                      {instanceType === "container"
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

          {/* Hardware Specifications */}
          <div className="grid grid-cols-3 gap-3 pt-2">
            <div>
              <label className="text-xs font-medium text-gray-700 dark:text-gray-300 flex items-center justify-between mb-1">
                <span>{t("cpuCores")}</span>
                <span className="text-[10px] text-gray-400">
                  {t("avail")}: {available?.cpu_cores === -1 ? "∞" : (available?.cpu_cores ?? "-")}
                </span>
              </label>
              <div className="relative">
                <input
                  type="number"
                  min={1}
                  max={available?.cpu_cores !== undefined && available.cpu_cores > 0 ? available.cpu_cores : 64}
                  value={cpuCores}
                  onChange={(e) => setCpuCores(Math.max(1, parseInt(e.target.value) || 1))}
                  className="w-full bg-gray-50 dark:bg-gray-950 border border-gray-200 dark:border-gray-800 rounded-xl px-3 py-2 text-sm text-gray-900 dark:text-white focus:outline-none focus:border-blue-500 pl-8"
                />
                <Cpu className="w-4 h-4 text-emerald-500 absolute left-2.5 top-2.5" />
              </div>
            </div>

            <div>
              <label className="text-xs font-medium text-gray-700 dark:text-gray-300 flex items-center justify-between mb-1">
                <span>{t("ramGb")}</span>
                <span className="text-[10px] text-gray-400">
                  {t("avail")}: {available?.ram_mb === -1 ? "∞" : (available ? Math.round(available.ram_mb / 1024) : "-")}
                </span>
              </label>
              <div className="relative">
                <input
                  type="number"
                  min={1}
                  max={available?.ram_mb !== undefined && available.ram_mb > 0 ? Math.round(available.ram_mb / 1024) : 128}
                  value={ramGb}
                  onChange={(e) => setRamGb(Math.max(1, parseInt(e.target.value) || 1))}
                  className="w-full bg-gray-50 dark:bg-gray-950 border border-gray-200 dark:border-gray-800 rounded-xl px-3 py-2 text-sm text-gray-900 dark:text-white focus:outline-none focus:border-blue-500 pl-8"
                />
                <Layers className="w-4 h-4 text-purple-500 absolute left-2.5 top-2.5" />
              </div>
            </div>

            <div>
              <label className="text-xs font-medium text-gray-700 dark:text-gray-300 flex items-center justify-between mb-1">
                <span>{t("diskGb")}</span>
                <span className="text-[10px] text-gray-400">
                  {t("avail")}: {available?.disk_gb === -1 ? "∞" : (available?.disk_gb ?? "-")}
                </span>
              </label>
              <div className="relative">
                <input
                  type="number"
                  min={5}
                  max={available?.disk_gb !== undefined && available.disk_gb > 0 ? available.disk_gb : 2000}
                  value={diskGb}
                  onChange={(e) => setDiskGb(Math.max(5, parseInt(e.target.value) || 5))}
                  className="w-full bg-gray-50 dark:bg-gray-950 border border-gray-200 dark:border-gray-800 rounded-xl px-3 py-2 text-sm text-gray-900 dark:text-white focus:outline-none focus:border-blue-500 pl-8"
                />
                <HardDrive className="w-4 h-4 text-amber-500 absolute left-2.5 top-2.5" />
              </div>
            </div>
          </div>

          {/* Footer Action Buttons */}
          <div className="flex items-center justify-end space-x-3 pt-4 border-t border-gray-200 dark:border-gray-800">
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
              disabled={loading || !name || isNameDuplicate}
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
