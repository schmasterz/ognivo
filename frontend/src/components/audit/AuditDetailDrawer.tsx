"use client";

import React, { useState } from "react";
import {
  X,
  Copy,
  Check,
  CheckCircle2,
  AlertTriangle,
  XCircle,
  ExternalLink,
  Layers,
  Clock,
  Globe,
  Monitor,
  User as UserIcon,
  Filter,
} from "lucide-react";
import { AuditEntry, AdminAuditEntry } from "@/lib/api";
import { useI18n } from "@/lib/i18n/I18nContext";
import { useModalEscape } from "@/lib/hooks/useModalEscape";
import { formatAuditType } from "./AuditTable";

interface AuditDetailDrawerProps {
  entry: AuditEntry | AdminAuditEntry | null;
  isAdmin: boolean;
  isOpen: boolean;
  onClose: () => void;
  onFilterRelated?: (requestId: string, operationId?: number | null) => void;
  onNavigate?: (section: "vms" | "networking" | "users", id?: string | null) => void;
}

export function AuditDetailDrawer({
  entry,
  isAdmin,
  isOpen,
  onClose,
  onFilterRelated,
  onNavigate,
}: AuditDetailDrawerProps) {
  const { t } = useI18n();
  const [copiedKey, setCopiedKey] = useState<string | null>(null);

  useModalEscape(onClose, isOpen);

  if (!isOpen || !entry) {
    return null;
  }

  const handleCopy = (text: string, key: string) => {
    navigator.clipboard.writeText(text);
    setCopiedKey(key);
    setTimeout(() => {
      setCopiedKey((prev) => (prev === key ? null : prev));
    }, 2000);
  };

  const adminEntry = isAdmin ? (entry as AdminAuditEntry) : null;
  const requestId = adminEntry?.request_id || (entry.context as Record<string, unknown> | null)?.request_id as string | undefined;
  const operationId = adminEntry?.operation_id || (entry.context as Record<string, unknown> | null)?.operation_id as number | undefined;

  const date = new Date(entry.occurred_at);
  const localizedTime = isNaN(date.getTime())
    ? entry.occurred_at
    : date.toLocaleString(undefined, {
        dateStyle: "medium",
        timeStyle: "medium",
      });
  const utcTooltip = isNaN(date.getTime()) ? entry.occurred_at : date.toISOString();

  // Translated action label or raw key fallback
  const actionTranslationKey = `audit.${entry.action}` as const;
  const translatedAction = t(actionTranslationKey as Parameters<typeof t>[0]);
  const actionDisplay =
    translatedAction && translatedAction !== actionTranslationKey
      ? translatedAction
      : entry.action;

  // Outcome config
  const outcomeConfig = {
    success: {
      label: t("outcomeSuccess"),
      bg: "bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border-emerald-500/20",
      icon: <CheckCircle2 className="w-3.5 h-3.5 mr-1" />,
    },
    denied: {
      label: t("outcomeDenied"),
      bg: "bg-amber-500/10 text-amber-600 dark:text-amber-400 border-amber-500/20",
      icon: <AlertTriangle className="w-3.5 h-3.5 mr-1" />,
    },
    failed: {
      label: t("outcomeFailed"),
      bg: "bg-rose-500/10 text-rose-600 dark:text-rose-400 border-rose-500/20",
      icon: <XCircle className="w-3.5 h-3.5 mr-1" />,
    },
  }[entry.outcome] || {
    label: entry.outcome,
    bg: "bg-gray-500/10 text-gray-600 dark:text-gray-400 border-gray-500/20",
    icon: null,
  };

  // Subject Navigation Handler
  const handleSubjectClick = () => {
    if (!onNavigate || !entry.subject_type) return;

    const lower = entry.subject_type.toLowerCase();
    if (lower.includes("vm") || lower.includes("virtualmachine")) {
      onNavigate("vms", entry.subject_id);
      onClose();
    } else if (lower.includes("ip") || lower.includes("pool")) {
      onNavigate("networking", entry.subject_id);
      onClose();
    } else if (lower.includes("user") && isAdmin) {
      onNavigate("users", entry.subject_id);
      onClose();
    }
  };

  const isNavigableSubject =
    !!onNavigate &&
    !!entry.subject_type &&
    (entry.subject_type.toLowerCase().includes("vm") ||
      entry.subject_type.toLowerCase().includes("ip") ||
      (isAdmin && entry.subject_type.toLowerCase().includes("user")));

  // Diff inspection
  const context = (entry.context || {}) as Record<string, unknown>;
  const diffObject = (context.diff as Record<string, { old?: unknown; new?: unknown; before?: unknown; after?: unknown }>) || null;

  return (
    <div className="fixed inset-0 z-50 overflow-hidden" aria-labelledby="audit-drawer-title" role="dialog" aria-modal="true">
      {/* Backdrop */}
      <div
        className="fixed inset-0 bg-black/50 backdrop-blur-sm transition-opacity"
        onClick={onClose}
        aria-hidden="true"
      />

      <div className="fixed inset-y-0 right-0 max-w-full flex pl-10">
        <div className="w-screen max-w-xl bg-white dark:bg-[#0e131f] border-l border-gray-200 dark:border-gray-800 shadow-2xl flex flex-col justify-between">
          {/* Header */}
          <div className="p-6 border-b border-gray-200 dark:border-gray-800 flex items-start justify-between bg-gray-50/50 dark:bg-gray-900/20">
            <div>
              <div className="flex items-center space-x-2">
                <span
                  className={`inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium border ${outcomeConfig.bg}`}
                >
                  {outcomeConfig.icon}
                  {outcomeConfig.label}
                </span>
                <span className="text-xs font-mono text-gray-500 dark:text-gray-400">
                  #{entry.id}
                </span>
              </div>
              <h2
                id="audit-drawer-title"
                className="text-base font-bold text-gray-900 dark:text-white mt-2 leading-snug"
              >
                {actionDisplay}
              </h2>
              <div className="text-xs text-gray-400 font-mono mt-0.5">
                {entry.action}
              </div>
            </div>

            <button
              onClick={onClose}
              className="p-2 text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-800 transition"
              aria-label={t("closeMenu")}
            >
              <X className="w-5 h-5" />
            </button>
          </div>

          {/* Body Content */}
          <div className="flex-1 overflow-y-auto p-6 space-y-6">
            {/* Meta Grid */}
            <div className="grid grid-cols-2 gap-4 bg-gray-50 dark:bg-gray-900/40 p-4 rounded-xl border border-gray-100 dark:border-gray-800/80">
              {/* Timestamp */}
              <div>
                <span className="text-[11px] font-semibold text-gray-400 uppercase tracking-wider block">
                  {t("colTimestamp")}
                </span>
                <div
                  className="text-xs font-medium text-gray-900 dark:text-gray-200 mt-1 cursor-help flex items-center space-x-1"
                  title={`UTC: ${utcTooltip}`}
                >
                  <Clock className="w-3.5 h-3.5 text-gray-400" />
                  <span>{localizedTime}</span>
                </div>
              </div>

              {/* Subject */}
              <div>
                <span className="text-[11px] font-semibold text-gray-400 uppercase tracking-wider block">
                  {t("colSubject")}
                </span>
                <div className="text-xs font-medium text-gray-900 dark:text-gray-200 mt-1">
                  {entry.subject_label || entry.subject_id ? (
                    isNavigableSubject ? (
                      <button
                        onClick={handleSubjectClick}
                        className="text-blue-600 dark:text-blue-400 hover:underline flex items-center space-x-1 font-semibold group"
                      >
                        <span>{entry.subject_label || `${formatAuditType(entry.subject_type)} #${entry.subject_id}`}</span>
                        <ExternalLink className="w-3 h-3 group-hover:translate-x-0.5 transition" />
                      </button>
                    ) : (
                      <span>{entry.subject_label || `${formatAuditType(entry.subject_type)} #${entry.subject_id}`}</span>
                    )
                  ) : (
                    <span className="text-gray-400">—</span>
                  )}
                </div>
              </div>

              {/* Actor */}
              <div>
                <span className="text-[11px] font-semibold text-gray-400 uppercase tracking-wider block">
                  {t("colActor")}
                </span>
                <div className="text-xs font-medium text-gray-900 dark:text-gray-200 mt-1 flex items-center space-x-1">
                  <UserIcon className="w-3.5 h-3.5 text-gray-400" />
                  {isAdmin && adminEntry ? (
                    <span>
                      {adminEntry.actor_label ||
                        (adminEntry.actor_id
                          ? `${formatAuditType(adminEntry.actor_type)} #${adminEntry.actor_id}`
                          : formatAuditType(adminEntry.actor_type))}
                    </span>
                  ) : (
                    <span>
                      {(entry as AuditEntry).actor?.kind === "you"
                        ? t("actorYou")
                        : (entry as AuditEntry).actor?.kind === "platform_admin"
                        ? (entry as AuditEntry).actor?.impersonator
                          ? `${t("actorPlatformAdmin")} (${t("supportSession")})`
                          : t("actorPlatformAdmin")
                        : t("actorSystem")}
                    </span>
                  )}
                </div>
              </div>

              {/* Tenant (Admin Only) */}
              {isAdmin && (
                <div>
                  <span className="text-[11px] font-semibold text-gray-400 uppercase tracking-wider block">
                    {t("colTenant")}
                  </span>
                  <div className="text-xs font-medium text-gray-900 dark:text-gray-200 mt-1">
                    {adminEntry?.tenant_user_id ? (
                      onNavigate ? (
                        <button
                          onClick={() => {
                            onNavigate("users", String(adminEntry.tenant_user_id));
                            onClose();
                          }}
                          className="text-blue-600 dark:text-blue-400 hover:underline font-semibold"
                        >
                          User #{adminEntry.tenant_user_id}
                        </button>
                      ) : (
                        `User #${adminEntry.tenant_user_id}`
                      )
                    ) : (
                      <span className="text-gray-400">—</span>
                    )}
                  </div>
                </div>
              )}

              {/* IP Address */}
              <div>
                <span className="text-[11px] font-semibold text-gray-400 uppercase tracking-wider block">
                  {t("colIp")}
                </span>
                <div className="text-xs font-mono text-gray-900 dark:text-gray-200 mt-1 flex items-center space-x-1.5">
                  <Globe className="w-3.5 h-3.5 text-gray-400 shrink-0" />
                  {entry.ip ? (
                    <>
                      <span>{entry.ip}</span>
                      <button
                        onClick={() => handleCopy(entry.ip!, "ip")}
                        className="p-1 hover:bg-gray-200 dark:hover:bg-gray-800 rounded text-gray-400 hover:text-gray-600 transition"
                        title={t("copied")}
                      >
                        {copiedKey === "ip" ? (
                          <Check className="w-3 h-3 text-emerald-500" />
                        ) : (
                          <Copy className="w-3 h-3" />
                        )}
                      </button>
                    </>
                  ) : (
                    <span className="text-gray-400 font-sans">—</span>
                  )}
                </div>
              </div>

              {/* Request ID */}
              <div>
                <span className="text-[11px] font-semibold text-gray-400 uppercase tracking-wider block">
                  {t("colRequestId")}
                </span>
                <div className="text-xs font-mono text-gray-900 dark:text-gray-200 mt-1 flex items-center space-x-1.5">
                  {requestId ? (
                    <>
                      <span className="truncate max-w-[130px]" title={requestId}>
                        {requestId}
                      </span>
                      <button
                        onClick={() => handleCopy(requestId, "request_id")}
                        className="p-1 hover:bg-gray-200 dark:hover:bg-gray-800 rounded text-gray-400 hover:text-gray-600 transition"
                        title={t("copied")}
                      >
                        {copiedKey === "request_id" ? (
                          <Check className="w-3 h-3 text-emerald-500" />
                        ) : (
                          <Copy className="w-3 h-3" />
                        )}
                      </button>
                    </>
                  ) : (
                    <span className="text-gray-400 font-sans">—</span>
                  )}
                </div>
              </div>
            </div>

            {/* User Agent */}
            {entry.user_agent && (
              <div className="p-3 bg-gray-50 dark:bg-gray-900/30 rounded-xl border border-gray-100 dark:border-gray-800/80 flex items-start space-x-2">
                <Monitor className="w-4 h-4 text-gray-400 mt-0.5 shrink-0" />
                <div className="text-xs text-gray-600 dark:text-gray-400 font-mono break-all leading-relaxed">
                  {entry.user_agent}
                </div>
              </div>
            )}

            {/* Related Events Action Button */}
            {requestId && onFilterRelated && (
              <div>
                <button
                  onClick={() => {
                    onFilterRelated(requestId, operationId);
                    onClose();
                  }}
                  className="w-full py-2.5 px-4 bg-indigo-50 dark:bg-indigo-950/40 hover:bg-indigo-100 dark:hover:bg-indigo-900/60 text-indigo-700 dark:text-indigo-300 border border-indigo-200 dark:border-indigo-800/60 rounded-xl text-xs font-semibold flex items-center justify-center space-x-2 transition shadow-sm"
                >
                  <Filter className="w-3.5 h-3.5" />
                  <span>{t("btnRelatedEvents")}</span>
                  {operationId ? (
                    <span className="ml-1 text-[10px] font-mono opacity-80">
                      (Op #{operationId})
                    </span>
                  ) : null}
                </button>
              </div>
            )}

            {/* Before / After Diff Table */}
            {diffObject && Object.keys(diffObject).length > 0 && (
              <div className="space-y-2">
                <h3 className="text-xs font-bold uppercase tracking-wider text-gray-500 dark:text-gray-400 flex items-center space-x-1.5">
                  <Layers className="w-3.5 h-3.5" />
                  <span>{t("diffTable")}</span>
                </h3>
                <div className="border border-gray-200 dark:border-gray-800 rounded-xl overflow-hidden shadow-sm">
                  <table className="min-w-full text-xs">
                    <thead>
                      <tr className="bg-gray-100 dark:bg-gray-800/80 text-gray-600 dark:text-gray-300 border-b border-gray-200 dark:border-gray-800">
                        <th className="py-2 px-3 text-left font-semibold">{t("diffField")}</th>
                        <th className="py-2 px-3 text-left font-semibold">{t("diffBefore")}</th>
                        <th className="py-2 px-3 text-left font-semibold">{t("diffAfter")}</th>
                      </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100 dark:divide-gray-800 font-mono">
                      {Object.entries(diffObject).map(([field, delta]) => {
                        const beforeVal = delta.old ?? delta.before ?? "—";
                        const afterVal = delta.new ?? delta.after ?? "—";
                        return (
                          <tr key={field} className="hover:bg-gray-50 dark:hover:bg-gray-900/30">
                            <td className="py-2 px-3 font-medium text-gray-800 dark:text-gray-200 font-sans">
                              {field}
                            </td>
                            <td className="py-2 px-3 text-rose-600 dark:text-rose-400 bg-rose-50/30 dark:bg-rose-950/20">
                              {typeof beforeVal === "object" ? JSON.stringify(beforeVal) : String(beforeVal)}
                            </td>
                            <td className="py-2 px-3 text-emerald-600 dark:text-emerald-400 bg-emerald-50/30 dark:bg-emerald-950/20">
                              {typeof afterVal === "object" ? JSON.stringify(afterVal) : String(afterVal)}
                            </td>
                          </tr>
                        );
                      })}
                    </tbody>
                  </table>
                </div>
              </div>
            )}

            {/* Raw Context Details */}
            {context && Object.keys(context).length > 0 && (
              <div className="space-y-2">
                <h3 className="text-xs font-bold uppercase tracking-wider text-gray-500 dark:text-gray-400">
                  {t("contextPayload")}
                </h3>
                <pre className="p-3 bg-gray-900 text-gray-100 rounded-xl text-[11px] font-mono overflow-x-auto max-h-64 border border-gray-800 shadow-inner">
                  {JSON.stringify(context, null, 2)}
                </pre>
              </div>
            )}
          </div>

          {/* Footer */}
          <div className="p-4 border-t border-gray-200 dark:border-gray-800 bg-gray-50/50 dark:bg-gray-900/20 flex justify-end">
            <button
              onClick={onClose}
              className="px-4 py-2 bg-gray-200 dark:bg-gray-800 hover:bg-gray-300 dark:hover:bg-gray-700 text-gray-800 dark:text-gray-200 rounded-xl text-xs font-semibold transition"
            >
              {t("cancel")}
            </button>
          </div>
        </div>
      </div>
    </div>
  );
}
