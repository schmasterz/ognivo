"use client";

import React, { useState } from "react";
import { RefreshCw } from "lucide-react";
import { Navbar } from "./Navbar";
import { Sidebar } from "./Sidebar";
import { FilterToolbar } from "./FilterToolbar";
import { DashboardModals } from "./DashboardModals";
import { ImpersonationBanner } from "./ImpersonationBanner";
import { DashboardQuotaSection } from "@/components/quotas/DashboardQuotaSection";
import { AuthView } from "@/components/auth/AuthView";
import { useDashboard } from "./DashboardContext";
import { useI18n } from "@/lib/i18n/I18nContext";
import { useAuth } from "@/lib/auth/AuthContext";
import { useGlobalFilters } from "@/lib/filters/GlobalFilterContext";

export function DashboardShell({ children }: { children: React.ReactNode }) {
  const { t } = useI18n();
  const [sidebarOpen, setSidebarOpen] = useState(false);

  const {
    user,
    setUser,
    roles,
    isAdmin,
    authLoading,
    setRegistrationEnabled,
    systemSettings,
    setSystemSettings,
    impersonatedUser,
    isStoppingImpersonation,
    handleAuthSuccess,
    handleLogout,
  } = useAuth();

  const {
    selectedUserId,
    setSelectedUserId,
    selectedHostId,
    setSelectedHostId,
    selectedStatus,
    setSelectedStatus,
    searchQuery,
    setSearchQuery,
    hasActiveFilters: globalHasActiveFilters,
    resetFilters,
  } = useGlobalFilters();

  const {
    activeTab,
    setActiveTab,
    detailsVm,
    handleSetDetailsVm,
    handleTabChange,
    vms,
    vmsMeta,
    ipsMeta,
    poolsMeta,
    usersMeta,
    adminStats,
    quotaGroups,
    quotaData,
    setQuotaData,
    hosts,
    setHosts,
    refreshing,
    loadStaticData,
    loadData,
    modals,
    confirmDialog,
    setConfirmDialog,
    handleStopImpersonation,
    handleRequestDeleteQuotaGroup,
    handleRequestDeleteHost,
  } = useDashboard();

  const onAuthSuccess = (
    authenticatedUser: NonNullable<typeof user>,
    userRoles: string[]
  ) => {
    handleAuthSuccess(authenticatedUser, userRoles);
    setActiveTab("vms");
  };

  const onLogout = async () => {
    await handleLogout();
    setQuotaData(null);
    resetFilters();
    setActiveTab("vms");
  };

  if (authLoading) {
    return (
      <div className="flex items-center justify-center min-h-screen bg-gray-50 dark:bg-gray-950">
        <div className="flex flex-col items-center space-y-3 text-gray-500 dark:text-gray-400">
          <RefreshCw className="w-8 h-8 animate-spin text-blue-500" />
          <span className="text-xs font-medium tracking-wide">
            {t("loading")}
          </span>
        </div>
      </div>
    );
  }

  if (!user) {
    return <AuthView onAuthSuccess={onAuthSuccess} />;
  }

  const isClusterCardView = isAdmin && selectedUserId === null;
  const hasActiveFilters = Boolean(
    (isAdmin && selectedUserId !== null) ||
      selectedHostId !== null ||
      selectedStatus ||
      searchQuery.trim() ||
      (isAdmin && globalHasActiveFilters)
  );

  return (
    <div className="min-h-screen bg-gray-50 dark:bg-gray-950 font-sans antialiased text-gray-900 dark:text-gray-100 flex transition-colors">
      <Sidebar
        user={user}
        roles={roles}
        activeTab={activeTab}
        onTabChange={handleTabChange}
        counts={{
          vms: vmsMeta.total,
          snapshots:
            adminStats?.total_snapshots ?? quotaData?.usage?.snapshots ?? 0,
          ips: adminStats?.total_ips ?? quotaData?.usage?.ips ?? ipsMeta.total,
          pools: adminStats?.total_pools ?? poolsMeta.total,
          users: adminStats?.total_users ?? usersMeta.total,
          quotaGroups: quotaGroups.length,
          nodes: hosts.length,
        }}
        isOpen={sidebarOpen}
        onClose={() => setSidebarOpen(false)}
        onOpenProfile={() => modals.setProfileModalOpen(true)}
        onOpenQuotaGroups={() => modals.setQuotaGroupsModalOpen(true)}
        onOpenDefaultQuota={() => modals.setDefaultQuotaModalOpen(true)}
        onOpenImages={() => modals.setImagesModalOpen(true)}
        onOpenSettings={() => modals.setSettingsModalOpen(true)}
        onLogout={onLogout}
      />

      {/* Main Layout Area (offset to right of sidebar on lg screens) */}
      <div className="flex-1 lg:pl-72 flex flex-col min-w-0 min-h-screen">
        <div className="sticky top-0 z-30">
          <ImpersonationBanner
            impersonatedUser={impersonatedUser}
            isStoppingImpersonation={isStoppingImpersonation}
            onStopImpersonation={handleStopImpersonation}
          />
          <Navbar
            user={user}
            roles={roles}
            activeTabTitle={
              activeTab === "vms"
                ? t("menuInstances")
                : activeTab === "snapshots"
                ? t("menuSnapshots")
                : activeTab === "networking"
                ? t("menuNetworking")
                : activeTab === "users"
                ? t("menuUsers")
                : activeTab === "audit"
                ? t("menuAuditLog")
                : activeTab === "nodes"
                ? t("menuNodes")
                : t("menuActivity")
            }
            onToggleSidebar={() => setSidebarOpen(true)}
            onOpenProfile={() => modals.setProfileModalOpen(true)}
          />
        </div>

        <main className="flex-1 max-w-7xl w-full mx-auto px-4 sm:px-6 lg:px-8 py-6 sm:py-8 space-y-8">
          {/* Dynamic Resource Quotas / Cluster Overview Section */}
          {(isAdmin
            ? activeTab === "vms"
            : activeTab === "vms" ||
              activeTab === "snapshots" ||
              activeTab === "networking") && (
            <DashboardQuotaSection
              quotaData={quotaData}
              adminStats={adminStats}
              isClusterCardView={isClusterCardView}
              hasActiveFilters={hasActiveFilters}
              selectedUserId={selectedUserId}
              refreshing={refreshing}
              onRefresh={loadData}
            />
          )}

          {/* Filter Toolbar (Admin & Tenant Scopes) */}
          {(activeTab === "vms" ||
            activeTab === "networking" ||
            activeTab === "users") && (
            <FilterToolbar
              isAdmin={isAdmin}
              adminStats={adminStats}
              hasActiveFilters={hasActiveFilters}
              onClearFilters={resetFilters}
              selectedUserId={
                selectedUserId !== null ? String(selectedUserId) : ""
              }
              onSelectUserId={(id) =>
                setSelectedUserId(id ? parseInt(id, 10) : null)
              }
              selectedHostId={
                selectedHostId !== null ? String(selectedHostId) : ""
              }
              onSelectHostId={(id) =>
                setSelectedHostId(id ? parseInt(id, 10) : null)
              }
              selectedStatus={selectedStatus ?? ""}
              onSelectStatus={(status) => setSelectedStatus(status || null)}
              searchQuery={searchQuery}
              onSearchChange={setSearchQuery}
              hosts={hosts}
              activeTab={activeTab}
            />
          )}

          {children}
        </main>
      </div>

      {/* Dashboard Domain Modals & Overlays */}
      <DashboardModals
        {...modals}
        user={user}
        isAdmin={isAdmin}
        setUser={setUser}
        vms={vms}
        hosts={hosts}
        setHosts={setHosts}
        quotaData={quotaData}
        quotaGroups={quotaGroups}
        systemSettings={systemSettings}
        setSystemSettings={setSystemSettings}
        setRegistrationEnabled={setRegistrationEnabled}
        loadData={loadData}
        loadStaticData={loadStaticData}
        detailsVm={detailsVm}
        setDetailsVm={handleSetDetailsVm}
        handleRequestDeleteQuotaGroup={handleRequestDeleteQuotaGroup}
        handleRequestDeleteHost={handleRequestDeleteHost}
        confirmDialog={confirmDialog}
        setConfirmDialog={setConfirmDialog}
      />
    </div>
  );
}
