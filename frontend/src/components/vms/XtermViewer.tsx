"use client";

import React, { useCallback, useEffect, useRef, useState } from "react";
import type { IDisposable, Terminal } from "@xterm/xterm";
import type { FitAddon } from "@xterm/addon-fit";
import "@xterm/xterm/css/xterm.css";
import {
  type ConnectionStatus,
  formatConsoleCloseMessage,
} from "./console/consoleHelpers";

interface XtermViewerProps {
  wsUrl: string;
  ticket: string;
  isActive?: boolean;
  onStatusChange?: (status: ConnectionStatus) => void;
  onSessionError?: (errorMessage: string) => void;
  onDataChunk?: (chunk: string) => void;
}

export function XtermViewer({
  wsUrl,
  ticket,
  isActive = true,
  onStatusChange,
  onSessionError,
  onDataChunk,
}: XtermViewerProps) {
  const containerRef = useRef<HTMLDivElement>(null);
  const terminalRef = useRef<Terminal | null>(null);
  const fitAddonRef = useRef<FitAddon | null>(null);
  const wsRef = useRef<WebSocket | null>(null);
  const lastSentSizeRef = useRef<{ cols: number; rows: number } | null>(null);
  const isActiveRef = useRef(isActive);
  const onStatusChangeRef = useRef(onStatusChange);
  const onSessionErrorRef = useRef(onSessionError);
  const onDataChunkRef = useRef(onDataChunk);

  const [termReady, setTermReady] = useState(false);

  useEffect(() => {
    isActiveRef.current = isActive;
  }, [isActive]);

  useEffect(() => {
    onStatusChangeRef.current = onStatusChange;
  }, [onStatusChange]);

  useEffect(() => {
    onSessionErrorRef.current = onSessionError;
  }, [onSessionError]);

  useEffect(() => {
    onDataChunkRef.current = onDataChunk;
  }, [onDataChunk]);

  const sendResize = useCallback((term: Terminal) => {
    const ws = wsRef.current;
    if (!ws || ws.readyState !== WebSocket.OPEN) return;
    const { cols, rows } = term;
    const last = lastSentSizeRef.current;
    if (last && last.cols === cols && last.rows === rows) return;
    lastSentSizeRef.current = { cols, rows };
    ws.send(JSON.stringify({ type: "resize", cols, rows }));
  }, []);

  // Initialize xterm instance once on mount so reconnects reuse the same terminal & canvas
  useEffect(() => {
    const container = containerRef.current;
    if (!container || typeof window === "undefined") return;

    let disposed = false;
    let term: Terminal | null = null;
    let dataDisposable: IDisposable | null = null;
    let removeResize: (() => void) | null = null;

    const handleWheel = (e: WheelEvent) => {
      if (e.defaultPrevented || e.deltaY === 0 || !terminalRef.current) return;
      const lines = Math.round(e.deltaY / 25) || (e.deltaY > 0 ? 1 : -1);
      terminalRef.current.scrollLines(lines);
    };
    container.addEventListener("wheel", handleWheel, { passive: false });

    const initTerminal = async () => {
      const [{ Terminal: XTerminal }, { FitAddon }] = await Promise.all([
        import("@xterm/xterm"),
        import("@xterm/addon-fit"),
      ]);

      if (disposed || !containerRef.current) return;

      containerRef.current.replaceChildren();

      const xterm = new XTerminal({
        cursorBlink: true,
        scrollback: 10000,
        scrollSensitivity: 1,
        smoothScrollDuration: 100,
        fontSize: 13,
        fontFamily: "ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace",
        theme: {
          background: "#090d16",
          foreground: "#e2e8f0",
          cursor: "#38bdf8",
          selectionBackground: "rgba(56, 189, 248, 0.3)",
        },
      });
      term = xterm;

      const fitAddon = new FitAddon();
      xterm.loadAddon(fitAddon);
      xterm.open(containerRef.current);

      terminalRef.current = xterm;
      fitAddonRef.current = fitAddon;

      const initialContainer = containerRef.current;
      if (
        isActiveRef.current &&
        initialContainer.clientWidth > 0 &&
        initialContainer.clientHeight > 0
      ) {
        try {
          fitAddon.fit();
        } catch {
          // ignore initial fit error when hidden
        }
      }

      const encoder = new TextEncoder();
      dataDisposable = xterm.onData((data) => {
        if (disposed) return;
        const ws = wsRef.current;
        if (ws && ws.readyState === WebSocket.OPEN) {
          ws.send(encoder.encode(data));
        }
      });

      let resizeRafId: number | null = null;
      const scheduleResize = () => {
        if (disposed || resizeRafId !== null) return;
        resizeRafId = window.requestAnimationFrame(() => {
          resizeRafId = null;
          if (disposed) return;
          const container = containerRef.current;
          if (
            !isActiveRef.current ||
            !container ||
            container.clientWidth === 0 ||
            container.clientHeight === 0
          ) {
            return;
          }
          try {
            fitAddon.fit();
            sendResize(xterm);
          } catch {
            // ignore resize errors on unmount/hidden elements
          }
        });
      };

      window.addEventListener("resize", scheduleResize);

      let resizeObserver: ResizeObserver | null = null;
      if (typeof ResizeObserver !== "undefined" && containerRef.current) {
        resizeObserver = new ResizeObserver(() => {
          scheduleResize();
        });
        resizeObserver.observe(containerRef.current);
      }

      removeResize = () => {
        if (resizeRafId !== null) {
          window.cancelAnimationFrame(resizeRafId);
          resizeRafId = null;
        }
        window.removeEventListener("resize", scheduleResize);
        resizeObserver?.disconnect();
      };

      setTermReady(true);
    };

    void initTerminal();

    return () => {
      disposed = true;
      setTermReady(false);
      container.removeEventListener("wheel", handleWheel);
      removeResize?.();
      dataDisposable?.dispose();
      term?.dispose();
      terminalRef.current = null;
      fitAddonRef.current = null;
    };
  }, [sendResize]);

  // Re-fit, refresh, resize, and focus when the tab becomes active
  useEffect(() => {
    if (!isActive || !termReady) return;

    const rafId = window.requestAnimationFrame(() => {
      const container = containerRef.current;
      const term = terminalRef.current;
      if (
        !container ||
        !term ||
        container.clientWidth === 0 ||
        container.clientHeight === 0
      ) {
        return;
      }
      try {
        fitAddonRef.current?.fit();
        if (term.rows > 0) {
          term.refresh(0, term.rows - 1);
        }
        sendResize(term);
        if (wsRef.current?.readyState === WebSocket.OPEN) {
          term.focus();
        }
      } catch {
        // ignore layout errors when hidden
      }
    });

    return () => {
      window.cancelAnimationFrame(rafId);
    };
  }, [isActive, termReady, sendResize]);

  // Manage WebSocket session independently from terminal instance lifecycle
  useEffect(() => {
    if (!termReady || !wsUrl || !ticket || !terminalRef.current) return;

    const term = terminalRef.current;
    let disposed = false;
    let ws: WebSocket | null = null;
    const decoder = new TextDecoder("utf-8", { fatal: false });

    term.reset();
    lastSentSizeRef.current = null;

    const container = containerRef.current;
    if (
      isActiveRef.current &&
      container &&
      container.clientWidth > 0 &&
      container.clientHeight > 0
    ) {
      try {
        fitAddonRef.current?.fit();
      } catch {
        // ignore fit errors when hidden
      }
    }

    onStatusChangeRef.current?.("connecting");

    // Defer socket creation so React 19 Strict Mode cleanup cancels mount #1 before burning the single-use ticket
    const connectTimer = window.setTimeout(() => {
      if (disposed) return;

      // Authenticate via Sec-WebSocket-Protocol without placing ticket in the URL
      ws = new WebSocket(wsUrl, ["ognivo-terminal", `ticket.${ticket}`]);
      ws.binaryType = "arraybuffer";
      wsRef.current = ws;

      ws.onopen = () => {
        if (disposed) return;
        onStatusChangeRef.current?.("connected");
        lastSentSizeRef.current = null;
        sendResize(term);
        if (isActiveRef.current) {
          term.focus();
        }
      };

      ws.onmessage = (event) => {
        if (disposed) return;
        if (typeof event.data === "string") {
          term.write(event.data);
          onDataChunkRef.current?.(event.data);
        } else if (event.data instanceof ArrayBuffer) {
          const bytes = new Uint8Array(event.data);
          term.write(bytes);
          const decoded = decoder.decode(bytes, { stream: true });
          if (decoded) {
            onDataChunkRef.current?.(decoded);
          }
        }
      };

      ws.onerror = () => {
        if (disposed) return;
        onStatusChangeRef.current?.("error");
        onSessionErrorRef.current?.("WebSocket connection error");
        term.write("\r\n\x1b[31m[WebSocket connection error]\x1b[0m\r\n");
      };

      ws.onclose = (event: CloseEvent) => {
        if (disposed) return;
        onStatusChangeRef.current?.("disconnected");
        const { message, isTerminalError } = formatConsoleCloseMessage(
          event.code,
          event.reason,
          "Console session"
        );
        term.write(`\r\n\x1b[33m${message}\x1b[0m\r\n`);
        if (isTerminalError) {
          onSessionErrorRef.current?.(message);
        }
      };
    }, 0);

    return () => {
      disposed = true;
      window.clearTimeout(connectTimer);
      if (ws) {
        ws.onopen = null;
        ws.onmessage = null;
        ws.onerror = null;
        ws.onclose = null;
        if (ws.readyState === WebSocket.OPEN || ws.readyState === WebSocket.CONNECTING) {
          ws.close();
        }
        if (wsRef.current === ws) {
          wsRef.current = null;
        }
      }
      try {
        decoder.decode();
      } catch {
        // ignore decoder flush errors on cleanup
      }
    };
  }, [termReady, wsUrl, ticket, sendResize]);

  return <div ref={containerRef} className="w-full h-full p-2 bg-[#090d16]" />;
}
