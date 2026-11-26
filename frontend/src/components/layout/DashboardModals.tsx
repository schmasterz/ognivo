"use client";

import React from "react";
import dynamic from "next/dynamic";
import {
  User,
  QuotaResponse,
  VirtualMachine,
  AdminUserItem,
  QuotaGroup,
  HostNode,
  SystemSettings,
} from "@/lib/api";
import { ConfirmModal, ConfirmVariant } from "@/components/ui/ConfirmModal";
import { useI18n } from "@/lib/i18n/I18nContext";
import { useToast } from "@/lib/toast/ToastContext";
import { useDashboardModals } from "./useDashboardModals";

const CreateVmModal = dynamic(
  () => import("@/components/vms/CreateVmModal").then((mod) => mod.CreateVmModal),
  { ssr: false }
);
const ConsoleModal = dynamic(
  () => import("@/components/vms/ConsoleModal").then((mod) => mod.ConsoleModal),
  { ssr: false }
);
const VmDetailsModal = dynamic(
  () => import("@/components/vms/VmDetailsModal").then((mod) => mod.VmDetailsModal),
  { ssr: false }
);
const CreateIpPoolModal = dynamic(
  () => import("@/components/networking/CreateIpPoolModal").then((mod) => mod.CreateIpPoolModal),
  { ssr: false }
);
const CreateHostModal = dynamic(
  () => import("@/components/hosts/CreateHostModal").then((mod) => mod.CreateHostModal),
  { ssr: false }
);
const EditHostModal = dynamic(
  () => import("@/components/hosts/EditHostModal").then((mod) => mod.EditHostModal),
  { ssr: false }
);
const ImagesModal = dynamic(
  () => import("@/components/images/ImagesModal").then((mod) => mod.ImagesModal),
  { ssr: false }
);
const DefaultQuotaModal = dynamic(
  () => import("@/components/quotas/DefaultQuotaModal").then((mod) => mod.DefaultQuotaModal),
  { ssr: false }
);
const QuotaGroupsModal = dynamic(
  () => import("@/components/quotas/QuotaGroupsModal").then((mod) => mod.QuotaGroupsModal),
  { ssr: false }
);
const CreateQuotaGroupModal = dynamic(
  () => import("@/components/quotas/CreateQuotaGroupModal").then((mod) => mod.CreateQuotaGroupModal),
  { ssr: false }
);
const EditQuotaGroupModal = dynamic(
  () => import("@/components/quotas/EditQuotaGroupModal").then((mod) => mod.EditQuotaGroupModal),
  { ssr: false }
);
const AssignQuotaGroupModal = dynamic(
  () => import("@/components/quotas/AssignQuotaGroupModal").then((mod) => mod.AssignQuotaGroupModal),
  { ssr: false }
);
const CreateUserModal = dynamic(
  () => import("@/components/users/CreateUserModal").then((mod) => mod.CreateUserModal),
  { ssr: false }
);
const EditUserModal = dynamic(
  () => import("@/components/users/EditUserModal").then((mod) => mod.EditUserModal),
  { ssr: false }
);
const ProfileModal = dynamic(
  () => import("@/components/auth/ProfileModal").then((mod) => mod.ProfileModal),
  { ssr: false }
);
const SettingsModal = dynamic(
  () => import("@/components/settings/SettingsModal").then((mod) => mod.SettingsModal),
  { ssr: false }
);

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
  createModalOpen?: boolean;
  setCreateModalOpen?: (open: boolean) => void;
  consoleVm?: VirtualMachine | null;
  setConsoleVm?: (vm: VirtualMachine | null) => void;
  detailsVm?: { vm: VirtualMachine; initialTab?: string } | null;
  setDetailsVm?: (target: { vm: VirtualMachine; initialTab?: string } | null) => void;

  // Profile Modal State
  profileModalOpen?: boolean;
  setProfileModalOpen?: (open: boolean) => void;

  // User Administration Modal States
  createUserModalOpen?: boolean;
  setCreateUserModalOpen?: (open: boolean) => void;
  editUserTarget?: AdminUserItem | null;
  setEditUserTarget?: (user: AdminUserItem | null) => void;
  defaultQuotaModalOpen?: boolean;
  setDefaultQuotaModalOpen?: (open: boolean) => void;
  createPoolModalOpen?: boolean;
  setCreatePoolModalOpen?: (open: boolean) => void;

  // Quota Groups Modal States
  quotaGroupsModalOpen?: boolean;
  setQuotaGroupsModalOpen?: (open: boolean) => void;
  createQuotaGroupModalOpen?: boolean;
  setCreateQuotaGroupModalOpen?: (open: boolean) => void;
  editQuotaGroupTarget?: QuotaGroup | null;
  setEditQuotaGroupTarget?: (group: QuotaGroup | null) => void;
  handleRequestDeleteQuotaGroup: (group: QuotaGroup) => void;
  assignQuotaGroupTarget?: AdminUserItem | null;
  setAssignQuotaGroupTarget?: (user: AdminUserItem | null) => void;

  // Host Nodes Modal States
  imagesModalOpen?: boolean;
  setImagesModalOpen?: (open: boolean) => void;
  createHostModalOpen?: boolean;
  setCreateHostModalOpen?: (open: boolean) => void;
  editHostTarget?: HostNode | null;
  setEditHostTarget?: (host: HostNode | null) => void;
  handleRequestDeleteHost: (hostId: number, hostName: string) => void;

  // Settings Modal State
  settingsModalOpen?: boolean;
  setSettingsModalOpen?: (open: boolean) => void;

  // Confirmation Modal State
  confirmDialog: ConfirmDialogState;
  setConfirmDialog: React.Dispatch<React.SetStateAction<ConfirmDialogState>>;
}

export function DashboardModals(props: DashboardModalsProps) {
  const { t } = useI18n();
  const { toast } = useToast();
  const storeModals = useDashboardModals();

  const {
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

    createModalOpen = storeModals.createModalOpen,
    setCreateModalOpen = storeModals.setCreateModalOpen,
    consoleVm = storeModals.consoleVm,
    setConsoleVm = storeModals.setConsoleVm,
    detailsVm,
    setDetailsVm,

    profileModalOpen = storeModals.profileModalOpen,
    setProfileModalOpen = storeModals.setProfileModalOpen,

    createUserModalOpen = storeModals.createUserModalOpen,
    setCreateUserModalOpen = storeModals.setCreateUserModalOpen,
    editUserTarget = storeModals.editUserTarget,
    setEditUserTarget = storeModals.setEditUserTarget,
    defaultQuotaModalOpen = storeModals.defaultQuotaModalOpen,
    setDefaultQuotaModalOpen = storeModals.setDefaultQuotaModalOpen,
    createPoolModalOpen = storeModals.createPoolModalOpen,
    setCreatePoolModalOpen = storeModals.setCreatePoolModalOpen,

    quotaGroupsModalOpen = storeModals.quotaGroupsModalOpen,
    setQuotaGroupsModalOpen = storeModals.setQuotaGroupsModalOpen,
    createQuotaGroupModalOpen = storeModals.createQuotaGroupModalOpen,
    setCreateQuotaGroupModalOpen = storeModals.setCreateQuotaGroupModalOpen,
    editQuotaGroupTarget = storeModals.editQuotaGroupTarget,
    setEditQuotaGroupTarget = storeModals.setEditQuotaGroupTarget,
    handleRequestDeleteQuotaGroup,
    assignQuotaGroupTarget = storeModals.assignQuotaGroupTarget,
    setAssignQuotaGroupTarget = storeModals.setAssignQuotaGroupTarget,

    imagesModalOpen = storeModals.imagesModalOpen,
    setImagesModalOpen = storeModals.setImagesModalOpen,
    createHostModalOpen = storeModals.createHostModalOpen,
    setCreateHostModalOpen = storeModals.setCreateHostModalOpen,
    editHostTarget = storeModals.editHostTarget,
    setEditHostTarget = storeModals.setEditHostTarget,

    settingsModalOpen = storeModals.settingsModalOpen,
    setSettingsModalOpen = storeModals.setSettingsModalOpen,

    confirmDialog,
    setConfirmDialog,
  } = props;

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
      {consoleVm && (
        <ConsoleModal vm={consoleVm} onClose={() => setConsoleVm(null)} />
      )}

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
            setDetailsVm(null);
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
