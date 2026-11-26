"use client";

import React, { useCallback, useEffect, useRef, useState } from "react";
import { AlertCircle, Lock, Radio, RefreshCw } from "lucide-react";
import { VirtualMachine } from "@/lib/api";
import { useAuth } from "@/lib/auth/AuthContext";
import { useModalEscape } from "@/lib/hooks/useModalEscape";
import { useI18n } from "@/lib/i18n/I18nContext";
import { XtermViewer } from "./XtermViewer";
import {
  ReadOnlyXtermViewer,
  ReadOnlyXtermViewerHandle,
} from "./ReadOnlyXtermViewer";
import { ConsoleHeader } from "./console/ConsoleHeader";
import {
  ConsoleBootErrorCard,
  ConsoleTerminalErrorCard,
} from "./console/ConsoleErrorState";
import { useConsoleRealtime } from "./console/useConsoleRealtime";
import { useConsoleSessions } from "./console/useConsoleSessions";

interface ConsoleModalProps {
  vm: VirtualMachine | null;
  onClose: () => void;
}

/**
 * Orchestrates the interactive VM/container console modal, combining realtime
 * status/agent events, dual terminal/boot-log sessions, and persistent xterm views.
 */
export function ConsoleModal({ vm, onClose }: ConsoleModalProps) {
  const { t } = useI18n();
  const { user } = useAuth();

  const [activeTab, setActiveTab] = useState<"terminal" | "bootlog">("terminal");
  const [isFullscreen, setIsFullscreen] = useState(false);

  const readOnlyViewerRef = useRef<ReadOnlyXtermViewerHandle>(null);
  const onAgentStartedRef = useRef<(vmId: number) => void>(() => {});
  const onAgentStarted = useCallback((targetVmId: number) => {
    onAgentStartedRef.current(targetVmId);
  }, []);

  const currentVm = useConsoleRealtime({
    vm,
    userId: user?.id,
    onAgentStarted,
  });

  const {
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
    bootLoading,
    bootLogLoading,
    bootLogFrozen,
    bootWsUrl,
    bootTicket,
    bootSessionError,
    bootConnectionStatus,
    bootWaitingNotice,
    getBootTextFallback,
    fetchSession,
    fetchBootSession,
    closeAllSessions,
    handleConnectionStatusChange,
    handleSessionError,
    setBootConnectionStatus,
    handleBootDataChunk,
    handleBootFirstData,
    handleAgentStarted,
    refreshBootLog,
    ensureBootTabLoaded,
  } = useConsoleSessions({ currentVm, activeTab, isFullscreen });

  useEffect(() => {
    onAgentStartedRef.current = handleAgentStarted;
  }, [handleAgentStarted]);

  const vmId = vm?.id ?? null;
  useEffect(() => {
    let ignore = false;
    queueMicrotask(() => {
      if (!ignore) {
        setActiveTab("terminal");
      }
    });
    return () => {
      ignore = true;
    };
  }, [vmId]);

  const handleClose = useCallback(() => {
    closeAllSessions("client_closed");
    onClose();
  }, [closeAllSessions, onClose]);

  useModalEscape(handleClose, !!currentVm);

  if (!currentVm) return null;

  const isVm = currentVm.instance_type === "virtual-machine";
  const isRunning = currentVm.status === "running";

  const handleTabChange = (tab: "terminal" | "bootlog") => {
    setActiveTab(tab);
    if (tab === "bootlog") {
      ensureBootTabLoaded();
    }
  };

  const getCopyText = () =>
    readOnlyViewerRef.current?.getText() || getBootTextFallback() || bootLog;

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

  const useLiveSerialBootViewer =
    isVm && isRunning && driverMode === "exec" && !!bootWsUrl && !!bootTicket;

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
        <ConsoleHeader
          key={currentVm.id}
          vm={currentVm}
          driverMode={driverMode}
          activeTab={activeTab}
          onTabChange={handleTabChange}
          connectionStatus={connectionStatus}
          errorCategory={errorCategory}
          sessionLoading={sessionLoading}
          bootLoading={bootLoading}
          agentReady={agentReady}
          fallbackReason={fallbackReason}
          isFullscreen={isFullscreen}
          onReconnect={() =>
            void fetchSession(currentVm.id, agentReady ? "exec" : undefined)
          }
          onSwitchToShell={() => void fetchSession(currentVm.id, "exec")}
          onRefreshBootLog={refreshBootLog}
          getCopyText={getCopyText}
          onToggleFullscreen={() => setIsFullscreen((prev) => !prev)}
          onClose={handleClose}
        />

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
              <ConsoleTerminalErrorCard
                errorCategory={errorCategory}
                sessionError={sessionError}
                onRetry={() => void fetchSession(currentVm.id)}
              />
            ) : sessionLoading || !wsUrl || !ticket ? (
              <div className="flex-1 flex items-center justify-center text-gray-500 space-x-2 font-mono text-xs">
                <RefreshCw className="w-4 h-4 animate-spin text-blue-400" />
                <span>Acquiring secure console ticket...</span>
              </div>
            ) : (
              <div className="flex-1 w-full h-full relative">
                <XtermViewer
                  isActive={activeTab === "terminal"}
                  wsUrl={wsUrl}
                  ticket={ticket}
                  onStatusChange={handleConnectionStatusChange}
                  onSessionError={handleSessionError}
                  onDataChunk={
                    isVm && driverMode === "console"
                      ? handleBootDataChunk
                      : undefined
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
                        void fetchSession(
                          currentVm.id,
                          agentReady ? "exec" : undefined
                        )
                      }
                      disabled={sessionLoading}
                      className="flex items-center space-x-1.5 px-3 py-1.5 rounded-lg bg-blue-600 hover:bg-blue-500 text-white font-medium text-xs transition shrink-0 disabled:opacity-50"
                    >
                      <RefreshCw className="w-3.5 h-3.5" />
                      <span>
                        {agentReady
                          ? t("console.switch_to_shell")
                          : t("console.reconnect")}
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
                  <span className="text-emerald-400">
                    {t("console.boot_log_frozen")}
                  </span>
                )}
              </div>
              {driverMode === "exec" && bootWsUrl && (
                <span className="text-gray-500">{bootConnectionStatus}</span>
              )}
            </div>

            {bootSessionError ? (
              <ConsoleBootErrorCard
                errorMessage={bootSessionError}
                onRetry={() => void fetchBootSession(currentVm.id)}
              />
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
                  textOutput={useLiveSerialBootViewer ? null : bootLog}
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
              Mode:{" "}
              {driverMode === "exec"
                ? "Exec Shell (root)"
                : "Serial Console (xterm.js)"}
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
