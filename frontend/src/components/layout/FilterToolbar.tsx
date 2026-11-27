"use client";

import React from "react";
import { Server, Search, Shield, Filter, X } from "lucide-react";
import { AdminStats, AdminUserItem, HostNode } from "@/lib/api";
import { SearchableUserSelect } from "@/components/ui/SearchableUserSelect";
import { useI18n } from "@/lib/i18n/I18nContext";

export interface FilterToolbarProps {
  isAdmin: boolean;
  adminStats: AdminStats | null;
  hasActiveFilters: boolean;
  onClearFilters: () => void;
  allAdminUsers?: AdminUserItem[];
  selectedUserId: string;
  onSelectUserId: (id: string) => void;
  selectedHostId: string;
  onSelectHostId: (id: string) => void;
  selectedStatus: string;
  onSelectStatus: (status: string) => void;
  searchQuery: string;
  onSearchChange: (search: string) => void;
  hosts: HostNode[];
  activeTab: "vms" | "snapshots" | "networking" | "users";
}

export function FilterToolbar({
  isAdmin,
  adminStats,
  hasActiveFilters,
  onClearFilters,
  allAdminUsers,
  selectedUserId,
  onSelectUserId,
  selectedHostId,
  onSelectHostId,
  selectedStatus,
  onSelectStatus,
  searchQuery,
  onSearchChange,
  hosts,
  activeTab,
}: FilterToolbarProps) {
  const { t } = useI18n();

  const statusSelectValue =
    activeTab === "networking" &&
    selectedStatus !== "allocated" &&
    selectedStatus !== "cooling"
      ? ""
      : selectedStatus;

  const renderStatusOptions = () => {
    if (activeTab === "users") {
      return (
        <>
          <option value="active">{t("statusActive")}</option>
          <option value="pending">{t("statusPending")}</option>
          <option value="suspended">{t("statusSuspended")}</option>
          <option value="inactive">{t("statusInactive")}</option>
          <option value="deleting">{t("statusDeleting")}</option>
          <option value="deletion_failed">{t("statusDeletionFailed")}</option>
        </>
      );
    }

    if (activeTab === "networking") {
      return (
        <>
          <option value="allocated">{t("statusAllocatedFilter")}</option>
          <option value="cooling">{t("statusCoolingFilter")}</option>
        </>
      );
    }

    return (
      <>
        <option value="running">{t("vmStatusRunning")}</option>
        <option value="stopped">{t("vmStatusStopped")}</option>
        <option value="provisioning">{t("vmStatusProvisioning")}</option>
        <option value="suspended">{t("statusSuspended")}</option>
        <option value="deleting">{t("vmStatusDeleting")}</option>
        <option value="failed">{t("vmStatusFailed")}</option>
      </>
    );
  };

  return (
    <>
      {/* Admin Oversight Header & Cluster Overview Filter Bar */}
      {isAdmin && adminStats && (
        <div className="relative z-30 p-4 sm:p-5 rounded-3xl bg-gradient-to-r from-blue-500/10 via-indigo-500/10 to-purple-500/10 border border-blue-500/20 backdrop-blur-md shadow-sm">
          <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-2 mb-3">
            <div className="flex items-center space-x-2 text-xs font-semibold text-blue-600 dark:text-blue-400 uppercase tracking-wider">
              <Shield className="w-4 h-4" />
              <span>{t("adminScope")}</span>
            </div>
            <div className="flex items-center space-x-3 text-[11px] text-gray-500 dark:text-gray-400">
              <span>
                {t("totalTenants")}: <span className="font-semibold text-gray-800 dark:text-gray-200">{adminStats.total_users}</span> • {t("globalVms")}: <span className="font-semibold text-gray-800 dark:text-gray-200">{adminStats.total_vms}</span>
              </span>
              {hasActiveFilters && (
                <button
                  onClick={onClearFilters}
                  className="text-xs text-blue-600 dark:text-blue-400 hover:underline flex items-center space-x-1 font-medium"
                >
                  <X className="w-3.5 h-3.5" />
                  <span>{t("clearFilters")}</span>
                </button>
              )}
            </div>
          </div>

          {/* Filter Toolbar for Admin */}
          <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
            {/* Searchable Tenant Selector */}
            <SearchableUserSelect
              users={allAdminUsers}
              selectedUserId={selectedUserId}
              onSelectUser={onSelectUserId}
            />

            {/* Node / Cluster Selector */}
            <div className="relative">
              <Server className="w-4 h-4 text-gray-400 absolute left-3 top-3 pointer-events-none" />
              <select
                value={selectedHostId}
                onChange={(e) => onSelectHostId(e.target.value)}
                className="w-full bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-xl px-3.5 py-2 pl-9 text-xs text-gray-800 dark:text-gray-200 focus:outline-none focus:border-blue-500 transition appearance-none cursor-pointer"
              >
                <option value="">{t("allNodes")}</option>
                {hosts.map((h) => (
                  <option key={h.id} value={h.id}>
                    {h.name} {h.region ? `(${h.region})` : ""}
                  </option>
                ))}
              </select>
            </div>

            {/* Status Filter */}
            <div className="relative">
              <Filter className="w-4 h-4 text-gray-400 absolute left-3 top-3 pointer-events-none" />
              <select
                value={statusSelectValue}
                onChange={(e) => onSelectStatus(e.target.value)}
                className="w-full bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-xl px-3.5 py-2 pl-9 text-xs text-gray-800 dark:text-gray-200 focus:outline-none focus:border-blue-500 transition appearance-none cursor-pointer"
              >
                <option value="">{t("allStatuses")}</option>
                {renderStatusOptions()}
              </select>
            </div>

            {/* Keyword Search */}
            <div className="relative">
              <Search className="w-4 h-4 text-gray-400 absolute left-3 top-3 pointer-events-none" />
              <input
                type="text"
                value={searchQuery}
                onChange={(e) => onSearchChange(e.target.value)}
                placeholder={t("searchPlaceholder")}
                className="w-full bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-xl px-3.5 py-2 pl-9 pr-8 text-xs text-gray-800 dark:text-gray-200 placeholder-gray-400 focus:outline-none focus:border-blue-500 transition"
              >
              </input>
              {searchQuery && (
                <button
                  onClick={() => onSearchChange("")}
                  className="absolute right-2.5 top-2.5 text-gray-400 hover:text-gray-600 dark:hover:text-gray-200"
                >
                  <X className="w-3.5 h-3.5" />
                </button>
              )}
            </div>
          </div>
        </div>
      )}

      {/* Customer Filter Toolbar */}
      {!isAdmin && (
        <div className="p-4 sm:p-5 rounded-3xl bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 shadow-sm space-y-3">
          <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-2">
            <div className="flex items-center space-x-2 text-xs font-semibold text-gray-700 dark:text-gray-300 uppercase tracking-wider">
              <Filter className="w-4 h-4 text-blue-500" />
              <span>{t("filterScope")}</span>
            </div>
            {hasActiveFilters && (
              <button
                onClick={onClearFilters}
                className="text-xs text-blue-600 dark:text-blue-400 hover:underline flex items-center space-x-1 font-medium"
              >
                <X className="w-3.5 h-3.5" />
                <span>{t("clearFilters")}</span>
              </button>
            )}
          </div>

          <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
            {/* Node / Cluster filter */}
            <div className="relative">
              <Server className="w-4 h-4 text-gray-400 absolute left-3 top-3 pointer-events-none" />
              <select
                value={selectedHostId}
                onChange={(e) => onSelectHostId(e.target.value)}
                className="w-full bg-gray-50 dark:bg-gray-800/60 border border-gray-200 dark:border-gray-700/60 rounded-xl px-3.5 py-2 pl-9 text-xs text-gray-800 dark:text-gray-200 focus:outline-none focus:border-blue-500 transition appearance-none cursor-pointer"
              >
                <option value="">{t("allNodes")}</option>
                {hosts.map((h) => (
                  <option key={h.id} value={h.id}>
                    {h.name} {h.region ? `(${h.region})` : ""}
                  </option>
                ))}
              </select>
            </div>

            {/* Status filter */}
            <div className="relative">
              <Filter className="w-4 h-4 text-gray-400 absolute left-3 top-3 pointer-events-none" />
              <select
                value={statusSelectValue}
                onChange={(e) => onSelectStatus(e.target.value)}
                className="w-full bg-gray-50 dark:bg-gray-800/60 border border-gray-200 dark:border-gray-700/60 rounded-xl px-3.5 py-2 pl-9 text-xs text-gray-800 dark:text-gray-200 focus:outline-none focus:border-blue-500 transition appearance-none cursor-pointer"
              >
                <option value="">{t("allStatuses")}</option>
                {renderStatusOptions()}
              </select>
            </div>

            {/* Keyword Search */}
            <div className="relative">
              <Search className="w-4 h-4 text-gray-400 absolute left-3 top-3 pointer-events-none" />
              <input
                type="text"
                value={searchQuery}
                onChange={(e) => onSearchChange(e.target.value)}
                placeholder={t("searchPlaceholder")}
                className="w-full bg-gray-50 dark:bg-gray-800/60 border border-gray-200 dark:border-gray-700/60 rounded-xl px-3.5 py-2 pl-9 pr-8 text-xs text-gray-800 dark:text-gray-200 placeholder-gray-400 focus:outline-none focus:border-blue-500 transition"
              />
              {searchQuery && (
                <button
                  onClick={() => onSearchChange("")}
                  className="absolute right-2.5 top-2.5 text-gray-400 hover:text-gray-600 dark:hover:text-gray-200"
                >
                  <X className="w-3.5 h-3.5" />
                </button>
              )}
            </div>
          </div>
        </div>
      )}
    </>
  );
}
