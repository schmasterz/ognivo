"use client";

import React, {
  forwardRef,
  useEffect,
  useImperativeHandle,
  useRef,
  useState,
} from "react";
import type { Terminal } from "@xterm/xterm";
import type { FitAddon } from "@xterm/addon-fit";
import "@xterm/xterm/css/xterm.css";

export interface ReadOnlyXtermViewerProps {
  isActive?: boolean;
  wsUrl?: string | null;
  ticket?: string | null;
  textOutput?: string | null;
  onStatusChange?: (status: "connecting" | "connected" | "disconnected" | "error") => void;
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
    isActive,
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

  const onStatusChangeRef = useRef(onStatusChange);
  const onFirstDataRef = useRef(onFirstData);
  const onDataChunkRef = useRef(onDataChunk);

  const [termReady, setTermReady] = useState(false);
  const [hasData, setHasData] = useState(false);

  useEffect(() => {
    onStatusChangeRef.current = onStatusChange;
  }, [onStatusChange]);

  useEffect(() => {
    onFirstDataRef.current = onFirstData;
  }, [onFirstData]);

  useEffect(() => {
    onDataChunkRef.current = onDataChunk;
  }, [onDataChunk]);

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
    if (!containerRef.current || typeof window === "undefined") return;

    let disposed = false;
    let term: Terminal | null = null;
    let removeResize: (() => void) | null = null;

    const initTerminal = async () => {
      const [{ Terminal: XTerminal }, { FitAddon: XFitAddon }] = await Promise.all([
        import("@xterm/xterm"),
        import("@xterm/addon-fit"),
      ]);

      if (disposed || !containerRef.current) return;

      term = new XTerminal({
        disableStdin: true,
        cursorBlink: false,
        fontSize: 13,
        fontFamily: "ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace",
        theme: {
          background: "#090d16",
          foreground: "#e2e8f0",
          cursor: "#38bdf8",
          selectionBackground: "rgba(56, 189, 248, 0.3)",
        },
      });

      const fitAddon = new XFitAddon();
      term.loadAddon(fitAddon);
      term.open(containerRef.current);
      try {
        fitAddon.fit();
      } catch {
        // ignore initial fit error when container is hidden
      }

      terminalRef.current = term;
      fitAddonRef.current = fitAddon;
      setTermReady(true);

      const handleResize = () => {
        try {
          fitAddon.fit();
          const ws = wsRef.current;
          if (term && ws?.readyState === WebSocket.OPEN) {
            ws.send(JSON.stringify({ type: "resize", cols: term.cols, rows: term.rows }));
          }
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
      setTermReady(false);
      removeResize?.();
      term?.dispose();
      terminalRef.current = null;
      fitAddonRef.current = null;
    };
  }, []);

  // Re-fit and refresh xterm canvas when tab becomes visible
  useEffect(() => {
    if (isActive && termReady && terminalRef.current) {
      try {
        fitAddonRef.current?.fit();
        if (terminalRef.current.rows > 0) {
          terminalRef.current.refresh(0, terminalRef.current.rows - 1);
        }
      } catch {
        // ignore layout errors when hidden
      }
    }
  }, [isActive, termReady]);

  // Live WebSocket streaming mode (for VM serial console ttyS0)
  useEffect(() => {
    if (!termReady || !wsUrl || !ticket) return;

    const term = terminalRef.current;
    const fitAddon = fitAddonRef.current;
    if (!term) return;

    let disposed = false;
    firstDataFiredRef.current = false;
    lastTextOutputRef.current = "";
    queueMicrotask(() => {
      if (!disposed) {
        setHasData(false);
      }
    });

    term.reset();
    try {
      fitAddon?.fit();
    } catch {
      // ignore fit errors when hidden
    }

    onStatusChangeRef.current?.("connecting");

    const ws = new WebSocket(wsUrl, ["ognivo-terminal", `ticket.${ticket}`]);
    ws.binaryType = "arraybuffer";
    wsRef.current = ws;

    const decoder = new TextDecoder("utf-8", { fatal: false });

    const sendResize = () => {
      if (ws.readyState === WebSocket.OPEN) {
        ws.send(JSON.stringify({ type: "resize", cols: term.cols, rows: term.rows }));
      }
    };

    const markFirstData = () => {
      if (!firstDataFiredRef.current) {
        firstDataFiredRef.current = true;
        setHasData(true);
        onFirstDataRef.current?.();
      }
    };

    ws.onopen = () => {
      if (disposed) return;
      onStatusChangeRef.current?.("connected");
      sendResize();
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
      let reasonMsg = "[Serial console disconnected]";
      if (event.code === 4000) {
        reasonMsg = "[Serial console closed: idle timeout exceeded]";
      } else if (event.code === 1009) {
        reasonMsg = "[Serial console closed: message payload exceeded maximum limit]";
      } else if (event.code === 1011) {
        reasonMsg = "[Serial console closed: hypervisor upstream connection error]";
      } else if (event.reason) {
        reasonMsg = `[Serial console closed: ${event.reason}]`;
      }
      term.write(`\r\n\x1b[33m${reasonMsg}\x1b[0m\r\n`);
    };

    return () => {
      disposed = true;
      if (ws.readyState === WebSocket.OPEN || ws.readyState === WebSocket.CONNECTING) {
        ws.close();
      }
      if (wsRef.current === ws) {
        wsRef.current = null;
      }
    };
  }, [termReady, wsUrl, ticket]);

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

    const prevText = lastTextOutputRef.current;
    if (prevText && nextText.startsWith(prevText)) {
      const delta = nextText.slice(prevText.length);
      if (delta) {
        term.write(delta.replace(/\r?\n/g, "\r\n"));
      }
    } else {
      term.reset();
      term.write(nextText.replace(/\r?\n/g, "\r\n"));
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
