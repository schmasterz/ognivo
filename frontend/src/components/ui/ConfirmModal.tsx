"use client";

import React from "react";
import { AlertCircle, AlertTriangle, Info, Trash2, X, Loader2 } from "lucide-react";
import { useI18n } from "@/lib/i18n/I18nContext";
import { useModalEscape } from "@/lib/hooks/useModalEscape";

export type ConfirmVariant = "danger" | "warning" | "info" | "primary";

export interface ConfirmModalProps {
  isOpen: boolean;
  title: string;
  message: string;
  confirmText?: string;
  cancelText?: string;
  variant?: ConfirmVariant;
  isLoading?: boolean;
  confirmDisabled?: boolean;
  onConfirm: () => void | Promise<void>;
  onClose: () => void;
}

export function ConfirmModal({
  isOpen,
  title,
  message,
  confirmText,
  cancelText,
  variant = "danger",
  isLoading = false,
  confirmDisabled = false,
  onConfirm,
  onClose,
}: ConfirmModalProps) {
  const { t } = useI18n();

  useModalEscape(onClose, isOpen && !isLoading);

  if (!isOpen) return null;

  const config = {
    danger: {
      iconBg: "bg-red-500/10 text-red-600 dark:text-red-400 border border-red-500/20",
      icon: <Trash2 className="w-6 h-6 text-red-500" />,
      buttonClass: "bg-red-600 hover:bg-red-500 text-white shadow-lg shadow-red-600/25",
    },
    warning: {
      iconBg: "bg-amber-500/10 text-amber-600 dark:text-amber-400 border border-amber-500/20",
      icon: <AlertTriangle className="w-6 h-6 text-amber-500" />,
      buttonClass: "bg-amber-600 hover:bg-amber-500 text-white shadow-lg shadow-amber-600/25",
    },
    info: {
      iconBg: "bg-blue-500/10 text-blue-600 dark:text-blue-400 border border-blue-500/20",
      icon: <Info className="w-6 h-6 text-blue-500" />,
      buttonClass: "bg-blue-600 hover:bg-blue-500 text-white shadow-lg shadow-blue-600/25",
    },
    primary: {
      iconBg: "bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 border border-indigo-500/20",
      icon: <AlertCircle className="w-6 h-6 text-indigo-500" />,
      buttonClass: "bg-indigo-600 hover:bg-indigo-500 text-white shadow-lg shadow-indigo-600/25",
    },
  }[variant];

  return (
    <div
      className="fixed inset-0 z-[100] flex items-center justify-center p-4 bg-black/60 backdrop-blur-sm animate-fadeIn"
      onClick={(e) => {
        if (e.target === e.currentTarget && !isLoading) {
          onClose();
        }
      }}
    >
      <div className="relative w-full max-w-md bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-3xl p-6 sm:p-7 shadow-2xl transition-all scale-100">
        <div className="flex items-start justify-between gap-3 mb-5">
          <div className="flex items-start space-x-4 min-w-0">
            <div className={`p-3 rounded-2xl flex-shrink-0 ${config.iconBg}`}>
              {config.icon}
            </div>
            <div className="min-w-0">
              <h3 className="text-lg font-bold text-gray-900 dark:text-white tracking-tight">
                {title}
              </h3>
              <p className="text-xs text-gray-600 dark:text-gray-300 mt-1.5 leading-relaxed">
                {message}
              </p>
            </div>
          </div>
          <button
            onClick={onClose}
            disabled={isLoading}
            className="p-1.5 rounded-xl text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-800 transition disabled:opacity-40 flex-shrink-0 -mr-1 -mt-1"
          >
            <X className="w-4 h-4" />
          </button>
        </div>

        <div className="flex items-center justify-end space-x-3 pt-4 border-t border-gray-100 dark:border-gray-800">
          <button
            type="button"
            onClick={onClose}
            disabled={isLoading}
            className="px-4 py-2 rounded-xl border border-gray-200 dark:border-gray-700 text-xs font-semibold text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800 transition disabled:opacity-40"
          >
            {cancelText || t("cancel")}
          </button>

          <button
            type="button"
            onClick={onConfirm}
            disabled={isLoading || confirmDisabled}
            className={`px-5 py-2 rounded-xl text-xs font-semibold flex items-center space-x-2 transition disabled:opacity-50 ${config.buttonClass}`}
          >
            {isLoading && <Loader2 className="w-3.5 h-3.5 animate-spin" />}
            <span>{confirmText || t("confirmAction")}</span>
          </button>
        </div>
      </div>
    </div>
  );
}
