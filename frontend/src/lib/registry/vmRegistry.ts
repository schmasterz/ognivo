"use client";

import React, { useSyncExternalStore } from "react";
import type { VirtualMachine, User } from "@/lib/api";
import { getSlotComponents, type SlotItem } from "./slotRegistry.ts";

export interface VmTabContext {
  vm: VirtualMachine;
  user: User | null;
  isAdmin: boolean;
  metricsEnabled?: boolean;
}

export interface VmTabComponentProps {
  vm: VirtualMachine;
  user: User | null;
  isAdmin: boolean;
  onVmUpdated?: (vm: VirtualMachine) => void;
}

export interface VmTabDefinition {
  id: string;
  label: string | ((context: VmTabContext) => string);
  order?: number;
  icon?: React.ComponentType<{ className?: string }>;
  badge?: (context: VmTabContext) => React.ReactNode;
  visible?: (context: VmTabContext) => boolean;
  component: React.ComponentType<VmTabComponentProps>;
}

export interface VmActionContext {
  vm: VirtualMachine;
  user: User | null;
  isAdmin: boolean;
}

export interface VmActionDefinition {
  id: string;
  label: string | ((context: VmActionContext) => string);
  order?: number;
  icon?: React.ComponentType<{ className?: string }>;
  variant?: "default" | "primary" | "danger" | "warning";
  disabled?: (context: VmActionContext) => boolean;
  visible?: (context: VmActionContext) => boolean;
  onClick: (context: VmActionContext) => void | Promise<void>;
}

// In-memory registries
const tabsRegistry = new Map<string, VmTabDefinition>();
const actionsRegistry = new Map<string, VmActionDefinition>();
const listeners = new Set<() => void>();
let snapshotVersion = 0;

function getSnapshot(): number {
  return snapshotVersion;
}

function notifyListeners(): void {
  snapshotVersion++;
  listeners.forEach((listener) => {
    try {
      listener();
    } catch (err) {
      console.error("Error in vmRegistry listener:", err);
    }
  });
}

/**
 * Register a tab into the VM details page.
 * Returns an unregister function for easy cleanup.
 */
export function registerVmTab(tab: VmTabDefinition): () => void {
  tabsRegistry.set(tab.id, tab);
  notifyListeners();
  return () => unregisterVmTab(tab.id);
}

/**
 * Remove a registered tab by ID.
 */
export function unregisterVmTab(tabId: string): void {
  if (tabsRegistry.delete(tabId)) {
    notifyListeners();
  }
}

const ACTION_VARIANTS = new Set(["default", "primary", "danger", "warning"]);

function resolveSlotLabel<TContext>(
  item: SlotItem
): string | ((context: TContext) => string) {
  const rawLabel = item.metadata?.label;
  if (typeof rawLabel === "function") {
    return rawLabel as (context: TContext) => string;
  }
  if (rawLabel !== undefined && rawLabel !== null) {
    return String(rawLabel);
  }
  return item.id;
}

function slotItemToVmTab(item: SlotItem): VmTabDefinition {
  const metadata = item.metadata;
  return {
    id: item.id,
    label: resolveSlotLabel<VmTabContext>(item),
    order: item.priority,
    icon:
      typeof metadata?.icon === "function"
        ? (metadata.icon as VmTabDefinition["icon"])
        : undefined,
    badge:
      typeof metadata?.badge === "function"
        ? (metadata.badge as VmTabDefinition["badge"])
        : undefined,
    visible:
      typeof metadata?.visible === "function"
        ? (metadata.visible as VmTabDefinition["visible"])
        : undefined,
    component: item.component as React.ComponentType<VmTabComponentProps>,
  };
}

function slotItemToVmAction(item: SlotItem): VmActionDefinition {
  const metadata = item.metadata;
  const rawVariant = metadata?.variant;
  return {
    id: item.id,
    label: resolveSlotLabel<VmActionContext>(item),
    order: item.priority,
    icon:
      typeof metadata?.icon === "function"
        ? (metadata.icon as VmActionDefinition["icon"])
        : undefined,
    variant:
      typeof rawVariant === "string" && ACTION_VARIANTS.has(rawVariant)
        ? (rawVariant as VmActionDefinition["variant"])
        : undefined,
    disabled:
      typeof metadata?.disabled === "function"
        ? (metadata.disabled as VmActionDefinition["disabled"])
        : undefined,
    visible:
      typeof metadata?.visible === "function"
        ? (metadata.visible as VmActionDefinition["visible"])
        : undefined,
    onClick:
      typeof metadata?.onClick === "function"
        ? (metadata.onClick as VmActionDefinition["onClick"])
        : () => {},
  };
}

function mergeAndSortRegistry<
  T extends {
    id: string;
    order?: number;
    visible?: (context: TContext) => boolean;
  },
  TContext,
>(
  builtIns: Map<string, T>,
  slotItems: SlotItem[],
  converter: (item: SlotItem) => T,
  context?: TContext
): T[] {
  const merged = new Map<string, T>(builtIns);
  for (const item of slotItems) {
    if (!merged.has(item.id)) {
      merged.set(item.id, converter(item));
    }
  }

  const entries = Array.from(merged.values());
  const filtered = context
    ? entries.filter((entry) => (entry.visible ? entry.visible(context) : true))
    : entries;

  return filtered.sort((a, b) => (a.order ?? 100) - (b.order ?? 100));
}

/**
 * Retrieve registered tabs, optionally filtered by context visibility and sorted by order.
 */
export function getVmTabs(context?: VmTabContext): VmTabDefinition[] {
  return mergeAndSortRegistry(
    tabsRegistry,
    getSlotComponents("instance.detail.tabs"),
    slotItemToVmTab,
    context
  );
}

/**
 * Register an action into the VM actions bar.
 * Returns an unregister function for cleanup.
 */
export function registerVmAction(action: VmActionDefinition): () => void {
  actionsRegistry.set(action.id, action);
  notifyListeners();
  return () => unregisterVmAction(action.id);
}

/**
 * Remove a registered action by ID.
 */
export function unregisterVmAction(actionId: string): void {
  if (actionsRegistry.delete(actionId)) {
    notifyListeners();
  }
}

/**
 * Retrieve registered actions, optionally filtered by context visibility and sorted by order.
 */
export function getVmActions(context?: VmActionContext): VmActionDefinition[] {
  return mergeAndSortRegistry(
    actionsRegistry,
    getSlotComponents("instance.detail.actions"),
    slotItemToVmAction,
    context
  );
}

// Subscription helper for useSyncExternalStore
function subscribe(listener: () => void): () => void {
  listeners.add(listener);
  return () => listeners.delete(listener);
}

/**
 * React hook to retrieve VM tabs with reactivity to dynamic registrations.
 */
export function useVmTabs(context?: VmTabContext): VmTabDefinition[] {
  useSyncExternalStore(subscribe, getSnapshot, getSnapshot);
  return getVmTabs(context);
}

/**
 * React hook to retrieve VM actions with reactivity to dynamic registrations.
 */
export function useVmActions(context?: VmActionContext): VmActionDefinition[] {
  useSyncExternalStore(subscribe, getSnapshot, getSnapshot);
  return getVmActions(context);
}
