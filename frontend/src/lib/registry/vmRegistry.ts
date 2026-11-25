"use client";

import React, { useSyncExternalStore } from "react";
import { VirtualMachine, User } from "@/lib/api";

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

/**
 * Retrieve registered tabs, optionally filtered by context visibility and sorted by order.
 */
export function getVmTabs(context?: VmTabContext): VmTabDefinition[] {
  const tabs = Array.from(tabsRegistry.values());
  const filtered = context
    ? tabs.filter((t) => (t.visible ? t.visible(context) : true))
    : tabs;

  return filtered.sort((a, b) => (a.order ?? 100) - (b.order ?? 100));
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
  const actions = Array.from(actionsRegistry.values());
  const filtered = context
    ? actions.filter((a) => (a.visible ? a.visible(context) : true))
    : actions;

  return filtered.sort((a, b) => (a.order ?? 100) - (b.order ?? 100));
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
