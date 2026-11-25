import type React from "react";

export interface ThemeTokens {
  primaryColor?: string; // e.g. '#2563eb'
  accentColor?: string;
  borderRadius?: string; // e.g. '0.5rem'
}

export type BrandLogoComponent = React.ComponentType<{ className?: string }>;

let themeTokens: ThemeTokens = {};
let brandLogo: BrandLogoComponent | null = null;
const listeners = new Set<() => void>();

function notifyListeners(): void {
  for (const listener of listeners) {
    try {
      listener();
    } catch (err) {
      console.error("Error in themeRegistry listener:", err);
    }
  }
}

/**
 * Merge partial theme tokens into the active theme token registry and notify subscribers.
 */
export function setThemeTokens(tokens: Partial<ThemeTokens>): void {
  themeTokens = { ...themeTokens, ...tokens };
  notifyListeners();
}

/**
 * Retrieve a copy of the currently registered theme tokens.
 */
export function getThemeTokens(): ThemeTokens {
  return { ...themeTokens };
}

/**
 * Register a custom brand logo component (or null to restore the default logo) and notify subscribers.
 */
export function setBrandLogo(component: BrandLogoComponent | null): void {
  brandLogo = component;
  notifyListeners();
}

/**
 * Retrieve the currently registered custom brand logo component, or null if none is set.
 */
export function getBrandLogo(): BrandLogoComponent | null {
  return brandLogo;
}

/**
 * Subscribe to theme registry changes (compatible with useSyncExternalStore).
 * Returns an unsubscribe callback.
 */
export function subscribeThemeRegistry(callback: () => void): () => void {
  listeners.add(callback);
  return () => {
    listeners.delete(callback);
  };
}

/**
 * Reset all registered theme tokens and the custom brand logo, then notify subscribers.
 */
export function clearThemeRegistry(): void {
  themeTokens = {};
  brandLogo = null;
  notifyListeners();
}
