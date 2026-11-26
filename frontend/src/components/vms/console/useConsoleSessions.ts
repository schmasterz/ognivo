"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import { VirtualMachine, api } from "@/lib/api";
import {
  ConnectionStatus,
  ConsoleDriverMode,
  ConsolePreferredDriver,
  ErrorCategory,
  MAX_BOOT_LOG_CHARS,
  appendBootLogChunk,
  buildConsoleWebSocketUrl,
  classifyConsoleError,
  estimateTerminalDimensions,
  shouldAutoConnectOnRunningTransition,
} from "./consoleHelpers";

export interface UseConsoleSessionsParams {
  currentVm: VirtualMachine | null;
  activeTab: "terminal" | "bootlog";
  isFullscreen: boolean;
}

export interface UseConsoleSessionsReturn {
  wsUrl: string | null;
  ticket: string | null;
  driverMode: ConsoleDriverMode;
  fallbackReason: string | null;
  sessionLoading: boolean;
  sessionError: string | null;
  errorCategory: ErrorCategory | null;
  connectionStatus: ConnectionStatus;
  agentReady: boolean;
  bootLog: string;
  bootLoading: boolean;
  bootLogLoading: boolean;
  bootSessionLoading: boolean;
  bootLogFrozen: boolean;
  bootWsUrl: string | null;
  bootTicket: string | null;
  bootSessionError: string | null;
  bootConnectionStatus: ConnectionStatus;
  bootWaitingNotice: boolean;
  getBootTextFallback: () => string;
  fetchSession: (
    vmId: number,
    preferredDriver?: ConsolePreferredDriver
  ) => Promise<void>;
  fetchBootSession: (vmId: number) => Promise<void>;
  fetchBootLog: (vmId: number) => Promise<void>;
  closeAllSessions: (reason?: string) => void;
  handleConnectionStatusChange: (status: ConnectionStatus) => void;
  handleSessionError: (errorMessage: string) => void;
  setBootConnectionStatus: (status: ConnectionStatus) => void;
  handleBootDataChunk: (chunk: string) => void;
  handleBootFirstData: () => void;
  handleAgentStarted: (vmId: number) => void;
  refreshBootLog: () => void;
  ensureBootTabLoaded: () => void;
}

const KEEPALIVE_CLOSE_REASONS = new Set([
  "modal_closed",
  "modal_unmounted",
  "client_closed",
  "pagehide",
]);

function getViewportDimensions(fullscreen: boolean): {
  cols: number;
  rows: number;
} {
  const viewport =
    typeof window !== "undefined"
      ? { width: window.innerWidth, height: window.innerHeight }
      : null;
  return estimateTerminalDimensions(fullscreen, viewport);
}

/**
 * Manages interactive terminal and serial/boot console session lifecycles,
 * request deduplication, auto-connect on running transitions, and keepalive cleanup.
 */
export function useConsoleSessions({
  currentVm,
  activeTab,
  isFullscreen,
}: UseConsoleSessionsParams): UseConsoleSessionsReturn {
  const [wsUrl, setWsUrl] = useState<string | null>(null);
  const [ticket, setTicket] = useState<string | null>(null);
  const [driverMode, setDriverMode] = useState<ConsoleDriverMode>("exec");
  const [fallbackReason, setFallbackReason] = useState<string | null>(null);
  const [sessionLoading, setSessionLoading] = useState(false);
  const [sessionError, setSessionError] = useState<string | null>(null);
  const [errorCategory, setErrorCategory] = useState<ErrorCategory | null>(
    null
  );
  const [connectionStatus, setConnectionStatus] =
    useState<ConnectionStatus>("connecting");
  const [agentReady, setAgentReady] = useState(false);

  const [bootLog, setBootLog] = useState<string>("");
  const [bootLogLoading, setBootLogLoading] = useState(false);
  const [bootLogFrozen, setBootLogFrozen] = useState(false);
  const [bootWsUrl, setBootWsUrl] = useState<string | null>(null);
  const [bootTicket, setBootTicket] = useState<string | null>(null);
  const [bootSessionLoading, setBootSessionLoading] = useState(false);
  const [bootSessionError, setBootSessionError] = useState<string | null>(null);
  const [bootConnectionStatus, setBootConnectionStatus] =
    useState<ConnectionStatus>("connecting");
  const [bootSerialHasData, setBootSerialHasData] = useState(false);
  const [bootWaitingNotice, setBootWaitingNotice] = useState(false);

  const isFullscreenRef = useRef(isFullscreen);
  const sessionIdRef = useRef<string | null>(null);
  const sessionRequestIdRef = useRef<number>(0);
  const sessionLoadingRef = useRef<boolean>(false);
  const inFlightSessionRef = useRef<{
    vmId: number;
    driver: ConsolePreferredDriver;
  } | null>(null);
  const pendingRunningTransitionRef = useRef<boolean>(false);
  const connectionStatusRef = useRef<ConnectionStatus>("connecting");
  const driverModeRef = useRef<ConsoleDriverMode>("exec");
  const bootSessionIdRef = useRef<string | null>(null);
  const bootRequestIdRef = useRef<number>(0);
  const vmIdRef = useRef<number | null>(null);
  const prevAutoConnectVmIdRef = useRef<number | null>(null);
  const prevIsRunningRef = useRef<boolean>(false);
  const bootBufferRef = useRef<string>("");
  const bootLogFrozenRef = useRef<boolean>(false);

  const vmId = currentVm?.id ?? null;
  const isVm = currentVm?.instance_type === "virtual-machine";
  const isRunning = currentVm?.status?.toLowerCase() === "running";

  useEffect(() => {
    isFullscreenRef.current = isFullscreen;
  }, [isFullscreen]);

  useEffect(() => {
    connectionStatusRef.current = connectionStatus;
  }, [connectionStatus]);

  useEffect(() => {
    driverModeRef.current = driverMode;
  }, [driverMode]);

  const handleConnectionStatusChange = useCallback(
    (status: ConnectionStatus) => {
      connectionStatusRef.current = status;
      setConnectionStatus(status);
    },
    []
  );

  const handleSessionError = useCallback((errorMessage: string) => {
    const classified = classifyConsoleError(new Error(errorMessage));
    setErrorCategory(classified.category);
    setSessionError(errorMessage || classified.message);
    connectionStatusRef.current = "error";
    setConnectionStatus("error");
  }, []);

  const invalidatePendingSessionRequest = useCallback(() => {
    sessionRequestIdRef.current++;
    inFlightSessionRef.current = null;
    sessionLoadingRef.current = false;
    pendingRunningTransitionRef.current = false;
  }, []);

  const invalidatePendingBootRequest = useCallback(() => {
    bootRequestIdRef.current++;
  }, []);

  const closeActiveSession = useCallback(
    (reason: string = "client_disconnect", options?: { keepalive?: boolean }) => {
      const activeSessionId = sessionIdRef.current;
      const activeVmId = vmIdRef.current;
      if (activeSessionId && activeVmId) {
        sessionIdRef.current = null;
        const keepalive =
          options?.keepalive ?? KEEPALIVE_CLOSE_REASONS.has(reason);
        void api
          .closeConsoleSession(
            activeVmId,
            activeSessionId,
            reason,
            keepalive ? { keepalive: true } : undefined
          )
          .catch(() => {});
      }
    },
    []
  );

  const closeActiveBootSession = useCallback(
    (reason: string = "client_disconnect", options?: { keepalive?: boolean }) => {
      bootRequestIdRef.current++;
      const activeBootSessionId = bootSessionIdRef.current;
      const activeVmId = vmIdRef.current;
      if (activeBootSessionId && activeVmId) {
        bootSessionIdRef.current = null;
        const keepalive =
          options?.keepalive ?? KEEPALIVE_CLOSE_REASONS.has(reason);
        void api
          .closeConsoleSession(
            activeVmId,
            activeBootSessionId,
            reason,
            keepalive ? { keepalive: true } : undefined
          )
          .catch(() => {});
      }
    },
    []
  );

  const closeAllSessions = useCallback(
    (reason: string = "client_closed") => {
      invalidatePendingSessionRequest();
      invalidatePendingBootRequest();
      closeActiveSession(reason);
      closeActiveBootSession(reason);
    },
    [
      invalidatePendingSessionRequest,
      invalidatePendingBootRequest,
      closeActiveSession,
      closeActiveBootSession,
    ]
  );

  const fetchSession = useCallback(
    async (targetVmId: number, preferredDriver?: ConsolePreferredDriver) => {
      const requestedDriver: ConsolePreferredDriver = preferredDriver ?? "auto";
      if (
        inFlightSessionRef.current &&
        inFlightSessionRef.current.vmId === targetVmId &&
        inFlightSessionRef.current.driver === requestedDriver
      ) {
        return;
      }

      closeActiveSession("reconnect");
      const requestId = ++sessionRequestIdRef.current;
      inFlightSessionRef.current = {
        vmId: targetVmId,
        driver: requestedDriver,
      };
      sessionLoadingRef.current = true;

      try {
        setSessionLoading(true);
        setSessionError(null);
        setErrorCategory(null);
        connectionStatusRef.current = "connecting";
        setConnectionStatus("connecting");
        setWsUrl(null);
        setTicket(null);

        const { cols, rows } = getViewportDimensions(isFullscreenRef.current);
        const res = await api.getConsoleSession(targetVmId, {
          type: "console",
          cols,
          rows,
          driver: preferredDriver,
        });

        if (requestId !== sessionRequestIdRef.current) {
          void api
            .closeConsoleSession(targetVmId, res.session_id, "superseded", {
              keepalive: true,
            })
            .catch(() => {});
          return;
        }

        driverModeRef.current = res.driver;
        setDriverMode(res.driver);
        setFallbackReason(res.fallback_reason ?? null);
        setTicket(res.ticket);
        sessionIdRef.current = res.session_id;

        if (res.driver === "exec") {
          setAgentReady(false);
        } else {
          // Terminal holds the sole serial console slot; release any separate boot serial session
          closeActiveBootSession("terminal_took_serial");
          setBootWsUrl(null);
          setBootTicket(null);
        }

        setWsUrl(
          buildConsoleWebSocketUrl(res.websocket_path, window.location)
        );
      } catch (err: unknown) {
        if (requestId !== sessionRequestIdRef.current) {
          return;
        }

        const classified = classifyConsoleError(err);
        setErrorCategory(classified.category);
        setSessionError(classified.message);
        connectionStatusRef.current = "error";
        setConnectionStatus("error");
      } finally {
        if (requestId === sessionRequestIdRef.current) {
          inFlightSessionRef.current = null;
          sessionLoadingRef.current = false;
          setSessionLoading(false);
        }
      }
    },
    [closeActiveBootSession, closeActiveSession]
  );

  const fetchBootSession = useCallback(
    async (targetVmId: number) => {
      closeActiveBootSession("refresh");
      const requestId = ++bootRequestIdRef.current;

      try {
        setBootSessionLoading(true);
        setBootSessionError(null);
        setBootSerialHasData(false);
        setBootWaitingNotice(false);
        bootBufferRef.current = "";
        bootLogFrozenRef.current = false;
        setBootLog("");
        setBootLogFrozen(false);

        const { cols, rows } = getViewportDimensions(isFullscreenRef.current);
        const res = await api.getConsoleSession(targetVmId, {
          type: "console",
          cols,
          rows,
          driver: "console",
        });

        if (requestId !== bootRequestIdRef.current) {
          void api
            .closeConsoleSession(targetVmId, res.session_id, "superseded", {
              keepalive: true,
            })
            .catch(() => {});
          return;
        }

        bootSessionIdRef.current = res.session_id;
        setBootTicket(res.ticket);
        setBootWsUrl(
          buildConsoleWebSocketUrl(res.websocket_path, window.location)
        );
      } catch (err: unknown) {
        if (requestId !== bootRequestIdRef.current) {
          return;
        }

        const msg =
          err instanceof Error
            ? err.message
            : "Failed to attach to VM serial console (ttyS0).";
        setBootSessionError(msg);
      } finally {
        if (requestId === bootRequestIdRef.current) {
          setBootSessionLoading(false);
        }
      }
    },
    [closeActiveBootSession]
  );

  const fetchBootLog = useCallback(async (targetVmId: number) => {
    const requestId = ++bootRequestIdRef.current;

    try {
      setBootLogLoading(true);
      const res = await api.getConsoleLog(targetVmId);
      if (requestId !== bootRequestIdRef.current) {
        return;
      }

      const output = res.output || "";
      bootBufferRef.current = output;
      setBootLog(output);
    } catch (err: unknown) {
      if (requestId !== bootRequestIdRef.current) {
        return;
      }

      const msg =
        err instanceof Error
          ? `Error loading boot log: ${err.message}`
          : "Failed to load instance boot log.";
      bootBufferRef.current = msg;
      setBootLog(msg);
    } finally {
      if (requestId === bootRequestIdRef.current) {
        setBootLogLoading(false);
      }
    }
  }, []);

  const handleBootDataChunk = useCallback((chunk: string) => {
    if (!chunk) return;
    setBootSerialHasData(true);

    if (bootLogFrozenRef.current) return;

    const { buffer, frozen } = appendBootLogChunk(
      bootBufferRef.current,
      chunk,
      MAX_BOOT_LOG_CHARS
    );
    if (buffer !== bootBufferRef.current) {
      bootBufferRef.current = buffer;
      setBootLog(buffer);
    }
    if (frozen) {
      bootLogFrozenRef.current = true;
      setBootLogFrozen(true);
    }
  }, []);

  const handleBootFirstData = useCallback(() => {
    setBootSerialHasData(true);
    setBootWaitingNotice(false);
  }, []);

  const handleAgentStarted = useCallback(
    (targetVmId: number) => {
      if (vmIdRef.current !== targetVmId) {
        return;
      }

      setAgentReady(true);
      bootLogFrozenRef.current = true;
      setBootLogFrozen(true);

      const status = connectionStatusRef.current;
      const mode = driverModeRef.current;

      // Auto-connect to exec shell if disconnected/errored or on serial fallback without an active connection;
      // if actively connected on serial fallback, keep the live session and show the banner.
      if (
        status === "disconnected" ||
        status === "error" ||
        (mode === "console" && status !== "connected")
      ) {
        void fetchSession(targetVmId, "exec");
      }
    },
    [fetchSession]
  );

  const getBootTextFallback = useCallback(() => bootBufferRef.current, []);

  const refreshBootLog = useCallback(() => {
    if (!vmId) return;
    if (isVm && isRunning) {
      if (driverModeRef.current === "exec") {
        void fetchBootSession(vmId);
      } else {
        bootBufferRef.current = "";
        bootLogFrozenRef.current = false;
        setBootLog("");
        setBootLogFrozen(false);
        setBootSerialHasData(false);
        setBootWaitingNotice(false);
      }
    } else {
      void fetchBootLog(vmId);
    }
  }, [vmId, isVm, isRunning, fetchBootSession, fetchBootLog]);

  const ensureBootTabLoaded = useCallback(() => {
    if (!vmId) return;
    if (isVm) {
      if (
        isRunning &&
        driverMode === "exec" &&
        !bootWsUrl &&
        !bootSessionLoading &&
        !bootLogFrozen
      ) {
        void fetchBootSession(vmId);
      }
    } else if (!bootLog && !bootLogLoading) {
      void fetchBootLog(vmId);
    }
  }, [
    vmId,
    isVm,
    isRunning,
    driverMode,
    bootWsUrl,
    bootSessionLoading,
    bootLogFrozen,
    bootLog,
    bootLogLoading,
    fetchBootSession,
    fetchBootLog,
  ]);

  // Reset state and initiate Terminal session when modal opens or switches VM
  useEffect(() => {
    if (!vmId) {
      invalidatePendingSessionRequest();
      invalidatePendingBootRequest();
      closeActiveSession("modal_closed", { keepalive: true });
      closeActiveBootSession("modal_closed", { keepalive: true });
      vmIdRef.current = null;
      let ignore = false;

      queueMicrotask(() => {
        if (ignore) return;
        setWsUrl(null);
        setTicket(null);
        setBootWsUrl(null);
        setBootTicket(null);
        setBootSessionError(null);
        setBootSessionLoading(false);
        setBootLogLoading(false);
        setBootSerialHasData(false);
        setBootWaitingNotice(false);
        setSessionError(null);
        setErrorCategory(null);
        bootBufferRef.current = "";
        bootLogFrozenRef.current = false;
        setBootLog("");
        setBootLogFrozen(false);
        setFallbackReason(null);
        setAgentReady(false);
      });

      return () => {
        ignore = true;
      };
    }

    vmIdRef.current = vmId;
    let ignore = false;

    queueMicrotask(() => {
      if (!ignore) {
        setWsUrl(null);
        setTicket(null);
        setBootWsUrl(null);
        setBootTicket(null);
        setBootSessionError(null);
        setBootSessionLoading(false);
        setBootLogLoading(false);
        setBootSerialHasData(false);
        setBootWaitingNotice(false);
        setSessionError(null);
        setErrorCategory(null);
        bootBufferRef.current = "";
        bootLogFrozenRef.current = false;
        setBootLog("");
        setBootLogFrozen(false);
        setFallbackReason(null);
        setAgentReady(false);
        driverModeRef.current = "exec";
        setDriverMode("exec");
        connectionStatusRef.current = "connecting";
        setConnectionStatus("connecting");

        void fetchSession(vmId);
      }
    });

    return () => {
      ignore = true;
      invalidatePendingSessionRequest();
      invalidatePendingBootRequest();
      closeActiveSession("modal_unmounted", { keepalive: true });
      closeActiveBootSession("modal_unmounted", { keepalive: true });
    };
  }, [
    vmId,
    invalidatePendingSessionRequest,
    invalidatePendingBootRequest,
    closeActiveSession,
    closeActiveBootSession,
    fetchSession,
  ]);

  // Close active console sessions with keepalive when the page unloads or navigates away
  useEffect(() => {
    if (!vmId || typeof window === "undefined") {
      return;
    }

    const handlePageHide = () => {
      invalidatePendingSessionRequest();
      invalidatePendingBootRequest();
      closeActiveSession("pagehide", { keepalive: true });
      closeActiveBootSession("pagehide", { keepalive: true });
    };

    window.addEventListener("pagehide", handlePageHide);
    return () => {
      window.removeEventListener("pagehide", handlePageHide);
    };
  }, [
    vmId,
    invalidatePendingSessionRequest,
    invalidatePendingBootRequest,
    closeActiveSession,
    closeActiveBootSession,
  ]);

  // Auto-connect Terminal when instance transitions to running
  useEffect(() => {
    if (vmId === null) {
      prevAutoConnectVmIdRef.current = null;
      prevIsRunningRef.current = false;
      pendingRunningTransitionRef.current = false;
      return;
    }

    const isSameVm = prevAutoConnectVmIdRef.current === vmId;
    const wasRunning = isSameVm ? prevIsRunningRef.current : isRunning;
    if (!isSameVm) {
      pendingRunningTransitionRef.current = false;
    }
    prevAutoConnectVmIdRef.current = vmId;
    prevIsRunningRef.current = isRunning;

    const isInFlight =
      inFlightSessionRef.current !== null || sessionLoadingRef.current;

    // Defer auto-connect check if a fetch is already in flight when running transitions
    if (!wasRunning && isRunning && (sessionLoading || isInFlight)) {
      pendingRunningTransitionRef.current = true;
      return;
    }

    const shouldAutoConnect = shouldAutoConnectOnRunningTransition({
      wasRunning,
      isRunning,
      pendingRunningTransition: pendingRunningTransitionRef.current,
      hasWsUrl: !!wsUrl,
      sessionLoading,
      isInFlight,
      connectionStatus,
      errorCategory,
    });

    if (shouldAutoConnect) {
      pendingRunningTransitionRef.current = false;
      let ignore = false;
      queueMicrotask(() => {
        if (!ignore) {
          void fetchSession(vmId);
        }
      });
      return () => {
        ignore = true;
      };
    }

    if (!sessionLoading && !sessionLoadingRef.current) {
      pendingRunningTransitionRef.current = false;
    }
  }, [
    vmId,
    isRunning,
    wsUrl,
    errorCategory,
    sessionLoading,
    connectionStatus,
    fetchSession,
  ]);

  // Show waiting notice if no ttyS0 output arrives within 2s on a running VM
  const isSerialStreamActive =
    !!currentVm &&
    isVm &&
    isRunning &&
    ((driverMode === "exec" && !!bootWsUrl && !!bootTicket) ||
      (driverMode === "console" && !!wsUrl && !!ticket));
  const hasSerialOutput = bootSerialHasData || bootLog.length > 0;

  useEffect(() => {
    if (!isSerialStreamActive || hasSerialOutput) {
      let ignore = false;
      queueMicrotask(() => {
        if (!ignore) {
          setBootWaitingNotice(false);
        }
      });
      return () => {
        ignore = true;
      };
    }

    const timer = setTimeout(() => {
      setBootWaitingNotice(true);
    }, 2000);

    return () => clearTimeout(timer);
  }, [isSerialStreamActive, hasSerialOutput]);

  // Lazily attach Boot tab to ttyS0 when active in exec mode (unless already captured/frozen)
  useEffect(() => {
    if (
      !vmId ||
      activeTab !== "bootlog" ||
      !isVm ||
      !isRunning ||
      driverMode !== "exec" ||
      sessionLoading ||
      bootWsUrl ||
      bootSessionLoading ||
      bootSessionError ||
      bootLogFrozen
    ) {
      return;
    }

    let ignore = false;
    queueMicrotask(() => {
      if (!ignore) {
        void fetchBootSession(vmId);
      }
    });

    return () => {
      ignore = true;
    };
  }, [
    vmId,
    activeTab,
    isVm,
    isRunning,
    driverMode,
    sessionLoading,
    bootWsUrl,
    bootSessionLoading,
    bootSessionError,
    bootLogFrozen,
    fetchBootSession,
  ]);

  return {
    wsUrl,
    ticket,
    driverMode,
    fallbackReason,
    sessionLoading,
    sessionError,
    errorCategory,
    connectionStatus,
    agentReady,
    bootLog,
    bootLoading: bootSessionLoading || bootLogLoading,
    bootLogLoading,
    bootSessionLoading,
    bootLogFrozen,
    bootWsUrl,
    bootTicket,
    bootSessionError,
    bootConnectionStatus,
    bootWaitingNotice,
    getBootTextFallback,
    fetchSession,
    fetchBootSession,
    fetchBootLog,
    closeAllSessions,
    handleConnectionStatusChange,
    handleSessionError,
    setBootConnectionStatus,
    handleBootDataChunk,
    handleBootFirstData,
    handleAgentStarted,
    refreshBootLog,
    ensureBootTabLoaded,
  };
}
