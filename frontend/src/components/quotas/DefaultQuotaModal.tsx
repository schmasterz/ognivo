"use client";

import React, { useEffect, useState } from "react";
import { X, Sliders, Loader2, CheckCircle2 } from "lucide-react";
import { api } from "@/lib/api";
import { useI18n } from "@/lib/i18n/I18nContext";
import {
  QuotaLimitsFormSection,
  QuotaLimitsFormValue,
  quotaFormValueToPayload,
  quotaRecordToFormValue,
} from "./QuotaLimitsFormSection";

interface DefaultQuotaModalProps {
  onClose: () => void;
  onSuccess: () => void;
}

const DEFAULT_QUOTA_DEFAULTS: Partial<QuotaLimitsFormValue> = {
  maxVms: 2,
  maxCpu: 4,
  maxRamGb: 4,
  maxStorageGb: 80,
  maxIps: 1,
  maxSnapshots: 3,
};

export function DefaultQuotaModal({ onClose, onSuccess }: DefaultQuotaModalProps) {
  const { t } = useI18n();

  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [quotaLimits, setQuotaLimits] = useState<QuotaLimitsFormValue>(() =>
    quotaRecordToFormValue(null, DEFAULT_QUOTA_DEFAULTS)
  );

  useEffect(() => {
    api
      .getDefaultQuota()
      .then((res) => {
        setQuotaLimits(quotaRecordToFormValue(res.quota, DEFAULT_QUOTA_DEFAULTS));
      })
      .catch((err) => {
        setError(err instanceof Error ? err.message : "Failed to load default quotas.");
      })
      .finally(() => {
        setLoading(false);
      });
  }, []);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setError(null);

    try {
      setSaving(true);
      await api.updateDefaultQuota(quotaFormValueToPayload(quotaLimits));

      onSuccess();
      onClose();
    } catch (err: unknown) {
      if (err instanceof Error) {
        setError(err.message);
      } else {
        setError("Failed to update default quotas.");
      }
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-sm animate-fadeIn">
      <div className="relative w-full max-w-xl bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-3xl p-6 sm:p-8 shadow-2xl overflow-y-auto max-h-[90vh] transition-colors">
        <div className="flex items-center justify-between pb-4 mb-6 border-b border-gray-100 dark:border-gray-800">
          <div className="flex items-center space-x-3">
            <div className="p-2.5 rounded-2xl bg-indigo-500/10 text-indigo-600 dark:text-indigo-400">
              <Sliders className="w-6 h-6" />
            </div>
            <div>
              <h2 className="text-xl font-bold text-gray-900 dark:text-white tracking-tight">
                {t("defaultQuotas")}
              </h2>
              <p className="text-xs text-gray-500 dark:text-gray-400">
                {t("defaultQuotasSubtitle")}
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

        {error && (
          <div className="mb-5 p-3.5 rounded-xl bg-red-500/10 border border-red-500/20 text-xs text-red-600 dark:text-red-400">
            {error}
          </div>
        )}

        {loading ? (
          <div className="py-12 text-center">
            <Loader2 className="w-8 h-8 text-blue-500 animate-spin mx-auto mb-2" />
          </div>
        ) : (
          <form onSubmit={handleSubmit} className="space-y-4">
            <QuotaLimitsFormSection
              value={quotaLimits}
              onChange={setQuotaLimits}
              columns={2}
            />

            <div className="flex items-center justify-end space-x-3 pt-4 border-t border-gray-100 dark:border-gray-800">
              <button
                type="button"
                onClick={onClose}
                disabled={saving}
                className="px-4 py-2.5 rounded-xl text-xs font-medium text-gray-600 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-800 transition"
              >
                {t("cancel")}
              </button>
              <button
                type="submit"
                disabled={saving}
                className="flex items-center space-x-2 px-5 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-semibold shadow-md shadow-indigo-500/20 transition disabled:opacity-50"
              >
                {saving ? (
                  <Loader2 className="w-4 h-4 animate-spin" />
                ) : (
                  <CheckCircle2 className="w-4 h-4" />
                )}
                <span>{t("saveDefaultQuota")}</span>
              </button>
            </div>
          </form>
        )}
      </div>
    </div>
  );
}
