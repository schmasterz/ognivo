"use client";

import React, { useState, useEffect } from "react";
import { X, Camera, AlertCircle, Loader2, Info } from "lucide-react";
import { VirtualMachine, VmSnapshot, api, ApiError } from "@/lib/api";
import { useI18n } from "@/lib/i18n/I18nContext";
import { useToast } from "@/lib/toast/ToastContext";

interface CreateSnapshotModalProps {
  isOpen: boolean;
  onClose: () => void;
  onCreated: (snapshot: VmSnapshot) => void;
  vm: VirtualMachine;
  usedCount: number;
  maxSnapshots: number; // -1 represents infinite/unlimited
  remaining: number;
}

const SNAPSHOT_NAME_REGEX = /^[a-z0-9](?:[a-z0-9_-]{0,61}[a-z0-9])?$/;

export function CreateSnapshotModal({
  isOpen,
  onClose,
  onCreated,
  vm,
  usedCount,
  maxSnapshots,
  remaining,
}: CreateSnapshotModalProps) {
  const { t } = useI18n();
  const { toast } = useToast();

  const [name, setName] = useState("");
  const [stateful, setStateful] = useState(false);
  const [nameError, setNameError] = useState<string | null>(null);
  const [serverError, setServerError] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);

  // Reset form when modal opens
  useEffect(() => {
    if (isOpen) {
      setName("");
      setStateful(false);
      setNameError(null);
      setServerError(null);
      setLoading(false);
    }
  }, [isOpen]);

  if (!isOpen) return null;

  const isUnlimited = maxSnapshots === -1;
  const isQuotaExceeded = !isUnlimited && remaining <= 0;
  const isVmRunning = vm.status.toLowerCase() === "running";

  const handleNameChange = (val: string) => {
    const clean = val.toLowerCase().trim();
    setName(clean);
    setServerError(null);

    if (clean.length === 0) {
      setNameError(null);
      return;
    }

    if (!SNAPSHOT_NAME_REGEX.test(clean)) {
      setNameError(t("snapshotNameInvalid"));
    } else {
      setNameError(null);
    }
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();

    if (!name.trim()) {
      setNameError(t("snapshotNameInvalid"));
      return;
    }

    if (!SNAPSHOT_NAME_REGEX.test(name.trim())) {
      setNameError(t("snapshotNameInvalid"));
      return;
    }

    if (isQuotaExceeded) {
      setServerError(t("snapshotQuotaExceeded"));
      return;
    }

    setLoading(true);
    setServerError(null);

    try {
      const res = await api.createSnapshot(vm.id, {
        name: name.trim(),
        stateful: stateful && isVmRunning,
      });

      toast.success(res.message || t("snapshotCreateSuccess"));
      onCreated(res.snapshot);
      onClose();
    } catch (err: unknown) {
      if (err instanceof ApiError) {
        if (err.errors?.quota && err.errors.quota.length > 0) {
          setServerError(err.errors.quota[0]);
        } else if (err.errors?.name && err.errors.name.length > 0) {
          setNameError(err.errors.name[0]);
        } else {
          setServerError(err.message || t("snapshotCreateError"));
        }
      } else if (err instanceof Error) {
        setServerError(err.message);
      } else {
        setServerError(t("snapshotCreateError"));
      }
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 dark:bg-black/80 backdrop-blur-sm animate-in fade-in duration-200">
      <div className="bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 rounded-2xl w-full max-w-lg overflow-hidden shadow-2xl flex flex-col">
        {/* Header */}
        <div className="flex items-center justify-between px-6 py-4 border-b border-zinc-200 dark:border-zinc-800">
          <div className="flex items-center space-x-3">
            <div className="p-2 rounded-xl bg-blue-500/10 text-blue-600 dark:text-blue-400">
              <Camera className="w-5 h-5" />
            </div>
            <div>
              <h3 className="text-base font-semibold text-zinc-900 dark:text-zinc-100">
                {t("createSnapshotModalTitle")}
              </h3>
              <p className="text-xs text-zinc-500 dark:text-zinc-400">
                {vm.name} &bull; {t("createSnapshotModalSubtitle")}
              </p>
            </div>
          </div>
          <button
            onClick={onClose}
            disabled={loading}
            className="p-1 rounded-lg text-zinc-400 hover:text-zinc-700 dark:hover:text-zinc-200 hover:bg-zinc-100 dark:hover:bg-zinc-800 transition"
          >
            <X className="w-5 h-5" />
          </button>
        </div>

        {/* Form Body */}
        <form onSubmit={handleSubmit} className="p-6 space-y-4">
          {/* Quota Indicator Banner */}
          <div
            className={`p-3 rounded-xl border flex items-center justify-between text-xs ${
              isQuotaExceeded
                ? "bg-red-500/10 border-red-500/20 text-red-700 dark:text-red-400"
                : "bg-zinc-100 dark:bg-zinc-800/50 border-zinc-200 dark:border-zinc-700/50 text-zinc-600 dark:text-zinc-300"
            }`}
          >
            <div className="flex items-center space-x-2">
              <Info className="w-4 h-4 shrink-0 text-blue-500" />
              <span>{t("snapshotQuota")}:</span>
            </div>
            <div className="font-mono font-medium">
              <span>{usedCount}</span> /{" "}
              <span>{isUnlimited ? t("snapshotQuotaUnlimited") : maxSnapshots}</span>
              {!isUnlimited && (
                <span className="ml-1 text-[11px] text-zinc-400">
                  ({remaining} remaining)
                </span>
              )}
            </div>
          </div>

          {/* Quota Error / General Error */}
          {(isQuotaExceeded || serverError) && (
            <div className="p-3 rounded-xl bg-red-500/10 border border-red-500/20 text-red-600 dark:text-red-400 text-xs flex items-start space-x-2">
              <AlertCircle className="w-4 h-4 shrink-0 mt-0.5" />
              <span>{serverError || t("snapshotQuotaExceeded")}</span>
            </div>
          )}

          {/* Snapshot Name Input */}
          <div>
            <label className="block text-xs font-medium text-zinc-700 dark:text-zinc-300 mb-1.5">
              {t("snapshotNameLabel")} <span className="text-red-500">*</span>
            </label>
            <input
              type="text"
              value={name}
              onChange={(e) => handleNameChange(e.target.value)}
              placeholder={t("snapshotNamePlaceholder")}
              maxLength={63}
              autoFocus
              disabled={loading || isQuotaExceeded}
              className={`w-full px-3.5 py-2.5 rounded-xl border bg-zinc-50 dark:bg-zinc-800/80 text-zinc-900 dark:text-zinc-100 text-sm font-mono focus:outline-none focus:ring-2 transition ${
                nameError
                  ? "border-red-500/50 focus:ring-red-500/20"
                  : "border-zinc-300 dark:border-zinc-700 focus:ring-blue-500/20 focus:border-blue-500"
              } disabled:opacity-50`}
            />
            {nameError ? (
              <p className="mt-1 text-xs text-red-500 flex items-center space-x-1">
                <AlertCircle className="w-3.5 h-3.5 shrink-0" />
                <span>{nameError}</span>
              </p>
            ) : (
              <p className="mt-1 text-[11px] text-zinc-400 dark:text-zinc-500">
                {t("snapshotNameHelp")}
              </p>
            )}
          </div>

          {/* Stateful Checkbox */}
          <div className="pt-1">
            <label
              className={`flex items-start space-x-3 p-3 rounded-xl border border-zinc-200 dark:border-zinc-800 bg-zinc-50 dark:bg-zinc-800/40 cursor-pointer select-none ${
                !isVmRunning ? "opacity-50 cursor-not-allowed" : ""
              }`}
            >
              <input
                type="checkbox"
                checked={stateful && isVmRunning}
                onChange={(e) => isVmRunning && setStateful(e.target.checked)}
                disabled={!isVmRunning || loading || isQuotaExceeded}
                className="mt-0.5 h-4 w-4 rounded border-zinc-300 text-blue-600 focus:ring-blue-500 dark:border-zinc-700 dark:bg-zinc-800"
              />
              <div className="flex-1">
                <div className="text-xs font-medium text-zinc-900 dark:text-zinc-100">
                  {t("snapshotStatefulLabel")}
                </div>
                <div className="text-[11px] text-zinc-500 dark:text-zinc-400 mt-0.5">
                  {t("snapshotStatefulHelp")}
                </div>
              </div>
            </label>
          </div>

          {/* Actions */}
          <div className="flex items-center justify-end space-x-3 pt-3 border-t border-zinc-200 dark:border-zinc-800">
            <button
              type="button"
              onClick={onClose}
              disabled={loading}
              className="px-4 py-2 rounded-xl text-sm font-medium text-zinc-700 dark:text-zinc-300 hover:bg-zinc-100 dark:hover:bg-zinc-800 transition"
            >
              {t("close")}
            </button>
            <button
              type="submit"
              disabled={loading || !name.trim() || !!nameError || isQuotaExceeded}
              className="flex items-center space-x-2 px-5 py-2 rounded-xl text-sm font-medium bg-blue-600 hover:bg-blue-500 text-white shadow-lg shadow-blue-600/25 transition disabled:opacity-50 disabled:cursor-not-allowed"
            >
              {loading ? (
                <>
                  <Loader2 className="w-4 h-4 animate-spin" />
                  <span>{t("creatingSnapshot")}</span>
                </>
              ) : (
                <>
                  <Camera className="w-4 h-4" />
                  <span>{t("createSnapshot")}</span>
                </>
              )}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}
