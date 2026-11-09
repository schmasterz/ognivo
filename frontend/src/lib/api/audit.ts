/**
 * Audit Log API Client Module.
 */

import { request, API_BASE, ApiError } from "./client";
import {
  AuditEntry,
  AdminAuditEntry,
  AuditFilters,
  CursorPage,
  AuditVerifyResponse,
} from "./types";

export function buildAuditQueryString(
  filters: AuditFilters = {},
  cursor?: string | null
): string {
  const params = new URLSearchParams();

  if (cursor) {
    params.set("cursor", cursor);
  }
  if (filters.category) {
    params.set("category", filters.category);
  }
  if (filters.action) {
    params.set("action", filters.action);
  }
  if (filters.outcome) {
    params.set("outcome", filters.outcome);
  }
  if (filters.from) {
    params.set("from", filters.from);
  }
  if (filters.to) {
    params.set("to", filters.to);
  }
  if (filters.actor_id !== undefined && filters.actor_id !== null) {
    params.set("actor_id", String(filters.actor_id));
  }
  if (filters.tenant_user_id !== undefined && filters.tenant_user_id !== null) {
    params.set("tenant_user_id", String(filters.tenant_user_id));
  }
  if (filters.ip) {
    params.set("ip", filters.ip);
  }
  if (filters.request_id) {
    params.set("request_id", filters.request_id);
  }
  if (filters.per_page) {
    params.set("per_page", String(filters.per_page));
  }

  return params.toString();
}

/**
 * Fetch tenant-scoped audit activity log with keyset cursor pagination.
 */
export async function getAudit(
  filters: AuditFilters = {},
  cursor?: string | null
): Promise<CursorPage<AuditEntry>> {
  const qs = buildAuditQueryString(filters, cursor);
  return request<CursorPage<AuditEntry>>(`/audit${qs ? `?${qs}` : ""}`);
}

/**
 * Fetch system-wide administrative audit logs with keyset cursor pagination.
 */
export async function getAdminAudit(
  filters: AuditFilters = {},
  cursor?: string | null
): Promise<CursorPage<AdminAuditEntry>> {
  const qs = buildAuditQueryString(filters, cursor);
  return request<CursorPage<AdminAuditEntry>>(`/admin/audit${qs ? `?${qs}` : ""}`);
}

/**
 * Fetch a single administrative audit log entry by ID.
 */
export async function getAdminAuditEntry(
  id: number
): Promise<{ data: AdminAuditEntry }> {
  return request<{ data: AdminAuditEntry }>(`/admin/audit/${id}`);
}

/**
 * Stream and download exported audit logs in NDJSON or CSV format using Bearer token.
 */
export async function exportAdminAudit(
  filters: AuditFilters = {},
  format: "ndjson" | "csv" = "ndjson"
): Promise<void> {
  const token =
    typeof window !== "undefined" ? localStorage.getItem("auth_token") : null;
  const qs = buildAuditQueryString(filters);
  const params = new URLSearchParams(qs);
  params.set("format", format);

  const url = `${API_BASE}/admin/audit/export?${params.toString()}`;

  const response = await fetch(url, {
    method: "GET",
    headers: {
      Accept: format === "csv" ? "text/csv" : "application/x-ndjson",
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
    },
  });

  if (!response.ok) {
    const errorData = await response.json().catch(() => ({}));
    throw new ApiError(
      response.status,
      (errorData as { message?: string }).message || "Failed to export audit logs"
    );
  }

  const blob = await response.blob();
  const downloadUrl = window.URL.createObjectURL(blob);
  const a = document.createElement("a");
  a.href = downloadUrl;
  a.download = format === "csv" ? "audit_export.csv" : "audit_export.ndjson";
  document.body.appendChild(a);
  a.click();
  a.remove();
  window.URL.revokeObjectURL(downloadUrl);
}

/**
 * Cryptographically verify recent audit seals and hash chain linkage.
 */
export async function verifyAdminAudit(
  limit: number = 50
): Promise<AuditVerifyResponse> {
  return request<AuditVerifyResponse>(`/admin/audit/verify?limit=${limit}`);
}

export const auditApi = {
  getAudit,
  getAdminAudit,
  getAdminAuditEntry,
  exportAdminAudit,
  verifyAdminAudit,
};
