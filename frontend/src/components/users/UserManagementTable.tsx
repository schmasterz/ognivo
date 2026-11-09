"use client";

import React from "react";
import { UserPlus, Sliders, Edit2, Trash2, Shield, User, Layers, Sparkles, PauseCircle, PlayCircle, CheckCircle2, Ban, Clock, Loader2, AlertTriangle, LogIn } from "lucide-react";
import { AdminUserItem } from "@/lib/api";
import { useI18n } from "@/lib/i18n/I18nContext";
import { PaginationBar } from "@/components/ui/PaginationBar";

interface UserManagementTableProps {
  users: AdminUserItem[];
  currentUserId?: number;
  registrationEnabled?: boolean;
  onToggleRegistration?: (enabled: boolean) => void;
  onCreateUser: () => void;
  onEditUser: (user: AdminUserItem) => void;
  onDeleteUser: (userId: number, name: string) => void;
  onToggleUserStatus?: (user: AdminUserItem, nextStatus: "active" | "suspended") => void;
  onImpersonateUser?: (user: AdminUserItem) => void;
  onOpenDefaultQuota: () => void;
  onOpenQuotaGroups: () => void;
  onAssignQuotaGroup: (user: AdminUserItem) => void;
  pagination?: {
    currentPage: number;
    lastPage: number;
    perPage: number;
    total: number;
    onPageChange: (page: number) => void;
    onPerPageChange?: (perPage: number) => void;
  };
}

export function UserManagementTable({
  users,
  currentUserId,
  onCreateUser,
  onEditUser,
  onDeleteUser,
  onToggleUserStatus,
  onImpersonateUser,
  onOpenDefaultQuota,
  onOpenQuotaGroups,
  onAssignQuotaGroup,
  pagination,
}: UserManagementTableProps) {
  const { t } = useI18n();

  const formatQuotaLimit = (val: number | undefined, unit: string = "") => {
    if (val === undefined) return "—";
    if (val === -1) return "∞";
    return `${val} ${unit}`.trim();
  };

  return (
    <div className="space-y-4 animate-fadeIn">
      {/* Top action header */}
      <div className="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4">
        <div>
          <h2 className="text-lg font-bold text-gray-900 dark:text-white tracking-tight">
            {t("usersManagement")}
          </h2>
          <p className="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
            {t("usersSubtitle")}
          </p>
        </div>

        <div className="flex items-center flex-wrap gap-2.5">
          <button
            onClick={onOpenQuotaGroups}
            className="flex items-center space-x-2 px-3.5 py-2 rounded-xl bg-indigo-500/10 hover:bg-indigo-500/20 text-indigo-600 dark:text-indigo-400 border border-indigo-500/30 text-xs font-semibold transition"
          >
            <Layers className="w-3.5 h-3.5" />
            <span>{t("manageQuotaGroups")}</span>
          </button>

          <button
            onClick={onOpenDefaultQuota}
            className="flex items-center space-x-2 px-3.5 py-2 rounded-xl bg-gray-100 hover:bg-gray-200 dark:bg-gray-800 dark:hover:bg-gray-700 text-gray-700 dark:text-gray-200 text-xs font-medium transition"
          >
            <Sliders className="w-3.5 h-3.5" />
            <span>{t("defaultQuotas")}</span>
          </button>

          <button
            onClick={onCreateUser}
            className="flex items-center space-x-2 px-4 py-2 rounded-xl bg-blue-600 hover:bg-blue-500 text-white text-xs font-semibold shadow-md shadow-blue-500/20 transition"
          >
            <UserPlus className="w-3.5 h-3.5" />
            <span>{t("createUser")}</span>
          </button>
        </div>
      </div>

      {/* Users table */}
      <div className="bg-white dark:bg-gray-900/60 border border-gray-200 dark:border-gray-800 rounded-2xl overflow-hidden backdrop-blur-sm shadow-sm dark:shadow-none transition-colors">
        <div className="overflow-x-auto">
          <table className="w-full text-left text-sm text-gray-600 dark:text-gray-300">
            <thead className="bg-gray-50 dark:bg-gray-950/60 text-xs uppercase text-gray-500 dark:text-gray-400 border-b border-gray-200 dark:border-gray-800 font-semibold tracking-wider">
              <tr>
                <th scope="col" className="px-6 py-4">{t("fullName")}</th>
                <th scope="col" className="px-6 py-4">{t("colStatus")}</th>
                <th scope="col" className="px-6 py-4">{t("colRoles")}</th>
                <th scope="col" className="px-6 py-4">{t("quotaGroup")}</th>
                <th scope="col" className="px-6 py-4">{t("colWorkloadsCount")}</th>
                <th scope="col" className="px-6 py-4">{t("colIpsCount")}</th>
                <th scope="col" className="px-6 py-4">{t("colQuotas")}</th>
                <th scope="col" className="px-6 py-4 text-right">{t("colActions")}</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-200 dark:divide-gray-800/60">
              {users.length === 0 ? (
                <tr>
                  <td colSpan={8} className="px-6 py-10 text-center text-xs text-gray-500 dark:text-gray-400">
                    {t("noUsersFound")}
                  </td>
                </tr>
              ) : (
              users.map((u) => {
                const isAdminUser = u.roles.some((r) => r.name === "admin");
                const isCurrent = u.id === currentUserId;
                const quota = u.quota;
                const group = u.quota_group;
                const userStatus = u.status || "active";

                return (
                  <tr key={u.id} className="hover:bg-gray-50 dark:hover:bg-gray-800/40 transition">
                    <td className="px-6 py-4">
                      <div className="font-semibold text-gray-900 dark:text-white flex items-center space-x-2">
                        <span>{u.name}</span>
                        {isCurrent && (
                          <span className="text-[10px] px-2 py-0.5 rounded-full bg-blue-500/10 text-blue-600 dark:text-blue-400 font-medium">
                            You
                          </span>
                        )}
                      </div>
                      <div className="text-xs text-gray-500 dark:text-gray-400 mt-0.5">{u.email}</div>
                      {u.project_name ? (
                        <div className="mt-1 font-mono text-[10px] text-indigo-600 dark:text-indigo-400 bg-indigo-50 dark:bg-indigo-950/60 px-1.5 py-0.5 rounded inline-block">
                          Project: {u.project_name}
                        </div>
                      ) : (
                        <div className="mt-1 font-mono text-[10px] text-gray-400 dark:text-gray-500">
                          Project: Not assigned yet
                        </div>
                      )}
                    </td>

                    <td className="px-6 py-4">
                      {userStatus === "pending" ? (
                        <span className="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-amber-500/10 text-amber-600 dark:text-amber-400 border border-amber-500/20">
                          <Clock className="w-3 h-3 mr-1" />
                          {t("statusPending")}
                        </span>
                      ) : userStatus === "suspended" ? (
                        <span className="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-500/10 text-red-600 dark:text-red-400 border border-red-500/20">
                          <Ban className="w-3 h-3 mr-1" />
                          {t("statusSuspended")}
                        </span>
                      ) : userStatus === "deleting" ? (
                        <span className="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-amber-500/10 text-amber-600 dark:text-amber-400 border border-amber-500/20">
                          <Loader2 className="w-3 h-3 mr-1 animate-spin" />
                          {t("statusDeleting") || "Deleting"}
                        </span>
                      ) : userStatus === "deletion_failed" ? (
                        <span className="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-rose-500/10 text-rose-600 dark:text-rose-400 border border-rose-500/20">
                          <AlertTriangle className="w-3 h-3 mr-1" />
                          {t("statusDeletionFailed") || "Deletion Failed"}
                        </span>
                      ) : userStatus === "inactive" ? (
                        <span className="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-500/10 text-gray-600 dark:text-gray-400 border border-gray-500/20">
                          <Clock className="w-3 h-3 mr-1" />
                          {t("statusInactive")}
                        </span>
                      ) : (
                        <span className="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border border-emerald-500/20">
                          <CheckCircle2 className="w-3 h-3 mr-1" />
                          {t("statusActive")}
                        </span>
                      )}
                    </td>

                    <td className="px-6 py-4">
                      {isAdminUser ? (
                        <span className="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-purple-500/10 text-purple-600 dark:text-purple-400 border border-purple-500/20">
                          <Shield className="w-3 h-3 mr-1" />
                          {t("roleAdmin")}
                        </span>
                      ) : (
                        <span className="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-500/10 text-blue-600 dark:text-blue-400 border border-blue-500/20">
                          <User className="w-3 h-3 mr-1" />
                          {t("roleCustomer")}
                        </span>
                      )}
                    </td>

                    {/* Quota Group Column */}
                    <td className="px-6 py-4">
                      <button
                        onClick={() => onAssignQuotaGroup(u)}
                        title={t("assignQuotaGroup")}
                        className="inline-flex items-center space-x-1.5 px-2.5 py-1 rounded-xl text-xs font-semibold bg-gray-100 hover:bg-indigo-500/10 dark:bg-gray-800 dark:hover:bg-indigo-500/20 text-gray-800 hover:text-indigo-600 dark:text-gray-200 dark:hover:text-indigo-400 border border-gray-200 dark:border-gray-700/80 hover:border-indigo-400 dark:hover:border-indigo-500/40 transition cursor-pointer"
                      >
                        <Layers className="w-3 h-3 text-indigo-500" />
                        <span>{group ? group.name : t("noQuotaGroup")}</span>
                        {group?.is_default && (
                          <Sparkles className="w-2.5 h-2.5 text-amber-500 ml-0.5" />
                        )}
                      </button>
                    </td>

                    <td className="px-6 py-4 font-mono text-xs">
                      <span className="px-2 py-1 rounded-lg bg-gray-100 dark:bg-gray-800 font-semibold text-gray-900 dark:text-white">
                        {u.virtual_machines_count ?? 0}
                      </span>
                    </td>

                    <td className="px-6 py-4 font-mono text-xs">
                      <span className="px-2 py-1 rounded-lg bg-gray-100 dark:bg-gray-800 font-semibold text-gray-900 dark:text-white">
                        {u.ip_allocations_count ?? 0}
                      </span>
                    </td>

                    <td className="px-6 py-4 text-xs">
                      <div className="flex items-center space-x-2 text-gray-600 dark:text-gray-300">
                        <span title="Max VMs" className="font-semibold text-blue-600 dark:text-blue-400">
                          {formatQuotaLimit(quota?.max_vms)} VMs
                        </span>
                        <span className="text-gray-300 dark:text-gray-700">|</span>
                        <span title="Max CPU">
                          {formatQuotaLimit(quota?.max_cpu_cores)} Cores
                        </span>
                        <span className="text-gray-300 dark:text-gray-700">|</span>
                        <span title="Max RAM">
                          {quota?.max_ram_mb === -1 ? "∞" : `${Math.round((quota?.max_ram_mb || 0) / 1024)}GB`}
                        </span>
                        <span className="text-gray-300 dark:text-gray-700">|</span>
                        <span title="Max Disk">
                          {formatQuotaLimit(quota?.max_storage_gb)}GB
                        </span>
                        <span className="text-gray-300 dark:text-gray-700">|</span>
                        <span title="Max IPs">
                          {formatQuotaLimit(quota?.max_ips)} IPs
                        </span>
                        <span className="text-gray-300 dark:text-gray-700">|</span>
                        <span title="Max Snapshots">
                          {formatQuotaLimit(quota?.max_snapshots)} Snaps
                        </span>
                      </div>
                    </td>

                    <td className="px-6 py-4 text-right">
                      <div className="flex items-center justify-end space-x-2">
                        {onToggleUserStatus && (
                          userStatus === "suspended" ? (
                            <button
                              onClick={() => onToggleUserStatus(u, "active")}
                              disabled={isCurrent}
                              title={t("activateUser")}
                              className="p-1.5 rounded-lg bg-emerald-500/10 hover:bg-emerald-500/20 text-emerald-600 dark:text-emerald-400 transition disabled:opacity-30 disabled:pointer-events-none"
                            >
                              <PlayCircle className="w-4 h-4" />
                            </button>
                          ) : (
                            <button
                              onClick={() => onToggleUserStatus(u, "suspended")}
                              disabled={isCurrent || userStatus === "deleting"}
                              title={t("suspendUser")}
                              className="p-1.5 rounded-lg bg-amber-500/10 hover:bg-amber-500/20 text-amber-600 dark:text-amber-400 transition disabled:opacity-30 disabled:pointer-events-none"
                            >
                              <PauseCircle className="w-4 h-4" />
                            </button>
                          )
                        )}

                        {onImpersonateUser && !isAdminUser && !isCurrent && userStatus === "active" && (
                          <button
                            onClick={() => onImpersonateUser(u)}
                            title={t("impersonateUser")}
                            className="p-1.5 rounded-lg bg-indigo-500/10 hover:bg-indigo-500/20 text-indigo-600 dark:text-indigo-400 transition"
                          >
                            <LogIn className="w-4 h-4" />
                          </button>
                        )}

                        <button
                          onClick={() => onEditUser(u)}
                          disabled={userStatus === "deleting"}
                          title={t("editUser")}
                          className="p-1.5 rounded-lg bg-gray-100 hover:bg-gray-200 dark:bg-gray-800 dark:hover:bg-gray-700 text-gray-600 dark:text-gray-300 hover:text-gray-900 dark:hover:text-white transition disabled:opacity-30 disabled:pointer-events-none"
                        >
                          <Edit2 className="w-4 h-4" />
                        </button>

                        <button
                          onClick={() => onDeleteUser(u.id, u.name)}
                          disabled={isCurrent || userStatus === "deleting"}
                          title={t("deleteUser")}
                          className="p-1.5 rounded-lg bg-gray-100 hover:bg-red-500/20 dark:bg-gray-800 dark:hover:bg-red-500/20 text-gray-600 dark:text-gray-300 hover:text-red-500 transition disabled:opacity-30 disabled:pointer-events-none"
                        >
                          <Trash2 className="w-4 h-4" />
                        </button>
                      </div>
                    </td>
                  </tr>
                );
              })
              )}
            </tbody>
          </table>
        </div>
        {pagination && (
          <PaginationBar
            currentPage={pagination.currentPage}
            lastPage={pagination.lastPage}
            perPage={pagination.perPage}
            total={pagination.total}
            onPageChange={pagination.onPageChange}
            onPerPageChange={pagination.onPerPageChange}
          />
        )}
      </div>
    </div>
  );
}
