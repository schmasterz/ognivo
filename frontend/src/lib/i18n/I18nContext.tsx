"use client";

import React, { createContext, useContext, useEffect, useState, useTransition } from "react";
import { Locale, TranslationKey, dictionaries } from "./dictionaries";
import { setApiLocale } from "@/lib/api";

export type LocalePreference = "browser" | Locale;

interface I18nContextType {
  locale: Locale;
  preference: LocalePreference;
  setPreference: (pref: LocalePreference) => void;
  t: (key: TranslationKey, params?: Record<string, string | number>) => string;
}

const I18nContext = createContext<I18nContextType | null>(null);

/**
 * Determine the user's preferred locale from browser language settings.
 * If a matching translation dictionary exists, it is returned.
 * Otherwise, falls back to English ("en").
 */
export function getBrowserLocale(): Locale {
  if (typeof navigator === "undefined") {
    return "en";
  }

  const candidates: readonly string[] =
    navigator.languages && navigator.languages.length > 0
      ? navigator.languages
      : [navigator.language];

  for (const candidate of candidates) {
    if (!candidate) continue;

    // Normalise e.g. "bg-BG" -> "bg", "en-US" -> "en", "de-DE" -> "de"
    const baseCode = candidate.split("-")[0].toLowerCase();

    if (baseCode in dictionaries) {
      return baseCode as Locale;
    }
  }

  // Fallback to English if no matching language file exists
  return "en";
}

export function I18nProvider({ children }: { children: React.ReactNode }) {
  const [preference, setPreferenceState] = useState<LocalePreference>("browser");
  const [locale, setLocaleState] = useState<Locale>("en");
  const [, startTransition] = useTransition();

  const resolveAndApply = (pref: LocalePreference) => {
    let resolved: Locale;

    if (pref === "browser") {
      resolved = getBrowserLocale();
    } else {
      resolved = pref in dictionaries ? pref : "en";
    }

    setLocaleState(resolved);
    setApiLocale(resolved);
  };

  useEffect(() => {
    // 1. Check saved user preference in localStorage
    const saved = localStorage.getItem("app_locale_pref") as LocalePreference | null;
    const initialPref: LocalePreference =
      saved === "en" || saved === "bg" || saved === "browser" ? saved : "browser";

    setPreferenceState(initialPref);
    resolveAndApply(initialPref);

    // 2. Listen to browser language changes (if available)
    const handleLanguageChange = () => {
      const current = localStorage.getItem("app_locale_pref") as LocalePreference | null;
      if (!current || current === "browser") {
        resolveAndApply("browser");
      }
    };

    window.addEventListener("languagechange", handleLanguageChange);
    return () => window.removeEventListener("languagechange", handleLanguageChange);
  }, []);

  const setPreference = (newPref: LocalePreference) => {
    startTransition(() => {
      setPreferenceState(newPref);
      localStorage.setItem("app_locale_pref", newPref);
      resolveAndApply(newPref);
    });
  };

  const t = (key: TranslationKey, params?: Record<string, string | number>): string => {
    const dict = dictionaries[locale] || dictionaries.en;
    let text: string = dict[key] || dictionaries.en[key] || key;

    if (params) {
      Object.entries(params).forEach(([paramKey, val]) => {
        text = text.replace(new RegExp(`:${paramKey}`, "g"), String(val));
      });
    }

    return text;
  };

  return (
    <I18nContext.Provider value={{ locale, preference, setPreference, t }}>
      {children}
    </I18nContext.Provider>
  );
}

export function useI18n(): I18nContextType {
  const context = useContext(I18nContext);
  if (!context) {
    throw new Error("useI18n must be used within an I18nProvider");
  }
  return context;
}
