/**
 * Validates a post-login redirect path so only relative in-app URLs are accepted.
 */
export function sanitizeNextPath(
  raw: string | null,
  fallback = "/instances"
): string {
  if (!raw) {
    return fallback;
  }

  if (
    !raw.startsWith("/") ||
    raw.startsWith("//") ||
    raw.includes("\\") ||
    raw.includes(":")
  ) {
    return fallback;
  }

  for (let i = 0; i < raw.length; i += 1) {
    const code = raw.charCodeAt(i);
    if (code < 0x20 || code === 0x7f) {
      return fallback;
    }
  }

  return raw;
}
