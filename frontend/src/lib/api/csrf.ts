/**
 * Sanctum CSRF cookie helpers and unsafe HTTP method detection.
 */

const SAFE_METHODS = new Set(["GET", "HEAD", "OPTIONS"]);

let csrfCookiePromise: Promise<void> | null = null;

/**
 * Resets the memoized CSRF cookie promise so the next call re-fetches `/sanctum/csrf-cookie`.
 */
export function resetCsrfCookiePromise(): void {
  csrfCookiePromise = null;
}

/**
 * Fetches `/sanctum/csrf-cookie` with same-origin credentials once (memoized until reset).
 */
export function ensureCsrfCookie(
  fetchFn: (input: string, init?: RequestInit) => Promise<Response> = fetch
): Promise<void> {
  if (!csrfCookiePromise) {
    csrfCookiePromise = fetchFn("/sanctum/csrf-cookie", {
      method: "GET",
      credentials: "same-origin",
      headers: {
        Accept: "application/json",
      },
    })
      .then(() => undefined)
      .catch((err: unknown) => {
        csrfCookiePromise = null;
        throw err;
      });
  }

  return csrfCookiePromise;
}

/**
 * Reads and URL-decodes the `XSRF-TOKEN` cookie value from `document.cookie` or an injected cookie string.
 */
export function readXsrfToken(cookieString?: string): string | null {
  const source =
    cookieString !== undefined
      ? cookieString
      : typeof document !== "undefined"
        ? document.cookie
        : "";

  if (!source) {
    return null;
  }

  const cookies = source.split(";");
  for (const rawCookie of cookies) {
    const trimmed = rawCookie.trim();
    if (!trimmed) continue;

    const eqIndex = trimmed.indexOf("=");
    if (eqIndex === -1) continue;

    const name = trimmed.slice(0, eqIndex).trim();
    if (name !== "XSRF-TOKEN") continue;

    const rawValue = trimmed.slice(eqIndex + 1);
    if (!rawValue) {
      return null;
    }

    try {
      return decodeURIComponent(rawValue);
    } catch {
      return rawValue;
    }
  }

  return null;
}

/**
 * Returns `true` for state-changing HTTP methods other than `GET`, `HEAD`, and `OPTIONS`.
 */
export function isUnsafeMethod(method?: string): boolean {
  const normalized = (method ?? "GET").trim().toUpperCase() || "GET";
  return !SAFE_METHODS.has(normalized);
}
