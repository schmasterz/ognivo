"use client";

import React, { useState, useEffect, useCallback } from "react";
import { X, ShieldCheck, CheckCircle2, XCircle, RefreshCw, Loader2 } from "lucide-react";
import { api, IpPool } from "@/lib/api";
import { useI18n } from "@/lib/i18n/I18nContext";

interface PreflightModalProps {
  pool: IpPool;
  onClose: () => void;
}

interface PreflightCheck {
  name: string;
  ok: boolean;
  hint: string;
}

export function PreflightModal({ pool, onClose }: PreflightModalProps) {
  const { t } = useI18n();

  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [result, setResult] = useState<{ ok: boolean; checks: PreflightCheck[] } | null>(null);

  const runPreflight = useCallback(async () => {
    try {
      setLoading(true);
      setError(null);
      const res = await api.adminPreflightIpPool(pool.id);
      setResult(res);
    } catch (err: unknown) {
      if (err instanceof Error) {
        setError(err.message);
      } else {
        setError("Failed to run preflight check.");
      }
    } finally {
      setLoading(false);
    }
  }, [pool.id]);

  useEffect(() => {
    runPreflight();
  }, [runPreflight]);

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-sm animate-fadeIn">
      <div className="relative w-full max-w-lg bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-3xl p-6 sm:p-8 shadow-2xl overflow-y-auto max-h-[90vh] transition-colors">
        <div className="flex items-center justify-between pb-4 mb-5 border-b border-gray-100 dark:border-gray-800">
          <div className="flex items-center space-x-3">
            <div className="p-2.5 rounded-2xl bg-teal-500/10 text-teal-600 dark:text-teal-400">
              <ShieldCheck className="w-6 h-6" />
            </div>
            <div>
              <h2 className="text-xl font-bold text-gray-900 dark:text-white tracking-tight">{t("preflightTitle")}</h2>
              <p className="text-xs text-gray-500 dark:text-gray-400">
                {pool.name} ({pool.cidr || pool.subnet})
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
            <Loader2 className="w-8 h-8 text-teal-500 animate-spin mx-auto mb-3" />
            <p className="text-sm font-medium text-gray-700 dark:text-gray-300">
              {t("preflightRunning") || "Running preflight checks on hypervisor..."}
            </p>
            <p className="text-xs text-gray-400 mt-1">Verifying host API reachability and network interfaces</p>
          </div>
        ) : result ? (
          <div className="space-y-4">
            {/* Status Banner */}
            <div
              className={`p-4 rounded-2xl border flex items-center space-x-3 ${
                result.ok
                  ? "bg-emerald-500/10 border-emerald-500/20 text-emerald-700 dark:text-emerald-300"
                  : "bg-red-500/10 border-red-500/20 text-red-700 dark:text-red-300"
              }`}
            >
              {result.ok ? (
                <CheckCircle2 className="w-5 h-5 text-emerald-500 flex-shrink-0" />
              ) : (
                <XCircle className="w-5 h-5 text-red-500 flex-shrink-0" />
              )}
              <div>
                <p className="text-sm font-semibold">
                  {result.ok ? t("preflightPassed") : t("preflightFailed")}
                </p>
                <p className="text-xs opacity-90 mt-0.5">
                  {result.ok
                    ? "Hypervisor is reachable, parent interface exists, and CIDR range is consistent."
                    : "One or more preflight checks failed. Review the details below."}
                </p>
              </div>
            </div>

            {/* Checklist */}
            <div className="space-y-2.5">
              {result.checks.map((chk, idx) => (
                <div
                  key={idx}
                  className="p-3.5 rounded-xl bg-gray-50 dark:bg-gray-800/40 border border-gray-200 dark:border-gray-700/60"
                >
                  <div className="flex items-start space-x-3">
                    {chk.ok ? (
                      <CheckCircle2 className="w-4 h-4 text-emerald-500 flex-shrink-0 mt-0.5" />
                    ) : (
                      <XCircle className="w-4 h-4 text-red-500 flex-shrink-0 mt-0.5" />
                    )}
                    <div className="flex-1">
                      <div className="flex items-center justify-between">
                        <span className="text-xs font-semibold text-gray-900 dark:text-white">{chk.name}</span>
                        <span
                          className={`text-[10px] px-2 py-0.5 rounded-full font-mono font-medium ${
                            chk.ok
                              ? "bg-emerald-500/10 text-emerald-600 dark:text-emerald-400"
                              : "bg-red-500/10 text-red-600 dark:text-red-400"
                          }`}
                        >
                          {chk.ok ? "PASS" : "FAIL"}
                        </span>
                      </div>
                      <p className="text-[11px] text-gray-500 dark:text-gray-400 mt-1 leading-relaxed">
                        {chk.hint}
                      </p>
                    </div>
                  </div>
                </div>
              ))}
            </div>
          </div>
        ) : null}

        <div className="flex items-center justify-between pt-5 mt-6 border-t border-gray-100 dark:border-gray-800">
          <button
            type="button"
            onClick={runPreflight}
            disabled={loading}
            className="flex items-center space-x-1.5 px-3.5 py-2 rounded-xl text-xs font-medium text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800 transition disabled:opacity-50"
          >
            <RefreshCw className={`w-3.5 h-3.5 ${loading ? "animate-spin" : ""}`} />
            <span>Re-run Checks</span>
          </button>

          <button
            type="button"
            onClick={onClose}
            className="px-5 py-2 rounded-xl bg-gray-200 dark:bg-gray-800 text-gray-800 dark:text-gray-200 text-xs font-semibold hover:bg-gray-300 dark:hover:bg-gray-700 transition"
          >
            {t("cancel")}
          </button>
        </div>
      </div>
    </div>
  );
}
