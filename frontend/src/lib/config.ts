/**
 * Runtime configuration accessor for decoupling frontend builds from build-time environment variables.
 */

export interface OgnivoRuntimeConfig {
  app_name: string;
  app_env: string;
  api_base_url: string;
  ws_host?: string;
  ws_port?: number;
  ws_key?: string;
  features?: {
    registration?: boolean;
    impersonation?: boolean;
    fake_driver?: boolean;
  };
}

declare global {
  interface Window {
    __OGNIVO_CONFIG__?: OgnivoRuntimeConfig;
  }
}

export const DEFAULT_CONFIG: OgnivoRuntimeConfig = {
  app_name: "Ognivo",
  app_env: "production",
  api_base_url: "/api/v1",
  ws_host: typeof window !== "undefined" ? window.location.hostname : "localhost",
  ws_port: undefined,
  ws_key: undefined,
  features: {
    registration: false,
    impersonation: true,
    fake_driver: false,
  },
};

let activeConfig: OgnivoRuntimeConfig = DEFAULT_CONFIG;

/**
 * Sets the active in-memory runtime configuration for non-React modules.
 */
export function setRuntimeConfig(config: OgnivoRuntimeConfig): void {
  activeConfig = config;
}

/**
 * Returns the runtime configuration injected into `window.__OGNIVO_CONFIG__`, active in-memory config, or safe defaults.
 */
export function getRuntimeConfig(): OgnivoRuntimeConfig {
  if (typeof window !== "undefined" && window.__OGNIVO_CONFIG__) {
    return window.__OGNIVO_CONFIG__;
  }
  return activeConfig;
}
