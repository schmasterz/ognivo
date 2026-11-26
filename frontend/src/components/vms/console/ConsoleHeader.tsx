"use client";

import React, { useEffect, useRef, useState } from "react";
import {
  AlertTriangle,
  Check,
  Copy,
  FileText,
  Maximize2,
  Minimize2,
  RefreshCw,
  Terminal,
  X,
} from "lucide-react";
import { VirtualMachine } from "@/lib/api";
import { useI18n } from "@/lib/i18n/I18nContext";
import {
  ConnectionStatus,
  ConsoleDriverMode,
  ErrorCategory,
} from "./consoleHelpers";

export interface ConsoleHeaderProps {
  vm: VirtualMachine;
  driverMode: ConsoleDriverMode;
  activeTab: "terminal" | "bootlog";
  onTabChange: (tab: "terminal" | "bootlog") => void;
  connectionStatus: ConnectionStatus;
  errorCategory: ErrorCategory | null;
  sessionLoading: boolean;
  bootLoading: boolean;
  agentReady: boolean;
  fallbackReason: string | null;
  isFullscreen: boolean;
  onReconnect: () => void;
  onSwitchToShell: () => void;
  onRefreshBootLog: () => void;
  getCopyText: () => string;
  onToggleFullscreen: () => void;
  onClose: () => void;
}

/**
 * Renders the console modal title bar, mode/tab controls, action buttons,
 * agent-ready banner, and automatic serial-fallback notice.
 */
export function ConsoleHeader({
  vm,
  driverMode,
  activeTab,
  onTabChange,
  connectionStatus,
  errorCategory,
  sessionLoading,
  bootLoading,
  agentReady,
  fallbackReason,
  isFullscreen,
  onReconnect,
  onSwitchToShell,
  onRefreshBootLog,
  getCopyText,
  onToggleFullscreen,
  onClose,
}: ConsoleHeaderProps) {
  const { t } = useI18n();
  const [copied, setCopied] = useState(false);
  const copyTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null);

  useEffect(() => {
    return () => {
      if (copyTimerRef.current) {
        clearTimeout(copyTimerRef.current);
        copyTimerRef.current = null;
      }
    };
  }, []);

  const handleCopyBootLog = () => {
    const text = getCopyText();
    if (!text) return;

    void navigator.clipboard.writeText(text);
    setCopied(true);
    if (copyTimerRef.current) {
      clearTimeout(copyTimerRef.current);
    }
    copyTimerRef.current = setTimeout(() => {
      setCopied(false);
      copyTimerRef.current = null;
    }, 2000);
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

  return (
    <>
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
            <span className="text-[10px] px-1.5 py-0.5 rounded bg-blue-500/10 text-blue-400 border border-blue-500/20 uppercase tracking-wider">
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
                <span>{t("console.serial_mode")}</span>
              </span>
            )}
          </div>
        </div>

        <div className="flex items-center space-x-2">
          {/* Tab Switcher */}
          <div className="flex items-center bg-gray-950/60 p-0.5 rounded-lg border border-gray-800">
            <button
              onClick={() => onTabChange("terminal")}
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
              onClick={() => onTabChange("bootlog")}
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
                  onClick={onReconnect}
                  disabled={sessionLoading}
                  title={t("console.reconnect")}
                  className="flex items-center space-x-1 px-2.5 py-1 rounded-lg text-xs font-mono text-gray-300 bg-gray-800 hover:bg-gray-700 transition disabled:opacity-50"
                >
                  <RefreshCw
                    className={`w-3.5 h-3.5 ${sessionLoading ? "animate-spin" : ""}`}
                  />
                  <span className="hidden sm:inline">{t("console.reconnect")}</span>
                </button>
              )}
            </>
          )}

          {activeTab === "bootlog" && (
            <>
              <button
                onClick={onRefreshBootLog}
                disabled={bootLoading}
                title="Refresh Boot Log"
                className="flex items-center space-x-1 px-2.5 py-1 rounded-lg text-xs font-mono text-gray-300 bg-gray-800 hover:bg-gray-700 transition disabled:opacity-50"
              >
                <RefreshCw
                  className={`w-3.5 h-3.5 ${bootLoading ? "animate-spin" : ""}`}
                />
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
            onClick={onToggleFullscreen}
            title={
              isFullscreen ? t("console.exit_fullscreen") : t("console.fullscreen")
            }
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

      {/* Agent Ready Banner when Terminal is on serial fallback */}
      {agentReady && driverMode === "console" && (
        <div className="bg-emerald-950/80 border-b border-emerald-500/30 px-3 py-1.5 flex items-center justify-between text-xs text-emerald-300">
          <span className="flex items-center space-x-1.5">
            <span>⚡</span>
            <span>{t("console.agent_ready_banner")}</span>
          </span>
          <button
            onClick={onSwitchToShell}
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
          <span className="text-[10px] text-amber-400/70 hidden sm:inline">
            Automatic Fallback
          </span>
        </div>
      )}
    </>
  );
}
