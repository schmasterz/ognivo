"use client";

import React, {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useRef,
  useState,
  useTransition,
} from "react";
import { useRouter } from "next/navigation";
import {
  LOCALES,
  type Locale,
  DEFAULT_LOCALE,
  isLocale,
} from "./loaders";
import { setApiLocale } from "@/lib/api";
import { interpolate } from "./interpolate";

export type { Locale };
export type Messages = Record<string, string>;
export type TranslationKey = string;
export type LocalePreference = "browser" | Locale;

export interface I18nContextType {
  locale: Locale;
  preference: LocalePreference;
  setPreference: (pref: LocalePreference) => void;
  t: (key: TranslationKey, params?: Record<string, string | number>) => string;
}

const I18nContext = createContext<I18nContextType | null>(null);

/**
 * Determine the user's preferred locale from browser language settings.
 * If a matching translation dictionary exists, it is returned.
 * Otherwise, falls back to DEFAULT_LOCALE.
 */
export function getBrowserLocale(): Locale {
  if (typeof navigator === "undefined") {
    return DEFAULT_LOCALE;
  }

  const candidates: readonly string[] =
    navigator.languages && navigator.languages.length > 0
      ? navigator.languages
      : [navigator.language];

  for (const candidate of candidates) {
    if (!candidate) continue;

    const trimmed = candidate.trim().toLowerCase();

    // 1. Try exact case-insensitive match against LOCALES (e.g. pt-BR)
    const exact = (LOCALES as readonly string[]).find((l) => l.toLowerCase() === trimmed);
    if (exact && isLocale(exact)) {
      return exact;
    }

    // 2. Try match by base language code
    const baseCode = trimmed.split("-")[0];
    const baseMatch = (LOCALES as readonly string[]).find((l) => l.toLowerCase() === baseCode);
    if (baseMatch && isLocale(baseMatch)) {
      return baseMatch;
    }
  }

  // Fallback to DEFAULT_LOCALE if no matching language exists
  return DEFAULT_LOCALE;
}

export interface I18nProviderProps {
  children: React.ReactNode;
  locale?: Locale;
  messages?: Messages;
}

export function I18nProvider({
  children,
  locale = DEFAULT_LOCALE,
  messages = {},
}: I18nProviderProps) {
  // Synchronous call in render before children's effects execute
  setApiLocale(locale);

  const router = useRouter();
  // Server and client initial state match ("browser") to prevent hydration mismatch
  const [preference, setPreferenceState] = useState<LocalePreference>("browser");
  const mountedRef = useRef(false);
  const [, startTransition] = useTransition();

  useEffect(() => {
    if (mountedRef.current) return;
    mountedRef.current = true;

    // 1. Read app_locale_pref from localStorage
    let savedPref: LocalePreference = "browser";
    try {
      const saved = localStorage.getItem("app_locale_pref");
      if (saved === "browser" || isLocale(saved)) {
        savedPref = saved as LocalePreference;
      }
    } catch {
      // ignore storage errors
    }

    setPreferenceState(savedPref);

    // 2. Cookie migration using saved preference (only if no cookie exists yet)
    const hasLocaleCookie = /(?:^|;\s*)locale=([^;]*)/.test(document.cookie);
    if (!hasLocaleCookie) {
      let resolved: Locale = DEFAULT_LOCALE;
      if (savedPref === "browser") {
        resolved = getBrowserLocale();
      } else if (isLocale(savedPref)) {
        resolved = savedPref;
      }
      document.cookie = `locale=${resolved}; path=/; max-age=31536000; SameSite=Lax`;
      if (resolved !== locale) {
        setApiLocale(resolved);
        router.refresh();
      }
    }
  }, [locale, router]);

  useEffect(() => {
    // Listen to browser language changes (if user preference is "browser")
    const handleLanguageChange = () => {
      let currentPref: LocalePreference = "browser";
      try {
        const saved = localStorage.getItem("app_locale_pref");
        if (saved === "browser" || isLocale(saved)) {
          currentPref = saved as LocalePreference;
        }
      } catch {
        // ignore storage errors
      }

      if (currentPref === "browser") {
        const resolved = getBrowserLocale();
        document.cookie = `locale=${resolved}; path=/; max-age=31536000; SameSite=Lax`;
        setApiLocale(resolved);
        router.refresh();
      }
    };

    window.addEventListener("languagechange", handleLanguageChange);
    return () => window.removeEventListener("languagechange", handleLanguageChange);
  }, [router]);

  const setPreference = useCallback(
    (newPref: LocalePreference) => {
      startTransition(() => {
        setPreferenceState(newPref);
        try {
          localStorage.setItem("app_locale_pref", newPref);
        } catch {
          // ignore storage errors
        }

        let resolved: Locale;
        if (newPref === "browser") {
          resolved = getBrowserLocale();
        } else {
          resolved = isLocale(newPref) ? newPref : DEFAULT_LOCALE;
        }

        document.cookie = `locale=${resolved}; path=/; max-age=31536000; SameSite=Lax`;
        setApiLocale(resolved);
        router.refresh();
      });
    },
    [router]
  );

  const t = useCallback(
    (key: TranslationKey, params?: Record<string, string | number>): string =>
      interpolate(messages[key] || key, params),
    [messages]
  );

  const value = useMemo(
    () => ({ locale, preference, setPreference, t }),
    [locale, preference, setPreference, t]
  );

  return <I18nContext.Provider value={value}>{children}</I18nContext.Provider>;
}

export function useI18n(): I18nContextType {
  const context = useContext(I18nContext);
  if (!context) {
    throw new Error("useI18n must be used within an I18nProvider");
  }
  return context;
}
