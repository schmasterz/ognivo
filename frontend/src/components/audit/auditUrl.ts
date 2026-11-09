import type { AuditFilters } from "@/lib/api/types";

export function parseFiltersFromUrl(): AuditFilters {
  if (typeof window === "undefined") return {};
  const params = new URLSearchParams(window.location.search);
  const filters: AuditFilters = {};

  const category = params.get("category");
  if (category) filters.category = category;

  const action = params.get("action");
  if (action) filters.action = action;

  const outcome = params.get("outcome");
  if (outcome === "success" || outcome === "denied" || outcome === "failed") {
    filters.outcome = outcome;
  }

  const from = params.get("from");
  if (from) filters.from = from;

  const to = params.get("to");
  if (to) filters.to = to;

  const ip = params.get("ip");
  if (ip) filters.ip = ip;

  const actor_id = params.get("actor_id");
  if (actor_id) filters.actor_id = parseInt(actor_id, 10);

  const tenant_user_id = params.get("tenant_user_id");
  if (tenant_user_id) filters.tenant_user_id = parseInt(tenant_user_id, 10);

  const request_id = params.get("request_id");
  if (request_id) filters.request_id = request_id;

  const per_page = params.get("per_page");
  if (per_page) {
    const parsedPerPage = parseInt(per_page, 10);
    if (!Number.isNaN(parsedPerPage) && parsedPerPage > 0) {
      filters.per_page = parsedPerPage;
    }
  }

  return filters;
}

export function syncFiltersToUrl(filters: AuditFilters) {
  if (typeof window === "undefined") return;
  const params = new URLSearchParams();

  if (filters.category) params.set("category", filters.category);
  if (filters.action) params.set("action", filters.action);
  if (filters.outcome) params.set("outcome", filters.outcome);
  if (filters.from) params.set("from", filters.from);
  if (filters.to) params.set("to", filters.to);
  if (filters.actor_id !== undefined && filters.actor_id !== null) {
    params.set("actor_id", String(filters.actor_id));
  }
  if (filters.tenant_user_id !== undefined && filters.tenant_user_id !== null) {
    params.set("tenant_user_id", String(filters.tenant_user_id));
  }
  if (filters.ip) params.set("ip", filters.ip);
  if (filters.request_id) params.set("request_id", filters.request_id);
  if (filters.per_page) params.set("per_page", String(filters.per_page));

  const query = params.toString();
  const newUrl = `${window.location.pathname}${query ? `?${query}` : ""}`;
  window.history.replaceState(null, "", newUrl);
}

/**
 * Computes normalized pagination metadata (total and lastPage) for a keyset cursor page response.
 */
export function computeCursorPaginationMeta(params: {
  currentPage: number;
  perPage: number;
  itemsCount: number;
  nextCursor: string | null;
  reportedTotal?: number;
}): { total: number; lastPage: number } {
  const { currentPage, perPage, itemsCount, nextCursor, reportedTotal } = params;
  const safePerPage = Math.max(1, perPage);
  const safePage = Math.max(1, currentPage);

  const fallbackTotal =
    itemsCount === 0 && safePage === 1
      ? 0
      : (safePage - 1) * safePerPage + itemsCount + (nextCursor ? 1 : 0);

  const total =
    typeof reportedTotal === "number" && reportedTotal >= 0
      ? reportedTotal
      : fallbackTotal;

  let lastPage = Math.max(1, Math.ceil(total / safePerPage));
  if (nextCursor && safePage >= lastPage) {
    lastPage = safePage + 1;
  }

  return { total, lastPage };
}

