"use client";

import React, { useState, useEffect, useMemo, useCallback } from "react";
import {
  X,
  Terminal,
  Loader2,
  Box,
  Pause,
  AlertCircle,
} from "lucide-react";
import { VirtualMachine, User } from "@/lib/api";
import { vmsApi } from "@/lib/api/vms";
import { useAuth } from "@/lib/auth/AuthContext";
import { useI18n } from "@/lib/i18n/I18nContext";
import { useToast } from "@/lib/toast/ToastContext";
import { ConfirmModal, ConfirmVariant } from "@/components/ui/ConfirmModal";
import { useVmTabs, useVmActions } from "@/lib/registry/vmRegistry";
import { registerVmBuiltins } from "./registerVmBuiltins";

// Ensure built-in tabs (Overview, Snapshots) are registered
registerVmBuiltins();

import { useModalEscape } from "@/lib/hooks/useModalEscape";

interface VmDetailsModalProps {
  isOpen: boolean;
  onClose: () => void;
  vm: VirtualMachine | null;
  user: User | null;
  isAdmin?: boolean;
  initialTab?: string;
  onVmUpdated?: (vm: VirtualMachine) => void;
  onOpenConsole?: (vm: VirtualMachine) => void;
}

export function VmDetailsModal({
  isOpen,
  onClose,
  vm,
  user,
  isAdmin = false,
  initialTab = "overview",
  onVmUpdated,
  onOpenConsole,
}: VmDetailsModalProps) {
  const { t } = useI18n();
  const { toast } = useToast();
  const auth = useAuth();
  const metricsEnabled = Boolean(auth.systemSettings?.metrics_enabled);

  const [confirmDialog, setConfirmDialog] = useState<{
    isOpen: boolean;
    title: string;
    message: string;
    confirmText?: string;
    cancelText?: string;
    variant?: ConfirmVariant;
    isLoading?: boolean;
    onConfirm: () => Promise<void> | void;
  }>({
    isOpen: false,
    title: "",
    message: "",
    onConfirm: () => {},
  });

  const requestConfirm = useCallback(
    (options: {
      title: string;
      message: string;
      confirmText?: string;
      cancelText?: string;
      variant?: ConfirmVariant;
      onConfirm: () => Promise<void> | void;
    }) => {
      setConfirmDialog({
        isOpen: true,
        title: options.title,
        message: options.message,
        confirmText: options.confirmText,
        cancelText: options.cancelText,
        variant: options.variant,
        isLoading: false,
        onConfirm: async () => {
          setConfirmDialog((prev) => ({ ...prev, isLoading: true }));
          try {
            await options.onConfirm();
            setConfirmDialog((prev) => ({ ...prev, isOpen: false, isLoading: false }));
          } catch (err) {
            setConfirmDialog((prev) => ({ ...prev, isLoading: false }));
            throw err;
          }
        },
      });
    },
    []
  );

  const handleActionClick = async (action: (typeof registeredActions)[0]) => {
    if (!vm || vm.host?.status === "unreachable") return;
    if (action.id === "reinstall") {
      requestConfirm({
        title: t("confirmReinstallTitle"),
        message: `${t("confirmReinstall")} "${vm.name}"? ${t("confirmReinstallMessage")}`,
        confirmText: t("confirmReinstallBtn"),
        cancelText: t("cancel"),
        variant: "danger",
        onConfirm: async () => {
          try {
            const res = await vmsApi.action(vm.id, "reinstall");
            toast.success(res.message || t("reinstallSuccess"));
            if (res.virtual_machine && onVmUpdated) {
              onVmUpdated(res.virtual_machine);
            }
          } catch (err: unknown) {
            const msg = err instanceof Error ? err.message : t("reinstallError");
            toast.error(msg);
            throw err;
          }
        },
      });
      return;
    }

    await action.onClick({
      vm,
      user,
      isAdmin,
      requestConfirm,
      onVmUpdated,
    });
  };

  const tabContext = useMemo(
    () => (vm ? { vm, user, isAdmin, metricsEnabled } : undefined),
    [vm, user, isAdmin, metricsEnabled]
  );

  const registeredTabs = useVmTabs(tabContext);
  const registeredActions = useVmActions(tabContext);

  const [activeTabId, setActiveTabId] = useState<string>(initialTab);

  // Sync initialTab when modal opens or initialTab prop changes
  useEffect(() => {
    if (isOpen) {
      queueMicrotask(() => {
        setActiveTabId(initialTab || "overview");
      });
    }
  }, [isOpen, initialTab]);

  // Handle escape key
  useModalEscape(onClose, isOpen);

  if (!isOpen || !vm) return null;

  const isHostUnreachable = vm.host?.status === "unreachable";

  const activeTab = registeredTabs.find((t) => t.id === activeTabId) || registeredTabs[0];

  const getStatusBadge = (status: string) => {
    switch (status.toLowerCase()) {
      case "running":
        return (
          <span className="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border border-emerald-500/20">
            <span className="w-1.5 h-1.5 rounded-full bg-emerald-500 mr-1.5 animate-pulse" />
            Running
          </span>
        );
      case "provisioning":
        return (
          <span className="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium bg-amber-500/10 text-amber-600 dark:text-amber-400 border border-amber-500/20">
            <Loader2 className="w-3 h-3 mr-1.5 animate-spin" />
            Provisioning
          </span>
        );
      case "stopped":
        return (
          <span className="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium bg-zinc-200 dark:bg-zinc-700/30 text-zinc-700 dark:text-zinc-400 border border-zinc-300 dark:border-zinc-700/50">
            <span className="w-1.5 h-1.5 rounded-full bg-zinc-500 mr-1.5" />
            Stopped
          </span>
        );
      case "suspended":
        return (
          <span className="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium bg-amber-500/10 text-amber-600 dark:text-amber-400 border border-amber-500/20">
            <Pause className="w-3 h-3 mr-1.5" />
            {t("statusSuspended")}
          </span>
        );
      default:
        return (
          <span className="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium bg-zinc-100 dark:bg-zinc-800 text-zinc-600 dark:text-zinc-400 border border-zinc-200 dark:border-zinc-700">
            {status}
          </span>
        );
    }
  };

  const getTabLabel = (tab: (typeof registeredTabs)[0]) => {
    if (typeof tab.label === "function") {
      return tab.label({ vm, user, isAdmin, metricsEnabled });
    }
    if (tab.id === "overview") return t("tabOverview");
    if (tab.id === "metrics") return t("tabMetrics");
    if (tab.id === "snapshots") return t("tabSnapshots");
    return tab.label;
  };

  const getActionLabel = (action: (typeof registeredActions)[0]) => {
    if (action.id === "reinstall") return t("reinstall");
    if (typeof action.label === "function") {
      return action.label({ vm, user, isAdmin });
    }
    return action.label;
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-3 sm:p-6 bg-black/60 dark:bg-black/80 backdrop-blur-sm animate-in fade-in duration-200">
      <div className="bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 rounded-2xl w-full max-w-5xl max-h-[90vh] flex flex-col shadow-2xl overflow-hidden">
        {/* Modal Top Header */}
        <div className="px-6 py-4 border-b border-zinc-200 dark:border-zinc-800 bg-zinc-50/50 dark:bg-zinc-800/40 flex items-center justify-between">
          <div className="flex items-center space-x-3.5">
            <div className="p-2.5 rounded-xl bg-blue-500/10 text-blue-600 dark:text-blue-400 border border-blue-500/20">
              <Box className="w-5 h-5" />
            </div>
            <div>
              <div className="flex items-center space-x-2.5">
                <h3 className="text-base font-semibold text-zinc-900 dark:text-zinc-100 font-mono">
                  {vm.name}
                </h3>
                {getStatusBadge(vm.status)}
                {isHostUnreachable && (
                  <span
                    className="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-amber-500/10 text-amber-600 dark:text-amber-400 border border-amber-500/20"
                    title={t("hostUnreachableTooltip")}
                  >
                    <AlertCircle className="w-3 h-3 mr-1" />
                    {t("hostUnreachable")}
                  </span>
                )}
                <span className="text-[11px] px-2 py-0.5 rounded bg-zinc-100 dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700/60 font-mono text-zinc-600 dark:text-zinc-400">
                  {vm.instance_type}
                </span>
              </div>
              <p className="text-xs text-zinc-500 dark:text-zinc-400 mt-0.5 flex items-center space-x-2">
                <span>{vm.os_image_alias || vm.os_image.replace("images:", "")}</span>
                <span>&bull;</span>
                <span className="font-mono">
                  {vm.network?.primary?.address ?? vm.ipv4_address ?? "No IP"}
                </span>
                {vm.host?.name && (
                  <>
                    <span>&bull;</span>
                    <span>{vm.host.name}</span>
                  </>
                )}
              </p>
            </div>
          </div>

          <div className="flex items-center space-x-2">
            {/* Open Console Action */}
            {onOpenConsole && (
              <button
                onClick={() => onOpenConsole(vm)}
                disabled={isHostUnreachable}
                title={isHostUnreachable ? t("hostUnreachableTooltip") : "Open Console"}
                className="flex items-center space-x-1.5 px-3 py-1.5 rounded-lg text-xs font-medium text-zinc-700 dark:text-zinc-300 hover:text-zinc-900 dark:hover:text-white bg-zinc-100 dark:bg-zinc-800 hover:bg-zinc-200 dark:hover:bg-zinc-700 border border-zinc-200 dark:border-zinc-700/60 transition disabled:opacity-50"
              >
                <Terminal className="w-3.5 h-3.5 text-blue-500" />
                <span>Open Console</span>
              </button>
            )}

            {/* Custom Registered Actions */}
            {registeredActions.map((action) => {
              const ActionIcon = action.icon;
              const label = getActionLabel(action);
              const disabled = action.disabled ? action.disabled({ vm, user, isAdmin }) : false;
              const isDanger = action.variant === "danger";

              return (
                <button
                  key={action.id}
                  onClick={() => handleActionClick(action)}
                  disabled={disabled || confirmDialog.isLoading || isHostUnreachable}
                  title={isHostUnreachable ? t("hostUnreachableTooltip") : label}
                  className={`flex items-center space-x-1.5 px-3 py-1.5 rounded-lg text-xs font-medium border transition disabled:opacity-50 ${
                    isDanger
                      ? "text-red-600 dark:text-red-400 hover:text-red-700 dark:hover:text-red-300 bg-red-500/10 hover:bg-red-500/20 border-red-500/20"
                      : "text-zinc-700 dark:text-zinc-300 hover:text-zinc-900 dark:hover:text-white bg-zinc-100 dark:bg-zinc-800 hover:bg-zinc-200 dark:hover:bg-zinc-700 border-zinc-200 dark:border-zinc-700/60"
                  }`}
                >
                  {ActionIcon && <ActionIcon className="w-3.5 h-3.5" />}
                  <span>{label}</span>
                </button>
              );
            })}

            {/* Close Button */}
            <button
              onClick={onClose}
              className="p-1.5 rounded-lg text-zinc-400 hover:text-zinc-700 dark:hover:text-zinc-200 hover:bg-zinc-100 dark:hover:bg-zinc-800 transition"
            >
              <X className="w-5 h-5" />
            </button>
          </div>
        </div>

        {/* Unreachable Banner */}
        {isHostUnreachable && (
          <div className="px-6 py-2.5 bg-amber-500/10 border-b border-amber-500/20 flex items-center space-x-2 text-xs text-amber-700 dark:text-amber-400 font-medium">
            <AlertCircle className="w-4 h-4 shrink-0 text-amber-600 dark:text-amber-400" />
            <span>{t("hostUnreachableTooltip")}</span>
          </div>
        )}

        {/* Tab Navigation Bar */}
        <div className="flex items-center px-6 border-b border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 overflow-x-auto">
          <nav className="flex space-x-1" aria-label="Tabs">
            {registeredTabs.map((tab) => {
              const TabIcon = tab.icon;
              const isActive = activeTab?.id === tab.id;
              const label = getTabLabel(tab);
              const badge = tab.badge ? tab.badge({ vm, user, isAdmin }) : null;

              return (
                <button
                  key={tab.id}
                  onClick={() => setActiveTabId(tab.id)}
                  className={`flex items-center space-x-2 py-3 px-3.5 border-b-2 font-medium text-xs whitespace-nowrap transition ${
                    isActive
                      ? "border-blue-500 text-blue-600 dark:text-blue-400 font-semibold"
                      : "border-transparent text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-200 hover:border-zinc-300 dark:hover:border-zinc-700"
                  }`}
                >
                  {TabIcon && <TabIcon className="w-4 h-4 shrink-0" />}
                  <span>{label}</span>
                  {badge}
                </button>
              );
            })}
          </nav>
        </div>

        {/* Active Tab Content Area */}
        <div className="flex-1 overflow-y-auto p-6">
          {activeTab ? (
            <activeTab.component
              vm={vm}
              user={user}
              isAdmin={isAdmin}
              onVmUpdated={onVmUpdated}
            />
          ) : (
            <div className="py-12 text-center text-zinc-400 text-sm">
              No tabs registered.
            </div>
          )}
        </div>
      </div>

      {/* Confirmation Dialog */}
      <ConfirmModal
        isOpen={confirmDialog.isOpen}
        title={confirmDialog.title}
        message={confirmDialog.message}
        confirmText={confirmDialog.confirmText}
        cancelText={confirmDialog.cancelText}
        variant={confirmDialog.variant}
        isLoading={confirmDialog.isLoading}
        onConfirm={confirmDialog.onConfirm}
        onClose={() => setConfirmDialog((prev) => ({ ...prev, isOpen: false }))}
      />
    </div>
  );
}
