"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import type { TestConnectionResponse } from "@/lib/api";

export interface UseHostVerifyResult<TArgs> {
  verifyLoading: boolean;
  verifyResult: TestConnectionResponse | null;
  verifyError: string | null;
  verify: (args: TArgs) => Promise<void>;
  reset: () => void;
}

/**
 * Manages connection verification state and error handling for host modals.
 */
export function useHostVerify<TArgs>(
  fn: (args: TArgs) => Promise<TestConnectionResponse>
): UseHostVerifyResult<TArgs> {
  const [verifyLoading, setVerifyLoading] = useState(false);
  const [verifyResult, setVerifyResult] = useState<TestConnectionResponse | null>(null);
  const [verifyError, setVerifyError] = useState<string | null>(null);

  const fnRef = useRef(fn);
  useEffect(() => {
    fnRef.current = fn;
  }, [fn]);

  const verify = useCallback(async (args: TArgs): Promise<void> => {
    setVerifyLoading(true);
    setVerifyResult(null);
    setVerifyError(null);

    try {
      const res = await fnRef.current(args);
      setVerifyResult(res);
    } catch (err: unknown) {
      if (err instanceof Error) {
        setVerifyError(err.message);
      } else {
        setVerifyError("Verification failed.");
      }
    } finally {
      setVerifyLoading(false);
    }
  }, []);

  const reset = useCallback(() => {
    setVerifyLoading(false);
    setVerifyResult(null);
    setVerifyError(null);
  }, []);

  return {
    verifyLoading,
    verifyResult,
    verifyError,
    verify,
    reset,
  };
}
