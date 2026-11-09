"use client";

import React, { useEffect } from "react";
import { useRouter } from "next/navigation";
import { UsersTab, useDashboard } from "@/components";
import { useAuth } from "@/lib/auth/AuthContext";
import { useGlobalFilters } from "@/lib/filters/GlobalFilterContext";

export default function UsersPage() {
  const router = useRouter();
  const { user, isAdmin, authLoading, registrationEnabled } = useAuth();
  const { perPage } = useGlobalFilters();
  const {
    adminUsers,
    usersMeta,
    setUsersPage,
    handleToggleRegistration,
    handlePerPageChange,
    modals,
    handleRequestDeleteUser,
    handleRequestToggleUserStatus,
    handleRequestImpersonateUser,
  } = useDashboard();

  useEffect(() => {
    if (!authLoading && user && !isAdmin) {
      router.replace("/instances");
    }
  }, [authLoading, user, isAdmin, router]);

  if (!isAdmin) {
    return null;
  }

  return (
    <UsersTab
      users={adminUsers}
      currentUserId={user?.id}
      registrationEnabled={registrationEnabled}
      onToggleRegistration={handleToggleRegistration}
      onCreateUser={() => modals.setCreateUserModalOpen(true)}
      onEditUser={(u) => modals.setEditUserTarget(u)}
      onDeleteUser={(userId, name) => handleRequestDeleteUser(userId, name)}
      onToggleUserStatus={handleRequestToggleUserStatus}
      onImpersonateUser={handleRequestImpersonateUser}
      onOpenDefaultQuota={() => modals.setDefaultQuotaModalOpen(true)}
      onOpenQuotaGroups={() => modals.setQuotaGroupsModalOpen(true)}
      onAssignQuotaGroup={(u) => modals.setAssignQuotaGroupTarget(u)}
      pagination={{
        currentPage: usersMeta.current_page,
        lastPage: usersMeta.last_page,
        perPage,
        total: usersMeta.total,
        onPageChange: setUsersPage,
        onPerPageChange: handlePerPageChange,
      }}
    />
  );
}
