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
  const [config, setConfig] = React.useState<OgnivoRuntimeConfig>(initialConfig);
  setRuntimeConfig(config);

  React.useEffect(() => {
    if (typeof window === "undefined") return;

    let isMounted = true;
    const configUrl = `${initialConfig.api_base_url || "/api/v1"}/config`;

    fetch(configUrl, {
      headers: { Accept: "application/json" },
      cache: "no-store",
    })
      .then((res) => (res.ok ? res.json() : null))
      .then((data: Partial<OgnivoRuntimeConfig> | null) => {
        if (!isMounted || !data) return;

        const merged: OgnivoRuntimeConfig = {
          app_name: data.app_name || initialConfig.app_name,
          app_env: data.app_env || initialConfig.app_env,
          api_base_url: data.api_base_url || initialConfig.api_base_url,
          ws_host: data.ws_host ?? initialConfig.ws_host,
          ws_port: data.ws_port ?? initialConfig.ws_port,
          ws_key: data.ws_key ?? initialConfig.ws_key,
          features: {
            registration: data.features?.registration ?? initialConfig.features?.registration,
            impersonation: data.features?.impersonation ?? initialConfig.features?.impersonation,
            fake_driver: data.features?.fake_driver ?? initialConfig.features?.fake_driver,
          },
        };

        setRuntimeConfig(merged);
        setConfig(merged);
      })
      .catch(() => {
        // Silently retain initial/default config if network/server is unavailable
      });

    return () => {
      isMounted = false;
    };
  }, [initialConfig]);

  return (
    <ConfigContext.Provider value={config}>
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
