"use client";

import { useState, useEffect, useRef, useCallback } from "react";

export interface CacheRecord<T> {
  data: T;
  timestamp: number;
}

// Module-level in-memory cache shared across hook invocations
const cacheStore = new Map<string, CacheRecord<unknown>>();

/**
 * Clears all entries in the in-memory fetch cache when `prefix` is omitted,
 * or deletes all keys starting with `prefix` when provided.
 */
export function clearAllCachedFetchEntries(prefix?: string): void {
  if (!prefix) {
    cacheStore.clear();
    return;
  }
  for (const key of Array.from(cacheStore.keys())) {
    if (key.startsWith(prefix)) {
      cacheStore.delete(key);
    }
  }
}

export function setCachedFetchEntry<T>(
  key: string,
  data: T,
  timestamp: number = Date.now()
): void {
  cacheStore.set(key, { data, timestamp });
}

export function getCachedFetchEntry<T>(
  key: string
): CacheRecord<T> | undefined {
  return cacheStore.get(key) as CacheRecord<T> | undefined;
}

export function getValidCachedEntry<T>(
  key: string | null,
  ttlMs: number,
  force = false
): CacheRecord<T> | undefined {
  if (!key || force || ttlMs <= 0) return undefined;
  const cached = cacheStore.get(key) as CacheRecord<T> | undefined;
  if (cached && Date.now() - cached.timestamp < ttlMs) {
    return cached;
  }
  return undefined;
}

export interface UseCachedFetchOptions<T> {
  enabled?: boolean;
  ttlMs?: number;
  initialData?: T | null;
  onSuccess?: (data: T) => void;
  onError?: (err: unknown) => void;
}

export interface UseCachedFetchResult<T> {
  data: T | null;
  loading: boolean;
  error: Error | null;
  refetch: (silentOrOptions?: boolean | { force?: boolean } | unknown) => Promise<void>;
  setData: React.Dispatch<React.SetStateAction<T | null>>;
  clearCache: () => void;
}

/**
 * Lightweight data-fetching hook featuring:
 * 1. Automatic query-level in-memory caching with TTL
 * 2. Instant request abortion via AbortController on parameter change or component unmount
 * 3. Graceful fallback and error states
 */
export function useCachedFetch<T>(
  cacheKey: string | null,
  fetcher: (signal: AbortSignal) => Promise<T>,
  options: UseCachedFetchOptions<T> = {}
): UseCachedFetchResult<T> {
  const {
    enabled = true,
    ttlMs = 15000,
    initialData = null,
    onSuccess,
    onError,
  } = options;

  const [data, setData] = useState<T | null>(() => {
    const cached = getValidCachedEntry<T>(cacheKey, ttlMs, false);
    return cached ? cached.data : initialData;
  });

  const [loading, setLoading] = useState<boolean>(false);
  const [error, setError] = useState<Error | null>(null);

  const abortControllerRef = useRef<AbortController | null>(null);
  const onSuccessRef = useRef(onSuccess);
  const onErrorRef = useRef(onError);
  const fetcherRef = useRef(fetcher);

  useEffect(() => {
    onSuccessRef.current = onSuccess;
    onErrorRef.current = onError;
    fetcherRef.current = fetcher;
  });

  const executeFetch = useCallback(
    async (options?: { force?: boolean } | boolean) => {
      if (!cacheKey || !enabled) return;

      const force =
        typeof options === "boolean"
          ? options
          : (options?.force ?? false);

      // Check cache validity first unless forced
      const cached = getValidCachedEntry<T>(cacheKey, ttlMs, force);
      if (cached) {
        setData(cached.data);
        setLoading(false);
        setError(null);
        return;
      }

      // Abort previous in-flight request
      if (abortControllerRef.current) {
        abortControllerRef.current.abort();
      }
      const controller = new AbortController();
      abortControllerRef.current = controller;

      setLoading(true);
      setError(null);

      try {
        const result = await fetcherRef.current(controller.signal);
        if (!controller.signal.aborted) {
          setCachedFetchEntry(cacheKey, result, Date.now());
          setData(result);
          setLoading(false);
          onSuccessRef.current?.(result);
        }
      } catch (err: unknown) {
        if (err instanceof Error && err.name === "AbortError") {
          return;
        }
        if (!controller.signal.aborted) {
          const errorObj = err instanceof Error ? err : new Error(String(err));
          setError(errorObj);
          setLoading(false);
          onErrorRef.current?.(err);
        }
      }
    },
    [cacheKey, enabled, ttlMs]
  );

  useEffect(() => {
    executeFetch({ force: false });

    return () => {
      if (abortControllerRef.current) {
        abortControllerRef.current.abort();
      }
    };
  }, [executeFetch]);

  const refetch = useCallback(
    (silentOrOptions?: boolean | { force?: boolean } | unknown) => {
      const force =
        typeof silentOrOptions === "object" &&
        silentOrOptions !== null &&
        "force" in silentOrOptions &&
        typeof (silentOrOptions as { force?: unknown }).force === "boolean"
          ? (silentOrOptions as { force: boolean }).force
          : true;
      return executeFetch({ force });
    },
    [executeFetch]
  );

  const clearCache = useCallback(() => {
    if (cacheKey) {
      cacheStore.delete(cacheKey);
    }
  }, [cacheKey]);

  return {
    data,
    loading,
    error,
    refetch,
    setData,
    clearCache,
  };
}
