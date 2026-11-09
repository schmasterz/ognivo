"use client";

import React, { useEffect, useState } from "react";
import { Radio, RefreshCw } from "lucide-react";
import {
  EchoConnectionState,
  onEchoConnectionStateChange,
  reconnectEcho,
} from "@/lib/echo";
import { useI18n } from "@/lib/i18n/I18nContext";

export function WsStatusBadge() {
  const { t } = useI18n();
  const [status, setStatus] = useState<EchoConnectionState>("disconnected");
  const [reconnecting, setReconnecting] = useState(false);

  useEffect(() => {
    // Subscribe to connection state events (immediately emits current state)
    const unsubscribe = onEchoConnectionStateChange((nextState) => {
      setStatus(nextState);
      if (nextState === "connected") {
        setReconnecting(false);
      }
    });

    return () => {
      unsubscribe();
    };
  }, []);

  const handleReconnect = () => {
    setReconnecting(true);
    reconnectEcho();
    setTimeout(() => setReconnecting(false), 2000);
  };

  if (status === "connected") {
    return (
      <div
        title={t("wsConnected") || "Real-time updates active"}
        className="inline-flex items-center space-x-1.5 px-2.5 py-1 rounded-full bg-emerald-500/10 border border-emerald-500/20 text-[11px] font-medium text-emerald-600 dark:text-emerald-400 select-none"
      >
        <span className="relative flex h-2 w-2">
          <span className="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
          <span className="relative inline-flex rounded-full h-2 w-2 bg-emerald-500"></span>
        </span>
        <span className="hidden sm:inline">{t("wsConnected") || "Live"}</span>
      </div>
    );
  }

  if (status === "connecting" || reconnecting) {
    return (
      <div
        title={t("wsConnecting") || "Connecting to WebSocket server"}
        className="inline-flex items-center space-x-1.5 px-2.5 py-1 rounded-full bg-amber-500/10 border border-amber-500/20 text-[11px] font-medium text-amber-600 dark:text-amber-400 select-none"
      >
        <RefreshCw className="w-3 h-3 animate-spin text-amber-500" />
        <span className="hidden sm:inline">{t("wsConnecting") || "Connecting..."}</span>
      </div>
    );
  }

  // Disconnected / Unavailable / Failed
  return (
    <button
      onClick={handleReconnect}
      title={`${t("wsDisconnected") || "Offline"} - ${t("wsReconnect") || "Click to reconnect"}`}
      className="inline-flex items-center space-x-1.5 px-2.5 py-1 rounded-full bg-red-500/10 hover:bg-red-500/20 border border-red-500/20 text-[11px] font-medium text-red-600 dark:text-red-400 transition cursor-pointer select-none"
    >
      <Radio className="w-3 h-3 text-red-500 animate-pulse" />
      <span className="hidden sm:inline">{t("wsReconnect") || "Reconnect"}</span>
    </button>
  );
}
