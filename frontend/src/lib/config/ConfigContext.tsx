"use client";

import React, { createContext, useContext } from "react";
import {
  DEFAULT_CONFIG,
  setRuntimeConfig,
  type OgnivoRuntimeConfig,
} from "@/lib/config";

const ConfigContext = createContext<OgnivoRuntimeConfig>(DEFAULT_CONFIG);

/**
 * Provides server-resolved runtime configuration to React components and non-React modules.
 */
export function ConfigProvider({
  initialConfig,
  children,
}: {
  initialConfig: OgnivoRuntimeConfig;
  children: React.ReactNode;
}) {
  setRuntimeConfig(initialConfig);

  return (
    <ConfigContext.Provider value={initialConfig}>
      {children}
    </ConfigContext.Provider>
  );
}

/**
 * Returns the runtime configuration from the nearest ConfigProvider.
 */
export function useConfig(): OgnivoRuntimeConfig {
  return useContext(ConfigContext);
}
