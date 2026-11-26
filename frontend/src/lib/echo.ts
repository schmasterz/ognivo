import Echo from "laravel-echo";
import Pusher from "pusher-js";
import { getRuntimeConfig } from "@/lib/config";
import { apiFetch, PASSIVE_ACTIVITY_HEADER } from "@/lib/api/client";

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

const MAX_TIMEOUT_MS = 2_147_483_647;

let echoInstance: Echo<"reverb"> | null = null;
let echoEnabled = false;
let echoDisconnectTimer: ReturnType<typeof setTimeout> | null = null;

const stateListeners = new Set<(state: EchoConnectionState) => void>();

function clearDisconnectTimer(): void {
  if (echoDisconnectTimer !== null) {
    clearTimeout(echoDisconnectTimer);
    echoDisconnectTimer = null;
  }
}

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
 * Enables Echo connections for an authenticated browser session.
 */
export function enableEcho(): void {
  echoEnabled = true;
}

/**
 * Schedules Echo disconnection at the given ISO-8601 expiration timestamp.
 */
export function scheduleEchoDisconnectAt(expiresAtIso: string | null): void {
  clearDisconnectTimer();

  if (!expiresAtIso) {
    return;
  }

  const expiresAtMs = Date.parse(expiresAtIso);
  if (!Number.isFinite(expiresAtMs)) {
    return;
  }

  const scheduleAt = (targetMs: number): void => {
    const remainingMs = targetMs - Date.now();
    if (remainingMs <= 0) {
      disconnectEcho();
      return;
    }

    const waitMs = Math.min(remainingMs, MAX_TIMEOUT_MS);
    echoDisconnectTimer = setTimeout(() => {
      echoDisconnectTimer = null;
      if (Date.now() >= targetMs) {
        disconnectEcho();
      } else {
        scheduleAt(targetMs);
      }
    }, waitMs);
  };

  scheduleAt(expiresAtMs);
}

/**
 * Returns a singleton Laravel Echo instance configured for Laravel Reverb
 * when Echo is enabled for the active browser session.
 */
export function getEcho(): Echo<"reverb"> | null {
  if (typeof window === "undefined") {
    return null;
  }

  if (!echoEnabled) {
    if (echoInstance) {
      disconnectEcho();
    }
    return null;
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

  const authEndpoint = `${runtimeConfig.api_base_url || "/api/v1"}/broadcasting/auth`;

  echoInstance = new Echo({
    broadcaster: "reverb",
    key: reverbAppKey,
    wsHost,
    wsPort: port,
    wssPort: isHttps ? port : 443,
    forceTLS: isHttps,
    enabledTransports: ["ws", "wss"],
    channelAuthorization: {
      customHandler: (params, callback) => {
        void apiFetch(authEndpoint, {
          method: "POST",
          headers: {
            "Content-Type": "application/json",
            Accept: "application/json",
            ...PASSIVE_ACTIVITY_HEADER,
          },
          body: JSON.stringify({
            socket_id: params.socketId,
            channel_name: params.channelName,
          }),
        })
          .then(async (response) => {
            if (!response.ok) {
              throw new Error(
                `Unable to retrieve auth string from channel-authorization endpoint - received status: ${response.status} from ${authEndpoint}.`
              );
            }
            const data = (await response.json()) as {
              auth: string;
              channel_data?: string;
              shared_secret?: string;
            };
            callback(null, data);
          })
          .catch((error: unknown) => {
            callback(
              error instanceof Error ? error : new Error(String(error)),
              null
            );
          });
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
 * Remains bound across Echo instance re-initializations.
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
 * Forces manual reconnect attempt to Reverb when Echo is enabled.
 */
export function reconnectEcho(): void {
  if (!echoEnabled) {
    return;
  }

  if (echoInstance) {
    try {
      const connector = echoInstance.connector as unknown as {
        pusher?: { connect?: () => void };
      };
      connector?.pusher?.connect?.();
    } catch {
      disconnectEcho();
      enableEcho();
      getEcho();
    }
  } else {
    getEcho();
  }
}

/**
 * Disables Echo, clears any scheduled expiry disconnect timer,
 * disconnects the active Echo instance, and notifies listeners.
 */
export function disconnectEcho(): void {
  echoEnabled = false;
  clearDisconnectTimer();
  if (echoInstance) {
    try {
      echoInstance.disconnect();
    } catch {
      // Ignore disconnect errors
    }
    echoInstance = null;
  }
  if (typeof window !== "undefined") {
    delete window.Echo;
  }
  notifyStateListeners("disconnected");
}
