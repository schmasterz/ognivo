"use client";

import React, { useState } from "react";
import { X, Layers, CheckCircle2, Loader2, Sparkles, AlertCircle } from "lucide-react";
import { api, QuotaGroup } from "@/lib/api";
import { useI18n } from "@/lib/i18n/I18nContext";
import {
  QuotaLimitsFormSection,
  QuotaLimitsFormValue,
  quotaFormValueToPayload,
  quotaRecordToFormValue,
} from "./QuotaLimitsFormSection";

interface EditQuotaGroupModalProps {
  quotaGroup: QuotaGroup;
  onClose: () => void;
  onSuccess: (updatedGroup: QuotaGroup) => void;
}

const GROUP_QUOTA_DEFAULTS: Partial<QuotaLimitsFormValue> = {
  maxVms: 3,
  maxCpu: 4,
  maxRamGb: 8,
  maxStorageGb: 100,
  maxIps: 2,
  maxSnapshots: 3,
};

export function EditQuotaGroupModal({ quotaGroup, onClose, onSuccess }: EditQuotaGroupModalProps) {
  const { t } = useI18n();

  const [name, setName] = useState(quotaGroup.name);
  const [description, setDescription] = useState(quotaGroup.description || "");
  const [isDefault, setIsDefault] = useState(quotaGroup.is_default);

  const [quotaLimits, setQuotaLimits] = useState<QuotaLimitsFormValue>(() =>
    quotaRecordToFormValue(quotaGroup, GROUP_QUOTA_DEFAULTS)
  );

  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setError(null);

    if (!name.trim()) {
      setError("Please provide a name for this quota group.");
      return;
    }

    try {
      setLoading(true);

      const res = await api.updateQuotaGroup(quotaGroup.id, {
        name: name.trim(),
        description: description.trim() || undefined,
        is_default: isDefault,
        ...quotaFormValueToPayload(quotaLimits),
      });

      onSuccess(res.quota_group);
      onClose();
    } catch (err: unknown) {
      if (err instanceof Error) {
        setError(err.message);
      } else {
        setError("Failed to update quota group.");
      }
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-sm animate-fadeIn">
      <div className="relative w-full max-w-2xl bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-3xl p-6 sm:p-8 shadow-2xl overflow-y-auto max-h-[90vh] transition-colors">
        <div className="flex items-center justify-between pb-4 mb-6 border-b border-gray-100 dark:border-gray-800">
          <div className="flex items-center space-x-3">
            <div className="p-2.5 rounded-2xl bg-indigo-500/10 text-indigo-600 dark:text-indigo-400">
              <Layers className="w-6 h-6" />
            </div>
            <div>
              <h2 className="text-xl font-bold text-gray-900 dark:text-white tracking-tight">
                {t("editQuotaGroup")}
              </h2>
              <p className="text-xs text-gray-500 dark:text-gray-400">
                {t("quotaGroupsSubtitle")}
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

        {/* Sync notification banner */}
        <div className="mb-5 p-3.5 rounded-xl bg-blue-500/10 border border-blue-500/20 text-xs text-blue-600 dark:text-blue-400 flex items-center space-x-2">
          <AlertCircle className="w-4 h-4 flex-shrink-0" />
          <span>{t("syncNotice")}</span>
        </div>

        {error && (
          <div className="mb-5 p-3.5 rounded-xl bg-red-500/10 border border-red-500/20 text-xs text-red-600 dark:text-red-400">
            {error}
          </div>
        )}

        <form onSubmit={handleSubmit} className="space-y-6">
          <div className="space-y-4">
            <div>
              <label className="block text-xs font-semibold text-gray-700 dark:text-gray-300 uppercase tracking-wider mb-2">
                {t("groupName")}
              </label>
              <input
                type="text"
                required
                value={name}
                onChange={(e) => setName(e.target.value)}
                placeholder="e.g. Pro Plan, Developer Tier, Unlimited"
                className="w-full bg-gray-50 dark:bg-gray-800/80 border border-gray-200 dark:border-gray-700 rounded-xl px-4 py-2.5 text-sm text-gray-900 dark:text-white placeholder-gray-400 focus:outline-none focus:border-indigo-500 transition"
              />
            </div>

            <div>
              <label className="block text-xs font-semibold text-gray-700 dark:text-gray-300 uppercase tracking-wider mb-2">
                {t("groupDescription")}
              </label>
              <textarea
                rows={2}
                value={description}
                onChange={(e) => setDescription(e.target.value)}
                placeholder="Briefly describe who this tier is intended for..."
                className="w-full bg-gray-50 dark:bg-gray-800/80 border border-gray-200 dark:border-gray-700 rounded-xl px-4 py-2.5 text-sm text-gray-900 dark:text-white placeholder-gray-400 focus:outline-none focus:border-indigo-500 transition resize-none"
              />
            </div>

            <div className="pt-2">
              <label className="flex items-center space-x-3 cursor-pointer p-3.5 rounded-xl bg-gray-50 dark:bg-gray-800/40 border border-gray-200 dark:border-gray-800 hover:bg-gray-100 dark:hover:bg-gray-800 transition">
                <input
                  type="checkbox"
                  checked={isDefault}
                  onChange={(e) => setIsDefault(e.target.checked)}
                  className="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500 w-4 h-4 cursor-pointer"
                />
                <div className="flex flex-col">
                  <span className="text-xs font-semibold text-gray-900 dark:text-white flex items-center space-x-1.5">
                    <Sparkles className="w-3.5 h-3.5 text-amber-500" />
                    <span>{t("isDefaultGroup")}</span>
                  </span>
                  <span className="text-[11px] text-gray-500 dark:text-gray-400 mt-0.5">
                    Newly registered tenants will automatically receive these limits.
                  </span>
                </div>
              </label>
            </div>
          </div>

          {/* Quotas Section */}
          <div className="pt-2 border-t border-gray-200 dark:border-gray-800">
            <h3 className="text-xs font-bold text-gray-900 dark:text-white uppercase tracking-wider mb-4">
              {t("customQuotas")}
            </h3>

            <QuotaLimitsFormSection
              value={quotaLimits}
              onChange={setQuotaLimits}
              columns={2}
            />
          </div>

          <div className="flex items-center justify-end space-x-3 pt-4 border-t border-gray-200 dark:border-gray-800">
            <button
              type="button"
              onClick={onClose}
              className="px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-700 text-xs font-semibold text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800 transition"
            >
              {t("cancel")}
            </button>
            <button
              type="submit"
              disabled={loading}
              className="px-5 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-500 disabled:opacity-50 text-white text-xs font-semibold shadow-md shadow-indigo-600/20 transition flex items-center space-x-2"
            >
              {loading ? (
                <>
                  <Loader2 className="w-4 h-4 animate-spin" />
                  <span>{t("saving")}</span>
                </>
              ) : (
                <>
                  <CheckCircle2 className="w-4 h-4" />
                  <span>{t("saveQuotaGroup")}</span>
                </>
              )}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}
