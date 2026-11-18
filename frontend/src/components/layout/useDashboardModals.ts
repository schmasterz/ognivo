"use client";

import { useState } from "react";
import {
  VirtualMachine,
  AdminUserItem,
  QuotaGroup,
  HostNode,
} from "@/lib/api";

export function useDashboardModals() {
  // Action Modals state
  const [createModalOpen, setCreateModalOpen] = useState(false);
  const [consoleVm, setConsoleVm] = useState<VirtualMachine | null>(null);
  const [profileModalOpen, setProfileModalOpen] = useState(false);
  const [createUserModalOpen, setCreateUserModalOpen] = useState(false);
  const [editUserTarget, setEditUserTarget] = useState<AdminUserItem | null>(
    null
  );
  const [defaultQuotaModalOpen, setDefaultQuotaModalOpen] = useState(false);
  const [createPoolModalOpen, setCreatePoolModalOpen] = useState(false);

  // Quota Group Modals state
  const [quotaGroupsModalOpen, setQuotaGroupsModalOpen] = useState(false);
  const [createQuotaGroupModalOpen, setCreateQuotaGroupModalOpen] =
    useState(false);
  const [editQuotaGroupTarget, setEditQuotaGroupTarget] =
    useState<QuotaGroup | null>(null);
  const [assignQuotaGroupTarget, setAssignQuotaGroupTarget] =
    useState<AdminUserItem | null>(null);

  // Host Node Modals state
  const [imagesModalOpen, setImagesModalOpen] = useState(false);
  const [createHostModalOpen, setCreateHostModalOpen] = useState(false);
  const [editHostTarget, setEditHostTarget] = useState<HostNode | null>(null);

  // Platform Settings Modal state
  const [settingsModalOpen, setSettingsModalOpen] = useState(false);

  return {
    createModalOpen,
    setCreateModalOpen,
    consoleVm,
    setConsoleVm,
    profileModalOpen,
    setProfileModalOpen,
    createUserModalOpen,
    setCreateUserModalOpen,
    editUserTarget,
    setEditUserTarget,
    defaultQuotaModalOpen,
    setDefaultQuotaModalOpen,
    createPoolModalOpen,
    setCreatePoolModalOpen,
    quotaGroupsModalOpen,
    setQuotaGroupsModalOpen,
    createQuotaGroupModalOpen,
    setCreateQuotaGroupModalOpen,
    editQuotaGroupTarget,
    setEditQuotaGroupTarget,
    assignQuotaGroupTarget,
    setAssignQuotaGroupTarget,
    imagesModalOpen,
    setImagesModalOpen,
    createHostModalOpen,
    setCreateHostModalOpen,
    editHostTarget,
    setEditHostTarget,
    settingsModalOpen,
    setSettingsModalOpen,
  };
}
