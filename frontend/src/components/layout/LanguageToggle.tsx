"use client";

import React, { useState, useRef, useEffect } from "react";
import { Globe, Laptop } from "lucide-react";
import { useI18n, LocalePreference } from "@/lib/i18n/I18nContext";

export function LanguageToggle() {
  const { locale, preference, setPreference, t } = useI18n();
  const [open, setOpen] = useState(false);
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

  const options: { pref: LocalePreference; label: string; icon: string | null }[] = [
    { pref: "browser", label: t("browserDefault"), icon: null },
    { pref: "en", label: "English", icon: "🇺🇸" },
    { pref: "bg", label: "Български", icon: "🇧🇬" },
  ];

  return (
    <div className="relative" ref={dropdownRef}>
      <button
        onClick={() => setOpen(!open)}
        title={t("language")}
        className="px-2.5 py-1.5 rounded-xl bg-gray-100 hover:bg-gray-200 dark:bg-gray-900 dark:hover:bg-gray-800 text-gray-700 dark:text-gray-300 border border-gray-200 dark:border-gray-800 transition flex items-center space-x-1.5 text-xs font-semibold"
      >
        <Globe className="w-3.5 h-3.5 text-blue-500" />
        <span className="uppercase">{locale}</span>
        {preference === "browser" && (
          <span className="w-1.5 h-1.5 rounded-full bg-emerald-500" title={t("browserDefault")} />
        )}
      </button>

      {open && (
        <div className="absolute right-0 mt-2 w-52 rounded-2xl bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 shadow-2xl py-1.5 z-50 text-xs">
          {options.map((opt) => {
            const isSelected = preference === opt.pref;

            return (
              <button
                key={opt.pref}
                onClick={() => {
                  setPreference(opt.pref);
                  setOpen(false);
                }}
                className={`w-full px-3.5 py-2.5 flex items-center space-x-2.5 transition text-left ${
                  isSelected
                    ? "bg-blue-50 dark:bg-blue-950/40 text-blue-600 dark:text-blue-400 font-semibold"
                    : "text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800/60"
                }`}
              >
                {opt.icon ? (
                  <span>{opt.icon}</span>
                ) : (
                  <Laptop className="w-3.5 h-3.5 text-gray-400" />
                )}
                <span className="flex-1">{opt.label}</span>
                {isSelected && (
                  <span className="text-[10px] font-mono text-blue-500 uppercase">
                    ({opt.pref === "browser" ? locale : opt.pref})
                  </span>
                )}
              </button>
            );
          })}
        </div>
      )}
    </div>
  );
}
