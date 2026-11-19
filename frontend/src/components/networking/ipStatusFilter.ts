/**
 * Normalizes an incoming status filter for GET /api/ips.
 * Returns the value only if it is exactly "allocated" or "cooling", otherwise null.
 */
export function normalizeIpStatusFilter(
  status: string | null | undefined,
): "allocated" | "cooling" | null {
  if (status === "allocated" || status === "cooling") {
    return status;
  }
  return null;
}
