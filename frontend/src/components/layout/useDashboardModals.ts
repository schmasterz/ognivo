"use client";

import { useMemo, useSyncExternalStore } from "react";
import type { Dispatch, SetStateAction } from "react";
import type {
  VirtualMachine,
  AdminUserItem,
  QuotaGroup,
  HostNode,
} from "@/lib/api";

export interface DashboardModalsState {
  createModalOpen: boolean;
  consoleVm: VirtualMachine | null;
  profileModalOpen: boolean;
  createUserModalOpen: boolean;
  editUserTarget: AdminUserItem | null;
  defaultQuotaModalOpen: boolean;
  createPoolModalOpen: boolean;
  quotaGroupsModalOpen: boolean;
  createQuotaGroupModalOpen: boolean;
  editQuotaGroupTarget: QuotaGroup | null;
  assignQuotaGroupTarget: AdminUserItem | null;
  imagesModalOpen: boolean;
  createHostModalOpen: boolean;
  editHostTarget: HostNode | null;
  settingsModalOpen: boolean;
}

export interface DashboardModalsActions {
  setCreateModalOpen: Dispatch<SetStateAction<boolean>>;
  setConsoleVm: Dispatch<SetStateAction<VirtualMachine | null>>;
  setProfileModalOpen: Dispatch<SetStateAction<boolean>>;
  setCreateUserModalOpen: Dispatch<SetStateAction<boolean>>;
  setEditUserTarget: Dispatch<SetStateAction<AdminUserItem | null>>;
  setDefaultQuotaModalOpen: Dispatch<SetStateAction<boolean>>;
  setCreatePoolModalOpen: Dispatch<SetStateAction<boolean>>;
  setQuotaGroupsModalOpen: Dispatch<SetStateAction<boolean>>;
  setCreateQuotaGroupModalOpen: Dispatch<SetStateAction<boolean>>;
  setEditQuotaGroupTarget: Dispatch<SetStateAction<QuotaGroup | null>>;
  setAssignQuotaGroupTarget: Dispatch<SetStateAction<AdminUserItem | null>>;
  setImagesModalOpen: Dispatch<SetStateAction<boolean>>;
  setCreateHostModalOpen: Dispatch<SetStateAction<boolean>>;
  setEditHostTarget: Dispatch<SetStateAction<HostNode | null>>;
  setSettingsModalOpen: Dispatch<SetStateAction<boolean>>;
}

export type DashboardModalsReturn = DashboardModalsState & DashboardModalsActions;

const INITIAL_MODALS_STATE: DashboardModalsState = {
  createModalOpen: false,
  consoleVm: null,
  profileModalOpen: false,
  createUserModalOpen: false,
  editUserTarget: null,
  defaultQuotaModalOpen: false,
  createPoolModalOpen: false,
  quotaGroupsModalOpen: false,
  createQuotaGroupModalOpen: false,
  editQuotaGroupTarget: null,
  assignQuotaGroupTarget: null,
  imagesModalOpen: false,
  createHostModalOpen: false,
  editHostTarget: null,
  settingsModalOpen: false,
};

const MODAL_STATE_KEYS: ReadonlyArray<keyof DashboardModalsState> = [
  "createModalOpen",
  "consoleVm",
  "profileModalOpen",
  "createUserModalOpen",
  "editUserTarget",
  "defaultQuotaModalOpen",
  "createPoolModalOpen",
  "quotaGroupsModalOpen",
  "createQuotaGroupModalOpen",
  "editQuotaGroupTarget",
  "assignQuotaGroupTarget",
  "imagesModalOpen",
  "createHostModalOpen",
  "editHostTarget",
  "settingsModalOpen",
];

let modalsState: DashboardModalsState = INITIAL_MODALS_STATE;
const listeners = new Set<() => void>();

function subscribe(listener: () => void): () => void {
  listeners.add(listener);
  return () => {
    listeners.delete(listener);
  };
}

function getSnapshot(): DashboardModalsState {
  return modalsState;
}

function getServerSnapshot(): DashboardModalsState {
  return INITIAL_MODALS_STATE;
}

function updateModalField<K extends keyof DashboardModalsState>(
  key: K,
  valueOrUpdater: SetStateAction<DashboardModalsState[K]>
): void {
  const prev = modalsState[key];
  const next =
    typeof valueOrUpdater === "function"
      ? (valueOrUpdater as (current: DashboardModalsState[K]) => DashboardModalsState[K])(prev)
      : valueOrUpdater;

  if (Object.is(prev, next)) {
    return;
  }

  modalsState = {
    ...modalsState,
    [key]: next,
  };
  listeners.forEach((listener) => listener());
}

const dashboardModalActions: DashboardModalsActions = {
  setCreateModalOpen: (v) => updateModalField("createModalOpen", v),
  setConsoleVm: (v) => updateModalField("consoleVm", v),
  setProfileModalOpen: (v) => updateModalField("profileModalOpen", v),
  setCreateUserModalOpen: (v) => updateModalField("createUserModalOpen", v),
  setEditUserTarget: (v) => updateModalField("editUserTarget", v),
  setDefaultQuotaModalOpen: (v) => updateModalField("defaultQuotaModalOpen", v),
  setCreatePoolModalOpen: (v) => updateModalField("createPoolModalOpen", v),
  setQuotaGroupsModalOpen: (v) => updateModalField("quotaGroupsModalOpen", v),
  setCreateQuotaGroupModalOpen: (v) => updateModalField("createQuotaGroupModalOpen", v),
  setEditQuotaGroupTarget: (v) => updateModalField("editQuotaGroupTarget", v),
  setAssignQuotaGroupTarget: (v) => updateModalField("assignQuotaGroupTarget", v),
  setImagesModalOpen: (v) => updateModalField("imagesModalOpen", v),
  setCreateHostModalOpen: (v) => updateModalField("createHostModalOpen", v),
  setEditHostTarget: (v) => updateModalField("editHostTarget", v),
  setSettingsModalOpen: (v) => updateModalField("settingsModalOpen", v),
};

/**
 * Returns the stable modal action setters without subscribing the caller to modal
 * state updates.
 */
export function useDashboardModalActions(): DashboardModalsActions {
  return dashboardModalActions;
}

/**
 * Subscribes to a selected slice of the dashboard modal store.
 */
export function useDashboardModalSelector<T>(
  selector: (state: DashboardModalsState) => T
): T {
  return useSyncExternalStore(
    subscribe,
    () => selector(getSnapshot()),
    () => selector(getServerSnapshot())
  );
}

/**
 * Builds a context-safe handle exposing stable setters as own enumerable
 * properties and modal state fields as non-enumerable live getters.
 *
 * Non-enumerable state getters keep `<DashboardModals {...modals} />` from
 * forwarding stale snapshot values as explicit props while preserving the full
 * `DashboardModalsReturn` type and direct property reads (e.g. `modals.createModalOpen`).
 */
export function createDashboardModalsHandle(
  actions: DashboardModalsActions = dashboardModalActions
): DashboardModalsReturn {
  const handle = { ...actions } as DashboardModalsReturn;
  for (const key of MODAL_STATE_KEYS) {
    Object.defineProperty(handle, key, {
      enumerable: false,
      configurable: false,
      get: () => getSnapshot()[key],
    });
  }
  return handle;
}

/**
 * Subscribes to the external modal store and returns both current modal state
 * and stable action setters.
 */
export function useDashboardModals(): DashboardModalsReturn {
  const state = useSyncExternalStore(subscribe, getSnapshot, getServerSnapshot);

  return useMemo(
    () => ({
      ...state,
      ...dashboardModalActions,
    }),
    [state]
  );
}
