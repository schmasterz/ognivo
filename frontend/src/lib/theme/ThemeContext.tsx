"use client";

import React, { createContext, useContext, useEffect, useState } from "react";
import {
  getThemeTokens,
  subscribeThemeRegistry,
  type ThemeTokens,
} from "@/lib/theming/themeRegistry";

const TOKEN_CSS_VARS: ReadonlyArray<readonly [keyof ThemeTokens, string]> = [
  ["primaryColor", "--brand-primary"],
  ["accentColor", "--brand-accent"],
  ["borderRadius", "--brand-radius"],
];

function applyBrandTokens(): void {
  if (typeof document === "undefined") return;

  const tokens = getThemeTokens();
  const rootStyle = document.documentElement.style;

  for (const [key, cssVar] of TOKEN_CSS_VARS) {
    const value = tokens[key];
    if (value) {
      rootStyle.setProperty(cssVar, value);
    } else {
      rootStyle.removeProperty(cssVar);
    }
  }
}

export type ThemeMode = "light" | "dark";

interface ThemeContextType {
  theme: ThemeMode;
  resolvedTheme: "light" | "dark";
  setTheme: (theme: ThemeMode) => void;
  toggleTheme: () => void;
}

const ThemeContext = createContext<ThemeContextType | null>(null);

export function ThemeProvider({ children }: { children: React.ReactNode }) {
  const [theme, setThemeState] = useState<ThemeMode>("light");
  const [resolvedTheme, setResolvedTheme] = useState<"light" | "dark">("light");

  const applyTheme = (mode: ThemeMode) => {
    if (typeof window === "undefined") return;

    setResolvedTheme(mode);

    if (mode === "dark") {
      document.documentElement.classList.add("dark");
      document.documentElement.setAttribute("data-theme", "dark");
      document.documentElement.style.colorScheme = "dark";
    } else {
      document.documentElement.classList.remove("dark");
      document.documentElement.setAttribute("data-theme", "light");
      document.documentElement.style.colorScheme = "light";
    }
  };

  useEffect(() => {
    // 1. Check saved preference or default to OS theme
    const saved = localStorage.getItem("theme_mode");
    let initialMode: ThemeMode;

    if (saved === "light" || saved === "dark") {
      initialMode = saved;
    } else {
      // Default to OS preference
      const prefersDark = window.matchMedia("(prefers-color-scheme: dark)").matches;
      initialMode = prefersDark ? "dark" : "light";
      localStorage.setItem("theme_mode", initialMode);
    }

    queueMicrotask(() => {
      setThemeState(initialMode);
      applyTheme(initialMode);
    });
  }, []);

  useEffect(() => {
    applyBrandTokens();
    return subscribeThemeRegistry(applyBrandTokens);
  }, []);

  const setTheme = (newTheme: ThemeMode) => {
    setThemeState(newTheme);
    localStorage.setItem("theme_mode", newTheme);
    applyTheme(newTheme);
  };

  const toggleTheme = () => {
    const nextTheme: ThemeMode = theme === "dark" ? "light" : "dark";
    setTheme(nextTheme);
  };

  return (
    <ThemeContext.Provider value={{ theme, resolvedTheme, setTheme, toggleTheme }}>
      {children}
    </ThemeContext.Provider>
  );
}

export function useTheme(): ThemeContextType {
  const context = useContext(ThemeContext);
  if (!context) {
    throw new Error("useTheme must be used within a ThemeProvider");
  }
  return context;
}
