"use client";

import React, { useState } from "react";
import { X, UserPlus, Shield, User, Mail, Lock, Loader2, CheckCircle2, Layers, Sparkles, Eye, EyeOff } from "lucide-react";
import { api, AdminUserItem, QuotaGroup } from "@/lib/api";
import { useI18n } from "@/lib/i18n/I18nContext";
import { useModalEscape } from "@/lib/hooks/useModalEscape";
import {
  QuotaLimitsFormSection,
  QuotaLimitsFormValue,
  quotaFormValueToPayload,
  quotaRecordToFormValue,
} from "../quotas/QuotaLimitsFormSection";

interface CreateUserModalProps {
  quotaGroups?: QuotaGroup[];
  onClose: () => void;
  onSuccess: (newUser: AdminUserItem) => void;
}

const USER_QUOTA_DEFAULTS: Partial<QuotaLimitsFormValue> = {
  maxVms: 2,
  maxCpu: 4,
  maxRamGb: 4,
  maxStorageGb: 80,
  maxIps: 1,
  maxSnapshots: 3,
};

const PRESET_SELECT_DEFAULTS: Partial<QuotaLimitsFormValue> = {
  maxVms: 1,
  maxCpu: 1,
  maxRamGb: 1,
  maxStorageGb: 10,
  maxIps: 1,
  maxSnapshots: 3,
};

export function CreateUserModal({ quotaGroups = [], onClose, onSuccess }: CreateUserModalProps) {
  const { t } = useI18n();

  const [name, setName] = useState("");
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [showPassword, setShowPassword] = useState(false);
  const [role, setRole] = useState<"customer" | "admin">("customer");
  const [status, setStatus] = useState<"active" | "suspended" | "inactive" | "pending">("active");

  const defaultGroup = quotaGroups.find((g) => g.is_default);
  const [selectedGroupId, setSelectedGroupId] = useState<number | "custom">(
    defaultGroup ? defaultGroup.id : "custom"
  );

  const [quotaLimits, setQuotaLimits] = useState<QuotaLimitsFormValue>(() =>
    quotaRecordToFormValue(defaultGroup, USER_QUOTA_DEFAULTS)
  );

  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useModalEscape(onClose, !loading);

  const handleGroupSelect = (val: string) => {
    if (val === "custom") {
      setSelectedGroupId("custom");
      return;
    }

    const gId = parseInt(val, 10);
    setSelectedGroupId(gId);

    const group = quotaGroups.find((g) => g.id === gId);
    if (group) {
      setQuotaLimits(quotaRecordToFormValue(group, PRESET_SELECT_DEFAULTS));
    }
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setError(null);

    try {
      setLoading(true);

      const quotaPayload = quotaFormValueToPayload(quotaLimits);

      const res = await api.adminCreateUser({
        name,
        email,
        password,
        role,
        status,
        quota_group_id: selectedGroupId === "custom" ? undefined : selectedGroupId,
        quota: quotaPayload,
      });

      onSuccess(res.user);
      onClose();
    } catch (err: unknown) {
      if (err instanceof Error) {
        setError(err.message);
      } else {
        setError("Failed to create user.");
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
            <div className="p-2.5 rounded-2xl bg-blue-500/10 text-blue-600 dark:text-blue-400">
              <UserPlus className="w-6 h-6" />
            </div>
            <div>
              <h2 className="text-xl font-bold text-gray-900 dark:text-white tracking-tight">{t("createUser")}</h2>
              <p className="text-xs text-gray-500 dark:text-gray-400">{t("usersSubtitle")}</p>
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

        <form onSubmit={handleSubmit} className="space-y-5">
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <label className="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1.5">
                {t("fullName")}
              </label>
              <div className="relative">
                <input
                  type="text"
                  required
                  value={name}
                  onChange={(e) => setName(e.target.value)}
                  placeholder="e.g. Maria Ivanova"
                  className="w-full pl-10 pr-4 py-2.5 rounded-xl bg-gray-50 dark:bg-gray-800/60 border border-gray-200 dark:border-gray-700 text-sm text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500/50"
                />
                <User className="w-4 h-4 text-gray-400 absolute left-3.5 top-3" />
              </div>
            </div>

            <div>
              <label className="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1.5">
                {t("emailAddress")}
              </label>
              <div className="relative">
                <input
                  type="email"
                  required
                  value={email}
                  onChange={(e) => setEmail(e.target.value)}
                  placeholder="user@example.com"
                  className="w-full pl-10 pr-4 py-2.5 rounded-xl bg-gray-50 dark:bg-gray-800/60 border border-gray-200 dark:border-gray-700 text-sm text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500/50"
                />
                <Mail className="w-4 h-4 text-gray-400 absolute left-3.5 top-3" />
              </div>
            </div>
          </div>

          <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <div>
              <label className="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1.5">
                {t("password")}
              </label>
              <div className="relative">
                <input
                  type={showPassword ? "text" : "password"}
                  required
                  value={password}
                  onChange={(e) => setPassword(e.target.value)}
                  placeholder="Min. 8 characters"
                  className="w-full pl-10 pr-10 py-2.5 rounded-xl bg-gray-50 dark:bg-gray-800/60 border border-gray-200 dark:border-gray-700 text-sm text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500/50"
                />
                <Lock className="w-4 h-4 text-gray-400 absolute left-3.5 top-3" />
                <button
                  type="button"
                  onClick={() => setShowPassword(!showPassword)}
                  title={showPassword ? t("hidePassword") : t("showPassword")}
                  className="p-1 rounded-lg text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 absolute right-2.5 top-2.5 transition"
                >
                  {showPassword ? <EyeOff className="w-4 h-4" /> : <Eye className="w-4 h-4" />}
                </button>
              </div>
            </div>

            <div>
              <label className="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1.5">
                {t("role")}
              </label>
              <div className="relative">
                <select
                  value={role}
                  onChange={(e) => setRole(e.target.value as "customer" | "admin")}
                  className="w-full pl-10 pr-4 py-2.5 rounded-xl bg-gray-50 dark:bg-gray-800/60 border border-gray-200 dark:border-gray-700 text-sm text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500/50 appearance-none"
                >
                  <option value="customer">{t("roleCustomer")}</option>
                  <option value="admin">{t("roleAdmin")}</option>
                </select>
                <Shield className="w-4 h-4 text-gray-400 absolute left-3.5 top-3 pointer-events-none" />
              </div>
            </div>

            <div>
              <label className="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1.5">
                {t("userStatus")}
              </label>
              <div className="relative">
                <select
                  value={status}
                  onChange={(e) => setStatus(e.target.value as "active" | "suspended" | "inactive" | "pending")}
                  className="w-full pl-10 pr-4 py-2.5 rounded-xl bg-gray-50 dark:bg-gray-800/60 border border-gray-200 dark:border-gray-700 text-sm text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500/50 appearance-none"
                >
                  <option value="active">{t("statusActive")}</option>
                  <option value="pending">{t("statusPending")}</option>
                  <option value="suspended">{t("statusSuspended")}</option>
                  <option value="inactive">{t("statusInactive")}</option>
                </select>
                <CheckCircle2 className="w-4 h-4 text-gray-400 absolute left-3.5 top-3 pointer-events-none" />
              </div>
            </div>
          </div>

          {/* Quota Group Preset Selection */}
          {quotaGroups.length > 0 && (
            <div>
              <label className="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1.5">
                {t("quotaGroup")}
              </label>
              <div className="relative">
                <select
                  value={selectedGroupId}
                  onChange={(e) => handleGroupSelect(e.target.value)}
                  className="w-full pl-10 pr-4 py-2.5 rounded-xl bg-indigo-50/50 dark:bg-indigo-950/20 border border-indigo-200 dark:border-indigo-800 text-sm text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-indigo-500/50 appearance-none font-medium"
                >
                  {quotaGroups.map((g) => (
                    <option key={g.id} value={g.id}>
                      {g.name} {g.is_default ? `(${t("defaultGroupBadge")})` : ""}
                    </option>
                  ))}
                  <option value="custom">-- {t("noQuotaGroup")} --</option>
                </select>
                <Layers className="w-4 h-4 text-indigo-500 absolute left-3.5 top-3 pointer-events-none" />
              </div>
            </div>
          )}

          {/* Quotas Box */}
          <div className="p-4 rounded-2xl bg-gray-50 dark:bg-gray-800/40 border border-gray-200 dark:border-gray-700/60 space-y-4">
            <div className="flex items-center justify-between">
              <h3 className="text-xs font-bold uppercase tracking-wider text-gray-700 dark:text-gray-300">
                {t("customQuotas")}
              </h3>
              <span className="text-[11px] text-gray-500 dark:text-gray-400">
                Support for infinite / unlimited allocation
              </span>
            </div>

            {selectedGroupId !== "custom" ? (
              <div className="p-2.5 rounded-xl bg-amber-500/10 border border-amber-500/20 text-xs text-amber-700 dark:text-amber-400 flex items-center space-x-2">
                <Lock className="w-4 h-4 flex-shrink-0" />
                <span>{t("quotasLockedNotice")}</span>
              </div>
            ) : (
              <div className="p-2.5 rounded-xl bg-blue-500/10 border border-blue-500/20 text-xs text-blue-700 dark:text-blue-400 flex items-center space-x-2">
                <Sparkles className="w-4 h-4 flex-shrink-0" />
                <span>{t("customQuotaNotice")}</span>
              </div>
            )}

            <QuotaLimitsFormSection
              value={quotaLimits}
              onChange={setQuotaLimits}
              disabled={selectedGroupId !== "custom"}
              columns={3}
            />
          </div>

          <div className="flex items-center justify-end space-x-3 pt-3">
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
              className="px-5 py-2.5 rounded-xl bg-blue-600 hover:bg-blue-500 text-white text-xs font-semibold shadow-md shadow-blue-500/20 transition flex items-center space-x-2 disabled:opacity-50"
            >
              {loading ? (
                <>
                  <Loader2 className="w-4 h-4 animate-spin" />
                  <span>{t("saving")}</span>
                </>
              ) : (
                <>
                  <CheckCircle2 className="w-4 h-4" />
                  <span>{t("createUser")}</span>
                </>
              )}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}
