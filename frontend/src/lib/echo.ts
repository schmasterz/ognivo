import Echo from "laravel-echo";
import Pusher from "pusher-js";

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

  const isHttps = window.location.protocol === "https:";
  let port = isHttps ? 443 : 80;

  if (window.location.port && window.location.port !== "3000") {
    port = parseInt(window.location.port, 10);
  } else if (process.env.NEXT_PUBLIC_REVERB_PORT && isHttps) {
    port = parseInt(process.env.NEXT_PUBLIC_REVERB_PORT, 10);
  }

  const reverbAppKey = process.env.NEXT_PUBLIC_REVERB_APP_KEY;
  if (!reverbAppKey) {
    throw new Error("NEXT_PUBLIC_REVERB_APP_KEY is required to initialize Laravel Echo.");
  }

  echoAuthToken = currentToken;
  echoInstance = new Echo({
    broadcaster: "reverb",
    key: reverbAppKey,
    wsHost: window.location.hostname,
    wsPort: port,
    wssPort: isHttps ? port : 443,
    forceTLS: isHttps,
    enabledTransports: ["ws", "wss"],
    authEndpoint: "/api/broadcasting/auth",
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
