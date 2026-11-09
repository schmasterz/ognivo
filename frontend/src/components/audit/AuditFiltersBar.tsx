"use client";

import React, { useState } from "react";
import { Filter, X, Search, ChevronDown } from "lucide-react";
import { AuditFilters } from "@/lib/api";
import { useI18n } from "@/lib/i18n/I18nContext";
import { SearchableUserSelect } from "@/components/ui/SearchableUserSelect";

const CATEGORIES = [
  { value: "", labelKey: "filterAllCategories" },
  { value: "auth", label: "Auth" },
  { value: "user", label: "User" },
  { value: "vm", label: "Workload (VM)" },
  { value: "ip", label: "IP Address" },
  { value: "ip_pool", label: "IP Pool" },
  { value: "host", label: "Host Node" },
  { value: "cert", label: "Certificate" },
  { value: "settings", label: "Settings" },
  { value: "quota", label: "Quota" },
  { value: "console", label: "Console" },
  { value: "impersonation", label: "Impersonation" },
  { value: "audit", label: "Audit" },
];

const OUTCOMES = [
  { value: "", labelKey: "filterAllOutcomes" },
  { value: "success", labelKey: "outcomeSuccess" },
  { value: "denied", labelKey: "outcomeDenied" },
  { value: "failed", labelKey: "outcomeFailed" },
];

interface AuditFiltersBarProps {
  filters: AuditFilters;
  isAdmin: boolean;
  onFilterChange: (newFilters: AuditFilters) => void;
  onReset: () => void;
}

export function AuditFiltersBar({
  filters,
  isAdmin,
  onFilterChange,
  onReset,
}: AuditFiltersBarProps) {
  const { t } = useI18n();
  const [expanded, setExpanded] = useState(false);

  const hasActiveFilters =
    !!filters.category ||
    !!filters.action ||
    !!filters.outcome ||
    !!filters.from ||
    !!filters.to ||
    !!filters.actor_id ||
    !!filters.tenant_user_id ||
    !!filters.ip ||
    !!filters.request_id;

  const handleChange = <K extends keyof AuditFilters>(
    key: K,
    value: AuditFilters[K] | ""
  ) => {
    onFilterChange({
      ...filters,
      [key]: value === "" ? undefined : value,
    });
  };

  return (
    <div className="bg-white dark:bg-[#0e131f] border border-gray-200 dark:border-gray-800 rounded-2xl p-4 shadow-sm space-y-4">
      {/* Primary Filter Row */}
      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
        {/* Category Select */}
        <div>
          <label className="block text-[11px] font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-1">
            {t("filterCategory")}
          </label>
          <div className="relative">
            <select
              value={filters.category || ""}
              onChange={(e) => handleChange("category", e.target.value)}
              className="w-full pl-3 pr-8 py-2 bg-gray-50 dark:bg-gray-900/60 border border-gray-200 dark:border-gray-700/80 rounded-xl text-xs text-gray-900 dark:text-gray-100 font-medium focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition appearance-none"
            >
              {CATEGORIES.map((cat) => (
                <option key={cat.value} value={cat.value}>
                  {cat.labelKey ? t(cat.labelKey as Parameters<typeof t>[0]) : cat.label}
                </option>
              ))}
            </select>
            <ChevronDown className="w-3.5 h-3.5 text-gray-400 absolute right-2.5 top-1/2 -translate-y-1/2 pointer-events-none" />
          </div>
        </div>

        {/* Free Action Search / Input */}
        <div>
          <label className="block text-[11px] font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-1">
            {t("filterAction")}
          </label>
          <div className="relative">
            <input
              type="text"
              placeholder="e.g. vm.create.requested"
              value={filters.action || ""}
              onChange={(e) => handleChange("action", e.target.value)}
              className="w-full pl-8 pr-3 py-2 bg-gray-50 dark:bg-gray-900/60 border border-gray-200 dark:border-gray-700/80 rounded-xl text-xs text-gray-900 dark:text-gray-100 font-mono placeholder:font-sans focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition"
            />
            <Search className="w-3.5 h-3.5 text-gray-400 absolute left-2.5 top-1/2 -translate-y-1/2 pointer-events-none" />
          </div>
        </div>

        {/* Outcome Select */}
        <div>
          <label className="block text-[11px] font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-1">
            {t("filterOutcome")}
          </label>
          <div className="relative">
            <select
              value={filters.outcome || ""}
              onChange={(e) =>
                handleChange("outcome", e.target.value as AuditFilters["outcome"] | "")
              }
              className="w-full pl-3 pr-8 py-2 bg-gray-50 dark:bg-gray-900/60 border border-gray-200 dark:border-gray-700/80 rounded-xl text-xs text-gray-900 dark:text-gray-100 font-medium focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition appearance-none"
            >
              {OUTCOMES.map((o) => (
                <option key={o.value} value={o.value}>
                  {t(o.labelKey as Parameters<typeof t>[0])}
                </option>
              ))}
            </select>
            <ChevronDown className="w-3.5 h-3.5 text-gray-400 absolute right-2.5 top-1/2 -translate-y-1/2 pointer-events-none" />
          </div>
        </div>

        {/* Action Controls: Expand Extra Filters & Reset */}
        <div className="flex items-end space-x-2">
          {isAdmin && (
            <button
              onClick={() => setExpanded(!expanded)}
              className={`flex-1 py-2 px-3 border rounded-xl text-xs font-semibold flex items-center justify-center space-x-1.5 transition ${
                expanded
                  ? "bg-purple-50 dark:bg-purple-950/40 text-purple-700 dark:text-purple-300 border-purple-300 dark:border-purple-800"
                  : "bg-gray-50 dark:bg-gray-900/60 text-gray-700 dark:text-gray-300 border-gray-200 dark:border-gray-700/80 hover:bg-gray-100 dark:hover:bg-gray-800"
              }`}
            >
              <Filter className="w-3.5 h-3.5" />
              <span>{expanded ? "Fewer Filters" : "More Filters"}</span>
            </button>
          )}

          {hasActiveFilters && (
            <button
              onClick={onReset}
              className="py-2 px-3 bg-rose-50 dark:bg-rose-950/40 hover:bg-rose-100 dark:hover:bg-rose-900/60 text-rose-700 dark:text-rose-300 border border-rose-200 dark:border-rose-800/80 rounded-xl text-xs font-semibold flex items-center justify-center space-x-1 transition shadow-sm"
              title="Clear all filters"
            >
              <X className="w-3.5 h-3.5" />
              <span className="hidden sm:inline">Reset</span>
            </button>
          )}
        </div>
      </div>

      {/* Date Range Inputs */}
      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 pt-2 border-t border-gray-100 dark:border-gray-800/60">
        <div>
          <label className="block text-[11px] font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-1">
            {t("filterDateFrom")}
          </label>
          <div className="relative">
            <input
              type="date"
              value={filters.from || ""}
              onChange={(e) => handleChange("from", e.target.value)}
              className="w-full px-3 py-1.5 bg-gray-50 dark:bg-gray-900/60 border border-gray-200 dark:border-gray-700/80 rounded-xl text-xs text-gray-900 dark:text-gray-100 font-medium focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition"
            />
          </div>
        </div>

        <div>
          <label className="block text-[11px] font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-1">
            {t("filterDateTo")}
          </label>
          <div className="relative">
            <input
              type="date"
              value={filters.to || ""}
              onChange={(e) => handleChange("to", e.target.value)}
              className="w-full px-3 py-1.5 bg-gray-50 dark:bg-gray-900/60 border border-gray-200 dark:border-gray-700/80 rounded-xl text-xs text-gray-900 dark:text-gray-100 font-medium focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition"
            />
          </div>
        </div>

        {/* Request ID Filter */}
        <div className="sm:col-span-2">
          <label className="block text-[11px] font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-1">
            {t("filterRequestId")}
          </label>
          <div className="relative">
            <input
              type="text"
              placeholder="e.g. 550e8400-e29b-41d4-a716-446655440000"
              value={filters.request_id || ""}
              onChange={(e) => handleChange("request_id", e.target.value)}
              className="w-full px-3 py-1.5 bg-gray-50 dark:bg-gray-900/60 border border-gray-200 dark:border-gray-700/80 rounded-xl text-xs text-gray-900 dark:text-gray-100 font-mono placeholder:font-sans focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition"
            />
          </div>
        </div>
      </div>

      {/* Admin Expanded Filters (Actor Search, Tenant Search, IP) */}
      {isAdmin && expanded && (
        <div className="grid grid-cols-1 sm:grid-cols-3 gap-3 pt-3 border-t border-gray-100 dark:border-gray-800/60 animate-fadeIn">
          {/* Actor User Search */}
          <div>
            <label className="block text-[11px] font-bold text-purple-600 dark:text-purple-400 uppercase tracking-wider mb-1">
              {t("filterActor")}
            </label>
            <SearchableUserSelect
              selectedUserId={filters.actor_id ? String(filters.actor_id) : ""}
              onSelectUser={(userId) => handleChange("actor_id", userId ? parseInt(userId, 10) : undefined)}
              className="w-full"
            />
          </div>

          {/* Tenant User Search */}
          <div>
            <label className="block text-[11px] font-bold text-purple-600 dark:text-purple-400 uppercase tracking-wider mb-1">
              {t("filterTenant")}
            </label>
            <SearchableUserSelect
              selectedUserId={filters.tenant_user_id ? String(filters.tenant_user_id) : ""}
              onSelectUser={(userId) => handleChange("tenant_user_id", userId ? parseInt(userId, 10) : undefined)}
              className="w-full"
            />
          </div>

          {/* IP Address Filter */}
          <div>
            <label className="block text-[11px] font-bold text-purple-600 dark:text-purple-400 uppercase tracking-wider mb-1">
              {t("filterIp")}
            </label>
            <div className="relative">
              <input
                type="text"
                placeholder="e.g. 192.168.1.1 or 2001:db8::1"
                value={filters.ip || ""}
                onChange={(e) => handleChange("ip", e.target.value)}
                className="w-full px-3 py-2 bg-gray-50 dark:bg-gray-900/60 border border-gray-200 dark:border-gray-700/80 rounded-xl text-xs text-gray-900 dark:text-gray-100 font-mono placeholder:font-sans focus:ring-2 focus:ring-purple-500/20 focus:border-purple-500 transition"
              />
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
