"use client";

import React, {
  forwardRef,
  useCallback,
  useEffect,
  useImperativeHandle,
  useRef,
  useState,
} from "react";
import type { Terminal } from "@xterm/xterm";
import type { FitAddon } from "@xterm/addon-fit";
import "@xterm/xterm/css/xterm.css";
import {
  type ConnectionStatus,
  formatConsoleCloseMessage,
} from "./console/consoleHelpers";

export interface ReadOnlyXtermViewerProps {
  isActive?: boolean;
  wsUrl?: string | null;
  ticket?: string | null;
  textOutput?: string | null;
  onStatusChange?: (status: ConnectionStatus) => void;
  onFirstData?: () => void;
  onDataChunk?: (chunk: string) => void;
  emptyMessage?: string;
  className?: string;
}

export interface ReadOnlyXtermViewerHandle {
  /** Returns the rendered buffer text with trailing whitespace/blank lines trimmed. */
  getText: () => string;
  clear: () => void;
}

export const ReadOnlyXtermViewer = forwardRef<
  ReadOnlyXtermViewerHandle,
  ReadOnlyXtermViewerProps
>(function ReadOnlyXtermViewer(
  {
    isActive = true,
    wsUrl,
    ticket,
    textOutput,
    onStatusChange,
    onFirstData,
    onDataChunk,
    emptyMessage,
    className,
  },
  ref
) {
  const containerRef = useRef<HTMLDivElement>(null);
  const terminalRef = useRef<Terminal | null>(null);
  const fitAddonRef = useRef<FitAddon | null>(null);
  const wsRef = useRef<WebSocket | null>(null);
  const lastTextOutputRef = useRef<string>("");
  const firstDataFiredRef = useRef<boolean>(false);
  const lastSentSizeRef = useRef<{ cols: number; rows: number } | null>(null);
  const isActiveRef = useRef(isActive);

  const onStatusChangeRef = useRef(onStatusChange);
  const onFirstDataRef = useRef(onFirstData);
  const onDataChunkRef = useRef(onDataChunk);

  const [termReady, setTermReady] = useState(false);
  const [hasData, setHasData] = useState(false);

  useEffect(() => {
    isActiveRef.current = isActive;
  }, [isActive]);

  useEffect(() => {
    onStatusChangeRef.current = onStatusChange;
  }, [onStatusChange]);

  useEffect(() => {
    onFirstDataRef.current = onFirstData;
  }, [onFirstData]);

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

  useImperativeHandle(
    ref,
    () => ({
      getText: () => {
        const term = terminalRef.current;
        if (!term) return "";
        const buffer = term.buffer.active;
        const lines: string[] = [];
        for (let i = 0; i < buffer.length; i++) {
          const line = buffer.getLine(i);
          if (line) {
            lines.push(line.translateToString(true));
          }
        }
        while (lines.length > 0 && lines[lines.length - 1] === "") {
          lines.pop();
        }
        return lines.join("\n");
      },
      clear: () => {
        terminalRef.current?.reset();
        lastTextOutputRef.current = "";
        firstDataFiredRef.current = false;
        setHasData(false);
      },
    }),
    []
  );

  // Initialize read-only xterm instance once on mount
  useEffect(() => {
    const container = containerRef.current;
    if (!container || typeof window === "undefined") return;

    let disposed = false;
    let term: Terminal | null = null;
    let removeResize: (() => void) | null = null;

    const handleWheel = (e: WheelEvent) => {
      if (e.defaultPrevented || e.deltaY === 0 || !terminalRef.current) return;
      const lines = Math.round(e.deltaY / 25) || (e.deltaY > 0 ? 1 : -1);
      terminalRef.current.scrollLines(lines);
    };
    container.addEventListener("wheel", handleWheel, { passive: false });

    const initTerminal = async () => {
      const [{ Terminal: XTerminal }, { FitAddon: XFitAddon }] = await Promise.all([
        import("@xterm/xterm"),
        import("@xterm/addon-fit"),
      ]);

      if (disposed || !containerRef.current) return;

      const xterm = new XTerminal({
        disableStdin: true,
        cursorBlink: false,
        scrollback: 20000,
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

      const fitAddon = new XFitAddon();
      xterm.loadAddon(fitAddon);
      xterm.open(containerRef.current);

      const initialContainer = containerRef.current;
      if (
        isActiveRef.current &&
        initialContainer.clientWidth > 0 &&
        initialContainer.clientHeight > 0
      ) {
        try {
          fitAddon.fit();
        } catch {
          // ignore initial fit error when container is hidden
        }
      }

      terminalRef.current = xterm;
      fitAddonRef.current = fitAddon;

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
      term?.dispose();
      terminalRef.current = null;
      fitAddonRef.current = null;
    };
  }, [sendResize]);

  // Re-fit and refresh xterm canvas when tab becomes visible
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
      } catch {
        // ignore layout errors when hidden
      }
    });

    return () => {
      window.cancelAnimationFrame(rafId);
    };
  }, [isActive, termReady, sendResize]);

  // Live WebSocket streaming mode (for VM serial console ttyS0)
  useEffect(() => {
    if (!termReady || !wsUrl || !ticket) return;

    const term = terminalRef.current;
    if (!term) return;

    let disposed = false;
    let ws: WebSocket | null = null;
    firstDataFiredRef.current = false;
    lastTextOutputRef.current = "";
    lastSentSizeRef.current = null;
    queueMicrotask(() => {
      if (!disposed) {
        setHasData(false);
      }
    });

    term.reset();
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

    const decoder = new TextDecoder("utf-8", { fatal: false });

    const markFirstData = () => {
      if (!firstDataFiredRef.current) {
        firstDataFiredRef.current = true;
        setHasData(true);
        onFirstDataRef.current?.();
      }
    };

    // Defer socket creation so React 19 Strict Mode cleanup cancels mount #1 before burning the single-use ticket
    const connectTimer = window.setTimeout(() => {
      if (disposed) return;

      ws = new WebSocket(wsUrl, ["ognivo-terminal", `ticket.${ticket}`]);
      ws.binaryType = "arraybuffer";
      wsRef.current = ws;

      ws.onopen = () => {
        if (disposed) return;
        onStatusChangeRef.current?.("connected");
        lastSentSizeRef.current = null;
        sendResize(term);
      };

      ws.onmessage = (event) => {
        if (disposed) return;
        if (typeof event.data === "string") {
          term.write(event.data);
          markFirstData();
          onDataChunkRef.current?.(event.data);
        } else if (event.data instanceof ArrayBuffer) {
          const bytes = new Uint8Array(event.data);
          term.write(bytes);
          markFirstData();
          const decoded = decoder.decode(bytes, { stream: true });
          if (decoded) {
            onDataChunkRef.current?.(decoded);
          }
        }
      };

      ws.onerror = () => {
        if (disposed) return;
        onStatusChangeRef.current?.("error");
        term.write("\r\n\x1b[31m[WebSocket connection error]\x1b[0m\r\n");
      };

      ws.onclose = (event: CloseEvent) => {
        if (disposed) return;
        onStatusChangeRef.current?.("disconnected");
        const { message } = formatConsoleCloseMessage(
          event.code,
          event.reason,
          "Serial console"
        );
        term.write(`\r\n\x1b[33m${message}\x1b[0m\r\n`);
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

  // Static / mirrored textOutput mode (LXC console log or mirrored VM serial buffer)
  useEffect(() => {
    if (!termReady || (wsUrl && ticket)) return;

    const term = terminalRef.current;
    if (!term) return;

    const nextText = textOutput ?? "";
    if (!nextText) {
      if (lastTextOutputRef.current !== "") {
        term.reset();
        lastTextOutputRef.current = "";
      }
      queueMicrotask(() => {
        setHasData(false);
      });
      return;
    }

    const isAtBottom = term.buffer.active.baseY === term.buffer.active.viewportY;
    const prevText = lastTextOutputRef.current;
    if (prevText && nextText.startsWith(prevText)) {
      const delta = nextText.slice(prevText.length);
      if (delta) {
        term.write(
          delta.replace(/\r?\n/g, "\r\n"),
          isAtBottom
            ? () => {
                if (terminalRef.current === term) {
                  term.scrollToBottom();
                }
              }
            : undefined
        );
      }
    } else {
      term.reset();
      term.write(nextText.replace(/\r?\n/g, "\r\n"), () => {
        if (terminalRef.current === term) {
          term.scrollToBottom();
        }
      });
    }

    lastTextOutputRef.current = nextText;
    queueMicrotask(() => {
      setHasData(true);
    });
  }, [termReady, textOutput, wsUrl, ticket]);

  return (
    <div className={`relative w-full h-full bg-[#090d16] ${className ?? ""}`}>
      <div ref={containerRef} className="w-full h-full p-2 bg-[#090d16]" />
      {!hasData && emptyMessage && (
        <div className="absolute inset-0 flex items-center justify-center p-6 pointer-events-none">
          <div className="max-w-lg text-center font-mono text-xs text-gray-400 bg-gray-900/90 border border-gray-800 rounded-xl px-4 py-3 shadow-lg leading-relaxed">
            {emptyMessage}
          </div>
        </div>
      )}
    </div>
  );
});
