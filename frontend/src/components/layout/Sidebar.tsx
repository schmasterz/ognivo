"use client";

import React from "react";
import {
  Server,
  Globe,
  Layers,
  Sliders,
  Shield,
  LogOut,
  X,
  UserCog,
  Settings,
  Activity,
  Camera,
} from "lucide-react";
import { User } from "@/lib/api";
import { useI18n } from "@/lib/i18n/I18nContext";

interface SidebarProps {
  user: User | null;
  roles: string[];
  activeTab: "vms" | "snapshots" | "networking" | "users" | "activity" | "audit";
  onTabChange: (tab: "vms" | "snapshots" | "networking" | "users" | "activity" | "audit") => void;
  counts: {
    vms: number;
    snapshots?: number;
    ips: number;
    pools: number;
    users: number;
    quotaGroups: number;
    nodes?: number;
  };
  isOpen: boolean;
  onClose: () => void;
  onOpenProfile: () => void;
  onOpenQuotaGroups: () => void;
  onOpenDefaultQuota: () => void;
  onOpenNodes?: () => void;
  onOpenImages?: () => void;
  onOpenSettings?: () => void;
  onLogout: () => void;
}

export function Sidebar({
  user,
  roles,
  activeTab,
  onTabChange,
  counts,
  isOpen,
  onClose,
  onOpenProfile,
  onOpenQuotaGroups,
  onOpenDefaultQuota,
  onOpenNodes,
  onOpenImages,
  onOpenSettings,
  onLogout,
}: SidebarProps) {
  const { t } = useI18n();
  const isAdmin = roles.includes("admin");

  const handleNavClick = (tab: "vms" | "snapshots" | "networking" | "users" | "activity" | "audit") => {
    onTabChange(tab);
    onClose();
  };

  return (
    <>
      {/* Mobile Backdrop Overlay */}
      {isOpen && (
        <div
          className="fixed inset-0 z-40 bg-black/60 backdrop-blur-sm lg:hidden animate-fadeIn"
          onClick={onClose}
          aria-hidden="true"
        />
      )}

      {/* Sidebar Container */}
      <aside
        className={`fixed inset-y-0 left-0 z-50 w-72 bg-white dark:bg-[#0b101b] border-r border-gray-200 dark:border-gray-800/80 flex flex-col justify-between transition-transform duration-300 ease-in-out lg:translate-x-0 ${
          isOpen ? "translate-x-0 shadow-2xl" : "-translate-x-full"
        }`}
      >
        {/* Top Header & Brand */}
        <div className="p-5 border-b border-gray-200 dark:border-gray-800/60">
          <div className="flex items-center justify-between">
            <div className="flex items-center space-x-3">
              <div className="p-2.5 bg-gradient-to-br from-blue-500 to-indigo-600 rounded-2xl text-white shadow-md shadow-blue-500/20">
                <Server className="w-5 h-5" />
              </div>
              <div>
                <div className="flex items-center space-x-1.5">
                  <span className="font-extrabold text-sm text-gray-900 dark:text-white tracking-tight">
                    {t("platformTitle")}
                  </span>
                </div>
                <div className="flex items-center space-x-1 mt-0.5">
                  <span className="text-[10px] font-semibold px-1.5 py-0.2 rounded-md bg-blue-500/10 text-blue-600 dark:text-blue-400">
                    {t("poweredBy")}
                  </span>
                </div>
              </div>
            </div>

            {/* Mobile close button */}
            <button
              onClick={onClose}
              className="lg:hidden p-1.5 rounded-xl text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-800 transition"
              aria-label={t("closeMenu")}
            >
              <X className="w-5 h-5" />
            </button>
          </div>
        </div>

        {/* Navigation Links Area */}
        <div className="flex-1 overflow-y-auto px-4 py-5 space-y-6">
          {/* Main Navigation */}
          <div>
            <div className="px-3 mb-2 text-[10px] font-bold uppercase tracking-wider text-gray-400 dark:text-gray-400">
              {t("sidebarMenu")}
            </div>

            <nav className="space-y-1.5">
              {/* Workloads */}
              <button
                onClick={() => handleNavClick("vms")}
                className={`w-full flex items-center justify-between px-3.5 py-2.5 rounded-2xl text-xs font-semibold transition ${
                  activeTab === "vms"
                    ? "bg-blue-600 text-white shadow-md shadow-blue-600/25"
                    : "text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-900"
                }`}
              >
                <div className="flex items-center space-x-3">
                  <Server className="w-4 h-4" />
                  <span>{t("menuInstances")}</span>
                </div>
                <span
                  className={`px-2 py-0.5 rounded-full text-[10px] font-mono ${
                    activeTab === "vms"
                      ? "bg-white/20 text-white font-bold"
                      : "bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-400"
                  }`}
                >
                  {counts.vms}
                </span>
              </button>

              {/* Snapshots */}
              <button
                onClick={() => handleNavClick("snapshots")}
                className={`w-full flex items-center justify-between px-3.5 py-2.5 rounded-2xl text-xs font-semibold transition ${
                  activeTab === "snapshots"
                    ? "bg-blue-600 text-white shadow-md shadow-blue-600/25"
                    : "text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-900"
                }`}
              >
                <div className="flex items-center space-x-3">
                  <Camera className="w-4 h-4" />
                  <span>{t("menuSnapshots")}</span>
                </div>
                {typeof counts.snapshots === "number" && (
                  <span
                    className={`px-2 py-0.5 rounded-full text-[10px] font-mono ${
                      activeTab === "snapshots"
                        ? "bg-white/20 text-white font-bold"
                        : "bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-400"
                    }`}
                  >
                    {counts.snapshots}
                  </span>
                )}
              </button>

              {/* Networking */}
              <button
                onClick={() => handleNavClick("networking")}
                className={`w-full flex items-center justify-between px-3.5 py-2.5 rounded-2xl text-xs font-semibold transition ${
                  activeTab === "networking"
                    ? "bg-blue-600 text-white shadow-md shadow-blue-600/25"
                    : "text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-900"
                }`}
              >
                <div className="flex items-center space-x-3">
                  <Globe className="w-4 h-4" />
                  <span>{t("menuNetworking")}</span>
                </div>
                <span
                  className={`px-2 py-0.5 rounded-full text-[10px] font-mono ${
                    activeTab === "networking"
                      ? "bg-white/20 text-white font-bold"
                      : "bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-400"
                  }`}
                >
                  {counts.ips}
                </span>
              </button>

              {/* Activity Log (Tenant Only) */}
              {!isAdmin && (
                <button
                  onClick={() => handleNavClick("activity")}
                  className={`w-full flex items-center justify-between px-3.5 py-2.5 rounded-2xl text-xs font-semibold transition ${
                    activeTab === "activity"
                      ? "bg-blue-600 text-white shadow-md shadow-blue-600/25"
                      : "text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-900"
                  }`}
                >
                  <div className="flex items-center space-x-3">
                    <Activity className="w-4 h-4" />
                    <span>{t("menuActivity")}</span>
                  </div>
                </button>
              )}
            </nav>
          </div>

          {/* Administration Section */}
          {isAdmin && (
            <div>
              <div className="px-3 mb-2 text-[10px] font-bold uppercase tracking-wider text-purple-600 dark:text-purple-400 flex items-center space-x-1.5">
                <Shield className="w-3 h-3" />
                <span>{t("adminSection")}</span>
              </div>

              <nav className="space-y-1.5">
                {/* Users & Quotas Tab */}
                <button
                  onClick={() => handleNavClick("users")}
                  className={`w-full flex items-center justify-between px-3.5 py-2.5 rounded-2xl text-xs font-semibold transition ${
                    activeTab === "users"
                      ? "bg-purple-600 text-white shadow-md shadow-purple-600/25"
                      : "text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-900"
                  }`}
                >
                  <div className="flex items-center space-x-3">
                    <UserCog className="w-4 h-4" />
                    <span>{t("menuUsers")}</span>
                  </div>
                  <span
                    className={`px-2 py-0.5 rounded-full text-[10px] font-mono ${
                      activeTab === "users"
                        ? "bg-white/20 text-white font-bold"
                        : "bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-400"
                    }`}
                  >
                    {counts.users}
                  </span>
                </button>

                {/* Audit Log Tab */}
                <button
                  onClick={() => handleNavClick("audit")}
                  className={`w-full flex items-center justify-between px-3.5 py-2.5 rounded-2xl text-xs font-semibold transition ${
                    activeTab === "audit"
                      ? "bg-purple-600 text-white shadow-md shadow-purple-600/25"
                      : "text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-900"
                  }`}
                >
                  <div className="flex items-center space-x-3">
                    <Shield className="w-4 h-4 text-purple-400" />
                    <span>{t("menuAuditLog")}</span>
                  </div>
                </button>

                {/* Host Nodes & Regions Action */}
                {onOpenNodes && (
                  <button
                    onClick={() => {
                      onOpenNodes();
                      onClose();
                    }}
                    className="w-full flex items-center justify-between px-3.5 py-2.5 rounded-2xl text-xs font-medium text-gray-700 dark:text-gray-300 hover:bg-indigo-500/10 hover:text-indigo-600 dark:hover:text-indigo-400 transition group"
                  >
                    <div className="flex items-center space-x-3">
                      <Server className="w-4 h-4 text-indigo-500" />
                      <span>{t("menuNodes")}</span>
                    </div>
                    {counts.nodes !== undefined && (
                      <span className="px-2 py-0.5 rounded-full text-[10px] font-mono bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 font-semibold">
                        {counts.nodes}
                      </span>
                    )}
                  </button>
                )}

                {/* OS Images Catalog Action */}
                {onOpenImages && (
                  <button
                    onClick={() => {
                      onOpenImages();
                      onClose();
                    }}
                    className="w-full flex items-center justify-between px-3.5 py-2.5 rounded-2xl text-xs font-medium text-gray-700 dark:text-gray-300 hover:bg-indigo-500/10 hover:text-indigo-600 dark:hover:text-indigo-400 transition group"
                  >
                    <div className="flex items-center space-x-3">
                      <Layers className="w-4 h-4 text-indigo-500" />
                      <span>OS Images</span>
                    </div>
                  </button>
                )}

                {/* Quota Groups Action */}
                <button
                  onClick={() => {
                    onOpenQuotaGroups();
                    onClose();
                  }}
                  className="w-full flex items-center justify-between px-3.5 py-2.5 rounded-2xl text-xs font-medium text-gray-700 dark:text-gray-300 hover:bg-indigo-500/10 hover:text-indigo-600 dark:hover:text-indigo-400 transition group"
                >
                  <div className="flex items-center space-x-3">
                    <Layers className="w-4 h-4 text-indigo-500" />
                    <span>{t("menuQuotaGroups")}</span>
                  </div>
                  <span className="px-2 py-0.5 rounded-full text-[10px] font-mono bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 font-semibold">
                    {counts.quotaGroups}
                  </span>
                </button>

                {/* Default Quotas Action */}
                <button
                  onClick={() => {
                    onOpenDefaultQuota();
                    onClose();
                  }}
                  className="w-full flex items-center justify-between px-3.5 py-2.5 rounded-2xl text-xs font-medium text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-900 transition"
                >
                  <div className="flex items-center space-x-3">
                    <Sliders className="w-4 h-4 text-gray-400" />
                    <span>{t("menuDefaultQuotas")}</span>
                  </div>
                </button>

                {/* Platform Settings Action */}
                {onOpenSettings && (
                  <button
                    onClick={() => {
                      onOpenSettings();
                      onClose();
                    }}
                    className="w-full flex items-center justify-between px-3.5 py-2.5 rounded-2xl text-xs font-medium text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-900 transition"
                  >
                    <div className="flex items-center space-x-3">
                      <Settings className="w-4 h-4 text-gray-400" />
                      <span>{t("menuSettings")}</span>
                    </div>
                  </button>
                )}
              </nav>
            </div>
          )}
        </div>

        {/* Footer Area: User Card & Sign Out */}
        <div className="p-4 border-t border-gray-200 dark:border-gray-800/80 space-y-3 bg-gray-50/50 dark:bg-gray-950/40">
          {/* User Profile Card */}
          {user && (
            <div className="p-3 rounded-2xl bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 flex items-center justify-between shadow-sm">
              <button
                onClick={() => {
                  onOpenProfile();
                  onClose();
                }}
                className="flex items-center space-x-2.5 min-w-0 text-left hover:opacity-80 transition"
                title={t("myProfile")}
              >
                <div className="w-8 h-8 rounded-xl bg-blue-500/10 text-blue-600 dark:text-blue-400 flex items-center justify-center font-bold text-xs flex-shrink-0">
                  {user.name.charAt(0).toUpperCase()}
                </div>
                <div className="min-w-0">
                  <div className="text-xs font-bold text-gray-900 dark:text-white truncate">
                    {user.name}
                  </div>
                  <div className="text-[10px] text-gray-400 truncate">
                    {user.email}
                  </div>
                </div>
              </button>

              <button
                onClick={onLogout}
                title={t("signOut")}
                className="p-1.5 rounded-xl hover:bg-red-500/10 text-gray-400 hover:text-red-500 transition"
              >
                <LogOut className="w-4 h-4" />
              </button>
            </div>
          )}
        </div>
      </aside>
    </>
  );
}
