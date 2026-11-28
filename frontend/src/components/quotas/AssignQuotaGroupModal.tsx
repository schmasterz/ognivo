"use client";

import React, { useState, useEffect } from "react";
import { X, Layers, CheckCircle2, Loader2, Sparkles, User, Info } from "lucide-react";
import { api, AdminUserItem, QuotaGroup } from "@/lib/api";
import { useI18n } from "@/lib/i18n/I18nContext";
import { useModalEscape } from "@/lib/hooks/useModalEscape";

interface AssignQuotaGroupModalProps {
  user: AdminUserItem;
  quotaGroups: QuotaGroup[];
  onClose: () => void;
  onSuccess: (updatedUser: AdminUserItem) => void;
}

export function AssignQuotaGroupModal({
  user,
  quotaGroups,
  onClose,
  onSuccess,
}: AssignQuotaGroupModalProps) {
  const { t } = useI18n();

  const [selectedGroupId, setSelectedGroupId] = useState<number>(
    user.quota_group_id || (quotaGroups.find((g) => g.is_default)?.id ?? (quotaGroups[0]?.id || 0))
  );
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useModalEscape(onClose, !loading);

  useEffect(() => {
    queueMicrotask(() => {
      setSelectedGroupId(
        user.quota_group_id || (quotaGroups.find((g) => g.is_default)?.id ?? (quotaGroups[0]?.id || 0))
      );
      setError(null);
      setLoading(false);
    });
  }, [user, quotaGroups]);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setError(null);

    if (!selectedGroupId) {
      setError("Please select a valid quota group.");
      return;
    }

    try {
      setLoading(true);
      const res = await api.assignUserQuotaGroup(user.id, selectedGroupId);
      onSuccess(res.user);
      onClose();
    } catch (err: unknown) {
      if (err instanceof Error) {
        setError(err.message);
      } else {
        setError("Failed to assign quota group.");
      }
    } finally {
      setLoading(false);
    }
  };

  const formatQuota = (val: number, unit = "") => {
    return val === -1 ? "∞" : `${val}${unit ? " " + unit : ""}`;
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-sm animate-fadeIn">
      <div className="relative w-full max-w-lg bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-3xl p-6 sm:p-8 shadow-2xl transition-colors">
        <div className="flex items-center justify-between pb-4 mb-6 border-b border-gray-100 dark:border-gray-800">
          <div className="flex items-center space-x-3">
            <div className="p-2.5 rounded-2xl bg-indigo-500/10 text-indigo-600 dark:text-indigo-400">
              <Layers className="w-6 h-6" />
            </div>
            <div>
              <h2 className="text-xl font-bold text-gray-900 dark:text-white tracking-tight">
                {t("assignQuotaGroup")}
              </h2>
              <p className="text-xs text-gray-500 dark:text-gray-400">
                Change resource tier and synchronization plan for this tenant
              </p>
            </div>
          </div>
          <button
            onClick={onClose}
            className="p-2 rounded-xl text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-800 transition"
          >
            <X className="w-5 h-5" />
          </button>
        </div>

        {/* User Info Card */}
        <div className="mb-5 p-3.5 rounded-2xl bg-gray-50 dark:bg-gray-800/50 border border-gray-200 dark:border-gray-800 flex items-center space-x-3">
          <div className="p-2 rounded-xl bg-blue-500/10 text-blue-600 dark:text-blue-400">
            <User className="w-4 h-4" />
          </div>
          <div>
            <div className="text-sm font-semibold text-gray-900 dark:text-white">{user.name}</div>
            <div className="text-xs text-gray-500 dark:text-gray-400">{user.email}</div>
          </div>
        </div>

        {error && (
          <div className="mb-5 p-3.5 rounded-xl bg-red-500/10 border border-red-500/20 text-xs text-red-600 dark:text-red-400">
            {error}
          </div>
        )}

        <form onSubmit={handleSubmit} className="space-y-4">
          <div className="space-y-2.5">
            <label className="block text-xs font-semibold text-gray-700 dark:text-gray-300 uppercase tracking-wider">
              {t("selectQuotaGroup")}
            </label>

            <div className="space-y-2 max-h-60 overflow-y-auto pr-1">
              {quotaGroups.map((g) => {
                const isSelected = selectedGroupId === g.id;
                const isCurrent = user.quota_group_id === g.id;

                return (
                  <div
                    key={g.id}
                    onClick={() => setSelectedGroupId(g.id)}
                    className={`p-3.5 rounded-2xl border cursor-pointer transition flex flex-col space-y-1.5 ${
                      isSelected
                        ? "bg-indigo-500/10 border-indigo-500 dark:border-indigo-500 ring-2 ring-indigo-500/20"
                        : "bg-white dark:bg-gray-800/40 border-gray-200 dark:border-gray-800 hover:border-gray-300 dark:hover:border-gray-700"
                    }`}
                  >
                    <div className="flex items-center justify-between">
                      <div className="flex items-center space-x-2">
                        <input
                          type="radio"
                          name="quota_group"
                          value={g.id}
                          checked={isSelected}
                          onChange={() => setSelectedGroupId(g.id)}
                          className="text-indigo-600 focus:ring-indigo-500 cursor-pointer"
                        />
                        <span className="text-sm font-bold text-gray-900 dark:text-white">
                          {g.name}
                        </span>
                        {g.is_default && (
                          <span className="inline-flex items-center space-x-1 px-2 py-0.5 rounded-full text-[10px] font-semibold bg-amber-500/10 text-amber-600 dark:text-amber-400 border border-amber-500/20">
                            <Sparkles className="w-2.5 h-2.5" />
                            <span>{t("defaultGroupBadge")}</span>
                          </span>
                        )}
                        {isCurrent && (
                          <span className="px-2 py-0.5 rounded-full text-[10px] font-semibold bg-emerald-500/10 text-emerald-600 dark:text-emerald-400">
                            Current
                          </span>
                        )}
                      </div>
                    </div>

                    {g.description && (
                      <p className="text-xs text-gray-500 dark:text-gray-400 pl-6">
                        {g.description}
                      </p>
                    )}

                    <div className="pl-6 text-[11px] text-gray-600 dark:text-gray-300 flex items-center space-x-2 font-mono">
                      <span>{formatQuota(g.max_vms)} VMs</span>
                      <span className="text-gray-300 dark:text-gray-700">•</span>
                      <span>{formatQuota(g.max_cpu_cores)} vCPU</span>
                      <span className="text-gray-300 dark:text-gray-700">•</span>
                      <span>{g.max_ram_mb === -1 ? "∞" : `${Math.round(g.max_ram_mb / 1024)}GB`} RAM</span>
                      <span className="text-gray-300 dark:text-gray-700">•</span>
                      <span>{formatQuota(g.max_storage_gb)}GB Disk</span>
                      <span className="text-gray-300 dark:text-gray-700">•</span>
                      <span>{formatQuota(g.max_ips)} IPs</span>
                      <span className="text-gray-300 dark:text-gray-700">•</span>
                      <span>{formatQuota(g.max_snapshots)} Snaps</span>
                    </div>
                  </div>
                );
              })}
            </div>
          </div>

          <div className="p-3 rounded-xl bg-gray-50 dark:bg-gray-800/40 border border-gray-200 dark:border-gray-800/80 text-[11px] text-gray-500 dark:text-gray-400 flex items-start space-x-2">
            <Info className="w-3.5 h-3.5 mt-0.5 flex-shrink-0 text-blue-500" />
            <span>
              Assigning this group will update this user&apos;s active quotas to match the plan, and any future changes made to this group will keep them in sync.
            </span>
          </div>

          <div className="flex items-center justify-end space-x-3 pt-4 border-t border-gray-200 dark:border-gray-800">
            <button
              type="button"
              onClick={onClose}
              className="px-4 py-2 rounded-xl border border-gray-200 dark:border-gray-700 text-xs font-semibold text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800 transition"
            >
              {t("cancel")}
            </button>
            <button
              type="submit"
              disabled={loading}
              className="px-5 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-500 disabled:opacity-50 text-white text-xs font-semibold shadow-md shadow-indigo-600/20 transition flex items-center space-x-2"
            >
              {loading ? (
                <>
                  <Loader2 className="w-4 h-4 animate-spin" />
                  <span>{t("saving")}</span>
                </>
              ) : (
                <>
                  <CheckCircle2 className="w-4 h-4" />
                  <span>{t("assignQuotaGroup")}</span>
                </>
              )}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}
