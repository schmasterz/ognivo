"use client";

import React, { useEffect, useState } from "react";
import Link from "next/link";
import { Server, Lock, AlertCircle, Loader2, CheckCircle2, ArrowLeft, Eye, EyeOff, KeyRound } from "lucide-react";
import { api } from "@/lib/api";
import { useI18n } from "@/lib/i18n/I18nContext";
import { ThemeToggle } from "@/components/layout/ThemeToggle";
import { LanguageToggle } from "@/components/layout/LanguageToggle";

/**
 * Standalone screen opened from the password reset email link (/reset-password?token=...&email=...).
 */
export function ResetPasswordView() {
  const { t } = useI18n();
  const [token, setToken] = useState("");
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [passwordConfirmation, setPasswordConfirmation] = useState("");
  const [showPassword, setShowPassword] = useState(false);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [successMessage, setSuccessMessage] = useState<string | null>(null);
  const [linkChecked, setLinkChecked] = useState(false);

  useEffect(() => {
    const params = new URLSearchParams(window.location.search);
    const initialToken = params.get("token") ?? "";
    const initialEmail = params.get("email") ?? "";
    queueMicrotask(() => {
      setToken(initialToken);
      setEmail(initialEmail);
      setLinkChecked(true);
    });
  }, []);

  const linkInvalid = linkChecked && (!token || !email);
  const mismatch = passwordConfirmation.length > 0 && password !== passwordConfirmation;

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setError(null);
    if (password !== passwordConfirmation) {
      setError(t("passwordsDoNotMatch"));
      return;
    }
    setLoading(true);
    try {
      const res = await api.resetPassword({
        token,
        email,
        password,
        password_confirmation: passwordConfirmation,
      });
      setSuccessMessage(res.message);
      // Remove the token from the address bar once it has been consumed
      window.history.replaceState(null, "", "/reset-password");
    } catch (err: unknown) {
      setError(err instanceof Error ? err.message : "Password reset failed.");
    } finally {
      setLoading(false);
    }
  };

  const inputClass =
    "w-full bg-gray-50 dark:bg-gray-950 border border-gray-200 dark:border-gray-800 rounded-xl px-3.5 py-2.5 text-sm text-gray-900 dark:text-white focus:outline-none focus:border-blue-500 pl-9 pr-10 transition";

  return (
    <div className="min-h-screen flex flex-col items-center justify-center p-4 bg-gray-50 dark:bg-[#090d16] text-gray-900 dark:text-white transition-colors relative">
      <div className="absolute top-6 right-6 flex items-center space-x-3">
        <LanguageToggle />
        <ThemeToggle />
      </div>

      <div className="w-full max-w-md my-auto">
        <div className="text-center mb-8">
          <div className="inline-flex p-3 bg-blue-500/10 dark:bg-blue-600/15 border border-blue-500/30 rounded-2xl text-blue-600 dark:text-blue-400 mb-3 shadow-lg shadow-blue-500/10">
            <Server className="w-8 h-8" />
          </div>
          <h1 className="text-2xl font-bold tracking-tight text-gray-900 dark:text-white">{t("platformTitle")}</h1>
        </div>

        <div className="bg-white dark:bg-gray-900/80 border border-gray-200 dark:border-gray-800 rounded-3xl p-7 shadow-xl dark:shadow-2xl backdrop-blur-xl transition-colors">
          <div className="flex items-center space-x-2 mb-1">
            <KeyRound className="w-4 h-4 text-blue-500" />
            <h2 className="text-sm font-bold text-gray-900 dark:text-white">{t("resetPasswordTitle")}</h2>
          </div>
          <p className="text-xs text-gray-500 dark:text-gray-400 mb-5">
            {t("resetPasswordDesc")}
            {email && <span className="block mt-1 font-mono text-gray-700 dark:text-gray-300">{email}</span>}
          </p>

          {successMessage ? (
            <div className="space-y-5">
              <div className="p-3 bg-emerald-500/10 border border-emerald-500/30 rounded-xl text-emerald-700 dark:text-emerald-400 text-xs flex items-start space-x-2">
                <CheckCircle2 className="w-4 h-4 flex-shrink-0 mt-0.5" />
                <span>{successMessage}</span>
              </div>
              <Link
                href="/"
                className="w-full py-2.5 px-4 rounded-xl text-xs font-semibold text-white bg-blue-600 hover:bg-blue-500 transition flex items-center justify-center space-x-2 shadow-lg shadow-blue-600/25"
              >
                <span>{t("signInAction")}</span>
              </Link>
            </div>
          ) : linkInvalid ? (
            <div className="p-3 bg-red-500/10 border border-red-500/30 rounded-xl text-red-600 dark:text-red-400 text-xs flex items-center space-x-2">
              <AlertCircle className="w-4 h-4 flex-shrink-0" />
              <span>{t("resetLinkInvalid")}</span>
            </div>
          ) : (
            <>
              {error && (
                <div className="mb-4 p-3 bg-red-500/10 border border-red-500/30 rounded-xl text-red-600 dark:text-red-400 text-xs flex items-center space-x-2">
                  <AlertCircle className="w-4 h-4 flex-shrink-0" />
                  <span>{error}</span>
                </div>
              )}

              <form onSubmit={handleSubmit} className="space-y-4">
                <div>
                  <label className="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1.5">
                    {t("newPassword")}
                  </label>
                  <div className="relative">
                    <input
                      type={showPassword ? "text" : "password"}
                      required
                      autoFocus
                      autoComplete="new-password"
                      value={password}
                      onChange={(e) => setPassword(e.target.value)}
                      placeholder="••••••••"
                      className={inputClass}
                    />
                    <Lock className="w-4 h-4 text-gray-400 dark:text-gray-500 absolute left-3 top-3" />
                    <button
                      type="button"
                      onClick={() => setShowPassword(!showPassword)}
                      className="p-1 rounded-lg text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 absolute right-2.5 top-2.5 transition"
                    >
                      {showPassword ? <EyeOff className="w-4 h-4" /> : <Eye className="w-4 h-4" />}
                    </button>
                  </div>
                </div>

                <div>
                  <label className="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1.5">
                    {t("confirmNewPassword")}
                  </label>
                  <div className="relative">
                    <input
                      type={showPassword ? "text" : "password"}
                      required
                      autoComplete="new-password"
                      value={passwordConfirmation}
                      onChange={(e) => setPasswordConfirmation(e.target.value)}
                      placeholder="••••••••"
                      className={`${inputClass} ${mismatch ? "border-red-500 focus:border-red-500" : ""}`}
                    />
                    <Lock className="w-4 h-4 text-gray-400 dark:text-gray-500 absolute left-3 top-3" />
                  </div>
                  {mismatch && (
                    <p className="text-[11px] text-red-500 font-medium mt-1">{t("passwordsDoNotMatch")}</p>
                  )}
                </div>

                <button
                  type="submit"
                  disabled={loading || !password || mismatch}
                  className="w-full mt-2 py-2.5 px-4 rounded-xl text-xs font-semibold text-white bg-blue-600 hover:bg-blue-500 disabled:opacity-50 transition flex items-center justify-center space-x-2 shadow-lg shadow-blue-600/25"
                >
                  {loading && <Loader2 className="w-4 h-4 animate-spin" />}
                  <span>{t("resetPasswordAction")}</span>
                </button>
              </form>
            </>
          )}

          {!successMessage && (
            <Link
              href="/"
              className="mt-5 w-full text-xs text-blue-600 dark:text-blue-400 hover:underline flex items-center justify-center space-x-1"
            >
              <ArrowLeft className="w-3.5 h-3.5" />
              <span>{t("backToSignIn")}</span>
            </Link>
          )}
        </div>
      </div>
    </div>
  );
}
