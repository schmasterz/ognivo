"use client";

import React from "react";
import { Menu } from "lucide-react";
import { User } from "@/lib/api";
import { ThemeToggle } from "./ThemeToggle";
import { LanguageToggle } from "./LanguageToggle";
import { WsStatusBadge } from "./WsStatusBadge";
import { useI18n } from "@/lib/i18n/I18nContext";

interface NavbarProps {
  user: User | null;
  roles: string[];
  activeTabTitle?: string;
  onToggleSidebar: () => void;
  onOpenProfile?: () => void;
}

export function Navbar({
  user,
  roles,
  activeTabTitle,
  onToggleSidebar,
  onOpenProfile,
}: NavbarProps) {
  const { t } = useI18n();
  const isAdmin = roles.includes("admin");

  return (
    <header className="border-b border-gray-200 dark:border-gray-800 bg-white/80 dark:bg-gray-950/70 backdrop-blur-md sticky top-0 z-30 transition-colors">
      <div className="w-full px-4 sm:px-6 lg:px-8 h-16 flex items-center justify-between">
        {/* Left: Mobile hamburger & Active view title */}
        <div className="flex items-center space-x-3">
          <button
            onClick={onToggleSidebar}
            className="lg:hidden p-2 rounded-xl text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800 transition"
            aria-label={t("openMenu")}
          >
            <Menu className="w-5 h-5" />
          </button>

          <div>
            <h1 className="text-sm sm:text-base font-bold text-gray-900 dark:text-white tracking-tight">
              {activeTabTitle || t("platformTitle")}
            </h1>
            <p className="text-[11px] text-gray-500 dark:text-gray-400 hidden sm:block">
              {t("platformSubtitle")}
            </p>
          </div>
        </div>

        {/* Right: Quick actions & controls */}
        <div className="flex items-center space-x-2.5">
          <WsStatusBadge />
          <LanguageToggle />
          <ThemeToggle />

          {user && (
            <div className="flex items-center pl-2 sm:pl-3 border-l border-gray-200 dark:border-gray-800">
              <button
                onClick={onOpenProfile}
                title={t("myProfile")}
                className="flex items-center space-x-2 p-1.5 rounded-xl hover:bg-gray-100 dark:hover:bg-gray-900 transition"
              >
                <div className="w-7 h-7 rounded-xl bg-blue-500/10 text-blue-600 dark:text-blue-400 flex items-center justify-center font-bold text-xs">
                  {user.name.charAt(0).toUpperCase()}
                </div>
                <div className="hidden md:block text-left text-xs">
                  <div className="font-semibold text-gray-900 dark:text-white flex items-center space-x-1">
                    <span>{user.name}</span>
                    {isAdmin && (
                      <span className="inline-flex items-center px-1.5 py-0.2 rounded text-[9px] font-semibold bg-amber-500/10 text-amber-600 dark:text-amber-400">
                        {t("adminBadge")}
                      </span>
                    )}
                  </div>
                </div>
              </button>
            </div>
          )}
        </div>
      </div>
    </header>
  );
}
