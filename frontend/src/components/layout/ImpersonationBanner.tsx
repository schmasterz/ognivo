"use client";

import React from "react";
import { AlertTriangle } from "lucide-react";
import { ImpersonatedUserInfo } from "@/lib/auth/AuthContext";
import { useI18n } from "@/lib/i18n/I18nContext";

export interface ImpersonationBannerProps {
  impersonatedUser: ImpersonatedUserInfo | null;
  isStoppingImpersonation: boolean;
  onStopImpersonation: () => void;
}

export function ImpersonationBanner({
  impersonatedUser,
  isStoppingImpersonation,
  onStopImpersonation,
}: ImpersonationBannerProps) {
  const { t } = useI18n();

  if (!impersonatedUser) {
    return null;
  }

  return (
    <div className="bg-amber-600 dark:bg-amber-700 text-white px-4 sm:px-6 py-2.5 shadow-md flex items-center justify-between transition-all">
      <div className="flex items-center space-x-2">
        <AlertTriangle className="w-4 h-4 flex-shrink-0 text-amber-200" />
        <span className="text-xs sm:text-sm font-medium">
          {t("actingAsNotice")}{" "}
          <strong className="font-semibold">{impersonatedUser.name}</strong> (
          {impersonatedUser.email})
        </span>
      </div>
      <button
        onClick={onStopImpersonation}
        disabled={isStoppingImpersonation}
        className="px-3 py-1 bg-white hover:bg-amber-50 text-amber-900 rounded-lg text-xs font-semibold shadow-sm transition disabled:opacity-50 cursor-pointer"
      >
        {isStoppingImpersonation ? "..." : t("stopImpersonation")}
      </button>
    </div>
  );
}
