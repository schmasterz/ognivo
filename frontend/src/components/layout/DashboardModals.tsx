"use client";

import React from "react";
import {
  User,
  QuotaResponse,
  VirtualMachine,
  AdminUserItem,
  QuotaGroup,
  HostNode,
  SystemSettings,
} from "@/lib/api";
import { CreateVmModal } from "@/components/vms/CreateVmModal";
import { ConsoleModal } from "@/components/vms/ConsoleModal";
import { VmDetailsModal } from "@/components/vms/VmDetailsModal";
import { CreateIpPoolModal } from "@/components/networking/CreateIpPoolModal";
import { CreateHostModal } from "@/components/hosts/CreateHostModal";
import { EditHostModal } from "@/components/hosts/EditHostModal";
import { NodesModal } from "@/components/hosts/NodesModal";
import { ImagesModal } from "@/components/images/ImagesModal";
import { DefaultQuotaModal } from "@/components/quotas/DefaultQuotaModal";
import { QuotaGroupsModal } from "@/components/quotas/QuotaGroupsModal";
import { CreateQuotaGroupModal } from "@/components/quotas/CreateQuotaGroupModal";
import { EditQuotaGroupModal } from "@/components/quotas/EditQuotaGroupModal";
import { AssignQuotaGroupModal } from "@/components/quotas/AssignQuotaGroupModal";
import { CreateUserModal } from "@/components/users/CreateUserModal";
import { EditUserModal } from "@/components/users/EditUserModal";
import { ProfileModal } from "@/components/auth/ProfileModal";
import { SettingsModal } from "@/components/settings/SettingsModal";
import { ConfirmModal, ConfirmVariant } from "@/components/ui/ConfirmModal";
import { useI18n } from "@/lib/i18n/I18nContext";
import { useToast } from "@/lib/toast/ToastContext";

export interface ConfirmDialogState {
  isOpen: boolean;
  title: string;
  message: string;
  confirmText?: string;
  cancelText?: string;
  variant?: ConfirmVariant;
  isLoading?: boolean;
  onConfirm: () => void | Promise<void>;
}

export interface DashboardModalsProps {
  user: User | null;
  isAdmin?: boolean;
  setUser: (user: User) => void;
  vms: VirtualMachine[];
  hosts: HostNode[];
  setHosts: React.Dispatch<React.SetStateAction<HostNode[]>>;
  quotaData: QuotaResponse | null;
  quotaGroups: QuotaGroup[];
  systemSettings: SystemSettings;
  setSystemSettings: (settings: SystemSettings) => void;
  setRegistrationEnabled: (enabled: boolean) => void;
  loadData: () => Promise<void> | void;
  loadStaticData?: () => Promise<void> | void;

  // VM & Networking Modal States
  createModalOpen: boolean;
  setCreateModalOpen: (open: boolean) => void;
  consoleVm: VirtualMachine | null;
  setConsoleVm: (vm: VirtualMachine | null) => void;
  detailsVm?: { vm: VirtualMachine; initialTab?: string } | null;
  setDetailsVm?: (target: { vm: VirtualMachine; initialTab?: string } | null) => void;

  // Profile Modal State
  profileModalOpen: boolean;
  setProfileModalOpen: (open: boolean) => void;

  // User Administration Modal States
  createUserModalOpen: boolean;
  setCreateUserModalOpen: (open: boolean) => void;
  editUserTarget: AdminUserItem | null;
  setEditUserTarget: (user: AdminUserItem | null) => void;
  defaultQuotaModalOpen: boolean;
  setDefaultQuotaModalOpen: (open: boolean) => void;
  createPoolModalOpen: boolean;
  setCreatePoolModalOpen: (open: boolean) => void;

  // Quota Groups Modal States
  quotaGroupsModalOpen: boolean;
  setQuotaGroupsModalOpen: (open: boolean) => void;
  createQuotaGroupModalOpen: boolean;
  setCreateQuotaGroupModalOpen: (open: boolean) => void;
  editQuotaGroupTarget: QuotaGroup | null;
  setEditQuotaGroupTarget: (group: QuotaGroup | null) => void;
  handleRequestDeleteQuotaGroup: (group: QuotaGroup) => void;
  assignQuotaGroupTarget: AdminUserItem | null;
  setAssignQuotaGroupTarget: (user: AdminUserItem | null) => void;

  // Host Nodes Modal States
  nodesModalOpen: boolean;
  setNodesModalOpen: (open: boolean) => void;
  imagesModalOpen?: boolean;
  setImagesModalOpen?: (open: boolean) => void;
  createHostModalOpen: boolean;
  setCreateHostModalOpen: (open: boolean) => void;
  editHostTarget: HostNode | null;
  setEditHostTarget: (host: HostNode | null) => void;
  handleRequestDeleteHost: (hostId: number, hostName: string) => void;

  // Settings Modal State
  settingsModalOpen: boolean;
  setSettingsModalOpen: (open: boolean) => void;

  // Confirmation Modal State
  confirmDialog: ConfirmDialogState;
  setConfirmDialog: React.Dispatch<React.SetStateAction<ConfirmDialogState>>;
}

export function DashboardModals({
  user,
  isAdmin = false,
  setUser,
  vms,
  hosts,
  setHosts,
  quotaData,
  quotaGroups,
  systemSettings,
  setSystemSettings,
  setRegistrationEnabled,
  loadData,
  loadStaticData,

  createModalOpen,
  setCreateModalOpen,
  consoleVm,
  setConsoleVm,
  detailsVm,
  setDetailsVm,

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
  handleRequestDeleteQuotaGroup,
  assignQuotaGroupTarget,
  setAssignQuotaGroupTarget,

  nodesModalOpen,
  setNodesModalOpen,
  imagesModalOpen,
  setImagesModalOpen,
  createHostModalOpen,
  setCreateHostModalOpen,
  editHostTarget,
  setEditHostTarget,
  handleRequestDeleteHost,

  settingsModalOpen,
  setSettingsModalOpen,

  confirmDialog,
  setConfirmDialog,
}: DashboardModalsProps) {
  const { t } = useI18n();
  const { toast } = useToast();

  return (
    <>
      {/* Creation VM Modal */}
      {createModalOpen && (
        <CreateVmModal
          isOpen={createModalOpen}
          onClose={() => setCreateModalOpen(false)}
          quotaData={quotaData}
          systemSettings={systemSettings}
          existingVms={vms
            .filter((v) => v.status?.toLowerCase() !== "deleted")
            .map((v) => ({ name: v.name, host_id: v.host_id, status: v.status }))}
          onSuccess={() => {
            toast.success("Instance provisioning started!");
            loadData();
          }}
        />
      )}

      {/* Console Modal */}
      <ConsoleModal vm={consoleVm} onClose={() => setConsoleVm(null)} />

      {/* VM Details & Extensions Modal */}
      {detailsVm && setDetailsVm && (
        <VmDetailsModal
          isOpen={!!detailsVm}
          onClose={() => setDetailsVm(null)}
          vm={detailsVm.vm}
          user={user}
          isAdmin={isAdmin}
          initialTab={detailsVm.initialTab || "overview"}
          onOpenConsole={(vm) => {
            setConsoleVm(vm);
          }}
          onVmUpdated={(updatedVm) => {
            setDetailsVm({ ...detailsVm, vm: updatedVm });
          }}
        />
      )}

      {/* Profile Self-Service Modal */}
      {profileModalOpen && user && (
        <ProfileModal
          user={user}
          onClose={() => setProfileModalOpen(false)}
          onSuccess={(updatedUser) => {
            setUser(updatedUser);
            toast.success(t("profileSaved"));
          }}
        />
      )}

      {/* Admin: Create User Modal */}
      {createUserModalOpen && (
        <CreateUserModal
          quotaGroups={quotaGroups}
          onClose={() => setCreateUserModalOpen(false)}
          onSuccess={(newUser) => {
            toast.success(`User "${newUser.name}" created successfully!`);
            loadData();
          }}
        />
      )}

      {/* Admin: Edit User Modal */}
      {editUserTarget && (
        <EditUserModal
          user={editUserTarget}
          quotaGroups={quotaGroups}
          onClose={() => setEditUserTarget(null)}
          onSuccess={(updatedUser) => {
            toast.success(`User "${updatedUser.name}" updated successfully!`);
            loadData();
          }}
        />
      )}

      {/* Admin: Default Quota Modal */}
      {defaultQuotaModalOpen && (
        <DefaultQuotaModal
          onClose={() => setDefaultQuotaModalOpen(false)}
          onSuccess={() => {
            toast.success("Default quotas updated successfully!");
            loadData();
          }}
        />
      )}

      {/* Admin: Create IP Pool Modal */}
      {createPoolModalOpen && (
        <CreateIpPoolModal
          onClose={() => setCreatePoolModalOpen(false)}
          onSuccess={() => {
            toast.success(t("poolCreatedNotice"));
            loadData();
          }}
        />
      )}

      {/* Admin: Quota Groups List Modal */}
      {quotaGroupsModalOpen && (
        <QuotaGroupsModal
          quotaGroups={quotaGroups}
          onClose={() => setQuotaGroupsModalOpen(false)}
          onCreateGroup={() => setCreateQuotaGroupModalOpen(true)}
          onEditGroup={(g) => setEditQuotaGroupTarget(g)}
          onRequestDeleteGroup={handleRequestDeleteQuotaGroup}
          onGroupDeleted={() => {
            loadStaticData?.();
            loadData();
          }}
        />
      )}

      {/* Admin: Create Quota Group Modal */}
      {createQuotaGroupModalOpen && (
        <CreateQuotaGroupModal
          onClose={() => setCreateQuotaGroupModalOpen(false)}
          onSuccess={() => {
            toast.success(t("quotaGroupCreatedNotice"));
            loadStaticData?.();
            loadData();
          }}
        />
      )}

      {/* Admin: Edit Quota Group Modal */}
      {editQuotaGroupTarget && (
        <EditQuotaGroupModal
          quotaGroup={editQuotaGroupTarget}
          onClose={() => setEditQuotaGroupTarget(null)}
          onSuccess={() => {
            toast.success(t("quotaGroupUpdatedNotice"));
            loadStaticData?.();
            loadData();
          }}
        />
      )}

      {/* Admin: Assign Quota Group to User Modal */}
      {assignQuotaGroupTarget && (
        <AssignQuotaGroupModal
          user={assignQuotaGroupTarget}
          quotaGroups={quotaGroups}
          onClose={() => setAssignQuotaGroupTarget(null)}
          onSuccess={() => {
            toast.success(t("quotaGroupAssignedNotice"));
            loadStaticData?.();
            loadData();
          }}
        />
      )}

      {/* Admin: Compute Nodes Modal */}
      {nodesModalOpen && (
        <NodesModal
          hosts={hosts}
          onClose={() => setNodesModalOpen(false)}
          onAddNode={() => setCreateHostModalOpen(true)}
          onEditNode={(h) => setEditHostTarget(h)}
          onDeleteNode={handleRequestDeleteHost}
          onRefreshHosts={() => {
            loadStaticData?.();
            loadData();
          }}
        />
      )}

      {/* Admin: OS Images Catalog Modal */}
      {imagesModalOpen && setImagesModalOpen && (
        <ImagesModal
          hosts={hosts}
          onClose={() => setImagesModalOpen(false)}
        />
      )}

      {/* Admin: Add Compute Node Modal */}
      {createHostModalOpen && (
        <CreateHostModal
          onClose={() => setCreateHostModalOpen(false)}
          onSuccess={(newHost) => {
            toast.success(t("nodeCreatedNotice"));
            setHosts((prev) => [...prev, newHost]);
            loadStaticData?.();
            loadData();
          }}
        />
      )}

      {/* Admin: Edit Compute Node Modal */}
      {editHostTarget && (
        <EditHostModal
          host={editHostTarget}
          onClose={() => setEditHostTarget(null)}
          onSuccess={(updatedHost) => {
            toast.success(t("nodeUpdatedNotice"));
            setHosts((prev) => prev.map((h) => (h.id === updatedHost.id ? updatedHost : h)));
            loadStaticData?.();
            loadData();
          }}
        />
      )}

      {/* Admin: Platform Settings Modal */}
      {settingsModalOpen && (
        <SettingsModal
          isOpen={settingsModalOpen}
          onClose={() => setSettingsModalOpen(false)}
          settings={systemSettings}
          onSuccess={(updated) => {
            setSystemSettings(updated);
            setRegistrationEnabled(updated.registration_enabled);
            loadData();
          }}
        />
      )}

      {/* Confirmation Dialog (rendered as topmost overlay over all open modals) */}
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
    </>
  );
}
