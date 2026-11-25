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
} from "lucide-react";
import { VirtualMachine, api, ApiError } from "@/lib/api";
import { XtermViewer } from "./XtermViewer";
import { useModalEscape } from "@/lib/hooks/useModalEscape";

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

export function ConsoleModal({ vm, onClose }: ConsoleModalProps) {
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

  // Boot log state
  const [bootLog, setBootLog] = useState<string>("");
  const [bootLogLoading, setBootLogLoading] = useState(false);
  const [copied, setCopied] = useState(false);

  const sessionIdRef = useRef<string | null>(null);
  const vmIdRef = useRef<number | null>(null);
  const bootLogEndRef = useRef<HTMLDivElement>(null);

  const closeActiveSession = useCallback((reason: string = "client_disconnect") => {
    const activeSessionId = sessionIdRef.current;
    const currentVmId = vmIdRef.current;
    if (activeSessionId && currentVmId) {
      sessionIdRef.current = null;
      void api.closeConsoleSession(currentVmId, activeSessionId, reason).catch(() => {});
    }
  }, []);

  const handleClose = useCallback(() => {
    closeActiveSession("client_closed");
    onClose();
  }, [closeActiveSession, onClose]);

  useModalEscape(handleClose, !!vm);

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

  const fetchSession = async (vmId: number) => {
    closeActiveSession("reconnect");

    try {
      setSessionLoading(true);
      setSessionError(null);
      setErrorCategory(null);
      setConnectionStatus("connecting");

      const { cols, rows } = estimateTerminalDimensions(isFullscreen);
      const res = await api.getConsoleSession(vmId, {
        type: "console",
        cols,
        rows,
      });

      setDriverMode(res.driver);
      setFallbackReason(res.fallback_reason ?? null);
      setTicket(res.ticket);
      sessionIdRef.current = res.session_id;

      const protocol = window.location.protocol === "https:" ? "wss:" : "ws:";
      const targetWsUrl = `${protocol}//${window.location.host}${res.websocket_path}`;
      setWsUrl(targetWsUrl);
    } catch (err: unknown) {
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
            "A serial console session is already active for this instance. Only one concurrent serial console connection is permitted.";
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
      setConnectionStatus("error");
    } finally {
      setSessionLoading(false);
    }
  };

  const fetchBootLog = async (vmId: number) => {
    try {
      setBootLogLoading(true);
      const res = await api.getConsoleLog(vmId);
      setBootLog(res.output || "Boot log buffer is empty.");
    } catch (err: unknown) {
      if (err instanceof Error) {
        setBootLog(`Error loading boot log: ${err.message}`);
      } else {
        setBootLog("Failed to load instance boot log.");
      }
    } finally {
      setBootLogLoading(false);
    }
  };

  useEffect(() => {
    if (!vm) {
      closeActiveSession("modal_closed");
      queueMicrotask(() => {
        setWsUrl(null);
        setTicket(null);
        setSessionError(null);
        setErrorCategory(null);
        setBootLog("");
        setCopied(false);
        setFallbackReason(null);
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
        setSessionError(null);
        setErrorCategory(null);
        setBootLog("");
        setCopied(false);
        setFallbackReason(null);
        setActiveTab("terminal");
        setDriverMode("exec");

        void fetchSession(vm.id);
        void fetchBootLog(vm.id);
      }
    });

    return () => {
      ignore = true;
      closeActiveSession("modal_unmounted");
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [vm?.id]);

  useEffect(() => {
    if (activeTab === "bootlog" && bootLog) {
      bootLogEndRef.current?.scrollIntoView({ behavior: "smooth" });
    }
  }, [bootLog, activeTab]);

  if (!vm) return null;

  const handleCopyBootLog = () => {
    if (bootLog) {
      void navigator.clipboard.writeText(bootLog);
      setCopied(true);
      setTimeout(() => setCopied(false), 2000);
    }
  };

  const renderStatusBadge = () => {
    if (connectionStatus === "connected") {
      return (
        <span className="flex items-center space-x-1 text-[11px] px-2 py-0.5 rounded-full bg-emerald-500/10 text-emerald-400 border border-emerald-500/20 font-mono">
          <span className="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-pulse" />
          <span>Connected</span>
        </span>
      );
    }

    if (connectionStatus === "connecting") {
      return (
        <span className="flex items-center space-x-1 text-[11px] px-2 py-0.5 rounded-full bg-blue-500/10 text-blue-400 border border-blue-500/20 font-mono">
          <RefreshCw className="w-2.5 h-2.5 animate-spin" />
          <span>Connecting...</span>
        </span>
      );
    }

    if (connectionStatus === "disconnected") {
      return (
        <span className="text-[11px] px-2 py-0.5 rounded-full bg-amber-500/10 text-amber-400 border border-amber-500/20 font-mono">
          Disconnected
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
              Console Access Restricted
            </h3>
            <p className="text-gray-400 text-xs max-w-md leading-relaxed font-sans">
              {sessionError ||
                "Console access is restricted to the workload owner only. Administrators and impersonated sessions are denied guest console access to uphold tenant privacy."}
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
              onClick={() => void fetchSession(vm.id)}
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
              onClick={() => void fetchSession(vm.id)}
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
              onClick={() => void fetchSession(vm.id)}
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
              onClick={() => void fetchSession(vm.id)}
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
              onClick={() => void fetchSession(vm.id)}
              className="mt-2 px-3 py-1.5 bg-blue-600 hover:bg-blue-500 text-white rounded-lg text-xs font-sans transition"
            >
              Retry Connection
            </button>
          </div>
        );
    }
  };

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
              <span>{vm.name}</span>
              <span className="text-[10px] px-1.5 py-0.5 rounded bg-gray-800 text-gray-400 uppercase tracking-wider">
                {vm.instance_type}
              </span>
              <span className="text-[10px] px-1.5 py-0.5 rounded bg-blue-500/10 text-blue-400 border border-blue-500/20">
                {vm.status}
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
                  <span>Serial Console</span>
                </span>
              )}
            </div>
          </div>

          <div className="flex items-center space-x-2">
            {/* Tab Switcher */}
            <div className="flex items-center bg-gray-950/60 p-0.5 rounded-lg border border-gray-800">
              <button
                onClick={() => setActiveTab("terminal")}
                className={`flex items-center space-x-1.5 px-2.5 py-1 rounded-md text-xs font-mono transition ${
                  activeTab === "terminal"
                    ? "bg-blue-600 text-white font-medium shadow-sm"
                    : "text-gray-400 hover:text-gray-200 hover:bg-gray-800/60"
                }`}
              >
                <Terminal className="w-3.5 h-3.5" />
                <span>Terminal</span>
              </button>
              <button
                onClick={() => setActiveTab("bootlog")}
                className={`flex items-center space-x-1.5 px-2.5 py-1 rounded-md text-xs font-mono transition ${
                  activeTab === "bootlog"
                    ? "bg-blue-600 text-white font-medium shadow-sm"
                    : "text-gray-400 hover:text-gray-200 hover:bg-gray-800/60"
                }`}
              >
                <FileText className="w-3.5 h-3.5" />
                <span>Boot Log</span>
              </button>
            </div>

            {/* Action controls based on active tab */}
            {activeTab === "terminal" && (
              <>
                {renderStatusBadge()}

                {errorCategory !== "forbidden" && (
                  <button
                    onClick={() => void fetchSession(vm.id)}
                    disabled={sessionLoading}
                    title="Reconnect Terminal"
                    className="flex items-center space-x-1 px-2.5 py-1 rounded-lg text-xs font-mono text-gray-300 bg-gray-800 hover:bg-gray-700 transition disabled:opacity-50"
                  >
                    <RefreshCw className={`w-3.5 h-3.5 ${sessionLoading ? "animate-spin" : ""}`} />
                    <span className="hidden sm:inline">Reconnect</span>
                  </button>
                )}
              </>
            )}

            {activeTab === "bootlog" && (
              <>
                <button
                  onClick={() => void fetchBootLog(vm.id)}
                  disabled={bootLogLoading}
                  title="Refresh Boot Log"
                  className="flex items-center space-x-1 px-2.5 py-1 rounded-lg text-xs font-mono text-gray-300 bg-gray-800 hover:bg-gray-700 transition disabled:opacity-50"
                >
                  <RefreshCw className={`w-3.5 h-3.5 ${bootLogLoading ? "animate-spin" : ""}`} />
                  <span className="hidden sm:inline">Refresh</span>
                </button>

                <button
                  onClick={handleCopyBootLog}
                  title="Copy Boot Log"
                  className="flex items-center space-x-1 px-2.5 py-1 rounded-lg text-xs font-mono text-gray-300 bg-gray-800 hover:bg-gray-700 transition"
                >
                  {copied ? (
                    <Check className="w-3.5 h-3.5 text-emerald-400" />
                  ) : (
                    <Copy className="w-3.5 h-3.5" />
                  )}
                  <span>{copied ? "Copied" : "Copy"}</span>
                </button>
              </>
            )}

            <div className="h-4 w-px bg-gray-800 mx-0.5 hidden sm:block" />

            <button
              onClick={() => setIsFullscreen(!isFullscreen)}
              title={isFullscreen ? "Exit Fullscreen" : "Fullscreen"}
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

        {/* Informational Sub-header Notice for Automatic Fallback */}
        {fallbackReason === "agent_unavailable" && (
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

        {/* Modal Body: Tabbed Views */}
        <div className="flex-1 overflow-hidden relative flex flex-col bg-black">
          {/* Terminal Tab View: Kept mounted with hidden to preserve WebSocket session */}
          <div
            className={
              activeTab === "terminal"
                ? "flex-1 flex flex-col min-w-0 h-full relative"
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
              <div className={activeTab === "terminal" ? "flex-1 w-full h-full relative" : "hidden"}>
                <XtermViewer
                  wsUrl={wsUrl}
                  ticket={ticket}
                  onStatusChange={setConnectionStatus}
                />
              </div>
            )}
          </div>

          {/* Boot Log Tab View */}
          {activeTab === "bootlog" && (
            <div className="flex-1 flex flex-col min-w-0 h-full overflow-hidden bg-black">
              <div className="flex-1 p-4 font-mono text-xs overflow-y-auto text-gray-300 select-text leading-relaxed whitespace-pre-wrap">
                {bootLogLoading && !bootLog ? (
                  <div className="flex items-center justify-center h-full text-gray-500 space-x-2">
                    <RefreshCw className="w-4 h-4 animate-spin text-blue-400" />
                    <span>Reading hypervisor console log buffer...</span>
                  </div>
                ) : (
                  <div>{bootLog}</div>
                )}
                <div ref={bootLogEndRef} />
              </div>
            </div>
          )}
        </div>

        {/* Footer */}
        <div className="px-4 py-2 bg-gray-900/80 border-t border-gray-800/80 flex items-center justify-between text-[11px] font-mono text-gray-500 select-none">
          <div className="flex items-center space-x-3">
            <span>
              Mode: {driverMode === "exec" ? "Exec Shell (root)" : "Serial Console (xterm.js)"}
            </span>
            <span className="text-gray-700">•</span>
            <span>Active: {activeTab === "terminal" ? "Terminal" : "Boot Log"}</span>
          </div>
          <span>Instance #{vm.id}</span>
        </div>
      </div>
    </div>
  );
}
