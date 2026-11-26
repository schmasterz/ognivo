"use client";

import React, { useState, useEffect } from "react";
import { useRouter } from "next/navigation";
import { Server, Lock, Mail, User as UserIcon, AlertCircle, Loader2, ArrowRight, ArrowLeft, Eye, EyeOff, CheckCircle2 } from "lucide-react";
import { api, User } from "@/lib/api";
import { useAuth } from "@/lib/auth/AuthContext";
import { sanitizeNextPath } from "@/lib/auth/sanitizeNextPath";
import { useI18n } from "@/lib/i18n/I18nContext";
import { ThemeToggle } from "@/components/layout/ThemeToggle";
import { LanguageToggle } from "@/components/layout/LanguageToggle";

interface AuthViewProps {
  onAuthSuccess: (user: User, roles: string[], sessionExpiresAt?: string | null) => void;
}

export function AuthView({ onAuthSuccess }: AuthViewProps) {
  const { t } = useI18n();
  const router = useRouter();
  const { user, authLoading } = useAuth();
  const [isRegister, setIsRegister] = useState(false);
  const [name, setName] = useState("");
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [showPassword, setShowPassword] = useState(false);
  const [registrationEnabled, setRegistrationEnabled] = useState(true);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [isForgot, setIsForgot] = useState(false);
  const [forgotSentMessage, setForgotSentMessage] = useState<string | null>(null);
  const [verificationSentMessage, setVerificationSentMessage] = useState<string | null>(null);
  const [resendingVerification, setResendingVerification] = useState(false);

  const handleResendVerification = async () => {
    if (!email.trim()) return;
    setResendingVerification(true);
    try {
      const res = await api.resendVerificationEmail(email.trim());
      setVerificationSentMessage(res.message || t("resendVerificationSuccess"));
      setError(null);
    } catch (err: unknown) {
      setError(err instanceof Error ? err.message : "Failed to resend verification email.");
    } finally {
      setResendingVerification(false);
    }
  };

  const handleForgotSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setError(null);
    setForgotSentMessage(null);
    setLoading(true);
    try {
      const res = await api.forgotPassword(email.trim());
      setForgotSentMessage(res.message);
    } catch (err: unknown) {
      setError(err instanceof Error ? err.message : "Request failed.");
    } finally {
      setLoading(false);
    }
  };

  const openForgot = () => {
    setIsForgot(true);
    setError(null);
    setForgotSentMessage(null);
  };

  const closeForgot = () => {
    setIsForgot(false);
    setError(null);
    setForgotSentMessage(null);
  };

  useEffect(() => {
    if (!authLoading && user && typeof window !== "undefined") {
      const nextParam = new URLSearchParams(window.location.search).get("next");
      router.replace(sanitizeNextPath(nextParam, "/instances"));
    }
  }, [authLoading, user, router]);

  useEffect(() => {
    api.getPublicSettings().then((res) => {
      setRegistrationEnabled(res.registration_enabled);
      if (!res.registration_enabled) {
        setIsRegister(false);
      }
    }).catch(console.error);
  }, []);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setError(null);
    setLoading(true);

    try {
      if (isRegister) {
        const res = await api.register(name, email, password);
        setVerificationSentMessage(res.message || t("verifyEmailPendingNotice"));
        setIsRegister(false);
        setPassword("");
        return;
      }

      await api.login(email, password);

      const me = await api.getMe();
      onAuthSuccess(me.user, me.roles, me.session_expires_at);
    } catch (err: unknown) {
      if (err instanceof Error) {
        setError(err.message);
      } else {
        setError("Authentication failed.");
      }
    } finally {
      setLoading(false);
    }
  };

  if (authLoading || user) {
    return (
      <div className="min-h-screen flex flex-col items-center justify-center p-4 bg-gray-50 dark:bg-[#090d16] text-gray-900 dark:text-white transition-colors">
        <div className="flex flex-col items-center space-y-3 text-gray-500 dark:text-gray-400">
          <Loader2 className="w-8 h-8 animate-spin text-blue-500" />
          <span className="text-xs font-medium tracking-wide">{t("loading")}</span>
        </div>
      </div>
    );
  }

  return (
    <div className="min-h-screen flex flex-col items-center justify-center p-4 bg-gray-50 dark:bg-[#090d16] text-gray-900 dark:text-white transition-colors relative">
      {/* Top Controls */}
      <div className="absolute top-6 right-6 flex items-center space-x-3">
        <LanguageToggle />
        <ThemeToggle />
      </div>

      <div className="w-full max-w-md my-auto">
        {/* Brand Header */}
        <div className="text-center mb-8">
          <div className="inline-flex p-3 bg-blue-500/10 dark:bg-blue-600/15 border border-blue-500/30 rounded-2xl text-blue-600 dark:text-blue-400 mb-3 shadow-lg shadow-blue-500/10">
            <Server className="w-8 h-8" />
          </div>
          <h1 className="text-2xl font-bold tracking-tight text-gray-900 dark:text-white">{t("platformTitle")}</h1>
          <p className="text-xs text-gray-500 dark:text-gray-400 mt-1">{t("platformSubtitle")}</p>
        </div>

        {/* Card */}
        <div className="bg-white dark:bg-gray-900/80 border border-gray-200 dark:border-gray-800 rounded-3xl p-7 shadow-xl dark:shadow-2xl backdrop-blur-xl transition-colors">
          {isForgot ? (
            <div>
              <h2 className="text-sm font-bold text-gray-900 dark:text-white">{t("forgotPasswordTitle")}</h2>
              <p className="text-xs text-gray-500 dark:text-gray-400 mt-1 mb-5">{t("forgotPasswordDesc")}</p>

              {error && (
                <div className="mb-4 p-3 bg-red-500/10 border border-red-500/30 rounded-xl text-red-600 dark:text-red-400 text-xs flex items-center space-x-2">
                  <AlertCircle className="w-4 h-4 flex-shrink-0" />
                  <span>{error}</span>
                </div>
              )}

              {forgotSentMessage && (
                <div className="mb-4 p-3 bg-emerald-500/10 border border-emerald-500/30 rounded-xl text-emerald-700 dark:text-emerald-400 text-xs flex items-start space-x-2">
                  <CheckCircle2 className="w-4 h-4 flex-shrink-0 mt-0.5" />
                  <span>{forgotSentMessage}</span>
                </div>
              )}

              <form onSubmit={handleForgotSubmit} className="space-y-4">
                <div>
                  <label className="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1.5">
                    {t("emailAddress")}
                  </label>
                  <div className="relative">
                    <input
                      type="email"
                      required
                      autoFocus
                      value={email}
                      onChange={(e) => setEmail(e.target.value)}
                      placeholder="name@example.com"
                      className="w-full bg-gray-50 dark:bg-gray-950 border border-gray-200 dark:border-gray-800 rounded-xl px-3.5 py-2.5 text-sm text-gray-900 dark:text-white focus:outline-none focus:border-blue-500 pl-9 transition"
                    />
                    <Mail className="w-4 h-4 text-gray-400 dark:text-gray-500 absolute left-3 top-3" />
                  </div>
                </div>

                <button
                  type="submit"
                  disabled={loading || !email.trim()}
                  className="w-full py-2.5 px-4 rounded-xl text-xs font-semibold text-white bg-blue-600 hover:bg-blue-500 disabled:opacity-50 transition flex items-center justify-center space-x-2 shadow-lg shadow-blue-600/25"
                >
                  {loading ? <Loader2 className="w-4 h-4 animate-spin" /> : <Mail className="w-4 h-4" />}
                  <span>{t("sendResetLink")}</span>
                </button>
              </form>

              <button
                type="button"
                onClick={closeForgot}
                className="mt-5 w-full text-xs text-blue-600 dark:text-blue-400 hover:underline flex items-center justify-center space-x-1"
              >
                <ArrowLeft className="w-3.5 h-3.5" />
                <span>{t("backToSignIn")}</span>
              </button>
            </div>
          ) : (
          <>
          <div className="flex border-b border-gray-200 dark:border-gray-800 mb-6">
            <button
              onClick={() => {
                setIsRegister(false);
                setError(null);
              }}
              className={`flex-1 pb-3 text-xs font-semibold tracking-wide transition border-b-2 ${
                !isRegister
                  ? "border-blue-500 text-blue-600 dark:text-white"
                  : "border-transparent text-gray-400 hover:text-gray-600 dark:hover:text-gray-300"
              }`}
            >
              {t("signIn")}
            </button>
            {registrationEnabled && (
              <button
                onClick={() => {
                  setIsRegister(true);
                  setError(null);
                }}
                className={`flex-1 pb-3 text-xs font-semibold tracking-wide transition border-b-2 ${
                  isRegister
                    ? "border-blue-500 text-blue-600 dark:text-white"
                    : "border-transparent text-gray-400 hover:text-gray-600 dark:hover:text-gray-300"
                }`}
              >
                {t("createAccount")}
              </button>
            )}
          </div>

          {verificationSentMessage && (
            <div className="mb-4 p-3 bg-emerald-500/10 border border-emerald-500/30 rounded-xl text-emerald-700 dark:text-emerald-400 text-xs flex items-start space-x-2">
              <CheckCircle2 className="w-4 h-4 flex-shrink-0 mt-0.5" />
              <span>{verificationSentMessage}</span>
            </div>
          )}

          {error && (
            <div className="mb-4 p-3 bg-red-500/10 border border-red-500/30 rounded-xl text-red-600 dark:text-red-400 text-xs flex flex-col space-y-2">
              <div className="flex items-center space-x-2">
                <AlertCircle className="w-4 h-4 flex-shrink-0" />
                <span>{error}</span>
              </div>
              {email && (error.toLowerCase().includes("verification") || error.toLowerCase().includes("потвърждение")) && (
                <button
                  type="button"
                  disabled={resendingVerification}
                  onClick={handleResendVerification}
                  className="text-left text-xs text-blue-600 dark:text-blue-400 hover:underline pt-1"
                >
                  {resendingVerification ? t("loading") : t("resendVerificationTitle")} →
                </button>
              )}
            </div>
          )}

          <form onSubmit={handleSubmit} className="space-y-4">
            {isRegister && (
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
                    placeholder="e.g. John Doe"
                    className="w-full bg-gray-50 dark:bg-gray-950 border border-gray-200 dark:border-gray-800 rounded-xl px-3.5 py-2.5 text-sm text-gray-900 dark:text-white focus:outline-none focus:border-blue-500 pl-9 transition"
                  />
                  <UserIcon className="w-4 h-4 text-gray-400 dark:text-gray-500 absolute left-3 top-3" />
                </div>
              </div>
            )}

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
                  placeholder="name@example.com"
                  className="w-full bg-gray-50 dark:bg-gray-950 border border-gray-200 dark:border-gray-800 rounded-xl px-3.5 py-2.5 text-sm text-gray-900 dark:text-white focus:outline-none focus:border-blue-500 pl-9 transition"
                />
                <Mail className="w-4 h-4 text-gray-400 dark:text-gray-500 absolute left-3 top-3" />
              </div>
            </div>

            <div>
              <div className="flex items-center justify-between mb-1.5">
                <label className="block text-xs font-medium text-gray-700 dark:text-gray-300">
                  {t("password")}
                </label>
                {!isRegister && (
                  <button
                    type="button"
                    onClick={openForgot}
                    className="text-[11px] font-medium text-blue-600 dark:text-blue-400 hover:underline"
                  >
                    {t("forgotPasswordLink")}
                  </button>
                )}
              </div>
              <div className="relative">
                <input
                  type={showPassword ? "text" : "password"}
                  required
                  value={password}
                  onChange={(e) => setPassword(e.target.value)}
                  placeholder="••••••••"
                  className="w-full bg-gray-50 dark:bg-gray-950 border border-gray-200 dark:border-gray-800 rounded-xl px-3.5 py-2.5 text-sm text-gray-900 dark:text-white focus:outline-none focus:border-blue-500 pl-9 pr-10 transition"
                />
                <Lock className="w-4 h-4 text-gray-400 dark:text-gray-500 absolute left-3 top-3" />
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

            <button
              type="submit"
              disabled={loading || (isRegister && !registrationEnabled)}
              className="w-full mt-2 py-2.5 px-4 rounded-xl text-xs font-semibold text-white bg-blue-600 hover:bg-blue-500 disabled:opacity-50 transition flex items-center justify-center space-x-2 shadow-lg shadow-blue-600/25"
            >
              {loading ? (
                <>
                  <Loader2 className="w-4 h-4 animate-spin" />
                  <span>{t("authenticating")}</span>
                </>
              ) : (
                <>
                  <span>{isRegister ? t("registerAction") : t("signInAction")}</span>
                  <ArrowRight className="w-4 h-4" />
                </>
              )}
            </button>
          </form>
          </>
          )}
        </div>
      </div>
    </div>
  );
}
