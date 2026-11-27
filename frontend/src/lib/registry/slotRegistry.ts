import type React from "react";

export type SlotId =
  | "instance.detail.tabs"
  | "instance.detail.actions"
  | "settings.sections"
  | "dashboard.widgets"
  | "login.providers"
  /** @experimental */
  | "vps.plan.card"
  /** @experimental */
  | "vps.plan.change.preview"
  | (string & {}); // allow custom extension slot IDs while preserving autocomplete

// eslint-disable-next-line @typescript-eslint/no-explicit-any
export interface SlotItem<TProps = any> {
  id: string;
  priority?: number; // default 100. Lower number = higher priority / rendered first
  component: React.ComponentType<TProps>;
  metadata?: Record<string, unknown>;
}

const DEFAULT_PRIORITY = 100;

// In-memory registry mapping slotId -> (itemId -> SlotItem)
const slotRegistry = new Map<string, Map<string, SlotItem>>();

/**
 * Register a component into a UI slot.
 * If an item with the same ID already exists in the slot, it is replaced.
 * Returns an unregister callback for cleanup.
 */
// eslint-disable-next-line @typescript-eslint/no-explicit-any
export function registerSlotComponent<TProps = any>(
  slotId: SlotId,
  item: SlotItem<TProps>
): () => void {
  let slotMap = slotRegistry.get(slotId);
  if (!slotMap) {
    slotMap = new Map<string, SlotItem>();
    slotRegistry.set(slotId, slotMap);
  }

  slotMap.set(item.id, item as SlotItem);

  return () => {
    unregisterSlotComponent(slotId, item.id);
  };
}

/**
 * Retrieve all registered items for a slot, sorted ascending by priority (default 100).
 */
// eslint-disable-next-line @typescript-eslint/no-explicit-any
export function getSlotComponents<TProps = any>(
  slotId: SlotId
): SlotItem<TProps>[] {
  const slotMap = slotRegistry.get(slotId);
  if (!slotMap) {
    return [];
  }

  return Array.from(slotMap.values()).sort(
    (a, b) => (a.priority ?? DEFAULT_PRIORITY) - (b.priority ?? DEFAULT_PRIORITY)
  ) as SlotItem<TProps>[];
}

/**
 * Unregister a component from a slot by its item ID.
 * Returns true if the item was found and removed, false otherwise.
 */
export function unregisterSlotComponent(
  slotId: SlotId,
  itemId: string
): boolean {
  const slotMap = slotRegistry.get(slotId);
  if (!slotMap) {
    return false;
  }

  const deleted = slotMap.delete(itemId);
  if (slotMap.size === 0) {
    slotRegistry.delete(slotId);
  }

  return deleted;
}

/**
 * Clear all registered components across all slots.
 */
export function clearSlotRegistry(): void {
  slotRegistry.clear();
}
