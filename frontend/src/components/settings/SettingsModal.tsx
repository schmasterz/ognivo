"use client";

import React, { useState, useEffect, useRef } from "react";
import { X, Settings, Check, AlertCircle, Loader2, Shield, Mail } from "lucide-react";
import { api, SystemSettings } from "@/lib/api";
import { useI18n } from "@/lib/i18n/I18nContext";
import { useModalEscape } from "@/lib/hooks/useModalEscape";
import { GeneralSettingsTab } from "./GeneralSettingsTab";
import { SmtpSettingsTab } from "./SmtpSettingsTab";
import { useSmtpTest } from "./useSmtpTest";
import {
  AdminSettingsFormValues, SmtpEncryption, buildAdminSettingsPayload,
  canToggleVirtualizationMode, getDefaultPortForEncryption, isVirtualizationConfigValid,
} from "./settingsSchema";

interface SettingsModalProps {
  isOpen: boolean;
  onClose: () => void;
  settings: SystemSettings;
  onSuccess: (updated: SystemSettings) => void;
}

function getInitialFormValues(settings: SystemSettings): AdminSettingsFormValues {
  return {
    allowVm: settings.allow_virtual_machines,
    allowContainers: settings.allow_containers,
    registrationEnabled: settings.registration_enabled,
    frontendUrl: settings.frontend_url ?? "",
    smtpEnabled: settings.smtp?.enabled ?? true,
    smtpHost: settings.smtp?.host ?? "",
    smtpPort: settings.smtp?.port ?? 587,
    smtpEncryption: settings.smtp?.encryption ?? "tls",
    smtpUsername: settings.smtp?.username ?? "",
    smtpPassword: "",
    smtpFromAddress: settings.smtp?.from_address ?? "",
    smtpFromName: settings.smtp?.from_name ?? "",
  };
}

export function SettingsModal({ isOpen, onClose, settings, onSuccess }: SettingsModalProps) {
  const { t } = useI18n();
  const [activeTab, setActiveTab] = useState<"general" | "smtp">("general");
  const [form, setForm] = useState<AdminSettingsFormValues>(() => getInitialFormValues(settings));
  const [hasPassword, setHasPassword] = useState(settings.smtp?.has_password ?? false);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const { testRecipient, setTestRecipient, testingEmail, testResult, sendTestEmail, resetTestResult } =
    useSmtpTest(t("testEmailSuccess"));

  useModalEscape(onClose, isOpen && !saving && !testingEmail);

  const prevIsOpenRef = useRef(false);
  useEffect(() => {
    // Only re-initialize form state when modal transitions from closed to open
    if (isOpen && !prevIsOpenRef.current) {
      setForm(getInitialFormValues(settings));
      setHasPassword(settings.smtp?.has_password ?? false);
      setError(null);
      resetTestResult();
    }
    prevIsOpenRef.current = isOpen;
  }, [isOpen, settings, resetTestResult]);

  if (!isOpen) return null;

  const setField = <K extends keyof AdminSettingsFormValues>(
    key: K,
    value: AdminSettingsFormValues[K]
  ) => setForm((prev) => ({ ...prev, [key]: value }));

  const handleToggleMode = (mode: "allowVm" | "allowContainers") => {
    const other = mode === "allowVm" ? form.allowContainers : form.allowVm;
    if (!canToggleVirtualizationMode(form[mode], other)) {
      setError(t("atLeastOneModeRequired"));
      return;
    }
    setError(null);
    setField(mode, !form[mode]);
  };

  const handleEncryptionChange = (enc: SmtpEncryption) => {
    setForm((prev) => ({ ...prev, smtpEncryption: enc, smtpPort: getDefaultPortForEncryption(enc) }));
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!isVirtualizationConfigValid(form.allowVm, form.allowContainers)) {
      setError(t("atLeastOneModeRequired"));
      return;
    }
    try {
      setSaving(true);
      setError(null);
      const res = await api.updateAdminSettings(buildAdminSettingsPayload(form));
      onSuccess(res.settings);
      onClose();
    } catch (err: unknown) {
      setError(err instanceof Error ? err.message : "Failed to update system settings.");
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-sm animate-fadeIn">
      <div className="bg-white dark:bg-[#0e131f] border border-gray-200 dark:border-gray-800 rounded-3xl w-full max-w-2xl shadow-2xl overflow-hidden flex flex-col max-h-[92vh]">
        {/* Header */}
        <div className="p-6 border-b border-gray-200 dark:border-gray-800/80 flex items-center justify-between">
          <div className="flex items-center space-x-3">
            <div className="p-2.5 bg-blue-500/10 text-blue-600 dark:text-blue-400 rounded-2xl border border-blue-500/20">
              <Settings className="w-5 h-5" />
            </div>
            <div>
              <h2 className="text-base font-bold text-gray-900 dark:text-white tracking-tight">{t("settingsTitle")}</h2>
              <p className="text-xs text-gray-500 dark:text-gray-400 mt-0.5">{t("settingsSubtitle")}</p>
            </div>
          </div>
          <button onClick={onClose} className="p-2 rounded-xl text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-800 transition">
            <X className="w-4 h-4" />
          </button>
        </div>

        {/* Navigation Tabs */}
        <div className="flex border-b border-gray-200 dark:border-gray-800 px-6 bg-gray-50/50 dark:bg-gray-900/30">
          <button
            type="button"
            onClick={() => setActiveTab("general")}
            className={`py-3 px-4 text-xs font-semibold border-b-2 flex items-center space-x-2 transition ${
              activeTab === "general"
                ? "border-blue-600 text-blue-600 dark:text-blue-400"
                : "border-transparent text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200"
            }`}
          >
            <Shield className="w-4 h-4" />
            <span>{t("tabGeneral")}</span>
          </button>
          <button
            type="button"
            onClick={() => setActiveTab("smtp")}
            className={`py-3 px-4 text-xs font-semibold border-b-2 flex items-center space-x-2 transition ${
              activeTab === "smtp"
                ? "border-blue-600 text-blue-600 dark:text-blue-400"
                : "border-transparent text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200"
            }`}
          >
            <Mail className="w-4 h-4" />
            <span>{t("tabSmtp")}</span>
            {form.smtpEnabled && <span className="w-2 h-2 rounded-full bg-emerald-500 animate-pulse" />}
          </button>
        </div>

        {/* Content Body */}
        <form onSubmit={handleSubmit} className="p-6 space-y-6 overflow-y-auto flex-1">
          {error && (
            <div className="p-3.5 bg-red-500/10 border border-red-500/20 rounded-2xl flex items-center space-x-2 text-xs text-red-600 dark:text-red-400">
              <AlertCircle className="w-4 h-4 flex-shrink-0" />
              <span>{error}</span>
            </div>
          )}

          {activeTab === "general" && (
            <GeneralSettingsTab
              allowVm={form.allowVm}
              allowContainers={form.allowContainers}
              registrationEnabled={form.registrationEnabled}
              frontendUrl={form.frontendUrl}
              onToggleVm={() => handleToggleMode("allowVm")}
              onToggleContainers={() => handleToggleMode("allowContainers")}
              onToggleRegistration={() => setField("registrationEnabled", !form.registrationEnabled)}
              onFrontendUrlChange={(val) => setField("frontendUrl", val)}
            />
          )}

          {activeTab === "smtp" && (
            <SmtpSettingsTab
              smtpEnabled={form.smtpEnabled} onToggleSmtpEnabled={() => setField("smtpEnabled", !form.smtpEnabled)}
              smtpHost={form.smtpHost} onSmtpHostChange={(val) => setField("smtpHost", val)}
              smtpPort={form.smtpPort} onSmtpPortChange={(val) => setField("smtpPort", val)}
              smtpEncryption={form.smtpEncryption} onEncryptionChange={handleEncryptionChange}
              smtpUsername={form.smtpUsername} onSmtpUsernameChange={(val) => setField("smtpUsername", val)}
              smtpPassword={form.smtpPassword} onSmtpPasswordChange={(val) => setField("smtpPassword", val)}
              hasPassword={hasPassword}
              smtpFromAddress={form.smtpFromAddress} onSmtpFromAddressChange={(val) => setField("smtpFromAddress", val)}
              smtpFromName={form.smtpFromName} onSmtpFromNameChange={(val) => setField("smtpFromName", val)}
              testRecipient={testRecipient} onTestRecipientChange={setTestRecipient}
              testingEmail={testingEmail} testResult={testResult} onSendTestEmail={sendTestEmail}
            />
          )}

          {/* Footer Controls */}
          <div className="pt-4 border-t border-gray-100 dark:border-gray-800/80 flex items-center justify-end space-x-2.5">
            <button
              type="button"
              onClick={onClose}
              disabled={saving}
              className="px-4 py-2 rounded-xl text-xs font-semibold text-gray-600 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-800 transition"
            >
              {t("cancel")}
            </button>
            <button
              type="submit"
              disabled={saving}
              className="px-5 py-2 rounded-xl text-xs font-semibold text-white bg-blue-600 hover:bg-blue-500 transition shadow-md shadow-blue-500/25 flex items-center space-x-2 disabled:opacity-50"
            >
              {saving ? <Loader2 className="w-4 h-4 animate-spin" /> : <Check className="w-4 h-4" />}
              <span>{t("saveSettings")}</span>
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}
