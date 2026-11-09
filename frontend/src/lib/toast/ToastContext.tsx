"use client";

import React, { createContext, useContext, useState, useCallback, useRef, useEffect } from "react";
import { CheckCircle2, AlertCircle, Info, AlertTriangle, X } from "lucide-react";

export type ToastType = "success" | "error" | "info" | "warning";

export interface ToastItem {
  id: string;
  type: ToastType;
  title?: string;
  message: string;
  duration?: number;
}

interface ToastContextValue {
  toasts: ToastItem[];
  addToast: (message: string, type?: ToastType, title?: string, duration?: number) => string;
  removeToast: (id: string) => void;
  toast: {
    success: (message: string, title?: string, duration?: number) => string;
    error: (message: string, title?: string, duration?: number) => string;
    info: (message: string, title?: string, duration?: number) => string;
    warning: (message: string, title?: string, duration?: number) => string;
  };
}

const ToastContext = createContext<ToastContextValue | undefined>(undefined);

export function ToastProvider({ children }: { children: React.ReactNode }) {
  const [toasts, setToasts] = useState<ToastItem[]>([]);

  const removeToast = useCallback((id: string) => {
    setToasts((prev) => prev.filter((t) => t.id !== id));
  }, []);

  const addToast = useCallback(
    (message: string, type: ToastType = "info", title?: string, duration: number = 4000) => {
      const id = Math.random().toString(36).substring(2, 9) + Date.now().toString(36);
      const newToast: ToastItem = { id, type, title, message, duration };
      setToasts((prev) => [...prev, newToast]);
      return id;
    },
    []
  );

  const toast = {
    success: useCallback(
      (message: string, title?: string, duration?: number) => addToast(message, "success", title, duration),
      [addToast]
    ),
    error: useCallback(
      (message: string, title?: string, duration?: number) => addToast(message, "error", title, duration),
      [addToast]
    ),
    info: useCallback(
      (message: string, title?: string, duration?: number) => addToast(message, "info", title, duration),
      [addToast]
    ),
    warning: useCallback(
      (message: string, title?: string, duration?: number) => addToast(message, "warning", title, duration),
      [addToast]
    ),
  };

  return (
    <ToastContext.Provider value={{ toasts, addToast, removeToast, toast }}>
      {children}
      <ToastContainer toasts={toasts} onRemove={removeToast} />
    </ToastContext.Provider>
  );
}

export function useToast() {
  const context = useContext(ToastContext);
  if (!context) {
    throw new Error("useToast must be used within a ToastProvider");
  }
  return context;
}

function ToastContainer({
  toasts,
  onRemove,
}: {
  toasts: ToastItem[];
  onRemove: (id: string) => void;
}) {
  return (
    <div
      aria-live="polite"
      className="fixed top-5 right-5 z-[9999] flex flex-col space-y-3 pointer-events-none w-full max-w-sm sm:max-w-md px-4 sm:px-0"
    >
      {toasts.map((item) => (
        <ToastCard key={item.id} item={item} onDismiss={() => onRemove(item.id)} />
      ))}
    </div>
  );
}

function ToastCard({ item, onDismiss }: { item: ToastItem; onDismiss: () => void }) {
  const [isPaused, setIsPaused] = useState(false);
  const [progress, setProgress] = useState(100);
  const duration = item.duration ?? 4000;
  const remainingTimeRef = useRef<number>(duration);

  useEffect(() => {
    if (duration <= 0) return;

    if (isPaused) {
      return;
    }

    const intervalTime = 40;
    const interval = setInterval(() => {
      remainingTimeRef.current -= intervalTime;
      const pct = Math.max(0, (remainingTimeRef.current / duration) * 100);
      setProgress(pct);

      if (remainingTimeRef.current <= 0) {
        clearInterval(interval);
        onDismiss();
      }
    }, intervalTime);

    return () => clearInterval(interval);
  }, [isPaused, duration, onDismiss]);

  const config = {
    success: {
      icon: <CheckCircle2 className="w-5 h-5 text-emerald-500 flex-shrink-0" />,
      barColor: "bg-emerald-500",
      badgeColor: "bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border-emerald-500/20",
      defaultTitle: "Success",
    },
    error: {
      icon: <AlertCircle className="w-5 h-5 text-red-500 flex-shrink-0" />,
      barColor: "bg-red-500",
      badgeColor: "bg-red-500/10 text-red-600 dark:text-red-400 border-red-500/20",
      defaultTitle: "Error",
    },
    warning: {
      icon: <AlertTriangle className="w-5 h-5 text-amber-500 flex-shrink-0" />,
      barColor: "bg-amber-500",
      badgeColor: "bg-amber-500/10 text-amber-600 dark:text-amber-400 border-amber-500/20",
      defaultTitle: "Warning",
    },
    info: {
      icon: <Info className="w-5 h-5 text-blue-500 flex-shrink-0" />,
      barColor: "bg-blue-500",
      badgeColor: "bg-blue-500/10 text-blue-600 dark:text-blue-400 border-blue-500/20",
      defaultTitle: "Notice",
    },
  }[item.type];

  return (
    <div
      onMouseEnter={() => setIsPaused(true)}
      onMouseLeave={() => setIsPaused(false)}
      className="pointer-events-auto relative overflow-hidden rounded-2xl bg-white/95 dark:bg-gray-900/95 border border-gray-200 dark:border-gray-800 shadow-xl dark:shadow-2xl backdrop-blur-md transition-all duration-200 hover:scale-[1.01] animate-in slide-in-from-top-3 fade-in duration-200"
    >
      <div className="p-4 flex items-start space-x-3">
        <div className="mt-0.5">{config.icon}</div>
        <div className="flex-1 min-w-0 pr-2">
          <div className="flex items-center space-x-2">
            <span className="text-xs font-bold text-gray-900 dark:text-white">
              {item.title || config.defaultTitle}
            </span>
          </div>
          <p className="text-xs text-gray-600 dark:text-gray-300 mt-0.5 break-words leading-relaxed">
            {item.message}
          </p>
        </div>
        <button
          onClick={onDismiss}
          className="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 p-1 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-800 transition"
        >
          <X className="w-4 h-4" />
        </button>
      </div>

      {/* Toastify-style Progress Bar */}
      {duration > 0 && (
        <div className="absolute bottom-0 left-0 right-0 h-1 bg-gray-100 dark:bg-gray-800">
          <div
            className={`h-full transition-all duration-75 ${config.barColor}`}
            style={{ width: `${progress}%` }}
          />
        </div>
      )}
    </div>
  );
}
