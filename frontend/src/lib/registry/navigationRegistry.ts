import type React from "react";

export interface NavigationItem {
  id: string;
  label: string;
  href: string;
  priority?: number; // default 100, lower rendered first
  icon?: React.ComponentType<{ className?: string }>;
  requiredRole?: string; // e.g. "admin"
  badge?: string | number | (() => string | number);
  target?: "_blank" | "_self";
}

const DEFAULT_PRIORITY = 100;

// In-memory registry mapping item.id -> NavigationItem
const navigationRegistry = new Map<string, NavigationItem>();
const listeners = new Set<() => void>();

// Cached snapshots keyed by normalized userRoles for useSyncExternalStore stability
const snapshotCache = new Map<string, NavigationItem[]>();

function notifyListeners(): void {
  snapshotCache.clear();
  for (const listener of listeners) {
    try {
      listener();
    } catch (err) {
      console.error("Error in navigationRegistry listener:", err);
    }
  }
}

/**
 * Register a navigation item for the sidebar.
 * If an item with the same ID already exists, it is replaced.
 * Returns an unregister callback for cleanup.
 */
export function registerNavigationItem(item: NavigationItem): () => void {
  navigationRegistry.set(item.id, item);
  notifyListeners();

  return () => {
    unregisterNavigationItem(item.id);
  };
}

/**
 * Retrieve all registered navigation items visible to the given roles,
 * sorted ascending by priority (default 100).
 */
export function getNavigationItems(
  userRoles: string[] = []
): NavigationItem[] {
  const cacheKey = JSON.stringify([...userRoles].sort());
  const cached = snapshotCache.get(cacheKey);
  if (cached) {
    return cached;
  }

  const items = Array.from(navigationRegistry.values())
    .filter((item) =>
      item.requiredRole ? userRoles.includes(item.requiredRole) : true
    )
    .sort(
      (a, b) =>
        (a.priority ?? DEFAULT_PRIORITY) - (b.priority ?? DEFAULT_PRIORITY)
    );

  snapshotCache.set(cacheKey, items);
  return items;
}

/**
 * Unregister a navigation item by its ID.
 * Returns true if the item was found and removed, false otherwise.
 */
export function unregisterNavigationItem(id: string): boolean {
  const deleted = navigationRegistry.delete(id);
  if (deleted) {
    notifyListeners();
  }
  return deleted;
}

/**
 * Clear all registered navigation items.
 */
export function clearNavigationRegistry(): void {
  if (navigationRegistry.size > 0) {
    navigationRegistry.clear();
    notifyListeners();
  }
}

/**
 * Subscribe to navigation registry changes (compatible with useSyncExternalStore).
 * Returns an unsubscribe callback.
 */
export function subscribeNavigationRegistry(
  callback: () => void
): () => void {
  listeners.add(callback);
  return () => {
    listeners.delete(callback);
  };
}
