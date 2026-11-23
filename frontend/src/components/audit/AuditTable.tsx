"use client";

import React, { useState } from "react";
import {
  CheckCircle2,
  AlertTriangle,
  XCircle,
  Copy,
  Check,
  Clock,
  FileText,
} from "lucide-react";
import { AuditEntry, AdminAuditEntry } from "@/lib/api";
import { useI18n } from "@/lib/i18n/I18nContext";
import { PaginationBar } from "@/components/ui/PaginationBar";

export interface AuditTablePaginationProps {
  currentPage: number;
  lastPage: number;
  perPage: number;
  total: number;
  onPageChange: (page: number) => void;
  onPerPageChange: (perPage: number) => void;
}

/**
 * Formats an audit subject or actor type into a user-friendly label,
 * stripping internal PHP namespaces such as App\Models\ or App\Services\.
 */
export function formatAuditType(type?: string | null): string {
  if (!type) return "";
  // If it contains backslashes or starts with App\, take the basename/strip namespace
  const clean = type.split("\\").pop() ?? type;
  // Format common types into friendly names, or return the clean class name
  switch (clean.toLowerCase()) {
    case "virtualmachine":
    case "virtual_machine":
    case "vm":
      return "Virtual Machine";
    case "user":
      return "User";
    case "ippool":
    case "ip_pool":
      return "IP Pool";
    case "ipallocation":
    case "ip_allocation":
      return "IP Allocation";
    case "host":
    case "hostnode":
      return "Host Node";
    case "vmsnapshot":
    case "snapshot":
      return "Snapshot";
    case "quotagroup":
      return "Quota Group";
    default:
      // Strip any lingering namespace or internal prefix
      return clean.replace(/^App[_\\]/, "");
  }
}

interface AuditTableProps {
  entries: (AuditEntry | AdminAuditEntry)[];
  isAdmin: boolean;
  loading: boolean;
  pagination?: AuditTablePaginationProps;
  onSelectEntry: (entry: AuditEntry | AdminAuditEntry) => void;
}

export function AuditTable({
  entries,
  isAdmin,
  loading,
  pagination,
  onSelectEntry,
}: AuditTableProps) {
  const { t } = useI18n();
  const [copiedKey, setCopiedKey] = useState<string | null>(null);

  const handleCopy = (e: React.MouseEvent, text: string, key: string) => {
    e.stopPropagation();
    navigator.clipboard.writeText(text);
    setCopiedKey(key);
    setTimeout(() => {
      setCopiedKey((prev) => (prev === key ? null : prev));
    }, 2000);
  };

  const renderOutcomeBadge = (outcome: "success" | "denied" | "failed" | string) => {
    switch (outcome) {
      case "success":
        return (
          <span className="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-medium bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border border-emerald-500/20">
            <CheckCircle2 className="w-3 h-3 mr-1" />
            {t("outcomeSuccess")}
          </span>
        );
      case "denied":
        return (
          <span className="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-medium bg-amber-500/10 text-amber-600 dark:text-amber-400 border border-amber-500/20">
            <AlertTriangle className="w-3 h-3 mr-1" />
            {t("outcomeDenied")}
          </span>
        );
      case "failed":
        return (
          <span className="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-medium bg-rose-500/10 text-rose-600 dark:text-rose-400 border border-rose-500/20">
            <XCircle className="w-3 h-3 mr-1" />
            {t("outcomeFailed")}
          </span>
        );
      default:
        return (
          <span className="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-medium bg-gray-500/10 text-gray-600 dark:text-gray-400 border border-gray-500/20">
            {outcome}
          </span>
        );
    }
  };

  const getActionLabel = (action: string) => {
    const key = `audit.${action}` as const;
    const translated = t(key as Parameters<typeof t>[0]);
    return translated && translated !== key ? translated : action;
  };

  return (
    <div className="bg-white dark:bg-[#0e131f] border border-gray-200 dark:border-gray-800 rounded-2xl shadow-sm overflow-hidden flex flex-col justify-between">
      <div className="overflow-x-auto relative">
        <table className="min-w-full divide-y divide-gray-200 dark:divide-gray-800 text-xs">
          <thead>
            <tr className="bg-gray-50/75 dark:bg-gray-900/40 text-gray-500 dark:text-gray-400">
              {/* Timestamp */}
              <th scope="col" className="py-3.5 pl-4 pr-3 text-left font-bold uppercase tracking-wider">
                {t("colTimestamp")}
              </th>

              {/* Action */}
              <th scope="col" className="px-3 py-3.5 text-left font-bold uppercase tracking-wider">
                {t("colAction")}
              </th>

              {/* Outcome */}
              <th scope="col" className="px-3 py-3.5 text-left font-bold uppercase tracking-wider">
                {t("colOutcome")}
              </th>

              {/* Subject */}
              <th scope="col" className="px-3 py-3.5 text-left font-bold uppercase tracking-wider">
                {t("colSubject")}
              </th>

              {/* Actor */}
              <th scope="col" className="px-3 py-3.5 text-left font-bold uppercase tracking-wider">
                {t("colActor")}
              </th>

              {/* Admin Columns */}
              {isAdmin && (
                <>
                  <th scope="col" className="px-3 py-3.5 text-left font-bold uppercase tracking-wider">
                    {t("colTenant")}
                  </th>
                  <th scope="col" className="px-3 py-3.5 text-left font-bold uppercase tracking-wider">
                    {t("colIp")}
                  </th>
                  <th scope="col" className="px-3 py-3.5 text-left font-bold uppercase tracking-wider">
                    {t("colRequestId")}
                  </th>
                </>
              )}

              {/* Non-Admin Columns */}
              {!isAdmin && (
                <th scope="col" className="px-3 py-3.5 text-left font-bold uppercase tracking-wider">
                  {t("colIp")}
                </th>
              )}
            </tr>
          </thead>

          <tbody className="divide-y divide-gray-200 dark:divide-gray-800">
            {/* Loading Skeletons */}
            {loading && entries.length === 0 && (
              <>
                {[...Array(6)].map((_, i) => (
                  <tr key={i} className="animate-pulse">
                    <td className="py-3 pl-4 pr-3">
                      <div className="h-4 bg-gray-200 dark:bg-gray-800 rounded w-28" />
                    </td>
                    <td className="px-3 py-3">
                      <div className="h-4 bg-gray-200 dark:bg-gray-800 rounded w-36" />
                    </td>
                    <td className="px-3 py-3">
                      <div className="h-5 bg-gray-200 dark:bg-gray-800 rounded-full w-16" />
                    </td>
                    <td className="px-3 py-3">
                      <div className="h-4 bg-gray-200 dark:bg-gray-800 rounded w-24" />
                    </td>
                    <td className="px-3 py-3">
                      <div className="h-4 bg-gray-200 dark:bg-gray-800 rounded w-20" />
                    </td>
                    {isAdmin && (
                      <>
                        <td className="px-3 py-3">
                          <div className="h-4 bg-gray-200 dark:bg-gray-800 rounded w-16" />
                        </td>
                        <td className="px-3 py-3">
                          <div className="h-4 bg-gray-200 dark:bg-gray-800 rounded w-24" />
                        </td>
                        <td className="px-3 py-3">
                          <div className="h-4 bg-gray-200 dark:bg-gray-800 rounded w-20" />
                        </td>
                      </>
                    )}
                    {!isAdmin && (
                      <td className="px-3 py-3">
                        <div className="h-4 bg-gray-200 dark:bg-gray-800 rounded w-20" />
                      </td>
                    )}
                  </tr>
                ))}
              </>
            )}

            {/* Empty State */}
            {!loading && entries.length === 0 && (
              <tr>
                <td
                  colSpan={isAdmin ? 8 : 6}
                  className="py-12 px-4 text-center text-gray-500 dark:text-gray-400"
                >
                  <div className="max-w-xs mx-auto flex flex-col items-center">
                    <div className="p-3 bg-gray-100 dark:bg-gray-800/80 rounded-2xl text-gray-400 mb-3">
                      <FileText className="w-8 h-8" />
                    </div>
                    <span className="font-bold text-sm text-gray-900 dark:text-gray-200 mb-1">
                      {t("noAuditEntries")}
                    </span>
                    <p className="text-xs text-gray-500 dark:text-gray-400 text-center leading-relaxed">
                      {t("noAuditDesc")}
                    </p>
                  </div>
                </td>
              </tr>
            )}

            {/* Rows */}
            {entries.map((entry) => {
              const date = new Date(entry.occurred_at);
              const localizedTime = isNaN(date.getTime())
                ? entry.occurred_at
                : date.toLocaleString(undefined, {
                    dateStyle: "short",
                    timeStyle: "medium",
                  });
              const utcTooltip = isNaN(date.getTime()) ? entry.occurred_at : date.toISOString();

              const adminEntry = isAdmin ? (entry as AdminAuditEntry) : null;
              const tenantEntry = !isAdmin ? (entry as AuditEntry) : null;

              return (
                <tr
                  key={entry.id}
                  onClick={() => onSelectEntry(entry)}
                  className="hover:bg-blue-50/40 dark:hover:bg-blue-950/20 cursor-pointer transition group"
                >
                  {/* Timestamp with UTC Tooltip */}
                  <td
                    className="py-3 pl-4 pr-3 font-mono text-gray-600 dark:text-gray-300 whitespace-nowrap"
                    title={`UTC: ${utcTooltip}`}
                  >
                    <span className="cursor-help flex items-center space-x-1.5">
                      <Clock className="w-3.5 h-3.5 text-gray-400 group-hover:text-blue-500 transition shrink-0" />
                      <span>{localizedTime}</span>
                    </span>
                  </td>

                  {/* Action Badge */}
                  <td className="px-3 py-3">
                    <div className="font-semibold text-gray-900 dark:text-gray-100 max-w-[200px] truncate" title={entry.action}>
                      {getActionLabel(entry.action)}
                    </div>
                    <div className="text-[10px] font-mono text-gray-400 truncate max-w-[200px]">
                      {entry.action}
                    </div>
                  </td>

                  {/* Outcome */}
                  <td className="px-3 py-3 whitespace-nowrap">
                    {renderOutcomeBadge(entry.outcome)}
                  </td>

                  {/* Subject */}
                  <td className="px-3 py-3">
                    <div className="font-medium text-gray-900 dark:text-gray-200 truncate max-w-[150px]" title={entry.subject_label || formatAuditType(entry.subject_type) || ""}>
                      {entry.subject_label || (entry.subject_type ? `${formatAuditType(entry.subject_type)} #${entry.subject_id}` : "—")}
                    </div>
                    {entry.subject_type && (
                      <div className="text-[10px] text-gray-400 font-mono">
                        {formatAuditType(entry.subject_type)} {entry.subject_id ? `#${entry.subject_id}` : ""}
                      </div>
                    )}
                  </td>

                  {/* Actor Column */}
                  <td className="px-3 py-3 whitespace-nowrap">
                    {isAdmin && adminEntry ? (
                      <div>
                        <div className="font-medium text-gray-900 dark:text-gray-200">
                          {adminEntry.actor_label || formatAuditType(adminEntry.actor_type)}
                        </div>
                        {adminEntry.actor_id && (
                          <div className="text-[10px] font-mono text-gray-400">
                            ID #{adminEntry.actor_id}
                          </div>
                        )}
                      </div>
                    ) : (
                      <span className="font-medium text-gray-700 dark:text-gray-300">
                        {tenantEntry?.actor?.kind === "you"
                          ? t("actorYou")
                          : tenantEntry?.actor?.kind === "platform_admin"
                          ? tenantEntry?.actor?.impersonator
                            ? `${t("actorPlatformAdmin")} (${t("supportSession")})`
                            : t("actorPlatformAdmin")
                          : t("actorSystem")}
                      </span>
                    )}
                  </td>

                  {/* Admin Specific Columns */}
                  {isAdmin && adminEntry && (
                    <>
                      {/* Tenant User ID */}
                      <td className="px-3 py-3 whitespace-nowrap font-mono text-gray-600 dark:text-gray-300">
                        {adminEntry.tenant_user_id ? `User #${adminEntry.tenant_user_id}` : "—"}
                      </td>

                      {/* IP Address with compressed copy button */}
                      <td className="px-3 py-3 whitespace-nowrap font-mono text-gray-600 dark:text-gray-300">
                        {adminEntry.ip ? (
                          <div className="inline-flex items-center space-x-1">
                            <span>{adminEntry.ip}</span>
                            <button
                              onClick={(e) => handleCopy(e, adminEntry.ip!, `ip-${entry.id}`)}
                              className="p-1 hover:bg-gray-100 dark:hover:bg-gray-800 rounded text-gray-400 hover:text-gray-600 transition"
                              title={t("copied")}
                            >
                              {copiedKey === `ip-${entry.id}` ? (
                                <Check className="w-3 h-3 text-emerald-500" />
                              ) : (
                                <Copy className="w-3 h-3" />
                              )}
                            </button>
                          </div>
                        ) : (
                          <span className="text-gray-400 font-sans">—</span>
                        )}
                      </td>

                      {/* Request ID with copy button */}
                      <td className="px-3 py-3 whitespace-nowrap font-mono text-gray-600 dark:text-gray-300">
                        {adminEntry.request_id ? (
                          <div className="inline-flex items-center space-x-1">
                            <span className="truncate max-w-[80px]" title={adminEntry.request_id}>
                              {adminEntry.request_id.slice(0, 8)}...
                            </span>
                            <button
                              onClick={(e) => handleCopy(e, adminEntry.request_id!, `req-${entry.id}`)}
                              className="p-1 hover:bg-gray-100 dark:hover:bg-gray-800 rounded text-gray-400 hover:text-gray-600 transition"
                              title={t("copied")}
                            >
                              {copiedKey === `req-${entry.id}` ? (
                                <Check className="w-3 h-3 text-emerald-500" />
                              ) : (
                                <Copy className="w-3 h-3" />
                              )}
                            </button>
                          </div>
                        ) : (
                          <span className="text-gray-400 font-sans">—</span>
                        )}
                      </td>
                    </>
                  )}

                  {/* Customer Specific IP Column (only shown if kind === 'you') */}
                  {!isAdmin && (
                    <td className="px-3 py-3 whitespace-nowrap font-mono text-gray-600 dark:text-gray-300">
                      {entry.ip ? (
                        <div className="inline-flex items-center space-x-1">
                          <span>{entry.ip}</span>
                          <button
                            onClick={(e) => handleCopy(e, entry.ip!, `ip-${entry.id}`)}
                            className="p-1 hover:bg-gray-100 dark:hover:bg-gray-800 rounded text-gray-400 hover:text-gray-600 transition"
                            title={t("copied")}
                          >
                            {copiedKey === `ip-${entry.id}` ? (
                              <Check className="w-3 h-3 text-emerald-500" />
                            ) : (
                              <Copy className="w-3 h-3" />
                            )}
                          </button>
                        </div>
                      ) : (
                        <span className="text-gray-400 font-sans">—</span>
                      )}
                    </td>
                  )}
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>

      {/* Keyset Cursor Pagination Bar */}
      {pagination && (pagination.total > 0 || entries.length > 0) && (
        <PaginationBar
          currentPage={pagination.currentPage}
          lastPage={pagination.lastPage}
          perPage={pagination.perPage}
          total={pagination.total > 0 ? pagination.total : entries.length}
          onPageChange={pagination.onPageChange}
          onPerPageChange={pagination.onPerPageChange}
        />
      )}
    </div>
  );
}
