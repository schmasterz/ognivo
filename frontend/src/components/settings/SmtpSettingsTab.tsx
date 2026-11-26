"use client";

import React, { useState } from "react";
import {
  AlertCircle,
  Loader2,
  Mail,
  Lock,
  Eye,
  EyeOff,
  Send,
  CheckCircle2,
} from "lucide-react";
import { useI18n } from "@/lib/i18n/I18nContext";

interface SmtpSettingsTabProps {
  smtpEnabled: boolean;
  onToggleSmtpEnabled: () => void;
  smtpHost: string;
  onSmtpHostChange: (val: string) => void;
  smtpPort: number | string;
  onSmtpPortChange: (val: number | string) => void;
  smtpEncryption: "tls" | "ssl" | "none";
  onEncryptionChange: (enc: "tls" | "ssl" | "none") => void;
  smtpUsername: string;
  onSmtpUsernameChange: (val: string) => void;
  smtpPassword: string;
  onSmtpPasswordChange: (val: string) => void;
  hasPassword: boolean;
  smtpFromAddress: string;
  onSmtpFromAddressChange: (val: string) => void;
  smtpFromName: string;
  onSmtpFromNameChange: (val: string) => void;
  testRecipient: string;
  onTestRecipientChange: (val: string) => void;
  testingEmail: boolean;
  testResult: { success: boolean; message: string } | null;
  onSendTestEmail: () => void;
}

/**
 * Presentational tab panel for SMTP server configuration and live test email dispatch.
 */
export function SmtpSettingsTab({
  smtpEnabled,
  onToggleSmtpEnabled,
  smtpHost,
  onSmtpHostChange,
  smtpPort,
  onSmtpPortChange,
  smtpEncryption,
  onEncryptionChange,
  smtpUsername,
  onSmtpUsernameChange,
  smtpPassword,
  onSmtpPasswordChange,
  hasPassword,
  smtpFromAddress,
  onSmtpFromAddressChange,
  smtpFromName,
  onSmtpFromNameChange,
  testRecipient,
  onTestRecipientChange,
  testingEmail,
  testResult,
  onSendTestEmail,
}: SmtpSettingsTabProps) {
  const { t } = useI18n();
  const [showPassword, setShowPassword] = useState(false);

  return (
    <div className="space-y-5">
      {/* SMTP Enable / Disable Toggle Banner */}
      <div
        onClick={onToggleSmtpEnabled}
        className={`p-4 rounded-2xl border transition-all cursor-pointer flex items-start justify-between gap-4 ${
          smtpEnabled
            ? "bg-blue-500/5 border-blue-500/30 dark:bg-blue-950/20"
            : "bg-gray-50 dark:bg-gray-900/40 border-gray-200 dark:border-gray-800 opacity-60"
        }`}
      >
        <div className="flex items-start space-x-3.5 min-w-0">
          <div
            className={`p-2 rounded-xl mt-0.5 ${
              smtpEnabled
                ? "bg-blue-500/10 text-blue-600 dark:text-blue-400"
                : "bg-gray-200 dark:bg-gray-800 text-gray-400"
            }`}
          >
            <Mail className="w-4 h-4" />
          </div>
          <div>
            <div className="text-xs font-bold text-gray-900 dark:text-white">
              {t("smtpEnabled")}
            </div>
            <p className="text-[11px] text-gray-500 dark:text-gray-400 mt-0.5">
              {t("smtpDesc")}
            </p>
          </div>
        </div>

        <div
          className={`w-11 h-6 rounded-full transition-colors relative flex-shrink-0 p-0.5 ${
            smtpEnabled ? "bg-blue-600" : "bg-gray-300 dark:bg-gray-700"
          }`}
        >
          <div
            className={`w-5 h-5 rounded-full bg-white shadow-md transform transition-transform ${
              smtpEnabled ? "translate-x-5" : "translate-x-0"
            }`}
          />
        </div>
      </div>

      {/* Host and Encryption */}
      <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
        <div className="sm:col-span-2">
          <label className="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">
            {t("smtpHost")}
          </label>
          <input
            type="text"
            value={smtpHost}
            onChange={(e) => onSmtpHostChange(e.target.value)}
            placeholder="e.g. smtp.gmail.com or mail.example.com"
            className="w-full bg-gray-50 dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-xl px-3.5 py-2 text-xs text-gray-900 dark:text-white focus:outline-none focus:border-blue-500 transition font-mono"
          />
        </div>

        <div>
          <label className="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">
            {t("smtpEncryption")}
          </label>
          <select
            value={smtpEncryption}
            onChange={(e) => onEncryptionChange(e.target.value as "tls" | "ssl" | "none")}
            className="w-full bg-gray-50 dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-xl px-3 py-2 text-xs text-gray-900 dark:text-white focus:outline-none focus:border-blue-500 transition cursor-pointer"
          >
            <option value="tls">TLS (STARTTLS - 587)</option>
            <option value="ssl">SSL (SMTPS - 465)</option>
            <option value="none">None (Plain - 25)</option>
          </select>
        </div>
      </div>

      {/* Port and Username */}
      <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
        <div>
          <label className="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">
            {t("smtpPort")}
          </label>
          <input
            type="number"
            min="1"
            max="65535"
            value={smtpPort}
            onChange={(e) => onSmtpPortChange(e.target.value === "" ? "" : Number(e.target.value))}
            placeholder="587"
            className="w-full bg-gray-50 dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-xl px-3.5 py-2 text-xs text-gray-900 dark:text-white focus:outline-none focus:border-blue-500 transition font-mono"
          />
        </div>

        <div className="sm:col-span-2">
          <label className="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">
            {t("smtpUsername")}
          </label>
          <input
            type="text"
            value={smtpUsername}
            onChange={(e) => onSmtpUsernameChange(e.target.value)}
            placeholder="e.g. user@example.com or apikey"
            className="w-full bg-gray-50 dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-xl px-3.5 py-2 text-xs text-gray-900 dark:text-white focus:outline-none focus:border-blue-500 transition"
          />
        </div>
      </div>

      {/* Password */}
      <div>
        <div className="flex items-center justify-between mb-1">
          <label className="block text-xs font-medium text-gray-700 dark:text-gray-300">
            {t("smtpPassword")}
          </label>
          {hasPassword && (
            <span className="text-[10px] text-emerald-600 dark:text-emerald-400 font-medium bg-emerald-50 dark:bg-emerald-950/40 px-2 py-0.5 rounded-full">
              ✓ {t("smtpPasswordConfigured")}
            </span>
          )}
        </div>
        <div className="relative">
          <input
            type={showPassword ? "text" : "password"}
            value={smtpPassword}
            onChange={(e) => onSmtpPasswordChange(e.target.value)}
            placeholder={t("smtpPasswordPlaceholder")}
            className="w-full bg-gray-50 dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-xl px-3.5 py-2 pl-9 pr-9 text-xs text-gray-900 dark:text-white focus:outline-none focus:border-blue-500 transition font-mono"
          />
          <Lock className="w-4 h-4 text-gray-400 absolute left-3 top-2.5" />
          <button
            type="button"
            onClick={() => setShowPassword(!showPassword)}
            className="p-1 rounded-lg text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 absolute right-2.5 top-2 transition"
          >
            {showPassword ? <EyeOff className="w-3.5 h-3.5" /> : <Eye className="w-3.5 h-3.5" />}
          </button>
        </div>
      </div>

      {/* From Address and From Name */}
      <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
        <div>
          <label className="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">
            {t("smtpFromAddress")}
          </label>
          <input
            type="email"
            value={smtpFromAddress}
            onChange={(e) => onSmtpFromAddressChange(e.target.value)}
            placeholder="noreply@example.com"
            className="w-full bg-gray-50 dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-xl px-3.5 py-2 text-xs text-gray-900 dark:text-white focus:outline-none focus:border-blue-500 transition"
          />
        </div>

        <div>
          <label className="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">
            {t("smtpFromName")}
          </label>
          <input
            type="text"
            value={smtpFromName}
            onChange={(e) => onSmtpFromNameChange(e.target.value)}
            placeholder="Ognivo"
            className="w-full bg-gray-50 dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-xl px-3.5 py-2 text-xs text-gray-900 dark:text-white focus:outline-none focus:border-blue-500 transition"
          />
        </div>
      </div>

      {/* Live Test Email Section */}
      <div className="p-4 rounded-2xl bg-gray-50 dark:bg-gray-900/60 border border-gray-200 dark:border-gray-800 space-y-3 mt-4">
        <div>
          <div className="text-xs font-bold text-gray-900 dark:text-white flex items-center space-x-1.5">
            <Send className="w-3.5 h-3.5 text-blue-500" />
            <span>{t("testEmailTitle")}</span>
          </div>
          <p className="text-[11px] text-gray-500 dark:text-gray-400 mt-0.5">
            {t("testEmailDesc")}
          </p>
        </div>

        {testResult && (
          <div
            className={`p-3 rounded-xl border text-xs flex items-start space-x-2 ${
              testResult.success
                ? "bg-emerald-50 dark:bg-emerald-950/30 border-emerald-500/30 text-emerald-700 dark:text-emerald-300"
                : "bg-red-50 dark:bg-red-950/30 border-red-500/30 text-red-700 dark:text-red-300"
            }`}
          >
            {testResult.success ? (
              <CheckCircle2 className="w-4 h-4 text-emerald-500 flex-shrink-0 mt-0.5" />
            ) : (
              <AlertCircle className="w-4 h-4 text-red-500 flex-shrink-0 mt-0.5" />
            )}
            <span className="break-all">{testResult.message}</span>
          </div>
        )}

        <div className="flex flex-col sm:flex-row gap-2">
          <input
            type="email"
            value={testRecipient}
            onChange={(e) => onTestRecipientChange(e.target.value)}
            placeholder="recipient@example.com (optional)"
            className="flex-1 bg-white dark:bg-gray-950 border border-gray-200 dark:border-gray-800 rounded-xl px-3 py-2 text-xs text-gray-900 dark:text-white focus:outline-none focus:border-blue-500 transition"
          />
          <button
            type="button"
            onClick={onSendTestEmail}
            disabled={testingEmail || !smtpHost}
            className="px-4 py-2 rounded-xl text-xs font-semibold text-gray-700 dark:text-gray-200 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 hover:bg-gray-100 dark:hover:bg-gray-700 transition disabled:opacity-50 flex items-center justify-center space-x-1.5 shadow-sm"
          >
            {testingEmail ? (
              <>
                <Loader2 className="w-3.5 h-3.5 animate-spin text-blue-500" />
                <span>{t("testEmailSending")}</span>
              </>
            ) : (
              <>
                <Send className="w-3.5 h-3.5 text-blue-500" />
                <span>{t("testEmailAction")}</span>
              </>
            )}
          </button>
        </div>
      </div>
    </div>
  );
}
