import { request } from "./client";
import { QuotaResponse, UserQuota, QuotaGroup, AdminUserItem, PaginatedResponse } from "./types";

export interface QuotaFilters {
  user_id?: number | null;
  host_id?: number | null;
  status?: string | null;
  search?: string | null;
}

/**
 * Resource Quotas and Quota Groups (Tiers) API Methods.
 */
export const quotasApi = {
  async getQuota(
    filters?: QuotaFilters | number | null,
    options?: { signal?: AbortSignal }
  ): Promise<QuotaResponse> {
    let query = "";
    if (typeof filters === "number") {
      query = `?user_id=${filters}`;
    } else if (filters && typeof filters === "object") {
      const params = new URLSearchParams();
      if (filters.user_id) params.append("user_id", filters.user_id.toString());
      if (filters.host_id) params.append("host_id", filters.host_id.toString());
      if (filters.status) params.append("status", filters.status);
      if (filters.search) params.append("search", filters.search);
      const str = params.toString();
      if (str) query = `?${str}`;
    }
    return request<QuotaResponse>(`/quota${query}`, { signal: options?.signal });
  },

  async getDefaultQuota(): Promise<{ quota: UserQuota }> {
    return request<{ quota: UserQuota }>("/admin/quota/default");
  },

  async updateDefaultQuota(quota: {
    max_vms: number;
    max_cpu_cores: number;
    max_ram_mb: number;
    max_storage_gb: number;
    max_ips: number;
    max_snapshots?: number;
  }): Promise<{ message: string; quota: UserQuota }> {
    return request<{ message: string; quota: UserQuota }>("/admin/quota/default", {
      method: "PUT",
      body: JSON.stringify(quota),
    });
  },

  async getQuotaGroups(params?: {
    page?: number | null;
    per_page?: number | null;
  }): Promise<PaginatedResponse<QuotaGroup>> {
    const queryParams = new URLSearchParams();
    if (params?.page) queryParams.set("page", String(params.page));
    if (params?.per_page) queryParams.set("per_page", String(params.per_page));
    const query = queryParams.toString() ? `?${queryParams.toString()}` : "";
    return request<PaginatedResponse<QuotaGroup>>(`/admin/quota-groups${query}`);
  },

  async createQuotaGroup(params: {
    name: string;
    description?: string;
    is_default?: boolean;
    max_vms: number;
    max_cpu_cores: number;
    max_ram_mb: number;
    max_storage_gb: number;
    max_ips: number;
    max_snapshots?: number;
  }): Promise<{ message: string; quota_group: QuotaGroup }> {
    return request<{ message: string; quota_group: QuotaGroup }>("/admin/quota-groups", {
      method: "POST",
      body: JSON.stringify(params),
    });
  },

  async updateQuotaGroup(
    groupId: number,
    params: {
      name: string;
      description?: string;
      is_default?: boolean;
      max_vms: number;
      max_cpu_cores: number;
      max_ram_mb: number;
      max_storage_gb: number;
      max_ips: number;
      max_snapshots?: number;
    }
  ): Promise<{ message: string; quota_group: QuotaGroup }> {
    return request<{ message: string; quota_group: QuotaGroup }>(
      `/admin/quota-groups/${groupId}`,
      {
        method: "PUT",
        body: JSON.stringify(params),
      }
    );
  },

  async deleteQuotaGroup(groupId: number): Promise<{ message: string }> {
    return request<{ message: string }>(`/admin/quota-groups/${groupId}`, {
      method: "DELETE",
    });
  },

  async assignUserQuotaGroup(
    userId: number,
    quotaGroupId: number
  ): Promise<{ message: string; user: AdminUserItem }> {
    return request<{ message: string; user: AdminUserItem }>(
      `/admin/users/${userId}/assign-quota-group`,
      {
        method: "POST",
        body: JSON.stringify({ quota_group_id: quotaGroupId }),
      }
    );
  },
};
