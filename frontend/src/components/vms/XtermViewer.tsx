"use client";

import React, { useEffect, useRef } from "react";
import type { Terminal } from "@xterm/xterm";
import "@xterm/xterm/css/xterm.css";

interface XtermViewerProps {
  wsUrl: string;
  onStatusChange?: (status: "connecting" | "connected" | "disconnected" | "error") => void;
}

export function XtermViewer({ wsUrl, onStatusChange }: XtermViewerProps) {
  const containerRef = useRef<HTMLDivElement>(null);
  const terminalRef = useRef<Terminal | null>(null);
  const wsRef = useRef<WebSocket | null>(null);

  useEffect(() => {
    if (!containerRef.current || typeof window === "undefined") return;

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
      fitAddon.fit();
      terminalRef.current = term;

      ws = new WebSocket(wsUrl);
      ws.binaryType = "arraybuffer";
      wsRef.current = ws;

      ws.onopen = () => {
        onStatusChange?.("connected");
        term?.focus();
        if (term && ws?.readyState === WebSocket.OPEN) {
          ws.send(JSON.stringify({ type: "resize", cols: term.cols, rows: term.rows }));
        }
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
        term?.write("\r\n\x1b[31m[WebSocket connection error]\x1b[0m\r\n");
      };

      ws.onclose = () => {
        onStatusChange?.("disconnected");
        term?.write("\r\n\x1b[33m[Console session disconnected]\x1b[0m\r\n");
      };

      const handleResize = () => {
        try {
          fitAddon.fit();
          if (term && ws?.readyState === WebSocket.OPEN) {
            ws.send(JSON.stringify({ type: "resize", cols: term.cols, rows: term.rows }));
          }
        } catch {
          // ignore resize errors on unmount
        }
      };

      window.addEventListener("resize", handleResize);
      removeResize = () => window.removeEventListener("resize", handleResize);
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
  }, [wsUrl, onStatusChange]);

  return <div ref={containerRef} className="w-full h-full p-2 bg-[#090d16]" />;
}
