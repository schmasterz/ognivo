"use client";

import React, { useState, useEffect, useCallback, useMemo } from "react";
import {
  Camera,
  RotateCcw,
  Trash2,
  Loader2,
  AlertCircle,
  RefreshCw,
  Search,
  Server,
  User as UserIcon,
} from "lucide-react";
import { VirtualMachine, User, VmSnapshot, api, ApiError } from "@/lib/api";
import { useI18n } from "@/lib/i18n/I18nContext";
import { useToast } from "@/lib/toast/ToastContext";
import { formatBytes } from "@/lib/utils";
import { getEcho } from "@/lib/echo";
import { ConfirmModal } from "@/components/ui/ConfirmModal";
import { PaginationBar } from "@/components/ui/PaginationBar";

interface SnapshotsTabProps {
  isAdmin: boolean;
  currentUser: User | null;
  vms: VirtualMachine[];
  selectedUserId?: number | string | null;
  onOpenVmDetails?: (vm: VirtualMachine, initialTab?: string) => void;
}

export function SnapshotsTab({
  isAdmin,
  currentUser,
  vms,
  selectedUserId,
  onOpenVmDetails,
}: SnapshotsTabProps) {
  const { t } = useI18n();
  const { toast } = useToast();

  const [snapshots, setSnapshots] = useState<VmSnapshot[]>([]);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);

  // Filters
  const [searchQuery, setSearchQuery] = useState("");
  const [selectedVmId, setSelectedVmId] = useState<string>("");
  const [selectedStatus, setSelectedStatus] = useState<string>("");

  // Pagination state
  const [page, setPage] = useState<number>(1);
  const [perPage, setPerPage] = useState<number>(10);
  const [paginationMeta, setPaginationMeta] = useState<{
    currentPage: number;
    lastPage: number;
    total: number;
  }>({
    currentPage: 1,
    lastPage: 1,
    total: 0,
  });

  // Available VMs for Target Instance filter (untruncated across pages)
  const [availableVms, setAvailableVms] = useState<VirtualMachine[] | null>(null);

  const resolvedUserId = useMemo(() => {
    if (!isAdmin || !selectedUserId) return undefined;
    const parsed =
      typeof selectedUserId === "number"
        ? selectedUserId
        : parseInt(String(selectedUserId), 10);
    return Number.isNaN(parsed) || parsed <= 0 ? undefined : parsed;
  }, [isAdmin, selectedUserId]);

  const [prevUserId, setPrevUserId] = useState<number | undefined>(resolvedUserId);
  if (prevUserId !== resolvedUserId) {
    setPrevUserId(resolvedUserId);
    setPage(1);
    setSelectedVmId("");
  }

  useEffect(() => {
    let cancelled = false;
    api
      .getVms({
        per_page: 100,
        user_id: resolvedUserId,
      })
      .then((res) => {
        if (!cancelled) {
          setAvailableVms(res.data);
        }
      })
      .catch((err: unknown) => {
        console.error("Failed to fetch VMs for snapshot filter:", err);
      });

    return () => {
      cancelled = true;
    };
  }, [resolvedUserId]);

  const vmOptions = availableVms ?? vms;

  // Modals state
  const [restoreTarget, setRestoreTarget] = useState<VmSnapshot | null>(null);
  const [deleteTarget, setDeleteTarget] = useState<VmSnapshot | null>(null);
  const [actionLoading, setActionLoading] = useState(false);

  // Fetch snapshots list
  const fetchSnapshots = useCallback(
    async (showLoading = true) => {
      try {
        if (showLoading) setLoading(true);
        else setRefreshing(true);

        const params: {
          vm_id?: number;
          status?: string;
          search?: string;
          user_id?: number;
          page?: number;
          per_page?: number;
        } = {
          user_id: resolvedUserId,
          page,
          per_page: perPage,
        };

        if (selectedVmId) {
          params.vm_id = parseInt(selectedVmId, 10);
        }
        if (selectedStatus) {
          params.status = selectedStatus;
        }
        if (searchQuery.trim()) {
          params.search = searchQuery.trim();
        }

        const res = await api.getAllSnapshots(params);
        setSnapshots(res.data);
        const total = res.total ?? res.meta?.total ?? res.data.length;
        const lastPage = Math.max(1, res.last_page ?? res.meta?.last_page ?? 1);
        const currentPage = res.current_page ?? res.meta?.current_page ?? page;
        setPaginationMeta({
          currentPage,
          lastPage,
          total,
        });
        if (page > lastPage) {
          setPage(lastPage);
        }
      } catch (err: unknown) {
        console.error("Failed to fetch snapshots list:", err);
      } finally {
        setLoading(false);
        setRefreshing(false);
      }
    },
    [selectedVmId, selectedStatus, searchQuery, resolvedUserId, page, perPage]
  );

  useEffect(() => {
    fetchSnapshots(true);
  }, [fetchSnapshots]);

  // Real-time WebSocket updates via Laravel Echo
  useEffect(() => {
    const echo = getEcho();
    if (!echo) return;

    interface SnapshotEventPayload {
      snapshot: VmSnapshot;
      virtual_machine_id: number;
      user_id: number;
      status: string;
    }

    const handleSnapshotStatusUpdated = (event: SnapshotEventPayload) => {
      const updatedSnapshot = event.snapshot;
      const statusLower = (event.status || updatedSnapshot?.status || "").toLowerCase();

      setSnapshots((prev) => {
        const exists = prev.some((s) => s.id === updatedSnapshot?.id);

        if (statusLower === "deleted" || statusLower === "destroyed") {
          if (exists) {
            setPaginationMeta((m) => ({
              ...m,
              total: Math.max(0, m.total - 1),
            }));
          }
          return prev.filter((s) => s.id !== updatedSnapshot?.id);
        }

        if (exists) {
          return prev.map((s) =>
            s.id === updatedSnapshot?.id
              ? {
                  ...s,
                  ...updatedSnapshot,
                  status: statusLower || s.status,
                }
              : s
          );
        }

        if (updatedSnapshot && page === 1) {
          if (resolvedUserId && Number(event.user_id) !== resolvedUserId) {
            return prev;
          }
          if (selectedVmId && Number(event.virtual_machine_id) !== parseInt(selectedVmId, 10)) {
            return prev;
          }
          setPaginationMeta((m) => ({
            ...m,
            total: m.total + 1,
          }));
          return [updatedSnapshot, ...prev].slice(0, perPage);
        }

        return prev;
      });
    };

    const channels: string[] = [];
    if (currentUser?.id) {
      const userChannelName = `users.${currentUser.id}`;
      channels.push(userChannelName);
      const userChannel = echo.private(userChannelName);
      userChannel.listen(".SnapshotStatusUpdated", handleSnapshotStatusUpdated);
      userChannel.listen("SnapshotStatusUpdated", handleSnapshotStatusUpdated);
    }

    if (isAdmin) {
      const adminChannelName = "admin.workloads";
      channels.push(adminChannelName);
      const adminChannel = echo.private(adminChannelName);
      adminChannel.listen(".SnapshotStatusUpdated", handleSnapshotStatusUpdated);
      adminChannel.listen("SnapshotStatusUpdated", handleSnapshotStatusUpdated);
    }

    return () => {
      channels.forEach((ch) => {
        try {
          const channel = echo.private(ch);
          channel.stopListening(".SnapshotStatusUpdated");
          channel.stopListening("SnapshotStatusUpdated");
        } catch {
          // ignore cleanup issues
        }
      });
    };
  }, [currentUser?.id, isAdmin, page, perPage, resolvedUserId, selectedVmId]);

  // Handle Restore Snapshot
  const handleConfirmRestore = async () => {
    if (!restoreTarget) return;

    const vmId = restoreTarget.virtual_machine_id || restoreTarget.virtual_machine?.id;
    if (!vmId) {
      toast.error(t("snapshotRestoreError"));
      return;
    }

    setActionLoading(true);
    try {
      const res = await api.restoreSnapshot(vmId, restoreTarget.id);
      toast.success(res.message || t("snapshotRestoreSuccess"));

      // Optimistically update snapshot state to restoring
      setSnapshots((prev) =>
        prev.map((s) => (s.id === restoreTarget.id ? { ...s, status: "restoring" } : s))
      );
      setRestoreTarget(null);
    } catch (err: unknown) {
      if (err instanceof ApiError) {
        toast.error(err.message || t("snapshotRestoreError"));
      } else if (err instanceof Error) {
        toast.error(err.message);
      } else {
        toast.error(t("snapshotRestoreError"));
      }
    } finally {
      setActionLoading(false);
    }
  };

  // Handle Delete Snapshot
  const handleConfirmDelete = async () => {
    if (!deleteTarget) return;

    const vmId = deleteTarget.virtual_machine_id || deleteTarget.virtual_machine?.id;
    if (!vmId) {
      toast.error(t("snapshotDeleteError"));
      return;
    }

    setActionLoading(true);
    try {
      const res = await api.deleteSnapshot(vmId, deleteTarget.id);
      toast.success(res.message || t("snapshotDeleteSuccess"));

      // Optimistically update snapshot state to deleting
      setSnapshots((prev) =>
        prev.map((s) => (s.id === deleteTarget.id ? { ...s, status: "deleting" } : s))
      );
      setDeleteTarget(null);
    } catch (err: unknown) {
      if (err instanceof ApiError) {
        toast.error(err.message || t("snapshotDeleteError"));
      } else if (err instanceof Error) {
        toast.error(err.message);
      } else {
        toast.error(t("snapshotDeleteError"));
      }
    } finally {
      setActionLoading(false);
    }
  };

  const getStatusBadge = (status: string, error?: string | null) => {
    switch (status?.toLowerCase()) {
      case "ready":
        return (
          <span className="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border border-emerald-500/20">
            <span className="w-1.5 h-1.5 rounded-full bg-emerald-500 mr-1.5" />
            {t("snapshotStatusReady")}
          </span>
        );
      case "creating":
        return (
          <span className="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-amber-500/10 text-amber-600 dark:text-amber-400 border border-amber-500/20">
            <Loader2 className="w-3 h-3 mr-1.5 animate-spin" />
            {t("snapshotStatusCreating")}
          </span>
        );
      case "restoring":
        return (
          <span className="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 border border-indigo-500/20">
            <Loader2 className="w-3 h-3 mr-1.5 animate-spin" />
            {t("snapshotStatusRestoring")}
          </span>
        );
      case "deleting":
        return (
          <span className="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-red-500/10 text-red-600 dark:text-red-400 border border-red-500/20">
            <Loader2 className="w-3 h-3 mr-1.5 animate-spin" />
            {t("snapshotStatusDeleting")}
          </span>
        );
      case "failed":
        return (
          <span
            title={error || undefined}
            className="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-red-500/10 text-red-600 dark:text-red-400 border border-red-500/20 cursor-help"
          >
            <AlertCircle className="w-3 h-3 mr-1.5" />
            {t("snapshotStatusFailed")}
          </span>
        );
      default:
        return (
          <span className="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-zinc-100 dark:bg-zinc-800 text-zinc-600 dark:text-zinc-400 border border-zinc-200 dark:border-zinc-700">
            {status || "Unknown"}
          </span>
        );
    }
  };

  const totalSizeBytes = useMemo(() => {
    return snapshots.reduce((acc, s) => acc + (s.size_bytes || 0), 0);
  }, [snapshots]);

  return (
    <div className="space-y-6">
      {/* Header and Controls */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 p-5 rounded-3xl bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 shadow-sm">
        <div>
          <h2 className="text-lg font-bold text-gray-900 dark:text-white tracking-tight flex items-center space-x-2">
            <Camera className="w-5 h-5 text-blue-600 dark:text-blue-400" />
            <span>{t("snapshotsGlobalTitle")}</span>
          </h2>
          <p className="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
            {t("snapshotsGlobalSubtitle")}
          </p>
        </div>

        <div className="flex items-center space-x-3">
          {/* Storage & Count Metrics */}
          <div className="flex items-center space-x-2 px-3 py-1.5 rounded-xl bg-gray-50 dark:bg-gray-800/60 border border-gray-200 dark:border-gray-700 text-xs text-gray-600 dark:text-gray-300 font-mono">
            <span className="font-semibold text-gray-900 dark:text-white">
              {paginationMeta.total > 0 ? paginationMeta.total : snapshots.length}
            </span>
            <span className="text-gray-400">{t("snapshotsUnit")}</span>
            <span className="text-gray-300 dark:text-gray-600">•</span>
            <span className="font-semibold text-gray-900 dark:text-white">{formatBytes(totalSizeBytes)}</span>
          </div>

          <button
            onClick={() => fetchSnapshots(false)}
            disabled={loading || refreshing}
            title="Refresh Snapshots"
            className="p-2 rounded-xl text-gray-500 hover:text-gray-900 dark:text-gray-400 dark:hover:text-white hover:bg-gray-100 dark:hover:bg-gray-800 border border-gray-200 dark:border-gray-700 transition disabled:opacity-50"
          >
            <RefreshCw className={`w-4 h-4 ${refreshing ? "animate-spin text-blue-500" : ""}`} />
          </button>
        </div>
      </div>

      {/* Filter Toolbar */}
      <div className="flex flex-col sm:flex-row gap-3">
        {/* Search Input */}
        <div className="relative flex-1">
          <Search className="w-4 h-4 text-gray-400 absolute left-3 top-1/2 -translate-y-1/2" />
          <input
            type="text"
            value={searchQuery}
            onChange={(e) => {
              setSearchQuery(e.target.value);
              setPage(1);
            }}
            placeholder="Search snapshots or instances..."
            className="w-full pl-9 pr-4 py-2 rounded-2xl bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 text-xs text-gray-900 dark:text-white placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition"
          />
        </div>

        {/* Filter by Target Instance */}
        <div className="w-full sm:w-56">
          <select
            value={selectedVmId}
            onChange={(e) => {
              setSelectedVmId(e.target.value);
              setPage(1);
            }}
            className="w-full px-3 py-2 rounded-2xl bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 text-xs text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition"
          >
            <option value="">{t("filterAllVms")}</option>
            {vmOptions.map((vm) => (
              <option key={vm.id} value={vm.id}>
                {vm.name}
              </option>
            ))}
          </select>
        </div>

        {/* Filter by Status */}
        <div className="w-full sm:w-44">
          <select
            value={selectedStatus}
            onChange={(e) => {
              setSelectedStatus(e.target.value);
              setPage(1);
            }}
            className="w-full px-3 py-2 rounded-2xl bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 text-xs text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition"
          >
            <option value="">{t("filterAllStatuses")}</option>
            <option value="ready">{t("snapshotStatusReady")}</option>
            <option value="creating">{t("snapshotStatusCreating")}</option>
            <option value="restoring">{t("snapshotStatusRestoring")}</option>
            <option value="deleting">{t("snapshotStatusDeleting")}</option>
            <option value="failed">{t("snapshotStatusFailed")}</option>
          </select>
        </div>
      </div>

      {/* Snapshots Table */}
      <div className="rounded-3xl bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">
        {loading ? (
          <div className="p-12 text-center text-gray-400">
            <Loader2 className="w-8 h-8 animate-spin mx-auto mb-3 text-blue-500" />
            <p className="text-xs">{t("loadingSnapshots")}</p>
          </div>
        ) : snapshots.length === 0 ? (
          <div className="p-12 text-center">
            <div className="w-12 h-12 rounded-2xl bg-gray-100 dark:bg-gray-800 flex items-center justify-center mx-auto mb-3 text-gray-400">
              <Camera className="w-6 h-6" />
            </div>
            <h3 className="text-sm font-semibold text-gray-900 dark:text-white">
              {t("noGlobalSnapshotsTitle")}
            </h3>
            <p className="text-xs text-gray-500 dark:text-gray-400 mt-1 max-w-sm mx-auto">
              {t("noGlobalSnapshotsDesc")}
            </p>
          </div>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-left text-xs">
              <thead className="bg-gray-50 dark:bg-gray-800/50 text-[11px] uppercase tracking-wider text-gray-400 border-b border-gray-200 dark:border-gray-800">
                <tr>
                  <th className="px-6 py-3.5 font-semibold">{t("colSnapshotName")}</th>
                  <th className="px-6 py-3.5 font-semibold">{t("colInstance")}</th>
                  {isAdmin && <th className="px-6 py-3.5 font-semibold">{t("colOwner")}</th>}
                  <th className="px-6 py-3.5 font-semibold">{t("colSnapshotSource")}</th>
                  <th className="px-6 py-3.5 font-semibold">{t("colSnapshotSize")}</th>
                  <th className="px-6 py-3.5 font-semibold">{t("colSnapshotStatus")}</th>
                  <th className="px-6 py-3.5 font-semibold">{t("colSnapshotCreated")}</th>
                  <th className="px-6 py-3.5 font-semibold text-right">{t("colSnapshotActions")}</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-100 dark:divide-gray-800">
                {snapshots.map((snap) => {
                  const targetVm =
                    vmOptions.find((v) => v.id === snap.virtual_machine_id) ||
                    vms.find((v) => v.id === snap.virtual_machine_id) ||
                    snap.virtual_machine;
                  const isTransitioning = ["creating", "restoring", "deleting"].includes(
                    snap.status?.toLowerCase() || ""
                  );
                  const isReady = snap.status?.toLowerCase() === "ready";

                  return (
                    <tr
                      key={snap.id}
                      className="hover:bg-gray-50 dark:hover:bg-gray-800/40 transition-colors"
                    >
                      {/* Name & Flags */}
                      <td className="px-6 py-4 font-mono font-medium text-gray-900 dark:text-white">
                        <div className="flex items-center space-x-2">
                          <Camera className="w-3.5 h-3.5 text-blue-500 flex-shrink-0" />
                          <span>{snap.name}</span>
                          {snap.stateful && (
                            <span
                              title={t("snapshotStatefulTooltip")}
                              className="px-1.5 py-0.5 rounded text-[10px] font-sans font-semibold bg-purple-500/10 text-purple-600 dark:text-purple-400 border border-purple-500/20"
                            >
                              {t("snapshotStatefulBadge")}
                            </span>
                          )}
                        </div>
                      </td>

                      {/* Target Instance */}
                      <td className="px-6 py-4">
                        {targetVm ? (
                          <button
                            type="button"
                            onClick={() => {
                              const found =
                                vmOptions.find((v) => v.id === snap.virtual_machine_id) ||
                                vms.find((v) => v.id === snap.virtual_machine_id);
                              if (found && onOpenVmDetails) {
                                onOpenVmDetails(found, "snapshots");
                              }
                            }}
                            className="inline-flex items-center space-x-1.5 text-blue-600 dark:text-blue-400 hover:underline cursor-pointer"
                          >
                            <Server className="w-3.5 h-3.5 flex-shrink-0" />
                            <span className="font-semibold">{targetVm.name}</span>
                          </button>
                        ) : (
                          <span className="text-gray-400 font-mono">
                            ID: {snap.virtual_machine_id || "—"}
                          </span>
                        )}
                      </td>

                      {/* Tenant User (Admin only) */}
                      {isAdmin && (
                        <td className="px-6 py-4 text-gray-600 dark:text-gray-300">
                          {snap.user ? (
                            <div className="flex items-center space-x-1.5">
                              <UserIcon className="w-3 h-3 text-gray-400" />
                              <span title={snap.user.email}>{snap.user.name}</span>
                            </div>
                          ) : (
                            <span className="text-gray-400">—</span>
                          )}
                        </td>
                      )}

                      {/* Source */}
                      <td className="px-6 py-4 text-gray-500 dark:text-gray-400 capitalize">
                        {snap.source === "scheduled"
                          ? t("snapshotSourceScheduled")
                          : t("snapshotSourceManual")}
                      </td>

                      {/* Size */}
                      <td className="px-6 py-4 font-mono text-gray-600 dark:text-gray-300">
                        {formatBytes(snap.size_bytes)}
                      </td>

                      {/* Status Badge */}
                      <td className="px-6 py-4">{getStatusBadge(snap.status, snap.error)}</td>

                      {/* Created At */}
                      <td className="px-6 py-4 text-gray-500 dark:text-gray-400 whitespace-nowrap">
                        {snap.created_at ? new Date(snap.created_at).toLocaleString() : "—"}
                      </td>

                      {/* Actions */}
                      <td className="px-6 py-4 text-right">
                        <div className="flex items-center justify-end space-x-1.5">
                          {/* Restore Button */}
                          <button
                            onClick={() => setRestoreTarget(snap)}
                            disabled={!isReady || isTransitioning || actionLoading}
                            title={t("confirmRestoreSnapshotBtn")}
                            className="p-1.5 rounded-lg bg-gray-100 hover:bg-indigo-500/20 dark:bg-gray-800 dark:hover:bg-indigo-500/20 text-gray-600 dark:text-gray-300 hover:text-indigo-600 dark:hover:text-indigo-400 transition disabled:opacity-40 disabled:cursor-not-allowed"
                          >
                            <RotateCcw className="w-3.5 h-3.5" />
                          </button>

                          {/* Delete Button */}
                          <button
                            onClick={() => setDeleteTarget(snap)}
                            disabled={isTransitioning || actionLoading}
                            title={t("confirmDeleteSnapshotBtn")}
                            className="p-1.5 rounded-lg bg-gray-100 hover:bg-red-500/20 dark:bg-gray-800 dark:hover:bg-red-500/20 text-gray-600 dark:text-gray-300 hover:text-red-600 dark:hover:text-red-400 transition disabled:opacity-40 disabled:cursor-not-allowed"
                          >
                            <Trash2 className="w-3.5 h-3.5" />
                          </button>
                        </div>
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        )}

        {!loading && snapshots.length > 0 && (
          <PaginationBar
            currentPage={paginationMeta.currentPage}
            lastPage={paginationMeta.lastPage}
            perPage={perPage}
            total={paginationMeta.total}
            onPageChange={setPage}
            onPerPageChange={(newPerPage) => {
              setPerPage(newPerPage);
              setPage(1);
            }}
          />
        )}
      </div>

      {/* Restore Confirmation Dialog */}
      <ConfirmModal
        isOpen={!!restoreTarget}
        title={t("confirmRestoreSnapshotTitle")}
        message={t("confirmRestoreSnapshotMessage")}
        confirmText={t("confirmRestoreSnapshotBtn")}
        cancelText={t("cancel")}
        variant="warning"
        isLoading={actionLoading}
        onConfirm={handleConfirmRestore}
        onClose={() => setRestoreTarget(null)}
      />

      {/* Delete Confirmation Dialog */}
      <ConfirmModal
        isOpen={!!deleteTarget}
        title={t("confirmDeleteSnapshotTitle")}
        message={t("confirmDeleteSnapshotMessage")}
        confirmText={t("confirmDeleteSnapshotBtn")}
        cancelText={t("cancel")}
        variant="danger"
        isLoading={actionLoading}
        onConfirm={handleConfirmDelete}
        onClose={() => setDeleteTarget(null)}
      />
    </div>
  );
}
