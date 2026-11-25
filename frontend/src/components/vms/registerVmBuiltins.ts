"use client";

import { Activity, Box, Camera, RotateCcw } from "lucide-react";
import { registerVmTab, registerVmAction, VmActionContext } from "@/lib/registry/vmRegistry";
import { vmsApi } from "@/lib/api/vms";
import { VirtualMachine } from "@/lib/api/types";
import { VmOverviewTab } from "./VmOverviewTab";
import { VmMetricsTab } from "./metrics/VmMetricsTab";
import { VmSnapshotsTab } from "./snapshots/VmSnapshotsTab";

declare module "@/lib/registry/vmRegistry" {
  interface VmActionContext {
    onVmUpdated?: (vm: VirtualMachine) => void;
    requestConfirm?: (options: {
      title: string;
      message: string;
      confirmText?: string;
      cancelText?: string;
      variant?: "danger" | "warning" | "info" | "primary";
      onConfirm: () => void | Promise<void>;
    }) => void;
  }
}

let builtinsRegistered = false;

/**
 * Register built-in VM tabs into the extension registry.
 * Safe to call multiple times (idempotent).
 */
export function registerVmBuiltins(): void {
  if (builtinsRegistered) return;
  builtinsRegistered = true;

  // 1. Overview Tab (order 10)
  registerVmTab({
    id: "overview",
    label: "Overview",
    order: 10,
    icon: Box,
    component: VmOverviewTab,
  });

  // 1.5. Metrics Tab (order 15)
  registerVmTab({
    id: "metrics",
    label: "Metrics",
    order: 15,
    icon: Activity,
    visible: ({ metricsEnabled }) => Boolean(metricsEnabled),
    component: VmMetricsTab,
  });

  // 2. Snapshots Tab (order 20)
  registerVmTab({
    id: "snapshots",
    label: "Snapshots",
    order: 20,
    icon: Camera,
    component: VmSnapshotsTab,
  });

  // 3. Reinstall Action (order 50)
  registerVmAction({
    id: "reinstall",
    label: "Reinstall",
    order: 50,
    icon: RotateCcw,
    variant: "danger",
    visible: ({ vm }) => {
      const status = vm.status.toLowerCase();
      return ["running", "stopped", "suspended", "failed"].includes(status);
    },
    onClick: async (context: VmActionContext) => {
      if (context.requestConfirm) {
        context.requestConfirm({
          title: "Confirm Reinstall",
          message: `Are you sure you want to reinstall "${context.vm.name}"? This will erase all disk data on the instance while keeping its IP address.`,
          confirmText: "Reinstall Instance",
          variant: "danger",
          onConfirm: async () => {
            const res = await vmsApi.action(context.vm.id, "reinstall");
            if (context.onVmUpdated && res.virtual_machine) {
              context.onVmUpdated(res.virtual_machine);
            }
          },
        });
      } else {
        const res = await vmsApi.action(context.vm.id, "reinstall");
        if (context.onVmUpdated && res.virtual_machine) {
          context.onVmUpdated(res.virtual_machine);
        }
      }
    },
  });
}
