"use client";

import React, { useEffect, useState, useRef } from "react";
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
} from "lucide-react";
import { VirtualMachine, api } from "@/lib/api";
import { XtermViewer } from "./XtermViewer";
import { useModalEscape } from "@/lib/hooks/useModalEscape";

interface ConsoleModalProps {
  vm: VirtualMachine | null;
  onClose: () => void;
}

type TabType = "interactive" | "bootlog";
type ConnectionStatus = "connecting" | "connected" | "disconnected" | "error";

export function ConsoleModal({ vm, onClose }: ConsoleModalProps) {
  const [activeTab, setActiveTab] = useState<TabType>("interactive");
  const [wsUrl, setWsUrl] = useState<string | null>(null);
  const [consoleType, setConsoleType] = useState<"console" | "vga">("console");
  const [sessionLoading, setSessionLoading] = useState(false);
  const [sessionError, setSessionError] = useState<string | null>(null);
  const [isForbidden, setIsForbidden] = useState(false);
  const [connectionStatus, setConnectionStatus] = useState<ConnectionStatus>("connecting");
  const [isFullscreen, setIsFullscreen] = useState(false);

  // Boot log tab state
  const [bootLog, setBootLog] = useState<string>("");
  const [bootLogLoading, setBootLogLoading] = useState(false);
  const [copied, setCopied] = useState(false);
  const terminalEndRef = useRef<HTMLDivElement>(null);

  const fetchSession = async (vmId: number) => {
    try {
      setSessionLoading(true);
      setSessionError(null);
      setIsForbidden(false);
      setConnectionStatus("connecting");

      const res = await api.getConsoleSession(vmId, "console");
      setConsoleType(res.type);

      const protocol = window.location.protocol === "https:" ? "wss:" : "ws:";
      const targetWsUrl = `${protocol}//${window.location.host}${res.websocket_path}`;
      setWsUrl(targetWsUrl);
    } catch (err: unknown) {
      if (err instanceof Error) {
        if (err.message.includes("403") || err.message.toLowerCase().includes("forbidden")) {
          setIsForbidden(true);
          setSessionError(
            "Console access is restricted to the workload owner only. Administrators are denied guest access."
          );
        } else if (err.message.includes("409")) {
          setSessionError("Interactive console is only available while the instance is running.");
        } else {
          setSessionError(err.message);
        }
      } else {
        setSessionError("Failed to initiate interactive console session.");
      }
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

  useModalEscape(onClose, !!vm);

  useEffect(() => {
    if (!vm) {
      setWsUrl(null);
      setSessionError(null);
      setBootLog("");
      setIsForbidden(false);
      setCopied(false);
      return;
    }
    setWsUrl(null);
    setSessionError(null);
    setBootLog("");
    setIsForbidden(false);
    setCopied(false);
    fetchSession(vm.id);
    fetchBootLog(vm.id);
  }, [vm]);

  useEffect(() => {
    if (activeTab === "bootlog") {
      terminalEndRef.current?.scrollIntoView({ behavior: "smooth" });
    }
  }, [bootLog, activeTab]);

  if (!vm) return null;

  const handleCopyBootLog = () => {
    if (bootLog) {
      navigator.clipboard.writeText(bootLog);
      setCopied(true);
      setTimeout(() => setCopied(false), 2000);
    }
  };

  const renderStatusBadge = () => {
    if (activeTab === "bootlog") return null;

    if (connectionStatus === "connected") {
      return (
        <span className="flex items-center space-x-1 text-[11px] px-2 py-0.5 rounded-full bg-emerald-500/10 text-emerald-400 border border-emerald-500/20">
          <span className="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-pulse" />
          <span>Connected</span>
        </span>
      );
    }

    if (connectionStatus === "connecting") {
      return (
        <span className="flex items-center space-x-1 text-[11px] px-2 py-0.5 rounded-full bg-blue-500/10 text-blue-400 border border-blue-500/20">
          <RefreshCw className="w-2.5 h-2.5 animate-spin" />
          <span>Connecting...</span>
        </span>
      );
    }

    if (connectionStatus === "disconnected") {
      return (
        <span className="text-[11px] px-2 py-0.5 rounded-full bg-amber-500/10 text-amber-400 border border-amber-500/20">
          Disconnected
        </span>
      );
    }

    return (
      <span className="text-[11px] px-2 py-0.5 rounded-full bg-red-500/10 text-red-400 border border-red-500/20">
        Error
      </span>
    );
  };

  return (
    <div
      className={
        isFullscreen
          ? "fixed inset-0 z-50 flex flex-col bg-black animate-in fade-in duration-150"
          : "fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/75 dark:bg-black/85 backdrop-blur-md animate-in fade-in duration-200"
      }
    >
      <div
        className={
          isFullscreen
            ? "flex-1 flex flex-col w-full h-full bg-gray-950 overflow-hidden"
            : "bg-gray-950 border border-gray-800 rounded-2xl w-full max-w-5xl overflow-hidden shadow-2xl flex flex-col h-[640px]"
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

            {/* Mode Tab Switcher */}
            <div className="flex items-center space-x-1 bg-gray-950 p-1 rounded-lg border border-gray-800 ml-4">
              <button
                onClick={() => setActiveTab("interactive")}
                className={`flex items-center space-x-1.5 px-2.5 py-1 rounded text-xs font-mono transition ${
                  activeTab === "interactive"
                    ? "bg-blue-600 text-white font-medium shadow-sm"
                    : "text-gray-400 hover:text-gray-200 hover:bg-gray-900"
                }`}
              >
                <Terminal className="w-3.5 h-3.5" />
                <span>Console</span>
              </button>
              <button
                onClick={() => setActiveTab("bootlog")}
                className={`flex items-center space-x-1.5 px-2.5 py-1 rounded text-xs font-mono transition ${
                  activeTab === "bootlog"
                    ? "bg-blue-600 text-white font-medium shadow-sm"
                    : "text-gray-400 hover:text-gray-200 hover:bg-gray-900"
                }`}
              >
                <FileText className="w-3.5 h-3.5" />
                <span>Boot Log</span>
              </button>
            </div>
          </div>

          <div className="flex items-center space-x-2">
            {renderStatusBadge()}

            {activeTab === "interactive" && !isForbidden && (
              <button
                onClick={() => fetchSession(vm.id)}
                disabled={sessionLoading}
                title="Reconnect Console"
                className="flex items-center space-x-1 px-2.5 py-1 rounded-lg text-xs font-mono text-gray-300 bg-gray-800 hover:bg-gray-700 transition disabled:opacity-50"
              >
                <RefreshCw className={`w-3.5 h-3.5 ${sessionLoading ? "animate-spin" : ""}`} />
                <span className="hidden sm:inline">Reconnect</span>
              </button>
            )}

            {activeTab === "bootlog" && (
              <>
                <button
                  onClick={() => fetchBootLog(vm.id)}
                  disabled={bootLogLoading}
                  title="Refresh Boot Log"
                  className="p-1.5 rounded-lg text-gray-400 hover:text-white hover:bg-gray-800 transition disabled:opacity-50"
                >
                  <RefreshCw className={`w-3.5 h-3.5 ${bootLogLoading ? "animate-spin" : ""}`} />
                </button>
                <button
                  onClick={handleCopyBootLog}
                  title="Copy Output"
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
              onClick={onClose}
              title="Close"
              className="p-1.5 rounded-lg text-gray-400 hover:text-white hover:bg-gray-800 transition"
            >
              <X className="w-4 h-4" />
            </button>
          </div>
        </div>

        {/* Modal Body */}
        <div className="flex-1 overflow-hidden relative flex flex-col bg-black">
          {activeTab === "interactive" ? (
            isForbidden ? (
              <div className="flex-1 flex flex-col items-center justify-center p-6 text-center space-y-3">
                <div className="p-3 bg-red-500/10 rounded-2xl border border-red-500/20 text-red-400">
                  <ShieldAlert className="w-10 h-10" />
                </div>
                <h3 className="text-gray-200 text-sm font-semibold tracking-wide">
                  Console Access Restricted
                </h3>
                <p className="text-gray-400 text-xs max-w-md leading-relaxed font-mono">
                  Console access is restricted to the workload owner only. Administrators and
                  impersonated sessions are denied guest console access to uphold tenant privacy.
                </p>
              </div>
            ) : sessionError ? (
              <div className="flex-1 flex flex-col items-center justify-center p-6 text-center space-y-3 font-mono">
                <div className="text-amber-400 text-sm">Unable to connect to console</div>
                <div className="text-gray-400 text-xs max-w-md">{sessionError}</div>
                <button
                  onClick={() => fetchSession(vm.id)}
                  className="mt-2 px-3 py-1.5 bg-blue-600 hover:bg-blue-500 text-white rounded-lg text-xs font-sans transition"
                >
                  Retry Connection
                </button>
              </div>
            ) : sessionLoading || !wsUrl ? (
              <div className="flex-1 flex items-center justify-center text-gray-500 space-x-2 font-mono text-xs">
                <RefreshCw className="w-4 h-4 animate-spin text-blue-400" />
                <span>Acquiring secure console ticket...</span>
              </div>
            ) : (
              <div className="flex-1 w-full h-full relative">
                {/* Pluggable Viewer: XtermViewer in v1.0.x (supports NoVncViewer in v1.1.x roadmap) */}
                <XtermViewer wsUrl={wsUrl} onStatusChange={setConnectionStatus} />
              </div>
            )
          ) : (
            /* Read-Only Boot Log Viewer */
            <div className="flex-1 p-4 font-mono text-xs overflow-y-auto bg-black text-gray-300 select-text leading-relaxed whitespace-pre-wrap">
              {bootLogLoading && !bootLog ? (
                <div className="flex items-center justify-center h-full text-gray-500 space-x-2">
                  <RefreshCw className="w-4 h-4 animate-spin text-blue-400" />
                  <span>Reading hypervisor console log buffer...</span>
                </div>
              ) : (
                <div>{bootLog}</div>
              )}
              <div ref={terminalEndRef} />
            </div>
          )}
        </div>

        {/* Footer */}
        <div className="px-4 py-2 bg-gray-900/80 border-t border-gray-800/80 flex items-center justify-between text-[11px] font-mono text-gray-500 select-none">
          <div className="flex items-center space-x-2">
            <span>Mode: {activeTab === "interactive" ? (consoleType === "vga" ? "VGA (noVNC)" : "Serial (xterm.js)") : "Ring Buffer Log"}</span>
          </div>
          <span>Instance #{vm.id}</span>
        </div>
      </div>
    </div>
  );
}
