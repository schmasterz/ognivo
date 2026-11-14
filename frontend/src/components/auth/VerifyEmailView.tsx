"use client";

import React, { useEffect, useState } from "react";
import Link from "next/link";
import { Server, MailCheck, AlertCircle, Loader2, CheckCircle2, ArrowLeft, Mail } from "lucide-react";
import { api } from "@/lib/api";
import { useI18n } from "@/lib/i18n/I18nContext";
import { ThemeToggle } from "@/components/layout/ThemeToggle";
import { LanguageToggle } from "@/components/layout/LanguageToggle";

/**
 * Standalone verification screen opened from the email link (/verify-email?id=...&hash=...&expires=...&signature=...).
 */
export function VerifyEmailView() {
  const { t } = useI18n();
  const [verifying, setVerifying] = useState(true);
  const [successMessage, setSuccessMessage] = useState<string | null>(null);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);
  const [resendEmail, setResendEmail] = useState("");
  const [resending, setResending] = useState(false);
  const [resendSuccess, setResendSuccess] = useState<string | null>(null);

  useEffect(() => {
    const params = new URLSearchParams(window.location.search);
    const verifiedParam = params.get("verified");
    const errorParam = params.get("error");
    const id = params.get("id");
    const hash = params.get("hash");
    const expires = params.get("expires");
    const signature = params.get("signature");

    if (verifiedParam === "1") {
      queueMicrotask(() => {
        setSuccessMessage(t("verifyEmailSuccess"));
        setVerifying(false);
      });
      return;
    }

    if (errorParam) {
      queueMicrotask(() => {
        setErrorMessage(t("verifyEmailError"));
        setVerifying(false);
      });
      return;
    }

    if (!id || !hash || !expires || !signature) {
      queueMicrotask(() => {
        setErrorMessage(t("verifyEmailError"));
        setVerifying(false);
      });
      return;
    }

    api
      .verifyEmail({
        id: Number(id),
        hash,
        expires: Number(expires),
        signature,
      })
      .then((res) => {
        setSuccessMessage(res.message || t("verifyEmailSuccess"));
        // Remove signature query parameters from browser history
        window.history.replaceState(null, "", "/verify-email");
      })
      .catch((err: unknown) => {
        setErrorMessage(err instanceof Error ? err.message : t("verifyEmailError"));
      })
      .finally(() => {
        setVerifying(false);
      });
  }, [t]);

  const handleResend = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!resendEmail.trim()) return;
    setResending(true);
    setResendSuccess(null);
    try {
      const res = await api.resendVerificationEmail(resendEmail.trim());
      setResendSuccess(res.message || t("resendVerificationSuccess"));
    } catch (err: unknown) {
      setErrorMessage(err instanceof Error ? err.message : "Failed to resend verification email.");
    } finally {
      setResending(false);
    }
  };

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
            <MailCheck className="w-4 h-4 text-blue-500" />
            <h2 className="text-sm font-bold text-gray-900 dark:text-white">{t("verifyEmailTitle")}</h2>
          </div>
          <p className="text-xs text-gray-500 dark:text-gray-400 mb-5">
            {t("verifyEmailDesc")}
          </p>

          {verifying ? (
            <div className="py-8 flex flex-col items-center justify-center space-y-3 text-gray-500 dark:text-gray-400">
              <Loader2 className="w-8 h-8 animate-spin text-blue-500" />
              <span className="text-xs">{t("loading")}</span>
            </div>
          ) : successMessage ? (
            <div className="space-y-5">
              <div className="p-3.5 bg-emerald-500/10 border border-emerald-500/30 rounded-xl text-emerald-700 dark:text-emerald-400 text-xs flex items-start space-x-2.5">
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
          ) : (
            <div className="space-y-5">
              {errorMessage && (
                <div className="p-3 bg-red-500/10 border border-red-500/30 rounded-xl text-red-600 dark:text-red-400 text-xs flex items-center space-x-2">
                  <AlertCircle className="w-4 h-4 flex-shrink-0" />
                  <span>{errorMessage}</span>
                </div>
              )}

              {resendSuccess && (
                <div className="p-3 bg-emerald-500/10 border border-emerald-500/30 rounded-xl text-emerald-700 dark:text-emerald-400 text-xs flex items-start space-x-2">
                  <CheckCircle2 className="w-4 h-4 flex-shrink-0 mt-0.5" />
                  <span>{resendSuccess}</span>
                </div>
              )}

              <form onSubmit={handleResend} className="space-y-3 pt-2 border-t border-gray-100 dark:border-gray-800">
                <label className="block text-xs font-medium text-gray-700 dark:text-gray-300">
                  {t("resendVerificationDesc")}
                </label>
                <div className="relative">
                  <input
                    type="email"
                    required
                    value={resendEmail}
                    onChange={(e) => setResendEmail(e.target.value)}
                    placeholder="name@example.com"
                    className="w-full bg-gray-50 dark:bg-gray-950 border border-gray-200 dark:border-gray-800 rounded-xl px-3.5 py-2.5 text-sm text-gray-900 dark:text-white focus:outline-none focus:border-blue-500 pl-9 transition"
                  />
                  <Mail className="w-4 h-4 text-gray-400 dark:text-gray-500 absolute left-3 top-3" />
                </div>
                <button
                  type="submit"
                  disabled={resending}
                  className="w-full py-2.5 px-4 rounded-xl text-xs font-semibold text-white bg-blue-600 hover:bg-blue-500 disabled:opacity-50 transition flex items-center justify-center space-x-2 shadow-lg shadow-blue-600/25"
                >
                  {resending ? <Loader2 className="w-4 h-4 animate-spin" /> : <Mail className="w-4 h-4" />}
                  <span>{t("resendVerificationAction")}</span>
                </button>
              </form>

              <Link
                href="/"
                className="w-full text-xs text-blue-600 dark:text-blue-400 hover:underline flex items-center justify-center space-x-1"
              >
                <ArrowLeft className="w-3.5 h-3.5" />
                <span>{t("backToSignIn")}</span>
              </Link>
            </div>
          )}
        </div>
      </div>
    </div>
  );
}
