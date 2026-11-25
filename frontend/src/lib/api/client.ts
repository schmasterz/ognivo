/**
 * Core HTTP Request Engine and Error Handling for LXD/Incus API Client.
 */
import { getRuntimeConfig } from "@/lib/config";

export const API_BASE =
  typeof window !== "undefined"
    ? (getRuntimeConfig().api_base_url || "/api/v1")
    : process.env.NEXT_PUBLIC_API_URL || "http://webserver/api/v1";

let currentLocale: string | null = null;

export function setApiLocale(locale: string | null): void {
  currentLocale = locale;
}

export class ApiError extends Error {
  constructor(
    public status: number,
    message: string,
    public errors?: Record<string, string[]> | null,
    public retryAfter?: number | null
  ) {
    super(message);
    this.name = "ApiError";
  }
}

export async function request<T>(
  endpoint: string,
  options: RequestInit = {}
): Promise<T> {
  const token =
    typeof window !== "undefined" ? localStorage.getItem("auth_token") : null;

  const headers: Record<string, string> = {
    "Content-Type": "application/json",
    Accept: "application/json",
    ...(options.headers as Record<string, string>),
  };

  if (currentLocale) {
    headers["Accept-Language"] = currentLocale;
    headers["X-Locale"] = currentLocale;
  }

  if (token) {
    headers["Authorization"] = `Bearer ${token}`;
  }

  const url = `${API_BASE}${endpoint.startsWith("/") ? endpoint : `/${endpoint}`}`;
  let response: Response;
  
  try {
    response = await fetch(url, {
      ...options,
      headers,
    });
  } catch (fetchError) {
    if (fetchError instanceof TypeError && (fetchError.message?.includes("fetch failed") || fetchError.name === "TypeError")) {
      throw new ApiError(0, fetchError.message, null);
    }
    throw fetchError;
  }

  const data = await response.json().catch(() => ({}));

  if (!response.ok) {
    // Parse retryAfter from Response header for rate limit handling (Task 7)
    const retryAfterSeconds = parseInt(response.headers.get('Retry-After') || '0');
    
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

