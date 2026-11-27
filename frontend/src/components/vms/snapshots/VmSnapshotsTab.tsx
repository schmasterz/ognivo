"use client";

import React, { useState, useEffect, useCallback, useMemo, useRef } from "react";
import {
  Camera,
  RotateCcw,
  Trash2,
  Loader2,
  AlertCircle,
  Plus,
  RefreshCw,
  HardDrive,
  Clock,
} from "lucide-react";
import { VirtualMachine, User, VmSnapshot, VmSnapshotMeta, api, ApiError } from "@/lib/api";
import { useI18n } from "@/lib/i18n/I18nContext";
import { useToast } from "@/lib/toast/ToastContext";
import { formatBytes } from "@/lib/utils";
import { getEcho } from "@/lib/echo";
import { ConfirmModal } from "@/components/ui/ConfirmModal";
import { PaginationBar } from "@/components/ui/PaginationBar";
import { CreateSnapshotModal } from "./CreateSnapshotModal";

interface VmSnapshotsTabProps {
  vm: VirtualMachine;
  user: User | null;
  isAdmin: boolean;
  onVmUpdated?: (vm: VirtualMachine) => void;
}

export function VmSnapshotsTab({ vm, user, isAdmin }: VmSnapshotsTabProps) {
  const { t } = useI18n();
  const { toast } = useToast();

  const [snapshots, setSnapshots] = useState<VmSnapshot[]>([]);
  const [meta, setMeta] = useState<VmSnapshotMeta>({
    used: 0,
    max_snapshots: -1,
    remaining: -1,
  });
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);

  // Client-side pagination state
  const [page, setPage] = useState<number>(1);
  const [perPage, setPerPage] = useState<number>(10);

  // Modals state
  const [createModalOpen, setCreateModalOpen] = useState(false);
  const [restoreTarget, setRestoreTarget] = useState<VmSnapshot | null>(null);
  const [deleteTarget, setDeleteTarget] = useState<VmSnapshot | null>(null);
  const [actionLoading, setActionLoading] = useState(false);

  const lastPage = Math.max(1, Math.ceil(snapshots.length / perPage));
  const safePage = Math.min(page, lastPage);

  const paginatedSnapshots = useMemo(() => {
    const start = (safePage - 1) * perPage;
    return snapshots.slice(start, start + perPage);
  }, [snapshots, safePage, perPage]);

  const isTransitioningAny = useMemo(() => {
    return snapshots.some((s) =>
      ["creating", "restoring", "deleting"].includes(s.status?.toLowerCase() || "")
    );
  }, [snapshots]);

  // Fetch snapshots list and metadata
  const fetchSnapshots = useCallback(
    async (showLoading = true) => {
      try {
        if (showLoading) setLoading(true);
        else setRefreshing(true);

        const res = await api.getSnapshots(vm.id);
        setSnapshots(res.data);
        if (res.meta) {
          setMeta(res.meta);
        }
      } catch (err: unknown) {
        console.error("Failed to fetch VM snapshots:", err);
      } finally {
        setLoading(false);
        setRefreshing(false);
      }
    },
    [vm.id]
  );

  useEffect(() => {
    let ignore = false;
    queueMicrotask(() => {
      if (!ignore) {
        void fetchSnapshots(true);
      }
    });
    return () => {
      ignore = true;
    };
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
      if (Number(event?.virtual_machine_id) !== Number(vm.id)) {
        return;
      }

      const updatedSnapshot = event.snapshot;
      const statusLower = (event.status || updatedSnapshot?.status || "").toLowerCase();

      setSnapshots((prev) => {
        if (statusLower === "deleted") {
          return prev.filter((s) => s.id !== updatedSnapshot.id && s.name !== updatedSnapshot.name);
        }

        const index = prev.findIndex(
          (s) => s.id === updatedSnapshot.id || (s.name && s.name === updatedSnapshot.name)
        );

        if (index !== -1) {
          const next = [...prev];
          next[index] = { ...next[index], ...updatedSnapshot };
          return next;
        }

        return [updatedSnapshot, ...prev];
      });

      // Refetch metadata silently to guarantee quota count accuracy
      fetchSnapshots(false);
    };

    const channels: string[] = [];
    if (user) {
      const userChannelName = `users.${user.id}`;
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
  }, [vm.id, user, isAdmin, fetchSnapshots]);

  // Light polling fallback while any snapshot is transitioning
  const pollingTimerRef = useRef<NodeJS.Timeout | null>(null);

  useEffect(() => {
    if (isTransitioningAny) {
      pollingTimerRef.current = setInterval(() => {
        fetchSnapshots(false);
      }, 4000);
    } else if (pollingTimerRef.current) {
      clearInterval(pollingTimerRef.current);
      pollingTimerRef.current = null;
    }

    return () => {
      if (pollingTimerRef.current) {
        clearInterval(pollingTimerRef.current);
        pollingTimerRef.current = null;
      }
    };
  }, [isTransitioningAny, fetchSnapshots]);

  // Handle Restore Snapshot
  const handleConfirmRestore = async () => {
    if (!restoreTarget) return;

    setActionLoading(true);
    try {
      const res = await api.restoreSnapshot(vm.id, restoreTarget.id);
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

    setActionLoading(true);
    try {
      const res = await api.deleteSnapshot(vm.id, deleteTarget.id);
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
    switch (status.toLowerCase()) {
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
            {status}
          </span>
        );
    }
  };

  const isUnlimited = meta.max_snapshots === -1;
  const isQuotaReached = !isUnlimited && meta.remaining <= 0;
  const isHostUnavailable = Boolean(vm.host && vm.host.status !== "ok");
  const hostUnavailableTooltip =
    vm.host?.status === "unreachable" ? t("hostUnreachableTooltip") : t("hostUnavailableTooltip");

  return (
    <div className="space-y-4">
      {/* Action Bar & Quota Status */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 p-4 rounded-xl bg-zinc-50 dark:bg-zinc-800/40 border border-zinc-200 dark:border-zinc-800">
        <div>
          <h4 className="text-sm font-semibold text-zinc-900 dark:text-zinc-100 flex items-center space-x-2">
            <span>{t("snapshotsTitle")}</span>
          </h4>
          <p className="text-xs text-zinc-500 dark:text-zinc-400 mt-0.5">
            {t("snapshotsDesc")}
          </p>
        </div>

        <div className="flex items-center space-x-3">
          {/* Quota Counter Pill */}
          <div
            className={`px-3 py-1.5 rounded-lg text-xs font-mono border flex items-center space-x-2 ${
              isQuotaReached
                ? "bg-red-500/10 text-red-600 dark:text-red-400 border-red-500/20"
                : "bg-white dark:bg-zinc-900 text-zinc-700 dark:text-zinc-300 border-zinc-200 dark:border-zinc-800"
            }`}
          >
            <span className="text-zinc-400 font-sans text-[11px]">{t("snapshotQuota")}:</span>
            <span className="font-semibold">{meta.used}</span>
            <span className="text-zinc-400">/</span>
            <span>{isUnlimited ? t("snapshotQuotaUnlimited") : meta.max_snapshots}</span>
          </div>

          {/* Refresh Button */}
          <button
            onClick={() => fetchSnapshots(false)}
            disabled={loading || refreshing}
            title="Refresh Snapshots"
            className="p-2 rounded-lg text-zinc-500 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-100 hover:bg-zinc-100 dark:hover:bg-zinc-800 border border-zinc-200 dark:border-zinc-700/50 transition disabled:opacity-50"
          >
            <RefreshCw className={`w-4 h-4 ${refreshing ? "animate-spin" : ""}`} />
          </button>

          {/* Create Snapshot Button */}
          <button
            onClick={() => setCreateModalOpen(true)}
            disabled={isQuotaReached || isTransitioningAny || isHostUnavailable}
            title={
              isHostUnavailable
                ? hostUnavailableTooltip
                : isQuotaReached
                ? t("snapshotQuotaExceeded")
                : isTransitioningAny
                ? t("snapshotActionInProgress")
                : undefined
            }
            className="flex items-center space-x-1.5 px-3.5 py-1.5 rounded-lg text-xs font-medium bg-blue-600 hover:bg-blue-500 text-white shadow-md shadow-blue-600/20 transition disabled:opacity-50 disabled:cursor-not-allowed"
          >
            <Plus className="w-3.5 h-3.5" />
            <span>{t("createSnapshot")}</span>
          </button>
        </div>
      </div>

      {/* Snapshots Table */}
      <div className="border border-zinc-200 dark:border-zinc-800 rounded-xl overflow-hidden bg-white dark:bg-zinc-900">
        <div className="overflow-x-auto relative">
          <table className="w-full text-left text-xs text-zinc-600 dark:text-zinc-300">
            <thead className="bg-zinc-50 dark:bg-zinc-800/60 border-b border-zinc-200 dark:border-zinc-800 text-[11px] uppercase font-semibold text-zinc-500 dark:text-zinc-400 tracking-wider">
              <tr>
                <th className="px-4 py-3">{t("colSnapshotName")}</th>
                <th className="px-4 py-3">{t("colSnapshotStatus")}</th>
                <th className="px-4 py-3">{t("colSnapshotSize")}</th>
                <th className="px-4 py-3">{t("colSnapshotSource")}</th>
                <th className="px-4 py-3">{t("colSnapshotCreated")}</th>
                <th className="sticky right-0 z-20 px-4 py-3 text-right bg-zinc-50 dark:bg-zinc-800 border-l border-zinc-200 dark:border-zinc-800 shadow-[-4px_0_6px_-2px_rgba(0,0,0,0.05)] dark:shadow-[-4px_0_6px_-2px_rgba(0,0,0,0.3)]">{t("colSnapshotActions")}</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-zinc-200 dark:divide-zinc-800">
              {loading ? (
                <tr>
                  <td colSpan={6} className="py-12 text-center text-zinc-400">
                    <Loader2 className="w-6 h-6 animate-spin mx-auto mb-2 text-blue-500" />
                    <span>{t("loadingSnapshots")}</span>
                  </td>
                </tr>
              ) : snapshots.length === 0 ? (
                <tr>
                  <td colSpan={6} className="py-12 text-center">
                    <div className="flex flex-col items-center justify-center space-y-2">
                      <div className="p-3 rounded-full bg-zinc-100 dark:bg-zinc-800 text-zinc-400">
                        <Camera className="w-6 h-6" />
                      </div>
                      <p className="text-sm font-medium text-zinc-800 dark:text-zinc-200">
                        {t("noSnapshotsTitle")}
                      </p>
                      <p className="text-xs text-zinc-500 dark:text-zinc-400 max-w-sm">
                        {t("noSnapshotsDesc")}
                      </p>
                    </div>
                  </td>
                </tr>
              ) : (
                paginatedSnapshots.map((snapshot) => {
                  const status = (snapshot.status || "ready").toLowerCase();
                  const isTransitioning = ["creating", "restoring", "deleting"].includes(status);
                  const isReady = status === "ready";

                  return (
                    <tr
                      key={snapshot.id || snapshot.name}
                      className="hover:bg-zinc-50 dark:hover:bg-zinc-800/40 transition group"
                    >
                      {/* Name */}
                      <td className="px-4 py-3.5 font-medium text-zinc-900 dark:text-zinc-100 whitespace-nowrap">
                        <div className="flex items-center space-x-2">
                          <Camera className="w-4 h-4 text-blue-500 shrink-0" />
                          <span className="font-mono">{snapshot.name}</span>
                        </div>
                      </td>

                      {/* Status */}
                      <td className="px-4 py-3.5 whitespace-nowrap">
                        {getStatusBadge(snapshot.status, snapshot.error)}
                      </td>

                      {/* Size */}
                      <td className="px-4 py-3.5 font-mono text-zinc-500 dark:text-zinc-400 whitespace-nowrap">
                        <div className="flex items-center space-x-1.5">
                          <HardDrive className="w-3.5 h-3.5 text-zinc-400" />
                          <span>{formatBytes(snapshot.size_bytes)}</span>
                        </div>
                      </td>

                      {/* Source */}
                      <td className="px-4 py-3.5 whitespace-nowrap">
                        <span className="inline-flex items-center px-2 py-0.5 rounded text-[11px] font-medium bg-zinc-100 dark:bg-zinc-800 text-zinc-600 dark:text-zinc-400 border border-zinc-200 dark:border-zinc-700/50">
                          {snapshot.source === "scheduled"
                            ? t("snapshotSourceScheduled")
                            : t("snapshotSourceManual")}
                        </span>
                      </td>

                      {/* Created At */}
                      <td className="px-4 py-3.5 text-zinc-500 dark:text-zinc-400 whitespace-nowrap">
                        <div className="flex items-center space-x-1.5 text-xs">
                          <Clock className="w-3.5 h-3.5 text-zinc-400 shrink-0" />
                          <span>
                            {snapshot.created_at
                              ? new Date(snapshot.created_at).toLocaleString()
                              : "—"}
                          </span>
                        </div>
                      </td>

                      {/* Actions */}
                      <td className="sticky right-0 z-10 px-4 py-3.5 text-right bg-white dark:bg-zinc-900 group-hover:bg-zinc-50 dark:group-hover:bg-zinc-800 border-l border-zinc-200/80 dark:border-zinc-800/80 shadow-[-4px_0_6px_-2px_rgba(0,0,0,0.05)] dark:shadow-[-4px_0_6px_-2px_rgba(0,0,0,0.3)] transition-colors">
                        <div className="flex items-center justify-end space-x-1.5">
                          {/* Restore Action */}
                          <button
                            onClick={() => setRestoreTarget(snapshot)}
                            disabled={!isReady || isTransitioningAny || actionLoading || isHostUnavailable}
                            title={isHostUnavailable ? hostUnavailableTooltip : t("restoreSnapshot")}
                            className="p-1.5 rounded-lg bg-zinc-100 hover:bg-indigo-500/20 dark:bg-zinc-800 dark:hover:bg-indigo-500/20 text-zinc-600 dark:text-zinc-300 hover:text-indigo-600 dark:hover:text-indigo-400 transition disabled:opacity-40 disabled:cursor-not-allowed"
                          >
                            <RotateCcw className="w-3.5 h-3.5" />
                          </button>

                          {/* Delete Action */}
                          <button
                            onClick={() => setDeleteTarget(snapshot)}
                            disabled={isTransitioning || isTransitioningAny || actionLoading}
                            title={t("deleteSnapshot")}
                            className="p-1.5 rounded-lg bg-zinc-100 hover:bg-red-500/20 dark:bg-zinc-800 dark:hover:bg-red-500/20 text-zinc-600 dark:text-zinc-300 hover:text-red-600 dark:hover:text-red-400 transition disabled:opacity-40 disabled:cursor-not-allowed"
                          >
                            <Trash2 className="w-3.5 h-3.5" />
                          </button>
                        </div>
                      </td>
                    </tr>
                  );
                })
              )}
            </tbody>
          </table>
        </div>

        {!loading && snapshots.length > 0 && (
          <PaginationBar
            currentPage={safePage}
            lastPage={lastPage}
            perPage={perPage}
            total={snapshots.length}
            onPageChange={setPage}
            onPerPageChange={(newPerPage) => {
              setPerPage(newPerPage);
              setPage(1);
            }}
          />
        )}
      </div>

      {/* Create Snapshot Modal */}
      <CreateSnapshotModal
        isOpen={createModalOpen}
        onClose={() => setCreateModalOpen(false)}
        onCreated={(newSnapshot) => {
          setSnapshots((prev) => [newSnapshot, ...prev]);
          setPage(1);
          fetchSnapshots(false);
        }}
        vm={vm}
        usedCount={meta.used}
        maxSnapshots={meta.max_snapshots}
        remaining={meta.remaining}
      />

      {/* Restore Confirmation Dialog */}
      <ConfirmModal
        isOpen={!!restoreTarget}
        title={t("confirmRestoreSnapshotTitle")}
        message={t("confirmRestoreSnapshotMessage")}
        confirmText={t("confirmRestoreSnapshotBtn")}
        cancelText={t("close")}
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
        cancelText={t("close")}
        variant="danger"
        isLoading={actionLoading}
        onConfirm={handleConfirmDelete}
        onClose={() => setDeleteTarget(null)}
      />
    </div>
  );
}
