"use client";

import React, { useEffect, useState, useRef, useCallback } from "react";
import {
  X,
  RefreshCw,
  Copy,
  Check,
  FileText,
  Terminal,
  ShieldAlert,
  Maximize2,
  Minimize2,
  AlertCircle,
  AlertTriangle,
  PowerOff,
  Users,
  Lock,
  Radio,
} from "lucide-react";
import { VirtualMachine, api, ApiError } from "@/lib/api";
import { XtermViewer } from "./XtermViewer";
import {
  ReadOnlyXtermViewer,
  ReadOnlyXtermViewerHandle,
} from "./ReadOnlyXtermViewer";
import { useModalEscape } from "@/lib/hooks/useModalEscape";
import { useI18n } from "@/lib/i18n/I18nContext";
import { useAuth } from "@/lib/auth/AuthContext";
import { getEcho } from "@/lib/echo";

interface ConsoleModalProps {
  vm: VirtualMachine | null;
  onClose: () => void;
}

type ConnectionStatus = "connecting" | "connected" | "disconnected" | "error";
type ErrorCategory =
  | "forbidden"
  | "not_running"
  | "serial_in_use"
  | "limit_reached"
  | "not_found"
  | "generic";

const MAX_BOOT_LOG_CHARS = 51200;
const ESC = String.fromCharCode(27);
const BEL = String.fromCharCode(7);
const ANSI_ESCAPE_REGEX = new RegExp(
  `${ESC}(?:\\[[0-9;?]*[ -/]*[@-~]|\\][^${BEL}${ESC}]*(?:${BEL}|${ESC}\\\\)|[@-Z\\\\-_])`,
  "g"
);
const LOGIN_PROMPT_REGEX = /[a-zA-Z0-9._-]+ login:\s*$/i;

function hasLoginPrompt(text: string): boolean {
  const tail = text
    .slice(-512)
    .replace(ANSI_ESCAPE_REGEX, "")
    .replace(/[\r\n]+$/, "");
  return LOGIN_PROMPT_REGEX.test(tail);
}

export function ConsoleModal({ vm, onClose }: ConsoleModalProps) {
  const { t } = useI18n();
  const { user } = useAuth();

  const [realtimeVm, setRealtimeVm] = useState<VirtualMachine | null>(() => vm);
  useEffect(() => {
    let ignore = false;
    queueMicrotask(() => {
      if (!ignore) {
        setRealtimeVm(vm);
      }
    });
    return () => {
      ignore = true;
    };
  }, [vm]);

  const currentVm = vm
    ? realtimeVm && realtimeVm.id === vm.id
      ? realtimeVm
      : vm
    : null;

  const [wsUrl, setWsUrl] = useState<string | null>(null);
  const [ticket, setTicket] = useState<string | null>(null);
  const [driverMode, setDriverMode] = useState<"exec" | "console">("exec");
  const [fallbackReason, setFallbackReason] = useState<string | null>(null);
  const [sessionLoading, setSessionLoading] = useState(false);
  const [sessionError, setSessionError] = useState<string | null>(null);
  const [errorCategory, setErrorCategory] = useState<ErrorCategory | null>(null);
  const [connectionStatus, setConnectionStatus] = useState<ConnectionStatus>("connecting");
  const [isFullscreen, setIsFullscreen] = useState(false);
  const [activeTab, setActiveTab] = useState<"terminal" | "bootlog">("terminal");

  // Agent readiness state when Terminal fell back to serial console
  const [agentReady, setAgentReady] = useState(false);

  // Boot log / serial viewer state
  const [bootLog, setBootLog] = useState<string>("");
  const [bootLogLoading, setBootLogLoading] = useState(false);
  const [bootLogFrozen, setBootLogFrozen] = useState(false);
  const [bootWsUrl, setBootWsUrl] = useState<string | null>(null);
  const [bootTicket, setBootTicket] = useState<string | null>(null);
  const [bootSessionLoading, setBootSessionLoading] = useState(false);
  const [bootSessionError, setBootSessionError] = useState<string | null>(null);
  const [bootConnectionStatus, setBootConnectionStatus] = useState<ConnectionStatus>("connecting");
  const [bootSerialHasData, setBootSerialHasData] = useState(false);
  const [bootWaitingNotice, setBootWaitingNotice] = useState(false);
  const [copied, setCopied] = useState(false);

  const sessionIdRef = useRef<string | null>(null);
  const sessionRequestIdRef = useRef<number>(0);
  const sessionLoadingRef = useRef<boolean>(false);
  const inFlightSessionRef = useRef<{
    vmId: number;
    driver: "auto" | "exec" | "console";
  } | null>(null);
  const pendingRunningTransitionRef = useRef<boolean>(false);
  const connectionStatusRef = useRef<ConnectionStatus>("connecting");
  const driverModeRef = useRef<"exec" | "console">("exec");
  const bootSessionIdRef = useRef<string | null>(null);
  const vmIdRef = useRef<number | null>(null);
  const prevAutoConnectVmIdRef = useRef<number | null>(null);
  const prevIsRunningRef = useRef<boolean>(false);
  const bootBufferRef = useRef<string>("");
  const bootLogFrozenRef = useRef<boolean>(false);
  const readOnlyViewerRef = useRef<ReadOnlyXtermViewerHandle>(null);

  const isVm = currentVm?.instance_type === "virtual-machine";
  const isRunning = currentVm?.status?.toLowerCase() === "running";

  useEffect(() => {
    connectionStatusRef.current = connectionStatus;
  }, [connectionStatus]);

  useEffect(() => {
    driverModeRef.current = driverMode;
  }, [driverMode]);

  const handleConnectionStatusChange = useCallback((status: ConnectionStatus) => {
    connectionStatusRef.current = status;
    setConnectionStatus(status);
  }, []);

  const invalidatePendingSessionRequest = useCallback(() => {
    sessionRequestIdRef.current++;
    inFlightSessionRef.current = null;
    sessionLoadingRef.current = false;
    pendingRunningTransitionRef.current = false;
  }, []);

  const closeActiveSession = useCallback((reason: string = "client_disconnect") => {
    const activeSessionId = sessionIdRef.current;
    const currentVmId = vmIdRef.current;
    if (activeSessionId && currentVmId) {
      sessionIdRef.current = null;
      void api.closeConsoleSession(currentVmId, activeSessionId, reason).catch(() => {});
    }
  }, []);

  const closeActiveBootSession = useCallback((reason: string = "client_disconnect") => {
    const activeBootSessionId = bootSessionIdRef.current;
    const currentVmId = vmIdRef.current;
    if (activeBootSessionId && currentVmId) {
      bootSessionIdRef.current = null;
      void api.closeConsoleSession(currentVmId, activeBootSessionId, reason).catch(() => {});
    }
  }, []);

  const handleClose = useCallback(() => {
    invalidatePendingSessionRequest();
    closeActiveSession("client_closed");
    closeActiveBootSession("client_closed");
    onClose();
  }, [invalidatePendingSessionRequest, closeActiveSession, closeActiveBootSession, onClose]);

  useModalEscape(handleClose, !!currentVm);

  const estimateTerminalDimensions = (fullscreen: boolean) => {
    if (typeof window === "undefined") {
      return { cols: 80, rows: 24 };
    }
    const modalWidth = fullscreen ? window.innerWidth : Math.min(window.innerWidth - 32, 1152);
    const modalHeight = fullscreen ? window.innerHeight : 680;
    const termHeight = Math.max(200, modalHeight - 130);

    const cols = Math.max(80, Math.min(240, Math.floor((modalWidth - 24) / 9)));
    const rows = Math.max(24, Math.min(80, Math.floor((termHeight - 24) / 17)));
    return { cols, rows };
  };

  const fetchSession = useCallback(
    async (vmId: number, preferredDriver?: "auto" | "exec" | "console") => {
      const requestedDriver = preferredDriver ?? "auto";
      if (
        inFlightSessionRef.current &&
        inFlightSessionRef.current.vmId === vmId &&
        inFlightSessionRef.current.driver === requestedDriver
      ) {
        return;
      }

      closeActiveSession("reconnect");
      const requestId = ++sessionRequestIdRef.current;
      inFlightSessionRef.current = { vmId, driver: requestedDriver };
      sessionLoadingRef.current = true;

      try {
        setSessionLoading(true);
        setSessionError(null);
        setErrorCategory(null);
        connectionStatusRef.current = "connecting";
        setConnectionStatus("connecting");
        setWsUrl(null);
        setTicket(null);

        const { cols, rows } = estimateTerminalDimensions(isFullscreen);
        const res = await api.getConsoleSession(vmId, {
          type: "console",
          cols,
          rows,
          driver: preferredDriver,
        });

        if (requestId !== sessionRequestIdRef.current) {
          void api.closeConsoleSession(vmId, res.session_id, "superseded").catch(() => {});
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

        const protocol = window.location.protocol === "https:" ? "wss:" : "ws:";
        const targetWsUrl = `${protocol}//${window.location.host}${res.websocket_path}`;
        setWsUrl(targetWsUrl);
      } catch (err: unknown) {
        if (requestId !== sessionRequestIdRef.current) return;

        let category: ErrorCategory = "generic";
        let errorMessage = "Failed to initiate interactive console session.";

        if (err instanceof ApiError) {
          if (err.status === 403) {
            category = "forbidden";
            errorMessage =
              "Console access is restricted to the workload owner only. Administrators and impersonated sessions are denied guest console access to uphold tenant privacy.";
          } else if (err.status === 409) {
            const lowerMsg = (err.message || "").toLowerCase();
            if (
              lowerMsg.includes("already active") ||
              lowerMsg.includes("already in use") ||
              lowerMsg.includes("serial console")
            ) {
              category = "serial_in_use";
              errorMessage =
                err.message ||
                "A serial console session is already active for this instance. Only one concurrent serial console connection is permitted.";
            } else {
              category = "not_running";
              errorMessage =
                err.message ||
                "Interactive console is only available while the instance is running. Please start the instance first.";
            }
          } else if (err.status === 429) {
            category = "limit_reached";
            errorMessage =
              err.message ||
              "Maximum number of concurrent terminal sessions reached for this instance. Please close existing sessions or try again later.";
          } else if (err.status === 404 || (err.message || "").toLowerCase().includes("not found")) {
            category = "not_found";
            errorMessage =
              err.message || "Workload instance or guest shell executable could not be found.";
          } else {
            errorMessage = err.message || "An unexpected error occurred.";
          }
        } else if (err instanceof Error) {
          const lowerMsg = err.message.toLowerCase();
          if (lowerMsg.includes("403") || lowerMsg.includes("forbidden")) {
            category = "forbidden";
            errorMessage =
              "Console access is restricted to the workload owner only. Administrators and impersonated sessions are denied guest console access to uphold tenant privacy.";
          } else if (
            lowerMsg.includes("already active") ||
            lowerMsg.includes("already in use") ||
            lowerMsg.includes("serial console")
          ) {
            category = "serial_in_use";
            errorMessage =
              "A serial console session is already active for this instance. Only one active serial console connection is permitted.";
          } else if (lowerMsg.includes("409") || lowerMsg.includes("running")) {
            category = "not_running";
            errorMessage =
              "Interactive console is only available while the instance is running. Please start the instance first.";
          } else if (lowerMsg.includes("429") || lowerMsg.includes("concurrent")) {
            category = "limit_reached";
            errorMessage =
              "Maximum number of concurrent terminal sessions reached for this instance.";
          } else if (lowerMsg.includes("not found")) {
            category = "not_found";
            errorMessage = "Workload instance or guest shell executable could not be found.";
          } else {
            errorMessage = err.message;
          }
        }

        setErrorCategory(category);
        setSessionError(errorMessage);
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
    [closeActiveBootSession, closeActiveSession, isFullscreen]
  );

  const fetchSessionRef = useRef(fetchSession);
  useEffect(() => {
    fetchSessionRef.current = fetchSession;
  }, [fetchSession]);

  const fetchBootSession = async (vmId: number) => {
    closeActiveBootSession("refresh");

    try {
      setBootSessionLoading(true);
      setBootSessionError(null);
      setBootSerialHasData(false);
      setBootWaitingNotice(false);
      bootBufferRef.current = "";
      bootLogFrozenRef.current = false;
      setBootLog("");
      setBootLogFrozen(false);

      const { cols, rows } = estimateTerminalDimensions(isFullscreen);
      const res = await api.getConsoleSession(vmId, {
        type: "console",
        cols,
        rows,
        driver: "console",
      });

      bootSessionIdRef.current = res.session_id;
      setBootTicket(res.ticket);

      const protocol = window.location.protocol === "https:" ? "wss:" : "ws:";
      setBootWsUrl(`${protocol}//${window.location.host}${res.websocket_path}`);
    } catch (err: unknown) {
      const msg =
        err instanceof Error
          ? err.message
          : "Failed to attach to VM serial console (ttyS0).";
      setBootSessionError(msg);
    } finally {
      setBootSessionLoading(false);
    }
  };

  const fetchBootLog = async (vmId: number) => {
    try {
      setBootLogLoading(true);
      const res = await api.getConsoleLog(vmId);
      const output = res.output || "";
      bootBufferRef.current = output;
      setBootLog(output);
    } catch (err: unknown) {
      const msg =
        err instanceof Error
          ? `Error loading boot log: ${err.message}`
          : "Failed to load instance boot log.";
      bootBufferRef.current = msg;
      setBootLog(msg);
    } finally {
      setBootLogLoading(false);
    }
  };

  const handleBootDataChunk = useCallback((chunk: string) => {
    if (!chunk) return;
    setBootSerialHasData(true);

    if (bootLogFrozenRef.current) return;

    const remaining = MAX_BOOT_LOG_CHARS - bootBufferRef.current.length;
    if (remaining <= 0) {
      bootLogFrozenRef.current = true;
      setBootLogFrozen(true);
      return;
    }

    const next = bootBufferRef.current + chunk.slice(0, remaining);
    bootBufferRef.current = next;
    setBootLog(next);

    if (next.length >= MAX_BOOT_LOG_CHARS || hasLoginPrompt(next)) {
      bootLogFrozenRef.current = true;
      setBootLogFrozen(true);
    }
  }, []);

  const handleBootFirstData = useCallback(() => {
    setBootSerialHasData(true);
    setBootWaitingNotice(false);
  }, []);

  // Reset state and initiate Terminal session when modal opens for a VM
  useEffect(() => {
    if (!vm) {
      invalidatePendingSessionRequest();
      closeActiveSession("modal_closed");
      closeActiveBootSession("modal_closed");
      queueMicrotask(() => {
        setWsUrl(null);
        setTicket(null);
        setBootWsUrl(null);
        setBootTicket(null);
        setBootSessionError(null);
        setBootSerialHasData(false);
        setBootWaitingNotice(false);
        setSessionError(null);
        setErrorCategory(null);
        bootBufferRef.current = "";
        bootLogFrozenRef.current = false;
        setBootLog("");
        setBootLogFrozen(false);
        setCopied(false);
        setFallbackReason(null);
        setAgentReady(false);
        setActiveTab("terminal");
      });
      return;
    }

    vmIdRef.current = vm.id;
    let ignore = false;

    queueMicrotask(() => {
      if (!ignore) {
        setWsUrl(null);
        setTicket(null);
        setBootWsUrl(null);
        setBootTicket(null);
        setBootSessionError(null);
        setBootSerialHasData(false);
        setBootWaitingNotice(false);
        setSessionError(null);
        setErrorCategory(null);
        bootBufferRef.current = "";
        bootLogFrozenRef.current = false;
        setBootLog("");
        setBootLogFrozen(false);
        setCopied(false);
        setFallbackReason(null);
        setAgentReady(false);
        setActiveTab("terminal");
        driverModeRef.current = "exec";
        setDriverMode("exec");
        connectionStatusRef.current = "connecting";
        setConnectionStatus("connecting");

        void fetchSessionRef.current(vm.id);
      }
    });

    return () => {
      ignore = true;
      invalidatePendingSessionRequest();
      closeActiveSession("modal_unmounted");
      closeActiveBootSession("modal_unmounted");
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [vm?.id]);

  // Listen for VirtualMachineStatusUpdated on Echo to keep modal status reactive
  useEffect(() => {
    if (!vm?.id || !user?.id) return;

    const echo = getEcho();
    if (!echo) return;

    const targetVmId = vm.id;
    const handleStatusUpdate = (event: {
      vm?: Partial<VirtualMachine> & { id?: number };
    }) => {
      const updated = event?.vm;
      if (updated && Number(updated.id) === Number(targetVmId)) {
        setRealtimeVm((prev) => {
          const base = prev ?? vm;
          return {
            ...base,
            ...updated,
            id: base.id,
            status: updated.status ?? base.status,
          };
        });
      }
    };

    const userChannel = echo.private(`users.${user.id}`);
    userChannel.listen(".VirtualMachineStatusUpdated", handleStatusUpdate);
    userChannel.listen("VirtualMachineStatusUpdated", handleStatusUpdate);

    return () => {
      try {
        userChannel.stopListening(".VirtualMachineStatusUpdated", handleStatusUpdate);
        userChannel.stopListening("VirtualMachineStatusUpdated", handleStatusUpdate);
      } catch {
        // ignore cleanup issues
      }
    };
  }, [vm, user?.id]);

  // Auto-connect Terminal when instance transitions to running
  useEffect(() => {
    const currentId = currentVm?.id ?? null;
    if (currentId === null) {
      prevAutoConnectVmIdRef.current = null;
      prevIsRunningRef.current = false;
      pendingRunningTransitionRef.current = false;
      return;
    }

    const isSameVm = prevAutoConnectVmIdRef.current === currentId;
    const wasRunning = isSameVm ? prevIsRunningRef.current : isRunning;
    if (!isSameVm) {
      pendingRunningTransitionRef.current = false;
    }
    prevAutoConnectVmIdRef.current = currentId;
    prevIsRunningRef.current = isRunning;

    const isSessionActiveOrConnecting =
      sessionLoading ||
      sessionLoadingRef.current ||
      inFlightSessionRef.current !== null ||
      (!!wsUrl &&
        (connectionStatus === "connected" || connectionStatus === "connecting"));

    // Defer auto-connect check if a fetch is already in flight when running transitions
    if (!wasRunning && isRunning) {
      if (sessionLoading || sessionLoadingRef.current || inFlightSessionRef.current !== null) {
        pendingRunningTransitionRef.current = true;
        return;
      }
    }

    const shouldAutoConnect =
      errorCategory === "not_running"
        ? isRunning &&
          (!wasRunning || pendingRunningTransitionRef.current) &&
          !isSessionActiveOrConnecting
        : !wasRunning &&
          isRunning &&
          !wsUrl &&
          !sessionLoading &&
          !errorCategory &&
          !isSessionActiveOrConnecting;

    if (shouldAutoConnect) {
      pendingRunningTransitionRef.current = false;
      let ignore = false;
      queueMicrotask(() => {
        if (!ignore) {
          void fetchSessionRef.current(currentId);
        }
      });
      return () => {
        ignore = true;
      };
    }

    if (!sessionLoading && !sessionLoadingRef.current) {
      pendingRunningTransitionRef.current = false;
    }
  }, [currentVm?.id, isRunning, wsUrl, errorCategory, sessionLoading, connectionStatus]);

  // Listen for VirtualMachineAgentStarted on Echo to recover or upgrade Terminal when agent comes online
  useEffect(() => {
    const currentVmId = currentVm?.id ?? null;
    if (!currentVmId || !isVm || !user?.id) {
      return;
    }

    const echo = getEcho();
    if (!echo) return;

    interface AgentStartedEvent {
      vm_id?: number;
      vm?: { id?: number };
      agent_ready?: boolean;
    }

    const handleAgentStarted = (event: AgentStartedEvent) => {
      const targetVmId = Number(event?.vm_id ?? event?.vm?.id);
      if (targetVmId !== Number(currentVmId)) {
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
        void fetchSessionRef.current(targetVmId, "exec");
      }
    };

    const userChannel = echo.private(`users.${user.id}`);
    userChannel.listen(".VirtualMachineAgentStarted", handleAgentStarted);
    userChannel.listen("VirtualMachineAgentStarted", handleAgentStarted);

    return () => {
      try {
        userChannel.stopListening(".VirtualMachineAgentStarted", handleAgentStarted);
        userChannel.stopListening("VirtualMachineAgentStarted", handleAgentStarted);
      } catch {
        // ignore cleanup issues
      }
    };
  }, [currentVm?.id, isVm, user?.id]);

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
      queueMicrotask(() => {
        setBootWaitingNotice(false);
      });
      return;
    }

    const timer = setTimeout(() => {
      setBootWaitingNotice(true);
    }, 2000);

    return () => clearTimeout(timer);
  }, [isSerialStreamActive, hasSerialOutput]);

  // Lazily attach Boot tab to ttyS0 when active in exec mode (unless already captured/frozen)
  useEffect(() => {
    if (
      !currentVm ||
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
        void fetchBootSession(currentVm.id);
      }
    });

    return () => {
      ignore = true;
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [
    currentVm?.id,
    activeTab,
    isVm,
    isRunning,
    driverMode,
    sessionLoading,
    bootWsUrl,
    bootSessionLoading,
    bootSessionError,
    bootLogFrozen,
  ]);

  if (!currentVm) return null;

  const handleCopyBootLog = () => {
    const text =
      readOnlyViewerRef.current?.getText() || bootBufferRef.current || bootLog;
    if (text) {
      void navigator.clipboard.writeText(text);
      setCopied(true);
      setTimeout(() => setCopied(false), 2000);
    }
  };

  const handleTabChange = (tab: "terminal" | "bootlog") => {
    setActiveTab(tab);
    if (tab === "bootlog") {
      if (isVm) {
        if (
          isRunning &&
          driverMode === "exec" &&
          !bootWsUrl &&
          !bootSessionLoading &&
          !bootLogFrozen
        ) {
          void fetchBootSession(currentVm.id);
        }
      } else if (!bootLog && !bootLogLoading) {
        void fetchBootLog(currentVm.id);
      }
    }
  };

  const handleRefreshBootLog = () => {
    if (isVm && isRunning) {
      if (driverMode === "exec") {
        void fetchBootSession(currentVm.id);
      } else {
        bootBufferRef.current = "";
        bootLogFrozenRef.current = false;
        setBootLog("");
        setBootLogFrozen(false);
        setBootSerialHasData(false);
        setBootWaitingNotice(false);
      }
    } else {
      void fetchBootLog(currentVm.id);
    }
  };

  const renderStatusBadge = () => {
    if (connectionStatus === "connected") {
      return (
        <span className="flex items-center space-x-1 text-[11px] px-2 py-0.5 rounded-full bg-emerald-500/10 text-emerald-400 border border-emerald-500/20 font-mono">
          <span className="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-pulse" />
          <span>{t("console.connected")}</span>
        </span>
      );
    }

    if (connectionStatus === "connecting") {
      return (
        <span className="flex items-center space-x-1 text-[11px] px-2 py-0.5 rounded-full bg-blue-500/10 text-blue-400 border border-blue-500/20 font-mono">
          <RefreshCw className="w-2.5 h-2.5 animate-spin" />
          <span>{t("console.connecting")}</span>
        </span>
      );
    }

    if (connectionStatus === "disconnected") {
      return (
        <span className="text-[11px] px-2 py-0.5 rounded-full bg-amber-500/10 text-amber-400 border border-amber-500/20 font-mono">
          {t("console.disconnected")}
        </span>
      );
    }

    return (
      <span className="text-[11px] px-2 py-0.5 rounded-full bg-red-500/10 text-red-400 border border-red-500/20 font-mono">
        Error
      </span>
    );
  };

  const renderErrorCard = () => {
    switch (errorCategory) {
      case "forbidden":
        return (
          <div className="flex-1 flex flex-col items-center justify-center p-6 text-center space-y-3 font-mono">
            <div className="p-3 bg-red-500/10 rounded-2xl border border-red-500/20 text-red-400">
              <ShieldAlert className="w-10 h-10" />
            </div>
            <h3 className="text-gray-200 text-sm font-semibold tracking-wide">
              {t("console.forbidden_title")}
            </h3>
            <p className="text-gray-400 text-xs max-w-md leading-relaxed font-sans">
              {sessionError || t("console.forbidden_desc")}
            </p>
          </div>
        );

      case "not_running":
        return (
          <div className="flex-1 flex flex-col items-center justify-center p-6 text-center space-y-3 font-mono">
            <div className="p-3 bg-amber-500/10 rounded-2xl border border-amber-500/20 text-amber-400">
              <PowerOff className="w-10 h-10" />
            </div>
            <h3 className="text-gray-200 text-sm font-semibold tracking-wide">
              Instance Not Running
            </h3>
            <p className="text-gray-400 text-xs max-w-md leading-relaxed font-sans">
              {sessionError ||
                "Interactive console is only available while the instance is running. Please start the instance first."}
            </p>
            <button
              onClick={() => void fetchSession(currentVm.id)}
              className="mt-2 px-3 py-1.5 bg-blue-600 hover:bg-blue-500 text-white rounded-lg text-xs font-sans transition"
            >
              Retry Connection
            </button>
          </div>
        );

      case "serial_in_use":
        return (
          <div className="flex-1 flex flex-col items-center justify-center p-6 text-center space-y-3 font-mono">
            <div className="p-3 bg-amber-500/10 rounded-2xl border border-amber-500/20 text-amber-400">
              <Users className="w-10 h-10" />
            </div>
            <h3 className="text-gray-200 text-sm font-semibold tracking-wide">
              Serial Console Already In Use
            </h3>
            <p className="text-gray-400 text-xs max-w-md leading-relaxed font-sans">
              {sessionError ||
                "A serial console session is already active for this instance. Only one active serial console connection is permitted at a time."}
            </p>
            <button
              onClick={() => void fetchSession(currentVm.id)}
              className="mt-2 px-3 py-1.5 bg-blue-600 hover:bg-blue-500 text-white rounded-lg text-xs font-sans transition"
            >
              Retry Connection
            </button>
          </div>
        );

      case "limit_reached":
        return (
          <div className="flex-1 flex flex-col items-center justify-center p-6 text-center space-y-3 font-mono">
            <div className="p-3 bg-amber-500/10 rounded-2xl border border-amber-500/20 text-amber-400">
              <AlertCircle className="w-10 h-10" />
            </div>
            <h3 className="text-gray-200 text-sm font-semibold tracking-wide">
              Session Limit Reached
            </h3>
            <p className="text-gray-400 text-xs max-w-md leading-relaxed font-sans">
              {sessionError ||
                "Maximum number of concurrent terminal sessions reached for this instance. Please close existing sessions or try again later."}
            </p>
            <button
              onClick={() => void fetchSession(currentVm.id)}
              className="mt-2 px-3 py-1.5 bg-blue-600 hover:bg-blue-500 text-white rounded-lg text-xs font-sans transition"
            >
              Retry Connection
            </button>
          </div>
        );

      case "not_found":
        return (
          <div className="flex-1 flex flex-col items-center justify-center p-6 text-center space-y-3 font-mono">
            <div className="p-3 bg-blue-500/10 rounded-2xl border border-blue-500/20 text-blue-400">
              <Terminal className="w-10 h-10" />
            </div>
            <h3 className="text-gray-200 text-sm font-semibold tracking-wide">
              Agent Unavailable / Shell Not Found
            </h3>
            <p className="text-gray-400 text-xs max-w-md leading-relaxed font-sans">
              {sessionError || "The guest shell executable or guest agent could not be found."}
            </p>
            <button
              onClick={() => void fetchSession(currentVm.id)}
              className="mt-2 px-3 py-1.5 bg-blue-600 hover:bg-blue-500 text-white rounded-lg text-xs font-sans transition"
            >
              Retry Connection
            </button>
          </div>
        );

      default:
        return (
          <div className="flex-1 flex flex-col items-center justify-center p-6 text-center space-y-3 font-mono">
            <div className="p-3 bg-red-500/10 rounded-2xl border border-red-500/20 text-red-400">
              <AlertTriangle className="w-10 h-10" />
            </div>
            <h3 className="text-gray-200 text-sm font-semibold tracking-wide">
              Unable to Connect to Console
            </h3>
            <p className="text-gray-400 text-xs max-w-md leading-relaxed font-sans">
              {sessionError || "An unexpected error occurred while initiating the console session."}
            </p>
            <button
              onClick={() => void fetchSession(currentVm.id)}
              className="mt-2 px-3 py-1.5 bg-blue-600 hover:bg-blue-500 text-white rounded-lg text-xs font-sans transition"
            >
              Retry Connection
            </button>
          </div>
        );
    }
  };

  const bootEmptyMessage = bootWaitingNotice
    ? t("console.vm_waiting_serial")
    : !isVm && !bootLogLoading && !bootLog
      ? "No console log output available for this instance."
      : undefined;

  const isBootStreamingSerial =
    isVm &&
    isRunning &&
    ((driverMode === "exec" && !!bootWsUrl && !!bootTicket) ||
      (driverMode === "console" && !!wsUrl && !!ticket));

  const bootLoading = bootSessionLoading || bootLogLoading;

  return (
    <div
      className={
        isFullscreen
          ? "fixed inset-0 z-[60] flex flex-col bg-black animate-in fade-in duration-150"
          : "fixed inset-0 z-[60] flex items-center justify-center p-4 bg-black/75 dark:bg-black/85 backdrop-blur-md animate-in fade-in duration-200"
      }
    >
      <div
        className={
          isFullscreen
            ? "flex-1 flex flex-col w-full h-full bg-gray-950 overflow-hidden"
            : "bg-gray-950 border border-gray-800 rounded-2xl w-full max-w-6xl overflow-hidden shadow-2xl flex flex-col h-[680px] max-h-[90vh]"
        }
      >
        {/* Header */}
        <div className="flex items-center justify-between px-4 py-2.5 bg-gray-900 border-b border-gray-800 select-none">
          <div className="flex items-center space-x-3">
            <div className="flex space-x-1.5">
              <span className="w-3 h-3 rounded-full bg-red-500/80 inline-block" />
              <span className="w-3 h-3 rounded-full bg-amber-500/80 inline-block" />
              <span className="w-3 h-3 rounded-full bg-emerald-500/80 inline-block" />
            </div>

            <div className="flex items-center space-x-2 text-gray-200 text-xs font-mono font-medium">
              <span>{currentVm.name}</span>
              <span className="text-[10px] px-1.5 py-0.5 rounded bg-gray-800 text-gray-400 uppercase tracking-wider">
                {currentVm.instance_type}
              </span>
              <span className="text-[10px] px-1.5 py-0.5 rounded bg-blue-500/10 text-blue-400 border border-blue-500/20 uppercase tracking-wider">
                {currentVm.status}
              </span>
            </div>

            {/* Active Driver Mode Badge */}
            <div className="hidden sm:flex items-center">
              {driverMode === "exec" ? (
                <span className="flex items-center space-x-1 text-[11px] px-2 py-0.5 rounded-full bg-emerald-500/10 text-emerald-400 border border-emerald-500/20 font-mono">
                  <span className="w-1.5 h-1.5 rounded-full bg-emerald-400" />
                  <span>Exec Shell</span>
                </span>
              ) : (
                <span className="flex items-center space-x-1 text-[11px] px-2 py-0.5 rounded-full bg-purple-500/10 text-purple-400 border border-purple-500/20 font-mono">
                  <span className="w-1.5 h-1.5 rounded-full bg-purple-400" />
                  <span>{t("console.serial_mode")}</span>
                </span>
              )}
            </div>
          </div>

          <div className="flex items-center space-x-2">
            {/* Tab Switcher */}
            <div className="flex items-center bg-gray-950/60 p-0.5 rounded-lg border border-gray-800">
              <button
                onClick={() => handleTabChange("terminal")}
                className={`flex items-center space-x-1.5 px-2.5 py-1 rounded-md text-xs font-mono transition ${
                  activeTab === "terminal"
                    ? "bg-blue-600 text-white font-medium shadow-sm"
                    : "text-gray-400 hover:text-gray-200 hover:bg-gray-800/60"
                }`}
              >
                <Terminal className="w-3.5 h-3.5" />
                <span>{t("console.terminal_mode")}</span>
              </button>
              <button
                onClick={() => handleTabChange("bootlog")}
                className={`flex items-center space-x-1.5 px-2.5 py-1 rounded-md text-xs font-mono transition ${
                  activeTab === "bootlog"
                    ? "bg-blue-600 text-white font-medium shadow-sm"
                    : "text-gray-400 hover:text-gray-200 hover:bg-gray-800/60"
                }`}
              >
                <FileText className="w-3.5 h-3.5" />
                <span>{t("console.boot_log")}</span>
              </button>
            </div>

            {/* Action controls based on active tab */}
            {activeTab === "terminal" && (
              <>
                {renderStatusBadge()}

                {errorCategory !== "forbidden" && (
                  <button
                    onClick={() =>
                      void fetchSession(currentVm.id, agentReady ? "exec" : undefined)
                    }
                    disabled={sessionLoading}
                    title={t("console.reconnect")}
                    className="flex items-center space-x-1 px-2.5 py-1 rounded-lg text-xs font-mono text-gray-300 bg-gray-800 hover:bg-gray-700 transition disabled:opacity-50"
                  >
                    <RefreshCw className={`w-3.5 h-3.5 ${sessionLoading ? "animate-spin" : ""}`} />
                    <span className="hidden sm:inline">{t("console.reconnect")}</span>
                  </button>
                )}
              </>
            )}

            {activeTab === "bootlog" && (
              <>
                <button
                  onClick={handleRefreshBootLog}
                  disabled={bootLoading}
                  title="Refresh Boot Log"
                  className="flex items-center space-x-1 px-2.5 py-1 rounded-lg text-xs font-mono text-gray-300 bg-gray-800 hover:bg-gray-700 transition disabled:opacity-50"
                >
                  <RefreshCw className={`w-3.5 h-3.5 ${bootLoading ? "animate-spin" : ""}`} />
                  <span className="hidden sm:inline">Refresh</span>
                </button>

                <button
                  onClick={handleCopyBootLog}
                  title={t("console.copy_boot_log")}
                  className="flex items-center space-x-1 px-2.5 py-1 rounded-lg text-xs font-mono text-gray-300 bg-gray-800 hover:bg-gray-700 transition"
                >
                  {copied ? (
                    <Check className="w-3.5 h-3.5 text-emerald-400" />
                  ) : (
                    <Copy className="w-3.5 h-3.5" />
                  )}
                  <span>{copied ? "Copied" : t("console.copy_boot_log")}</span>
                </button>
              </>
            )}

            <div className="h-4 w-px bg-gray-800 mx-0.5 hidden sm:block" />

            <button
              onClick={() => setIsFullscreen(!isFullscreen)}
              title={isFullscreen ? t("console.exit_fullscreen") : t("console.fullscreen")}
              className="p-1.5 rounded-lg text-gray-400 hover:text-white hover:bg-gray-800 transition"
            >
              {isFullscreen ? (
                <Minimize2 className="w-4 h-4" />
              ) : (
                <Maximize2 className="w-4 h-4" />
              )}
            </button>

            <button
              onClick={handleClose}
              title="Close"
              className="p-1.5 rounded-lg text-gray-400 hover:text-white hover:bg-gray-800 transition"
            >
              <X className="w-4 h-4" />
            </button>
          </div>
        </div>

        {/* Agent Ready Banner when Terminal is on serial fallback */}
        {agentReady && driverMode === "console" && (
          <div className="bg-emerald-950/80 border-b border-emerald-500/30 px-3 py-1.5 flex items-center justify-between text-xs text-emerald-300">
            <span className="flex items-center space-x-1.5">
              <span>⚡</span>
              <span>{t("console.agent_ready_banner")}</span>
            </span>
            <button
              onClick={() => void fetchSession(currentVm.id, "exec")}
              className="px-2 py-0.5 rounded bg-emerald-600 hover:bg-emerald-500 text-white font-medium text-xs transition"
            >
              {t("console.switch_to_shell")}
            </button>
          </div>
        )}

        {/* Informational Sub-header Notice for Automatic Fallback */}
        {fallbackReason === "agent_unavailable" && !agentReady && (
          <div className="px-4 py-2 bg-amber-500/10 border-b border-amber-500/20 text-amber-300 text-xs font-mono flex items-center justify-between">
            <div className="flex items-center space-x-2">
              <AlertTriangle className="w-4 h-4 text-amber-400 shrink-0" />
              <span>
                Incus guest agent is unavailable on this VM — automatically connected to Serial Console (/console).
              </span>
            </div>
            <span className="text-[10px] text-amber-400/70 hidden sm:inline">Automatic Fallback</span>
          </div>
        )}

        {/* Modal Body: Persistent Tabbed Views (never unmount on tab switch) */}
        <div className="flex-1 overflow-hidden relative flex flex-col bg-black">
          {/* Terminal Tab View */}
          <div
            className={
              activeTab === "terminal"
                ? "flex-1 flex flex-col min-h-0 relative"
                : "hidden"
            }
          >
            {errorCategory ? (
              renderErrorCard()
            ) : sessionLoading || !wsUrl || !ticket ? (
              <div className="flex-1 flex items-center justify-center text-gray-500 space-x-2 font-mono text-xs">
                <RefreshCw className="w-4 h-4 animate-spin text-blue-400" />
                <span>Acquiring secure console ticket...</span>
              </div>
            ) : (
              <div className="flex-1 w-full h-full relative">
                <XtermViewer
                  wsUrl={wsUrl}
                  ticket={ticket}
                  onStatusChange={handleConnectionStatusChange}
                  onDataChunk={
                    isVm && driverMode === "console" ? handleBootDataChunk : undefined
                  }
                />
                {connectionStatus === "disconnected" && isRunning && (
                  <div className="absolute inset-x-4 bottom-4 z-10 flex items-center justify-between gap-3 px-4 py-2.5 rounded-xl bg-gray-900/95 border border-amber-500/30 shadow-xl backdrop-blur-sm text-xs">
                    <div className="flex items-center space-x-2 text-amber-200 font-mono">
                      <AlertCircle className="w-4 h-4 text-amber-400 shrink-0" />
                      <span>
                        {agentReady
                          ? t("console.agent_ready_banner")
                          : `${t("console.disconnected")} — click to reconnect.`}
                      </span>
                    </div>
                    <button
                      onClick={() =>
                        void fetchSession(currentVm.id, agentReady ? "exec" : undefined)
                      }
                      disabled={sessionLoading}
                      className="flex items-center space-x-1.5 px-3 py-1.5 rounded-lg bg-blue-600 hover:bg-blue-500 text-white font-medium text-xs transition shrink-0 disabled:opacity-50"
                    >
                      <RefreshCw className="w-3.5 h-3.5" />
                      <span>
                        {agentReady ? t("console.switch_to_shell") : t("console.reconnect")}
                      </span>
                    </button>
                  </div>
                )}
              </div>
            )}
          </div>

          {/* Boot Log Tab View */}
          <div
            className={
              activeTab === "bootlog"
                ? "flex-1 flex flex-col min-h-0 relative"
                : "hidden"
            }
          >
            {/* Read-only / Serial stream sub-bar */}
            <div className="px-3 py-1.5 bg-gray-900/70 border-b border-gray-800/80 flex items-center justify-between text-[11px] font-mono text-gray-400 select-none">
              <div className="flex items-center space-x-2">
                <span className="inline-flex items-center space-x-1 px-1.5 py-0.5 rounded bg-gray-800 text-gray-300 border border-gray-700/70">
                  <Lock className="w-3 h-3" />
                  <span>{t("console.read_only")}</span>
                </span>
                {isBootStreamingSerial && !bootLogFrozen && (
                  <span className="inline-flex items-center space-x-1 text-purple-300">
                    <Radio className="w-3 h-3 animate-pulse text-purple-400" />
                    <span>{t("console.streaming_serial")}</span>
                  </span>
                )}
                {bootLogFrozen && (
                  <span className="text-emerald-400">{t("console.boot_log_frozen")}</span>
                )}
              </div>
              {driverMode === "exec" && bootWsUrl && (
                <span className="text-gray-500">{bootConnectionStatus}</span>
              )}
            </div>

            {bootSessionError ? (
              <div className="flex-1 flex flex-col items-center justify-center p-6 text-center space-y-3 font-mono bg-[#090d16]">
                <div className="p-3 bg-amber-500/10 rounded-2xl border border-amber-500/20 text-amber-400">
                  <AlertTriangle className="w-8 h-8" />
                </div>
                <p className="text-gray-300 text-xs max-w-md leading-relaxed">
                  {bootSessionError}
                </p>
                <button
                  onClick={() => void fetchBootSession(currentVm.id)}
                  className="px-3 py-1.5 bg-blue-600 hover:bg-blue-500 text-white rounded-lg text-xs font-sans transition"
                >
                  Retry
                </button>
              </div>
            ) : bootLoading && !bootLog && !bootWsUrl ? (
              <div className="flex-1 flex items-center justify-center text-gray-500 space-x-2 font-mono text-xs bg-[#090d16]">
                <RefreshCw className="w-4 h-4 animate-spin text-blue-400" />
                <span>Reading hypervisor console log buffer...</span>
              </div>
            ) : (
              <div className="flex-1 min-h-0 relative">
                <ReadOnlyXtermViewer
                  ref={readOnlyViewerRef}
                  isActive={activeTab === "bootlog"}
                  wsUrl={isVm && isRunning && driverMode === "exec" ? bootWsUrl : null}
                  ticket={isVm && isRunning && driverMode === "exec" ? bootTicket : null}
                  textOutput={
                    isVm && isRunning && driverMode === "exec" && bootWsUrl && bootTicket
                      ? null
                      : bootLog
                  }
                  onStatusChange={setBootConnectionStatus}
                  onFirstData={handleBootFirstData}
                  onDataChunk={handleBootDataChunk}
                  emptyMessage={bootEmptyMessage}
                />
              </div>
            )}
          </div>
        </div>

        {/* Footer */}
        <div className="px-4 py-2 bg-gray-900/80 border-t border-gray-800/80 flex items-center justify-between text-[11px] font-mono text-gray-500 select-none">
          <div className="flex items-center space-x-3">
            <span>
              Mode: {driverMode === "exec" ? "Exec Shell (root)" : "Serial Console (xterm.js)"}
            </span>
            <span className="text-gray-700">•</span>
            <span>
              Active:{" "}
              {activeTab === "terminal"
                ? t("console.terminal_mode")
                : t("console.boot_log")}
            </span>
          </div>
          <span>Instance #{currentVm.id}</span>
        </div>
      </div>
    </div>
  );
}
