"use client";

import React, { useState, useRef, useEffect, useSyncExternalStore } from "react";
import { Sun, Moon, Check } from "lucide-react";
import { useTheme, ThemeMode } from "@/lib/theme/ThemeContext";
import { useI18n } from "@/lib/i18n/I18nContext";

const emptySubscribe = () => () => {};

export function ThemeToggle() {
  const { theme, resolvedTheme, setTheme } = useTheme();
  const { t } = useI18n();
  const [open, setOpen] = useState(false);
  const mounted = useSyncExternalStore(emptySubscribe, () => true, () => false);
  const dropdownRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    const handleClickOutside = (event: MouseEvent) => {
      if (dropdownRef.current && !dropdownRef.current.contains(event.target as Node)) {
        setOpen(false);
      }
    };
    document.addEventListener("mousedown", handleClickOutside);
    return () => document.removeEventListener("mousedown", handleClickOutside);
  }, []);

  const options: { mode: ThemeMode; labelKey: "themeLight" | "themeDark"; icon: typeof Sun }[] = [
    { mode: "light", labelKey: "themeLight", icon: Sun },
    { mode: "dark", labelKey: "themeDark", icon: Moon },
  ];

  return (
    <div className="relative" ref={dropdownRef}>
      <button
        onClick={() => setOpen(!open)}
        title={t("theme")}
        className="p-2 rounded-xl bg-gray-100 hover:bg-gray-200 dark:bg-gray-900 dark:hover:bg-gray-800 text-gray-700 dark:text-gray-300 border border-gray-200 dark:border-gray-800 transition flex items-center justify-center"
      >
        {!mounted ? (
          <div className="w-4 h-4" />
        ) : resolvedTheme === "dark" ? (
          <Moon className="w-4 h-4 text-blue-400" />
        ) : (
          <Sun className="w-4 h-4 text-amber-500" />
        )}
      </button>

      {open && (
        <div className="absolute right-0 mt-2 w-40 rounded-2xl bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 shadow-2xl py-1.5 z-50 text-xs">
          {options.map((opt) => {
            const Icon = opt.icon;
            const isSelected = theme === opt.mode;

            return (
              <button
                key={opt.mode}
                onClick={() => {
                  setTheme(opt.mode);
                  setOpen(false);
                }}
                className={`w-full px-3.5 py-2.5 flex items-center justify-between space-x-2.5 transition text-left ${
                  isSelected
                    ? "bg-blue-50 dark:bg-blue-950/40 text-blue-600 dark:text-blue-400 font-semibold"
                    : "text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800/60"
                }`}
              >
                <div className="flex items-center space-x-2.5">
                  <Icon className="w-3.5 h-3.5 flex-shrink-0" />
                  <span>{t(opt.labelKey)}</span>
                </div>
                {isSelected && <Check className="w-3.5 h-3.5 text-blue-600 dark:text-blue-400" />}
              </button>
            );
          })}
        </div>
      )}
    </div>
  );
}
