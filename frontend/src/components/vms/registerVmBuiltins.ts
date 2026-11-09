"use client";

import { Box, Camera } from "lucide-react";
import { registerVmTab } from "@/lib/registry/vmRegistry";
import { VmOverviewTab } from "./VmOverviewTab";
import { VmSnapshotsTab } from "./snapshots/VmSnapshotsTab";

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

  // 2. Snapshots Tab (order 20)
  registerVmTab({
    id: "snapshots",
    label: "Snapshots",
    order: 20,
    icon: Camera,
    component: VmSnapshotsTab,
  });
}
