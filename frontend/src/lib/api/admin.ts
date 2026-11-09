import { request } from "./client";
import { AdminUserItem, AdminStats, SystemSettings, PaginatedResponse, User } from "./types";

/**
 * Tenant User Administration, Cluster Statistics, and System Settings API Methods.
 */
export const adminApi = {
  async getAdminUsers(
    filters?: {
      user_id?: number | null;
      host_id?: number | null;
      status?: string | null;
      search?: string | null;
      page?: number | null;
      per_page?: number | null;
    },
    options?: { signal?: AbortSignal }
  ): Promise<PaginatedResponse<AdminUserItem>> {
    let query = "";
    if (filters) {
      const params = new URLSearchParams();
      if (filters.user_id) params.append("user_id", filters.user_id.toString());
      if (filters.host_id) params.append("host_id", filters.host_id.toString());
      if (filters.status) params.append("status", filters.status);
      if (filters.search) params.append("search", filters.search);
      if (filters.page) params.append("page", filters.page.toString());
      if (filters.per_page) params.append("per_page", filters.per_page.toString());
      const str = params.toString();
      if (str) query = `?${str}`;
    }
    return request<PaginatedResponse<AdminUserItem>>(`/admin/users${query}`, { signal: options?.signal });
  },

  async searchAdminUsers(
    query?: string,
    limit = 10,
    options?: { signal?: AbortSignal }
  ): Promise<{ data: Array<{ id: number; name: string; email: string }> }> {
    const params = new URLSearchParams();
    if (query) params.append("query", query);
    if (limit) params.append("limit", limit.toString());
    const qs = params.toString();
    return request<{ data: Array<{ id: number; name: string; email: string }> }>(
      `/admin/users/search${qs ? `?${qs}` : ""}`,
      { signal: options?.signal }
    );
  },

  async adminCreateUser(params: {
    name: string;
    email: string;
    password: string;
    role: "customer" | "admin";
    status?: "active" | "suspended" | "inactive" | "pending";
    quota_group_id?: number | null;
    quota?: {
      max_vms?: number;
      max_cpu_cores?: number;
      max_ram_mb?: number;
      max_storage_gb?: number;
      max_ips?: number;
      max_snapshots?: number;
    };
  }): Promise<{ message: string; user: AdminUserItem }> {
    return request<{ message: string; user: AdminUserItem }>("/admin/users", {
      method: "POST",
      body: JSON.stringify(params),
    });
  },

  async adminUpdateUser(
    userId: number,
    params: {
      name: string;
      email: string;
      password?: string;
      role: "customer" | "admin";
      status?: "active" | "suspended" | "inactive" | "pending";
      quota_group_id?: number | null;
      quota?: {
        max_vms?: number;
        max_cpu_cores?: number;
        max_ram_mb?: number;
        max_storage_gb?: number;
        max_ips?: number;
        max_snapshots?: number;
      };
    }
  ): Promise<{ message: string; user: AdminUserItem }> {
    return request<{ message: string; user: AdminUserItem }>(`/admin/users/${userId}`, {
      method: "PUT",
      body: JSON.stringify(params),
    });
  },

  async adminUpdateUserStatus(
    userId: number,
    status: "active" | "suspended" | "inactive" | "pending"
  ): Promise<{ message: string; user: AdminUserItem }> {
    return request<{ message: string; user: AdminUserItem }>(`/admin/users/${userId}/status`, {
      method: "POST",
      body: JSON.stringify({ status }),
    });
  },

  async adminGetUser(userId: number): Promise<{ user: AdminUserItem }> {
    return request<{ user: AdminUserItem }>(`/admin/users/${userId}`);
  },

  async adminDeleteUser(userId: number): Promise<{ message: string; status?: string }> {
    return request<{ message: string; status?: string }>(`/admin/users/${userId}`, {
      method: "DELETE",
    });
  },

  async getAdminStats(
    filters?: {
      user_id?: number | null;
      host_id?: number | null;
      status?: string | null;
      search?: string | null;
    },
    options?: { signal?: AbortSignal }
  ): Promise<{ overview: AdminStats }> {
    let query = "";
    if (filters) {
      const params = new URLSearchParams();
      if (filters.user_id) params.append("user_id", filters.user_id.toString());
      if (filters.host_id) params.append("host_id", filters.host_id.toString());
      if (filters.status) params.append("status", filters.status);
      if (filters.search) params.append("search", filters.search);
      const str = params.toString();
      if (str) query = `?${str}`;
    }
    return request<{ overview: AdminStats }>(`/admin/stats${query}`, { signal: options?.signal });
  },

  async getPublicSettings(): Promise<SystemSettings> {
    return request<SystemSettings>("/settings/public");
  },

  async getAdminSettings(): Promise<{ settings: SystemSettings }> {
    return request<{ settings: SystemSettings }>("/admin/settings");
  },

  async updateAdminSettings(params: Partial<SystemSettings>): Promise<{
    message: string;
    settings: SystemSettings;
  }> {
    return request<{ message: string; settings: SystemSettings }>("/admin/settings", {
      method: "PUT",
      body: JSON.stringify(params),
    });
  },

  async testSmtp(recipientEmail?: string): Promise<{ success: boolean; message: string }> {
    return request<{ success: boolean; message: string }>("/admin/settings/test-smtp", {
      method: "POST",
      body: JSON.stringify({ recipient_email: recipientEmail }),
    });
  },

  async adminImpersonateUser(
    userId: number
  ): Promise<{ token: string; user: User; expires_at: string; message: string }> {
    return request<{ token: string; user: User; expires_at: string; message: string }>(
      `/admin/users/${userId}/impersonate`,
      {
        method: "POST",
      }
    );
  },
};
