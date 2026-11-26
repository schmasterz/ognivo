/**
 * Core HTTP Request Engine and Error Handling for LXD/Incus API Client.
 */
import { getRuntimeConfig } from "../config.ts";
import {
  ensureCsrfCookie,
  isUnsafeMethod,
  readXsrfToken,
  resetCsrfCookiePromise,
} from "./csrf.ts";

export const API_BASE =
  typeof window !== "undefined"
    ? (getRuntimeConfig().api_base_url || "/api/v1")
    : process.env.NEXT_PUBLIC_API_URL || "http://webserver/api/v1";

export const PASSIVE_ACTIVITY_HEADER = {
  "X-Ognivo-Activity": "passive",
} as const;

let currentLocale: string | null = null;

export function setApiLocale(locale: string | null): void {
  currentLocale = locale;
}

export class ApiError extends Error {
  status: number;
  errors?: Record<string, string[]> | null;
  retryAfter?: number | null;

  constructor(
    status: number,
    message: string,
    errors?: Record<string, string[]> | null,
    retryAfter?: number | null
  ) {
    super(message);
    this.name = "ApiError";
    this.status = status;
    this.errors = errors;
    this.retryAfter = retryAfter;
  }
}

export interface ApiFetchDeps {
  fetchFn?: (input: string, init?: RequestInit) => Promise<Response>;
  readToken?: () => string | null;
}

function isAuthEndpointUrl(input: string): boolean {
  const pathname = input.split("?")[0].split("#")[0];
  return pathname.endsWith("/login") || pathname.endsWith("/register");
}

/**
 * Shared cookie-aware fetch wrapper handling same-origin credentials,
 * passive activity headers, Sanctum CSRF tokens, single 419 retry, and 401 session-expiry events.
 */
export async function apiFetch(
  input: string,
  init?: RequestInit & { passive?: boolean },
  deps?: ApiFetchDeps
): Promise<Response> {
  const fetchFn = deps?.fetchFn ?? fetch;
  const getToken = deps?.readToken ?? (() => readXsrfToken());

  const { passive, ...restInit } = init ?? {};
  const method = (restInit.method ?? "GET").toUpperCase();
  const headers = new Headers(restInit.headers);

  if (passive && method === "GET") {
    headers.set("X-Ognivo-Activity", PASSIVE_ACTIVITY_HEADER["X-Ognivo-Activity"]);
  }

  if (isUnsafeMethod(method)) {
    let token = getToken();
    if (!token) {
      await ensureCsrfCookie(fetchFn);
      token = getToken();
    }
    if (token) {
      headers.set("X-XSRF-TOKEN", token);
    }
  }

  const buildRequestInit = (): RequestInit => ({
    ...restInit,
    method,
    headers: new Headers(headers),
    credentials: "same-origin",
  });

  let response = await fetchFn(input, buildRequestInit());

  if (response.status === 419) {
    resetCsrfCookiePromise();
    await ensureCsrfCookie(fetchFn);
    const refreshedToken = getToken();
    if (isUnsafeMethod(method)) {
      if (refreshedToken) {
        headers.set("X-XSRF-TOKEN", refreshedToken);
      } else {
        headers.delete("X-XSRF-TOKEN");
      }
    }
    response = await fetchFn(input, buildRequestInit());
  }

  if (
    response.status === 401 &&
    !isAuthEndpointUrl(input) &&
    typeof window !== "undefined"
  ) {
    window.dispatchEvent(new CustomEvent("ognivo:unauthenticated"));
  }

  return response;
}

export async function request<T>(
  endpoint: string,
  options: RequestInit & { passive?: boolean } = {}
): Promise<T> {
  const headers = new Headers(options.headers);
  if (!headers.has("Content-Type")) {
    headers.set("Content-Type", "application/json");
  }
  if (!headers.has("Accept")) {
    headers.set("Accept", "application/json");
  }

  if (currentLocale) {
    headers.set("Accept-Language", currentLocale);
    headers.set("X-Locale", currentLocale);
  }

  const url = `${API_BASE}${endpoint.startsWith("/") ? endpoint : `/${endpoint}`}`;
  let response: Response;

  try {
    response = await apiFetch(url, {
      ...options,
      headers,
    });
  } catch (fetchError) {
    if (
      fetchError instanceof TypeError &&
      (fetchError.message?.includes("fetch failed") || fetchError.name === "TypeError")
    ) {
      throw new ApiError(0, fetchError.message, null);
    }
    throw fetchError;
  }

  const data = await response.json().catch(() => ({}));

  if (!response.ok) {
    // Parse retryAfter from Response header for rate limit handling (Task 7)
    const retryAfterSeconds = parseInt(response.headers.get("Retry-After") || "0", 10);

    if (retryAfterSeconds > 0 && response.status === 429) {
      throw new ApiError(
        response.status,
        data.message || "Too Many Requests",
        data.errors || null,
        retryAfterSeconds
      );
    }

    const message = data.message || "An unexpected error occurred.";
    throw new ApiError(response.status, message, data.errors);
  }

  return data as T;
}

export interface NormalizedPaginationMeta {
  currentPage: number;
  lastPage: number;
  perPage: number;
  total: number;
  current_page: number;
  last_page: number;
  per_page: number;
}

/**
 * Extracts normalized pagination metadata from a paginated API response,
 * supporting both top-level Laravel paginator fields and nested `meta` objects.
 */
export function extractPaginationMeta<T = unknown>(
  res?: {
    data?: T[];
    meta?: {
      current_page?: number;
      last_page?: number;
      per_page?: number;
      total?: number;
    };
    current_page?: number;
    last_page?: number;
    per_page?: number;
    total?: number;
  } | null,
  fallbackPerPage = 10
): NormalizedPaginationMeta {
  const dataLen = Array.isArray(res?.data) ? res.data.length : 0;
  const currentPage = Math.max(1, res?.meta?.current_page ?? res?.current_page ?? 1);
  const lastPage = Math.max(1, res?.meta?.last_page ?? res?.last_page ?? 1);
  const perPage = Math.max(1, res?.meta?.per_page ?? res?.per_page ?? fallbackPerPage);
  const total = Math.max(0, res?.meta?.total ?? res?.total ?? dataLen);

  return {
    currentPage,
    lastPage,
    perPage,
    total,
    current_page: currentPage,
    last_page: lastPage,
    per_page: perPage,
  };
}
