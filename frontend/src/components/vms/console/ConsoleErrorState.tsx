"use client";

import React from "react";
import {
  AlertCircle,
  AlertTriangle,
  PowerOff,
  ShieldAlert,
  Terminal,
  Users,
} from "lucide-react";
import { useI18n } from "@/lib/i18n/I18nContext";
import { ErrorCategory } from "./consoleHelpers";

export interface ConsoleTerminalErrorCardProps {
  errorCategory: ErrorCategory;
  sessionError: string | null;
  onRetry: () => void;
}

export interface ConsoleBootErrorCardProps {
  errorMessage: string;
  onRetry: () => void;
}

type Tone = "red" | "amber" | "blue";

const TONE_BOX_CLASSES: Record<Tone, string> = {
  red: "p-3 bg-red-500/10 rounded-2xl border border-red-500/20 text-red-400",
  amber:
    "p-3 bg-amber-500/10 rounded-2xl border border-amber-500/20 text-amber-400",
  blue: "p-3 bg-blue-500/10 rounded-2xl border border-blue-500/20 text-blue-400",
};

interface ErrorDescriptor {
  tone: Tone;
  icon: React.ReactNode;
  title: string;
  description: string;
  showRetry: boolean;
}

/**
 * Renders categorized error cards for the interactive terminal console tab.
 */
export function ConsoleTerminalErrorCard({
  errorCategory,
  sessionError,
  onRetry,
}: ConsoleTerminalErrorCardProps) {
  const { t } = useI18n();

  const getDescriptor = (): ErrorDescriptor => {
    switch (errorCategory) {
      case "forbidden":
        return {
          tone: "red",
          icon: <ShieldAlert className="w-10 h-10" />,
          title: t("console.forbidden_title"),
          description: sessionError || t("console.forbidden_desc"),
          showRetry: false,
        };

      case "not_running":
        return {
          tone: "amber",
          icon: <PowerOff className="w-10 h-10" />,
          title: "Instance Not Running",
          description:
            sessionError ||
            "Interactive console is only available while the instance is running. Please start the instance first.",
          showRetry: true,
        };

      case "serial_in_use":
        return {
          tone: "amber",
          icon: <Users className="w-10 h-10" />,
          title: "Serial Console Already In Use",
          description:
            sessionError ||
            "A serial console session is already active for this instance. Only one active serial console connection is permitted at a time.",
          showRetry: true,
        };

      case "limit_reached":
        return {
          tone: "amber",
          icon: <AlertCircle className="w-10 h-10" />,
          title: "Session Limit Reached",
          description:
            sessionError ||
            "Maximum number of concurrent terminal sessions reached for this instance. Please close existing sessions or try again later.",
          showRetry: true,
        };

      case "not_found":
        return {
          tone: "blue",
          icon: <Terminal className="w-10 h-10" />,
          title: "Agent Unavailable / Shell Not Found",
          description:
            sessionError ||
            "The guest shell executable or guest agent could not be found.",
          showRetry: true,
        };

      default:
        return {
          tone: "red",
          icon: <AlertTriangle className="w-10 h-10" />,
          title: "Unable to Connect to Console",
          description:
            sessionError ||
            "An unexpected error occurred while initiating the console session.",
          showRetry: true,
        };
    }
  };

  const { tone, icon, title, description, showRetry } = getDescriptor();

  return (
    <div className="flex-1 flex flex-col items-center justify-center p-6 text-center space-y-3 font-mono">
      <div className={TONE_BOX_CLASSES[tone]}>{icon}</div>
      <h3 className="text-gray-200 text-sm font-semibold tracking-wide">
        {title}
      </h3>
      <p className="text-gray-400 text-xs max-w-md leading-relaxed font-sans">
        {description}
      </p>
      {showRetry && (
        <button
          onClick={onRetry}
          className="mt-2 px-3 py-1.5 bg-blue-600 hover:bg-blue-500 text-white rounded-lg text-xs font-sans transition"
        >
          Retry Connection
        </button>
      )}
    </div>
  );
}

/**
 * Renders the error state for the read-only serial/boot log viewer tab.
 */
export function ConsoleBootErrorCard({
  errorMessage,
  onRetry,
}: ConsoleBootErrorCardProps) {
  return (
    <div className="flex-1 flex flex-col items-center justify-center p-6 text-center space-y-3 font-mono bg-[#090d16]">
      <div className="p-3 bg-amber-500/10 rounded-2xl border border-amber-500/20 text-amber-400">
        <AlertTriangle className="w-8 h-8" />
      </div>
      <p className="text-gray-300 text-xs max-w-md leading-relaxed">
        {errorMessage}
      </p>
      <button
        onClick={onRetry}
        className="px-3 py-1.5 bg-blue-600 hover:bg-blue-500 text-white rounded-lg text-xs font-sans transition"
      >
        Retry
      </button>
    </div>
  );
}
