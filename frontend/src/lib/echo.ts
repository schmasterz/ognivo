import Echo from "laravel-echo";
import Pusher from "pusher-js";
import { getRuntimeConfig } from "@/lib/config";

declare global {
  interface Window {
    Pusher: typeof Pusher;
    Echo?: Echo<"reverb">;
  }
}

export type EchoConnectionState =
  | "connected"
  | "connecting"
  | "disconnected"
  | "unavailable"
  | "failed";

let echoInstance: Echo<"reverb"> | null = null;
let echoAuthToken: string | null = null;

const stateListeners = new Set<(state: EchoConnectionState) => void>();

function notifyStateListeners(state: EchoConnectionState): void {
  stateListeners.forEach((listener) => {
    try {
      listener(state);
    } catch {
      // Ignore individual listener errors
    }
  });
}

/**
 * Returns a singleton Laravel Echo instance configured for Laravel Reverb.
 * Automatically recreates the connection if the Bearer auth token has changed.
 */
export function getEcho(): Echo<"reverb"> | null {
  if (typeof window === "undefined") {
    return null;
  }

  const currentToken = localStorage.getItem("auth_token");
  if (!currentToken) {
    if (echoInstance) {
      disconnectEcho();
    }
    return null;
  }

  if (echoInstance && echoAuthToken !== currentToken) {
    try {
      echoInstance.disconnect();
    } catch {
      // Ignore disconnect errors on stale instance
    }
    echoInstance = null;
    echoAuthToken = null;
  }

  if (echoInstance) {
    return echoInstance;
  }

  window.Pusher = Pusher;

  const runtimeConfig = getRuntimeConfig();
  const isHttps = window.location.protocol === "https:";

  const envPort = process.env.NEXT_PUBLIC_REVERB_PORT
    ? parseInt(process.env.NEXT_PUBLIC_REVERB_PORT, 10)
    : undefined;
  const runtimePort =
    typeof runtimeConfig.ws_port === "number" && Number.isFinite(runtimeConfig.ws_port)
      ? runtimeConfig.ws_port
      : undefined;
  const configuredPort =
    envPort !== undefined && Number.isFinite(envPort) ? envPort : runtimePort;
  const port =
    configuredPort ??
    (window.location.port && window.location.port !== "3000"
      ? parseInt(window.location.port, 10)
      : (isHttps ? 443 : 80));

  const wsHost =
    process.env.NEXT_PUBLIC_REVERB_HOST ||
    runtimeConfig.ws_host ||
    window.location.hostname;

  const reverbAppKey =
    runtimeConfig.ws_key ||
    process.env.NEXT_PUBLIC_REVERB_APP_KEY ||
    process.env.REVERB_APP_KEY;
  if (!reverbAppKey) {
    console.warn("Reverb app key is not configured; real-time updates are disabled.");
    return null;
  }

  echoAuthToken = currentToken;
  echoInstance = new Echo({
    broadcaster: "reverb",
    key: reverbAppKey,
    wsHost,
    wsPort: port,
    wssPort: isHttps ? port : 443,
    forceTLS: isHttps,
    enabledTransports: ["ws", "wss"],
    authEndpoint: `${runtimeConfig.api_base_url || "/api/v1"}/broadcasting/auth`,
    auth: {
      headers: {
        Authorization: `Bearer ${currentToken}`,
        Accept: "application/json",
      },
    },
  });

  try {
    const connector = echoInstance.connector as unknown as {
      pusher?: {
        connection?: {
          state?: EchoConnectionState;
          bind?: (
            event: string,
            fn: (states: { current: EchoConnectionState }) => void
          ) => void;
        };
      };
    };

    const connection = connector?.pusher?.connection;
    if (connection?.bind) {
      connection.bind("state_change", (states: { current: EchoConnectionState }) => {
        notifyStateListeners(states.current);
      });
    }
    if (connection?.state) {
      notifyStateListeners(connection.state);
    }
  } catch {
    // Ignore Pusher connection binding errors
  }

  window.Echo = echoInstance;
  return echoInstance;
}

/**
 * Returns current WebSocket connection state.
 */
export function getEchoConnectionState(): EchoConnectionState {
  if (!echoInstance) return "disconnected";
  try {
    const connector = echoInstance.connector as unknown as {
      pusher?: { connection?: { state?: EchoConnectionState } };
    };
    return connector?.pusher?.connection?.state ?? "disconnected";
  } catch {
    return "disconnected";
  }
}

/**
 * Subscribes to Reverb connection state transitions (e.g. connected, unavailable, disconnected).
 * Remains bound across Echo instance re-initializations and token changes.
 */
export function onEchoConnectionStateChange(
  callback: (state: EchoConnectionState) => void
): () => void {
  stateListeners.add(callback);

  if (typeof window !== "undefined") {
    getEcho();
    callback(getEchoConnectionState());
  }

  return () => {
    stateListeners.delete(callback);
  };
}

/**
 * Forces manual reconnect attempt to Reverb.
 */
export function reconnectEcho(): void {
  if (echoInstance) {
    try {
      const connector = echoInstance.connector as unknown as {
        pusher?: { connect?: () => void };
      };
      connector?.pusher?.connect?.();
    } catch {
      disconnectEcho();
      getEcho();
    }
  } else {
    getEcho();
  }
}

/**
 * Disconnects the active Echo instance and cleans up memory.
 */
export function disconnectEcho(): void {
  if (echoInstance) {
    try {
      echoInstance.disconnect();
    } catch {
      // Ignore disconnect errors
    }
    echoInstance = null;
  }
  echoAuthToken = null;
  notifyStateListeners("disconnected");
}
