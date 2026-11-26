"use client";

import { useCallback, useState, type Dispatch, type SetStateAction } from "react";
import { api } from "@/lib/api";

export interface SmtpTestResult {
  success: boolean;
  message: string;
}

export interface UseSmtpTestResult {
  testRecipient: string;
  setTestRecipient: Dispatch<SetStateAction<string>>;
  testingEmail: boolean;
  testResult: SmtpTestResult | null;
  sendTestEmail: () => Promise<void>;
  resetTestResult: () => void;
}

/**
 * Manages SMTP test email recipient input, sending state, and result feedback.
 */
export function useSmtpTest(defaultSuccessMessage: string): UseSmtpTestResult {
  const [testRecipient, setTestRecipient] = useState("");
  const [testingEmail, setTestingEmail] = useState(false);
  const [testResult, setTestResult] = useState<SmtpTestResult | null>(null);

  const sendTestEmail = useCallback(async (): Promise<void> => {
    setTestingEmail(true);
    setTestResult(null);
    try {
      const res = await api.testSmtp(testRecipient.trim() || undefined);
      setTestResult({
        success: true,
        message: res.message || defaultSuccessMessage,
      });
    } catch (err: unknown) {
      setTestResult({
        success: false,
        message: err instanceof Error ? err.message : "Failed to send test email.",
      });
    } finally {
      setTestingEmail(false);
    }
  }, [testRecipient, defaultSuccessMessage]);

  const resetTestResult = useCallback(() => {
    setTestResult(null);
  }, []);

  return {
    testRecipient,
    setTestRecipient,
    testingEmail,
    testResult,
    sendTestEmail,
    resetTestResult,
  };
}
