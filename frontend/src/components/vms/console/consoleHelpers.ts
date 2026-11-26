/**
 * Pure TypeScript helpers for the VM interactive console and serial boot viewer.
 * Zero React or DOM runtime dependencies so they can run directly under Node's
 * native TypeScript test runner.
 */

export type ConnectionStatus = "connecting" | "connected" | "disconnected" | "error";

export type ConsoleDriverMode = "exec" | "console";

export type ConsolePreferredDriver = "auto" | "exec" | "console";

export type ErrorCategory =
  | "forbidden"
  | "not_running"
  | "serial_in_use"
  | "limit_reached"
  | "not_found"
  | "generic";

export interface ClassifiedConsoleError {
  category: ErrorCategory;
  message: string;
}

export const MAX_BOOT_LOG_CHARS = 51200;

const ESC = String.fromCharCode(27);
const BEL = String.fromCharCode(7);

export const ANSI_ESCAPE_REGEX = new RegExp(
  `${ESC}(?:\\[[0-9;?]*[ -/]*[@-~]|\\][^${BEL}${ESC}]*(?:${BEL}|${ESC}\\\\)|[@-Z\\\\-_])`,
  "g"
);

export const LOGIN_PROMPT_REGEX = /[a-zA-Z0-9._-]+ login:\s*$/i;

const DEFAULT_CONSOLE_ERROR_MESSAGE = "Failed to initiate interactive console session.";

/**
 * Checks whether the tail of a serial output buffer ends with a guest login prompt
 * after stripping ANSI escape sequences and trailing line breaks.
 */
export function hasLoginPrompt(text: string): boolean {
  const tail = text
    .slice(-512)
    .replace(ANSI_ESCAPE_REGEX, "")
    .replace(/[\r\n]+$/, "");
  return LOGIN_PROMPT_REGEX.test(tail);
}

/**
 * Appends a serial boot log chunk to the captured buffer up to `maxChars` and
 * reports whether capture should freeze (cap reached or login prompt detected).
 */
export function appendBootLogChunk(
  currentBuffer: string,
  chunk: string,
  maxChars: number = MAX_BOOT_LOG_CHARS
): { buffer: string; frozen: boolean } {
  if (!chunk) {
    return {
      buffer: currentBuffer,
      frozen: currentBuffer.length >= maxChars || hasLoginPrompt(currentBuffer),
    };
  }

  const remaining = maxChars - currentBuffer.length;
  if (remaining <= 0) {
    return { buffer: currentBuffer, frozen: true };
  }

  const next = currentBuffer + chunk.slice(0, remaining);
  const frozen = next.length >= maxChars || hasLoginPrompt(next);
  return { buffer: next, frozen };
}

/**
 * Estimates terminal column and row counts from the current viewport dimensions
 * and modal fullscreen state, falling back to 80x24 without a valid viewport.
 */
export function estimateTerminalDimensions(
  fullscreen: boolean,
  viewport?: { width: number; height: number } | null
): { cols: number; rows: number } {
  if (!viewport || viewport.width <= 0 || viewport.height <= 0) {
    return { cols: 80, rows: 24 };
  }

  const modalWidth = fullscreen
    ? viewport.width
    : Math.min(viewport.width - 32, 1152);
  const modalHeight = fullscreen ? viewport.height : 680;
  const termHeight = Math.max(200, modalHeight - 130);

  const cols = Math.max(80, Math.min(240, Math.floor((modalWidth - 24) / 9)));
  const rows = Math.max(24, Math.min(80, Math.floor((termHeight - 24) / 17)));
  return { cols, rows };
}

/**
 * Builds a `ws://` or `wss://` WebSocket URL from a backend websocket path and
 * a browser-like location descriptor.
 */
export function buildConsoleWebSocketUrl(
  websocketPath: string,
  locationLike: { protocol: string; host: string }
): string {
  const protocol = locationLike.protocol === "https:" ? "wss:" : "ws:";
  return `${protocol}//${locationLike.host}${websocketPath}`;
}

/**
 * Formats a human-readable close message for a terminal WebSocket close event
 * and indicates whether the close code represents a terminal error condition.
 */
export function formatConsoleCloseMessage(
  code: number,
  reason: string,
  prefixLabel: "Console session" | "Serial console"
): { message: string; isTerminalError: boolean } {
  if (code === 4000) {
    return {
      message: `[${prefixLabel} closed: idle timeout exceeded]`,
      isTerminalError: true,
    };
  }
  if (code === 1009) {
    return {
      message: `[${prefixLabel} closed: message payload exceeded maximum limit]`,
      isTerminalError: true,
    };
  }
  if (code === 1011) {
    return {
      message: `[${prefixLabel} closed: hypervisor upstream connection error]`,
      isTerminalError: true,
    };
  }
  if (reason) {
    return {
      message: `[${prefixLabel} closed: ${reason}]`,
      isTerminalError: false,
    };
  }
  return {
    message: `[${prefixLabel} disconnected]`,
    isTerminalError: false,
  };
}

function classifyByStatus(status: number, rawMessage: string): ClassifiedConsoleError {
  const lowerMsg = rawMessage.toLowerCase();

  if (status === 403) {
    return {
      category: "forbidden",
      message:
        "Console access is restricted to the workload owner only. Administrators and impersonated sessions are denied guest console access to uphold tenant privacy.",
    };
  }

  if (status === 409) {
    if (
      lowerMsg.includes("already active") ||
      lowerMsg.includes("already in use") ||
      lowerMsg.includes("serial console")
    ) {
      return {
        category: "serial_in_use",
        message:
          rawMessage ||
          "A serial console session is already active for this instance. Only one concurrent serial console connection is permitted.",
      };
    }

    return {
      category: "not_running",
      message:
        rawMessage ||
        "Interactive console is only available while the instance is running. Please start the instance first.",
    };
  }

  if (status === 429) {
    return {
      category: "limit_reached",
      message:
        rawMessage ||
        "Maximum number of concurrent terminal sessions reached for this instance. Please close existing sessions or try again later.",
    };
  }

  if (status === 404 || lowerMsg.includes("not found")) {
    return {
      category: "not_found",
      message:
        rawMessage ||
        "Workload instance or guest shell executable could not be found.",
    };
  }

  return {
    category: "generic",
    message: rawMessage || "An unexpected error occurred.",
  };
}

function classifyByErrorMessage(rawMessage: string): ClassifiedConsoleError {
  const lowerMsg = rawMessage.toLowerCase();

  if (lowerMsg.includes("403") || lowerMsg.includes("forbidden")) {
    return {
      category: "forbidden",
      message:
        "Console access is restricted to the workload owner only. Administrators and impersonated sessions are denied guest console access to uphold tenant privacy.",
    };
  }

  if (
    lowerMsg.includes("already active") ||
    lowerMsg.includes("already in use") ||
    lowerMsg.includes("serial console")
  ) {
    return {
      category: "serial_in_use",
      message:
        "A serial console session is already active for this instance. Only one active serial console connection is permitted.",
    };
  }

  if (lowerMsg.includes("409") || lowerMsg.includes("running")) {
    return {
      category: "not_running",
      message:
        "Interactive console is only available while the instance is running. Please start the instance first.",
    };
  }

  if (lowerMsg.includes("429") || lowerMsg.includes("concurrent")) {
    return {
      category: "limit_reached",
      message:
        "Maximum number of concurrent terminal sessions reached for this instance.",
    };
  }

  if (lowerMsg.includes("not found")) {
    return {
      category: "not_found",
      message: "Workload instance or guest shell executable could not be found.",
    };
  }

  return {
    category: "generic",
    message: rawMessage || DEFAULT_CONSOLE_ERROR_MESSAGE,
  };
}

/**
 * Classifies an unknown console session initiation error into a structured
 * error category and user-facing message.
 */
export function classifyConsoleError(err: unknown): ClassifiedConsoleError {
  if (
    typeof err === "object" &&
    err !== null &&
    "status" in err &&
    typeof (err as { status: unknown }).status === "number"
  ) {
    const status = (err as { status: number }).status;
    const rawMessage =
      "message" in err && typeof (err as { message: unknown }).message === "string"
        ? (err as { message: string }).message
        : "";
    return classifyByStatus(status, rawMessage);
  }

  if (err instanceof Error) {
    return classifyByErrorMessage(err.message);
  }

  return {
    category: "generic",
    message: DEFAULT_CONSOLE_ERROR_MESSAGE,
  };
}

/**
 * Determines whether the console modal should automatically initiate a terminal
 * session when an instance transitions into the running state.
 */
export function shouldAutoConnectOnRunningTransition(params: {
  wasRunning: boolean;
  isRunning: boolean;
  pendingRunningTransition: boolean;
  hasWsUrl: boolean;
  sessionLoading: boolean;
  isInFlight: boolean;
  connectionStatus: ConnectionStatus;
  errorCategory: ErrorCategory | null;
}): boolean {
  const isSessionActiveOrConnecting =
    params.sessionLoading ||
    params.isInFlight ||
    (params.hasWsUrl &&
      (params.connectionStatus === "connected" ||
        params.connectionStatus === "connecting"));

  if (params.errorCategory === "not_running") {
    return (
      params.isRunning &&
      (!params.wasRunning || params.pendingRunningTransition) &&
      !isSessionActiveOrConnecting
    );
  }

  return (
    !params.wasRunning &&
    params.isRunning &&
    !params.hasWsUrl &&
    !params.sessionLoading &&
    !params.errorCategory &&
    !isSessionActiveOrConnecting
  );
}
