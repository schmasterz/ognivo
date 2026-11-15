"use client";

import React, { useEffect, useRef } from "react";
import type { Terminal } from "@xterm/xterm";
import "@xterm/xterm/css/xterm.css";

interface XtermViewerProps {
  wsUrl: string;
  ticket: string;
  onStatusChange?: (status: "connecting" | "connected" | "disconnected" | "error") => void;
  onSessionError?: (errorMessage: string) => void;
}

export function XtermViewer({
  wsUrl,
  ticket,
  onStatusChange,
  onSessionError,
}: XtermViewerProps) {
  const containerRef = useRef<HTMLDivElement>(null);
  const terminalRef = useRef<Terminal | null>(null);
  const wsRef = useRef<WebSocket | null>(null);

  useEffect(() => {
    if (!containerRef.current || typeof window === "undefined" || !wsUrl || !ticket) return;

    let disposed = false;
    let ws: WebSocket | null = null;
    let term: Terminal | null = null;
    let removeResize: (() => void) | null = null;

    onStatusChange?.("connecting");

    const initTerminal = async () => {
      const [{ Terminal: XTerminal }, { FitAddon }] = await Promise.all([
        import("@xterm/xterm"),
        import("@xterm/addon-fit"),
      ]);

      if (disposed || !containerRef.current) return;

      term = new XTerminal({
        cursorBlink: true,
        fontSize: 13,
        fontFamily: "ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace",
        theme: {
          background: "#090d16",
          foreground: "#e2e8f0",
          cursor: "#38bdf8",
          selectionBackground: "rgba(56, 189, 248, 0.3)",
        },
      });

      const fitAddon = new FitAddon();
      term.loadAddon(fitAddon);
      term.open(containerRef.current);
      try {
        fitAddon.fit();
      } catch {
        // ignore initial fit error
      }
      terminalRef.current = term;

      // Authenticate via Sec-WebSocket-Protocol without placing ticket in the URL
      ws = new WebSocket(wsUrl, ["ognivo-terminal", `ticket.${ticket}`]);
      ws.binaryType = "arraybuffer";
      wsRef.current = ws;

      const sendResize = () => {
        if (term && ws?.readyState === WebSocket.OPEN) {
          ws.send(JSON.stringify({ type: "resize", cols: term.cols, rows: term.rows }));
        }
      };

      ws.onopen = () => {
        onStatusChange?.("connected");
        term?.focus();
        sendResize();
      };

      ws.onmessage = (event) => {
        if (typeof event.data === "string") {
          term?.write(event.data);
        } else if (event.data instanceof ArrayBuffer) {
          term?.write(new Uint8Array(event.data));
        }
      };

      term.onData((data) => {
        if (ws?.readyState === WebSocket.OPEN) {
          const encoder = new TextEncoder();
          ws.send(encoder.encode(data));
        }
      });

      ws.onerror = () => {
        onStatusChange?.("error");
        onSessionError?.("WebSocket connection error");
        term?.write("\r\n\x1b[31m[WebSocket connection error]\x1b[0m\r\n");
      };

      ws.onclose = (event: CloseEvent) => {
        onStatusChange?.("disconnected");
        let reasonMsg = "[Console session disconnected]";
        if (event.code === 4000) {
          reasonMsg = "[Console session closed: idle timeout exceeded]";
        } else if (event.code === 1009) {
          reasonMsg = "[Console session closed: message payload exceeded maximum limit]";
        } else if (event.code === 1011) {
          reasonMsg = "[Console session closed: hypervisor upstream connection error]";
        } else if (event.reason) {
          reasonMsg = `[Console session closed: ${event.reason}]`;
        }
        term?.write(`\r\n\x1b[33m${reasonMsg}\x1b[0m\r\n`);
        if (event.code === 4000 || event.code === 1009 || event.code === 1011) {
          onSessionError?.(reasonMsg);
        }
      };

      const handleResize = () => {
        try {
          fitAddon.fit();
          sendResize();
        } catch {
          // ignore resize errors on unmount/hidden elements
        }
      };

      window.addEventListener("resize", handleResize);

      let resizeObserver: ResizeObserver | null = null;
      if (typeof ResizeObserver !== "undefined" && containerRef.current) {
        resizeObserver = new ResizeObserver(() => {
          handleResize();
        });
        resizeObserver.observe(containerRef.current);
      }

      removeResize = () => {
        window.removeEventListener("resize", handleResize);
        resizeObserver?.disconnect();
      };
    };

    void initTerminal();

    return () => {
      disposed = true;
      removeResize?.();
      if (ws && (ws.readyState === WebSocket.OPEN || ws.readyState === WebSocket.CONNECTING)) {
        ws.close();
      }
      term?.dispose();
      terminalRef.current = null;
      wsRef.current = null;
    };
  }, [wsUrl, ticket, onStatusChange, onSessionError]);

  return <div ref={containerRef} className="w-full h-full p-2 bg-[#090d16]" />;
}
