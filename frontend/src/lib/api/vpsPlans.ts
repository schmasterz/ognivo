import { request } from "./client.ts";
import type {
  AdminVpsPlan,
  AdminVpsPlanChange,
  AdminVpsPlanChangeFilters,
  AdminVpsPlanFilters,
  CreateVpsPlanPayload,
  MetaPaginatedResponse,
  ResolveAdminPlanChangePayload,
  UpdateVpsPlanPayload,
  VpsPlanCatalogItem,
  VpsPlanProfilePayload,
  VpsPlanUsageResponse,
  VpsPlanVersion,
} from "./types.ts";

/**
 * VPS Catalog Plans and Administrative Plan Lifecycle API Methods.
 */
export const vpsPlansApi = {
  async listCatalog(options?: { signal?: AbortSignal }): Promise<VpsPlanCatalogItem[]> {
    const res = await request<{ data: VpsPlanCatalogItem[] }>("/vps/plans", {
      signal: options?.signal,
    });
    return res.data;
  },

  async getCatalogPlan(
    slug: string,
    options?: { signal?: AbortSignal }
  ): Promise<VpsPlanCatalogItem> {
    const res = await request<{ data: VpsPlanCatalogItem }>(
      `/vps/plans/${encodeURIComponent(slug)}`,
      { signal: options?.signal }
    );
    return res.data;
  },

  async listAdminPlans(
    filters?: AdminVpsPlanFilters,
    options?: { signal?: AbortSignal }
  ): Promise<MetaPaginatedResponse<AdminVpsPlan>> {
    const params = new URLSearchParams();
    if (filters?.status) params.set("status", filters.status);
    if (filters?.search) params.set("search", filters.search);
    if (filters?.page) params.set("page", String(filters.page));
    if (filters?.per_page) params.set("per_page", String(filters.per_page));
    const qs = params.toString() ? `?${params.toString()}` : "";
    return request<MetaPaginatedResponse<AdminVpsPlan>>(`/admin/vps/plans${qs}`, {
      signal: options?.signal,
    });
  },

  async createPlan(payload: CreateVpsPlanPayload): Promise<AdminVpsPlan> {
    const res = await request<{ data: AdminVpsPlan }>("/admin/vps/plans", {
      method: "POST",
      body: JSON.stringify(payload),
    });
    return res.data;
  },

  async getAdminPlan(
    id: number,
    options?: { signal?: AbortSignal }
  ): Promise<AdminVpsPlan> {
    const res = await request<{ data: AdminVpsPlan }>(`/admin/vps/plans/${id}`, {
      signal: options?.signal,
    });
    return res.data;
  },

  async updatePlan(id: number, payload: UpdateVpsPlanPayload): Promise<AdminVpsPlan> {
    const res = await request<{ data: AdminVpsPlan }>(`/admin/vps/plans/${id}`, {
      method: "PATCH",
      body: JSON.stringify(payload),
    });
    return res.data;
  },

  async publishPlan(id: number): Promise<AdminVpsPlan> {
    const res = await request<{ data: AdminVpsPlan }>(`/admin/vps/plans/${id}/publish`, {
      method: "POST",
    });
    return res.data;
  },

  async unpublishPlan(id: number): Promise<AdminVpsPlan> {
    const res = await request<{ data: AdminVpsPlan }>(`/admin/vps/plans/${id}/unpublish`, {
      method: "POST",
    });
    return res.data;
  },

  async archivePlan(id: number): Promise<AdminVpsPlan> {
    const res = await request<{ data: AdminVpsPlan }>(`/admin/vps/plans/${id}/archive`, {
      method: "POST",
    });
    return res.data;
  },

  async unarchivePlan(id: number): Promise<AdminVpsPlan> {
    const res = await request<{ data: AdminVpsPlan }>(`/admin/vps/plans/${id}/unarchive`, {
      method: "POST",
    });
    return res.data;
  },

  async listVersions(
    id: number,
    options?: { signal?: AbortSignal }
  ): Promise<VpsPlanVersion[]> {
    const res = await request<{ data: VpsPlanVersion[] }>(
      `/admin/vps/plans/${id}/versions`,
      { signal: options?.signal }
    );
    return res.data;
  },

  async upsertDraftVersion(
    id: number,
    payload: VpsPlanProfilePayload
  ): Promise<VpsPlanVersion> {
    const res = await request<{ data: VpsPlanVersion }>(
      `/admin/vps/plans/${id}/versions/draft`,
      {
        method: "PUT",
        body: JSON.stringify(payload),
      }
    );
    return res.data;
  },

  async discardDraftVersion(id: number): Promise<{ discarded: boolean }> {
    const res = await request<{ data: { discarded: boolean } }>(
      `/admin/vps/plans/${id}/versions/draft`,
      {
        method: "DELETE",
      }
    );
    return res.data;
  },

  async publishDraftVersion(id: number): Promise<VpsPlanVersion> {
    const res = await request<{ data: VpsPlanVersion }>(
      `/admin/vps/plans/${id}/versions/draft/publish`,
      {
        method: "POST",
      }
    );
    return res.data;
  },

  async getPlanUsage(
    id: number,
    options?: { signal?: AbortSignal }
  ): Promise<VpsPlanUsageResponse> {
    const res = await request<{ data: VpsPlanUsageResponse }>(
      `/admin/vps/plans/${id}/usage`,
      { signal: options?.signal }
    );
    return res.data;
  },

  async listAdminPlanChanges(
    filters?: AdminVpsPlanChangeFilters,
    options?: { signal?: AbortSignal }
  ): Promise<MetaPaginatedResponse<AdminVpsPlanChange>> {
    const params = new URLSearchParams();
    if (filters?.status) params.set("status", filters.status);
    if (filters?.user_id) params.set("user_id", String(filters.user_id));
    if (filters?.vm_id) params.set("vm_id", String(filters.vm_id));
    if (filters?.page) params.set("page", String(filters.page));
    if (filters?.per_page) params.set("per_page", String(filters.per_page));
    const qs = params.toString() ? `?${params.toString()}` : "";
    return request<MetaPaginatedResponse<AdminVpsPlanChange>>(
      `/admin/vps/plan-changes${qs}`,
      { signal: options?.signal }
    );
  },

  async getAdminPlanChange(
    publicId: string,
    options?: { signal?: AbortSignal }
  ): Promise<AdminVpsPlanChange> {
    const res = await request<{ data: AdminVpsPlanChange }>(
      `/admin/vps/plan-changes/${encodeURIComponent(publicId)}`,
      { signal: options?.signal }
    );
    return res.data;
  },

  async resolveAdminPlanChange(
    publicId: string,
    payload: ResolveAdminPlanChangePayload
  ): Promise<AdminVpsPlanChange> {
    const res = await request<{ data: AdminVpsPlanChange }>(
      `/admin/vps/plan-changes/${encodeURIComponent(publicId)}/resolve`,
      {
        method: "POST",
        body: JSON.stringify(payload),
      }
    );
    return res.data;
  },
};
