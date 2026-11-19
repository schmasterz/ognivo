import { request } from "./client";
import {
  IpAllocation,
  IpPool,
  IpPoolAddressesResponse,
  IpPoolAddressState,
  MetaPaginatedResponse,
} from "./types";

/**
 * IPAM, Ranged Subnet Pools, and Allocation API Methods.
 */
export const networkingApi = {
  async getIps(
    filters?: {
      user_id?: number | null;
      host_id?: number | null;
      pool_id?: number | null;
      status?: string | null;
      search?: string | null;
      page?: number | null;
      per_page?: number | null;
    },
    options?: { signal?: AbortSignal }
  ): Promise<MetaPaginatedResponse<IpAllocation>> {
    const params = new URLSearchParams();
    if (filters?.user_id) params.set("user_id", String(filters.user_id));
    if (filters?.host_id) params.set("host_id", String(filters.host_id));
    if (filters?.pool_id) params.set("pool_id", String(filters.pool_id));
    if (filters?.status) params.set("status", filters.status);
    if (filters?.search) params.set("search", filters.search);
    if (filters?.page) params.set("page", String(filters.page));
    if (filters?.per_page) params.set("per_page", String(filters.per_page));

    const query = params.toString() ? `?${params.toString()}` : "";
    return request<MetaPaginatedResponse<IpAllocation>>(`/ips${query}`, { signal: options?.signal });
  },

  async getIpPools(
    filters?: {
      host_id?: number | null;
      page?: number | null;
      per_page?: number | null;
    },
    options?: { signal?: AbortSignal }
  ): Promise<MetaPaginatedResponse<IpPool>> {
    const params = new URLSearchParams();
    if (filters?.host_id) params.set("host_id", String(filters.host_id));
    if (filters?.page) params.set("page", String(filters.page));
    if (filters?.per_page) params.set("per_page", String(filters.per_page));
    const query = params.toString() ? `?${params.toString()}` : "";
    return request<MetaPaginatedResponse<IpPool>>(`/ip-pools${query}`, { signal: options?.signal });
  },

  async adminGetIpPools(
    filters?: {
      host_id?: number | null;
      page?: number | null;
      per_page?: number | null;
    },
    options?: { signal?: AbortSignal }
  ): Promise<MetaPaginatedResponse<IpPool>> {
    const params = new URLSearchParams();
    if (filters?.host_id) params.set("host_id", String(filters.host_id));
    if (filters?.page) params.set("page", String(filters.page));
    if (filters?.per_page) params.set("per_page", String(filters.per_page));
    const query = params.toString() ? `?${params.toString()}` : "";
    return request<MetaPaginatedResponse<IpPool>>(`/admin/ip-pools${query}`, { signal: options?.signal });
  },

  async adminReleaseIp(
    allocationId: number,
    forceDelete: boolean = false
  ): Promise<{ message: string }> {
    const query = forceDelete ? "?force_delete=1" : "";
    return request<{ message: string }>(`/admin/ips/${allocationId}/release${query}`, {
      method: "DELETE",
    });
  },

  async releaseIp(allocationId: number): Promise<{ message: string }> {
    return networkingApi.adminReleaseIp(allocationId, false);
  },

  async adminCreateIpPool(params: {
    name: string;
    host_id: number;
    cidr: string;
    subnet?: string;
    first_usable?: string;
    last_usable?: string;
    parent_interface?: string;
    network_mode?: string;
    gateway?: string;
    dns_servers?: string[];
    excluded_ips?: string[];
    cooldown_seconds?: number;
    ip_version?: number;
    is_public?: boolean;
    description?: string | null;
  }): Promise<{ message: string; pool: IpPool }> {
    return request<{ message: string; pool: IpPool }>("/admin/ip-pools", {
      method: "POST",
      body: JSON.stringify(params),
    });
  },

  async adminDeleteIpPool(poolId: number): Promise<{ message: string }> {
    return request<{ message: string }>(`/admin/ip-pools/${poolId}`, {
      method: "DELETE",
    });
  },

  async adminUpdateIpPool(
    poolId: number,
    params: {
      name?: string;
      description?: string | null;
      dns_servers?: string[];
      excluded_ips?: string[];
      cooldown_seconds?: number;
      active?: boolean;
      is_public?: boolean;
      gateway?: string | null;
      first_usable?: string;
      last_usable?: string;
      cidr?: string;
      host_id?: number;
      network_mode?: string;
      parent_interface?: string;
    }
  ): Promise<{ message: string; pool: IpPool }> {
    return request<{ message: string; pool: IpPool }>(`/admin/ip-pools/${poolId}`, {
      method: "PUT",
      body: JSON.stringify(params),
    });
  },

  async adminPreflightIpPool(
    poolId: number
  ): Promise<{
    ok: boolean;
    checks: Array<{ name: string; ok: boolean; hint: string }>;
  }> {
    return request<{
      ok: boolean;
      checks: Array<{ name: string; ok: boolean; hint: string }>;
    }>(`/admin/ip-pools/${poolId}/preflight`, {
      method: "POST",
    });
  },

  async adminGetIpPoolAddresses(
    poolId: number,
    params?: {
      state?: "all" | IpPoolAddressState;
      search?: string | null;
      page?: number;
      per_page?: number;
    },
    options?: { signal?: AbortSignal }
  ): Promise<IpPoolAddressesResponse> {
    const queryParams = new URLSearchParams();
    if (params?.state) queryParams.set("state", params.state);
    if (params?.search) queryParams.set("search", params.search);
    if (params?.page) queryParams.set("page", String(params.page));
    if (params?.per_page) queryParams.set("per_page", String(params.per_page));
    const query = queryParams.toString() ? `?${queryParams.toString()}` : "";
    return request<IpPoolAddressesResponse>(`/admin/ip-pools/${poolId}/addresses${query}`, {
      signal: options?.signal,
    });
  },
};
