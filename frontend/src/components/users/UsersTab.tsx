"use client";

import React from "react";
import { AdminUserItem } from "@/lib/api";
import { UserManagementTable } from "./UserManagementTable";

export interface UsersTabProps {
  users: AdminUserItem[];
  currentUserId?: number;
  registrationEnabled: boolean;
  onToggleRegistration: (enabled: boolean) => void;
  onCreateUser: () => void;
  onEditUser: (user: AdminUserItem) => void;
  onDeleteUser: (userId: number, name: string) => void;
  onToggleUserStatus: (user: AdminUserItem, nextStatus: "active" | "suspended") => void;
  onImpersonateUser?: (user: AdminUserItem) => void;
  onOpenDefaultQuota: () => void;
  onOpenQuotaGroups: () => void;
  onAssignQuotaGroup: (user: AdminUserItem) => void;
  pagination: {
    currentPage: number;
    lastPage: number;
    perPage: number;
    total: number;
    onPageChange: (page: number) => void;
    onPerPageChange: (perPage: number) => void;
  };
}

export function UsersTab({
  users,
  currentUserId,
  registrationEnabled,
  onToggleRegistration,
  onCreateUser,
  onEditUser,
  onDeleteUser,
  onToggleUserStatus,
  onImpersonateUser,
  onOpenDefaultQuota,
  onOpenQuotaGroups,
  onAssignQuotaGroup,
  pagination,
}: UsersTabProps) {
  return (
    <UserManagementTable
      users={users}
      currentUserId={currentUserId}
      registrationEnabled={registrationEnabled}
      onToggleRegistration={onToggleRegistration}
      onCreateUser={onCreateUser}
      onEditUser={onEditUser}
      onDeleteUser={onDeleteUser}
      onToggleUserStatus={onToggleUserStatus}
      onImpersonateUser={onImpersonateUser}
      onOpenDefaultQuota={onOpenDefaultQuota}
      onOpenQuotaGroups={onOpenQuotaGroups}
      onAssignQuotaGroup={onAssignQuotaGroup}
      pagination={pagination}
    />
  );
}
