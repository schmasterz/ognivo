"use client";

import React, { useState, useEffect, useRef } from "react";
import {
  X,
  Settings,
  Server,
  UserCheck,
  Check,
  AlertCircle,
  Loader2,
  Shield,
  Mail,
  Lock,
  Eye,
  EyeOff,
  Send,
  CheckCircle2,
  Globe,
} from "lucide-react";
import { api, SystemSettings } from "@/lib/api";
import { useI18n } from "@/lib/i18n/I18nContext";

interface SettingsModalProps {
  isOpen: boolean;
  onClose: () => void;
  settings: SystemSettings;
  onSuccess: (updated: SystemSettings) => void;
}

export function SettingsModal({
  isOpen,
  onClose,
  settings,
  onSuccess,
}: SettingsModalProps) {
  const { t } = useI18n();
  const [activeTab, setActiveTab] = useState<"general" | "smtp">("general");

  // Platform & Virtualization state
  const [allowVm, setAllowVm] = useState(settings.allow_virtual_machines);
  const [allowContainers, setAllowContainers] = useState(settings.allow_containers);
  const [registrationEnabled, setRegistrationEnabled] = useState(settings.registration_enabled);
  const [frontendUrl, setFrontendUrl] = useState(settings.frontend_url ?? "");

  // SMTP Settings state
  const [smtpEnabled, setSmtpEnabled] = useState(settings.smtp?.enabled ?? false);
  const [smtpHost, setSmtpHost] = useState(settings.smtp?.host ?? "");
  const [smtpPort, setSmtpPort] = useState<number | string>(settings.smtp?.port ?? 587);
  const [smtpEncryption, setSmtpEncryption] = useState<"tls" | "ssl" | "none">(
    settings.smtp?.encryption ?? "tls"
  );
  const [smtpUsername, setSmtpUsername] = useState(settings.smtp?.username ?? "");
  const [smtpPassword, setSmtpPassword] = useState("");
  const [showPassword, setShowPassword] = useState(false);
  const [hasPassword, setHasPassword] = useState(settings.smtp?.has_password ?? false);
  const [smtpFromAddress, setSmtpFromAddress] = useState(settings.smtp?.from_address ?? "");
  const [smtpFromName, setSmtpFromName] = useState(settings.smtp?.from_name ?? "");

  // Test Email state
  const [testRecipient, setTestRecipient] = useState("");
  const [testingEmail, setTestingEmail] = useState(false);
  const [testResult, setTestResult] = useState<{ success: boolean; message: string } | null>(null);

  // Form submission state
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const prevIsOpenRef = useRef(false);

  useEffect(() => {
    // Only re-initialize form state when modal transitions from closed to open
    if (isOpen && !prevIsOpenRef.current) {
      setAllowVm(settings.allow_virtual_machines);
      setAllowContainers(settings.allow_containers);
      setRegistrationEnabled(settings.registration_enabled);
      setFrontendUrl(settings.frontend_url ?? "");

      setSmtpEnabled(settings.smtp?.enabled ?? false);
      setSmtpHost(settings.smtp?.host ?? "");
      setSmtpPort(settings.smtp?.port ?? 587);
      setSmtpEncryption(settings.smtp?.encryption ?? "tls");
      setSmtpUsername(settings.smtp?.username ?? "");
      setSmtpPassword("");
      setHasPassword(settings.smtp?.has_password ?? false);
      setSmtpFromAddress(settings.smtp?.from_address ?? "");
      setSmtpFromName(settings.smtp?.from_name ?? "");

      setError(null);
      setTestResult(null);
    }
    prevIsOpenRef.current = isOpen;
  }, [isOpen, settings]);

  if (!isOpen) return null;

  const handleToggleVm = () => {
    if (allowVm && !allowContainers) {
      setError(t("atLeastOneModeRequired"));
      return;
    }
    setError(null);
    setAllowVm(!allowVm);
  };

  const handleToggleContainers = () => {
    if (allowContainers && !allowVm) {
      setError(t("atLeastOneModeRequired"));
      return;
    }
    setError(null);
    setAllowContainers(!allowContainers);
  };

  const handleEncryptionChange = (enc: "tls" | "ssl" | "none") => {
    setSmtpEncryption(enc);
    if (enc === "ssl") {
      setSmtpPort(465);
    } else if (enc === "tls") {
      setSmtpPort(587);
    } else if (enc === "none") {
      setSmtpPort(25);
    }
  };

  const handleSendTestEmail = async () => {
    setTestingEmail(true);
    setTestResult(null);
    try {
      const res = await api.testSmtp(testRecipient.trim() || undefined);
      setTestResult({
        success: true,
        message: res.message || t("testEmailSuccess"),
      });
    } catch (err: unknown) {
      setTestResult({
        success: false,
        message: err instanceof Error ? err.message : "Failed to send test email.",
      });
    } finally {
      setTestingEmail(false);
    }
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!allowVm && !allowContainers) {
      setError(t("atLeastOneModeRequired"));
      return;
    }

    try {
      setSaving(true);
      setError(null);
      const res = await api.updateAdminSettings({
        registration_enabled: registrationEnabled,
        allow_virtual_machines: allowVm,
        allow_containers: allowContainers,
        frontend_url: frontendUrl.trim() || undefined,
        smtp: {
          enabled: smtpEnabled,
          host: smtpHost.trim() || null,
          port: Number(smtpPort) || 587,
          encryption: smtpEncryption,
          username: smtpUsername.trim() || null,
          password: smtpPassword ? smtpPassword : null,
          from_address: smtpFromAddress.trim() || null,
          from_name: smtpFromName.trim() || null,
        },
      });
      onSuccess(res.settings);
      onClose();
    } catch (err: unknown) {
      if (err instanceof Error) {
        setError(err.message);
      } else {
        setError("Failed to update system settings.");
      }
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
              <h2 className="text-base font-bold text-gray-900 dark:text-white tracking-tight">
                {t("settingsTitle")}
              </h2>
              <p className="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                {t("settingsSubtitle")}
              </p>
            </div>
          </div>
          <button
            onClick={onClose}
            className="p-2 rounded-xl text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-800 transition"
          >
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
            {smtpEnabled && (
              <span className="w-2 h-2 rounded-full bg-emerald-500 animate-pulse" />
            )}
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
            <div className="space-y-6">
              {/* Section 1: Virtualization Modes */}
              <div className="space-y-3">
                <div className="flex items-center space-x-2 text-xs font-semibold uppercase tracking-wider text-purple-600 dark:text-purple-400">
                  <Shield className="w-3.5 h-3.5" />
                  <span>{t("virtualizationModes")}</span>
                </div>
                <p className="text-xs text-gray-500 dark:text-gray-400">
                  {t("virtualizationModesDesc")}
                </p>

                <div className="space-y-3 pt-1">
                  {/* Full Virtual Machine Switch */}
                  <div
                    onClick={handleToggleVm}
                    className={`p-4 rounded-2xl border transition-all cursor-pointer flex items-start justify-between gap-4 ${
                      allowVm
                        ? "bg-blue-500/5 border-blue-500/30 dark:bg-blue-950/20"
                        : "bg-gray-50 dark:bg-gray-900/40 border-gray-200 dark:border-gray-800 opacity-60"
                    }`}
                  >
                    <div className="flex items-start space-x-3.5 min-w-0">
                      <div
                        className={`p-2 rounded-xl mt-0.5 ${
                          allowVm
                            ? "bg-blue-500/10 text-blue-600 dark:text-blue-400"
                            : "bg-gray-200 dark:bg-gray-800 text-gray-400"
                        }`}
                      >
                        <Server className="w-4 h-4" />
                      </div>
                      <div>
                        <div className="text-xs font-bold text-gray-900 dark:text-white flex items-center space-x-2">
                          <span>{t("fullVm")}</span>
                          {allowVm && !allowContainers && (
                            <span className="text-[10px] px-2 py-0.2 rounded-full font-normal bg-blue-100 dark:bg-blue-900/40 text-blue-700 dark:text-blue-300">
                              {t("atLeastOneModeRequired")}
                            </span>
                          )}
                        </div>
                        <p className="text-[11px] text-gray-500 dark:text-gray-400 mt-0.5">
                          {t("fullVmDescLong")}
                        </p>
                      </div>
                    </div>

                    <div
                      className={`w-11 h-6 rounded-full transition-colors relative flex-shrink-0 p-0.5 ${
                        allowVm ? "bg-blue-600" : "bg-gray-300 dark:bg-gray-700"
                      }`}
                    >
                      <div
                        className={`w-5 h-5 rounded-full bg-white shadow-md transform transition-transform ${
                          allowVm ? "translate-x-5" : "translate-x-0"
                        }`}
                      />
                    </div>
                  </div>

                  {/* LXC Container Switch */}
                  <div
                    onClick={handleToggleContainers}
                    className={`p-4 rounded-2xl border transition-all cursor-pointer flex items-start justify-between gap-4 ${
                      allowContainers
                        ? "bg-indigo-500/5 border-indigo-500/30 dark:bg-indigo-950/20"
                        : "bg-gray-50 dark:bg-gray-900/40 border-gray-200 dark:border-gray-800 opacity-60"
                    }`}
                  >
                    <div className="flex items-start space-x-3.5 min-w-0">
                      <div
                        className={`p-2 rounded-xl mt-0.5 ${
                          allowContainers
                            ? "bg-indigo-500/10 text-indigo-600 dark:text-indigo-400"
                            : "bg-gray-200 dark:bg-gray-800 text-gray-400"
                        }`}
                      >
                        <Server className="w-4 h-4" />
                      </div>
                      <div>
                        <div className="text-xs font-bold text-gray-900 dark:text-white flex items-center space-x-2">
                          <span>{t("lxcContainer")}</span>
                          {allowContainers && !allowVm && (
                            <span className="text-[10px] px-2 py-0.2 rounded-full font-normal bg-indigo-100 dark:bg-indigo-900/40 text-indigo-700 dark:text-indigo-300">
                              {t("atLeastOneModeRequired")}
                            </span>
                          )}
                        </div>
                        <p className="text-[11px] text-gray-500 dark:text-gray-400 mt-0.5">
                          {t("lxcDescLong")}
                        </p>
                      </div>
                    </div>

                    <div
                      className={`w-11 h-6 rounded-full transition-colors relative flex-shrink-0 p-0.5 ${
                        allowContainers ? "bg-indigo-600" : "bg-gray-300 dark:bg-gray-700"
                      }`}
                    >
                      <div
                        className={`w-5 h-5 rounded-full bg-white shadow-md transform transition-transform ${
                          allowContainers ? "translate-x-5" : "translate-x-0"
                        }`}
                      />
                    </div>
                  </div>
                </div>
              </div>

              {/* Section 2: Tenant Public Registration */}
              <div className="space-y-3 pt-2">
                <div className="flex items-center space-x-2 text-xs font-semibold uppercase tracking-wider text-emerald-600 dark:text-emerald-400">
                  <UserCheck className="w-3.5 h-3.5" />
                  <span>{t("accessAndRegistration")}</span>
                </div>

                <div
                  onClick={() => setRegistrationEnabled(!registrationEnabled)}
                  className={`p-4 rounded-2xl border transition-all cursor-pointer flex items-start justify-between gap-4 ${
                    registrationEnabled
                      ? "bg-emerald-500/5 border-emerald-500/30 dark:bg-emerald-950/20"
                      : "bg-gray-50 dark:bg-gray-900/40 border-gray-200 dark:border-gray-800 opacity-60"
                  }`}
                >
                  <div className="flex items-start space-x-3.5 min-w-0">
                    <div
                      className={`p-2 rounded-xl mt-0.5 ${
                        registrationEnabled
                          ? "bg-emerald-500/10 text-emerald-600 dark:text-emerald-400"
                          : "bg-gray-200 dark:bg-gray-800 text-gray-400"
                      }`}
                    >
                      <UserCheck className="w-4 h-4" />
                    </div>
                    <div>
                      <div className="text-xs font-bold text-gray-900 dark:text-white">
                        {t("publicRegistration")}
                      </div>
                      <p className="text-[11px] text-gray-500 dark:text-gray-400 mt-0.5">
                        {t("publicRegistrationDesc")}
                      </p>
                    </div>
                  </div>

                  <div
                    className={`w-11 h-6 rounded-full transition-colors relative flex-shrink-0 p-0.5 ${
                      registrationEnabled ? "bg-emerald-600" : "bg-gray-300 dark:bg-gray-700"
                    }`}
                  >
                    <div
                      className={`w-5 h-5 rounded-full bg-white shadow-md transform transition-transform ${
                        registrationEnabled ? "translate-x-5" : "translate-x-0"
                      }`}
                    />
                  </div>
                </div>
              </div>

              {/* Section 3: Public Web Panel URL */}
              <div className="space-y-3 pt-2">
                <div className="flex items-center space-x-2 text-xs font-semibold uppercase tracking-wider text-blue-600 dark:text-blue-400">
                  <Globe className="w-3.5 h-3.5" />
                  <span>{t("frontendUrl")}</span>
                </div>
                <p className="text-xs text-gray-500 dark:text-gray-400">
                  {t("frontendUrlDesc")}
                </p>
                <div className="relative">
                  <input
                    type="url"
                    value={frontendUrl}
                    onChange={(e) => setFrontendUrl(e.target.value)}
                    placeholder={t("frontendUrlPlaceholder")}
                    className="w-full bg-gray-50 dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-xl px-3.5 py-2 pl-9 text-xs text-gray-900 dark:text-white focus:outline-none focus:border-blue-500 transition font-mono"
                  />
                  <Globe className="w-4 h-4 text-gray-400 absolute left-3 top-2.5" />
                </div>
              </div>
            </div>
          )}

          {activeTab === "smtp" && (
            <div className="space-y-5">
              {/* SMTP Enable / Disable Toggle Banner */}
              <div
                onClick={() => setSmtpEnabled(!smtpEnabled)}
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
                    onChange={(e) => setSmtpHost(e.target.value)}
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
                    onChange={(e) => handleEncryptionChange(e.target.value as "tls" | "ssl" | "none")}
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
                    onChange={(e) => setSmtpPort(e.target.value === "" ? "" : Number(e.target.value))}
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
                    onChange={(e) => setSmtpUsername(e.target.value)}
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
                    onChange={(e) => setSmtpPassword(e.target.value)}
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
                    onChange={(e) => setSmtpFromAddress(e.target.value)}
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
                    onChange={(e) => setSmtpFromName(e.target.value)}
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
                    onChange={(e) => setTestRecipient(e.target.value)}
                    placeholder="recipient@example.com (optional)"
                    className="flex-1 bg-white dark:bg-gray-950 border border-gray-200 dark:border-gray-800 rounded-xl px-3 py-2 text-xs text-gray-900 dark:text-white focus:outline-none focus:border-blue-500 transition"
                  />
                  <button
                    type="button"
                    onClick={handleSendTestEmail}
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
              {saving ? (
                <Loader2 className="w-4 h-4 animate-spin" />
              ) : (
                <Check className="w-4 h-4" />
              )}
              <span>{t("saveSettings")}</span>
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}
