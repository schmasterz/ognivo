/**
 * Domain-Organized UI Component Exports for LXD / Incus Control Plane.
 */

// Layout & Navigation
export { Navbar } from "./layout/Navbar";
export { Sidebar } from "./layout/Sidebar";
export { ThemeToggle } from "./layout/ThemeToggle";
export { LanguageToggle } from "./layout/LanguageToggle";
export { FilterToolbar } from "./layout/FilterToolbar";
export { DashboardModals } from "./layout/DashboardModals";
export { ImpersonationBanner } from "./layout/ImpersonationBanner";
export { DashboardProvider, useDashboard } from "./layout/DashboardContext";
export { DashboardShell } from "./layout/DashboardShell";

// Shared UI Primitives
export { ConfirmModal } from "./ui/ConfirmModal";
export type { ConfirmVariant } from "./ui/ConfirmModal";
export { PaginationBar } from "./ui/PaginationBar";
export { SearchableUserSelect } from "./ui/SearchableUserSelect";

// Authentication & Profile
export { AuthView } from "./auth/AuthView";
export { ProfileModal } from "./auth/ProfileModal";

// Workloads & Virtual Machines
export { VmTable } from "./vms/VmTable";
export { VmsTab } from "./vms/VmsTab";
export { useVms } from "./vms/useVms";
export { CreateVmModal } from "./vms/CreateVmModal";
export { ConsoleModal } from "./vms/ConsoleModal";
export { XtermViewer } from "./vms/XtermViewer";
export { VmDetailsModal } from "./vms/VmDetailsModal";
export { VmOverviewTab } from "./vms/VmOverviewTab";
export { VmSnapshotsTab } from "./vms/snapshots/VmSnapshotsTab";
export { CreateSnapshotModal } from "./vms/snapshots/CreateSnapshotModal";
export { registerVmBuiltins } from "./vms/registerVmBuiltins";
export { SnapshotsTab } from "./snapshots/SnapshotsTab";

// Networking & IPAM
export { IpTable } from "./networking/IpTable";
export { NetworkingTab } from "./networking/NetworkingTab";
export { useNetworking } from "./networking/useNetworking";
export { CreateIpPoolModal } from "./networking/CreateIpPoolModal";

// Resource Quotas & Plans
export { QuotaOverview } from "./quotas/QuotaOverview";
export { DashboardQuotaSection } from "./quotas/DashboardQuotaSection";
export { QuotaLimitsFormSection, quotaRecordToFormValue, quotaFormValueToPayload } from "./quotas/QuotaLimitsFormSection";
export type { QuotaLimitsFormValue, QuotaLimitsFormSectionProps } from "./quotas/QuotaLimitsFormSection";
export { DefaultQuotaModal } from "./quotas/DefaultQuotaModal";
export { QuotaGroupsModal } from "./quotas/QuotaGroupsModal";
export { CreateQuotaGroupModal } from "./quotas/CreateQuotaGroupModal";
export { EditQuotaGroupModal } from "./quotas/EditQuotaGroupModal";
export { AssignQuotaGroupModal } from "./quotas/AssignQuotaGroupModal";

// Compute Nodes & Hypervisors
export { NodesTab } from "./hosts/NodesTab";
export { NodesTable } from "./hosts/NodesTable";
export { CreateHostModal } from "./hosts/CreateHostModal";
export { EditHostModal } from "./hosts/EditHostModal";

// OS Images Catalog
export { ImagesModal } from "./images/ImagesModal";
export { CreateImageModal } from "./images/CreateImageModal";

// User Accounts Management
export { UserManagementTable } from "./users/UserManagementTable";
export { UsersTab } from "./users/UsersTab";
export { useUsers } from "./users/useUsers";
export { CreateUserModal } from "./users/CreateUserModal";
export { EditUserModal } from "./users/EditUserModal";

// Platform Settings
export { SettingsModal } from "./settings/SettingsModal";

// Audit & Activity Logs
export { AuditLogTab } from "./audit/AuditLogTab";
export type { AuditLogTabProps } from "./audit/AuditLogTab";
export { AuditTable } from "./audit/AuditTable";
export { AuditFiltersBar } from "./audit/AuditFiltersBar";
export { AuditDetailDrawer } from "./audit/AuditDetailDrawer";

