import { API_BASE, ApiError, request } from "./client";
import {
  HostNode,
  HostResourcesResponse,
  ImageSelectionPayload,
  PaginatedResponse,
  PanelCertificateInfo,
  PreviewImageDetachResponse,
  TestConnectionResponse,
} from "./types";

/**
 * Host Nodes and Multi-Region Hypervisor Clusters API Methods.
 */
export const hostsApi = {
  async getHosts(): Promise<{ data: HostNode[] }> {
    return request<{ data: HostNode[] }>("/hosts");
  },

  async adminGetHosts(params?: {
    page?: number | null;
    per_page?: number | null;
    search?: string | null;
    status?: string | null;
    is_active?: boolean | string | null;
    driver?: string | null;
  }): Promise<PaginatedResponse<HostNode>> {
    const queryParams = new URLSearchParams();
    if (params?.page) queryParams.set("page", String(params.page));
    if (params?.per_page) queryParams.set("per_page", String(params.per_page));
    if (params?.search && params.search.trim() !== "") {
      queryParams.set("search", params.search.trim());
    }
    if (params?.status && params.status.trim() !== "") {
      queryParams.set("status", params.status.trim());
    }
    if (
      params?.is_active !== undefined &&
      params?.is_active !== null &&
      String(params.is_active).trim() !== ""
    ) {
      queryParams.set("is_active", String(params.is_active));
    }
    if (params?.driver && params.driver.trim() !== "") {
      queryParams.set("driver", params.driver.trim());
    }
    const query = queryParams.toString() ? `?${queryParams.toString()}` : "";
    return request<PaginatedResponse<HostNode>>(`/admin/hosts${query}`);
  },

  async adminCreateHost(params: {
    name: string;
    region?: string | null;
    endpoint_url: string;
    driver?: string;
    token?: string | null;
    is_active?: boolean;
    total_cpu_cores?: number;
    total_ram_mb?: number;
    total_storage_gb?: number;
    cpu_overcommit?: number;
    ram_overcommit?: number;
    disk_overcommit?: number;
    settings?: Record<string, unknown> | null;
    auto_attach_images?: boolean;
    images?: ImageSelectionPayload;
  }): Promise<{ message: string; host: HostNode }> {
    return request<{ message: string; host: HostNode }>("/admin/hosts", {
      method: "POST",
      body: JSON.stringify(params),
    });
  },

  async adminUpdateHost(
    hostId: number,
    params: {
      name: string;
      region?: string | null;
      endpoint_url: string;
      driver?: string;
      token?: string | null;
      is_active?: boolean;
      total_cpu_cores?: number;
      total_ram_mb?: number;
      total_storage_gb?: number;
      cpu_overcommit?: number;
      ram_overcommit?: number;
      disk_overcommit?: number;
      settings?: Record<string, unknown> | null;
      auto_attach_images?: boolean;
      images?: ImageSelectionPayload;
    }
  ): Promise<{ message: string; host: HostNode }> {
    return request<{ message: string; host: HostNode }>(`/admin/hosts/${hostId}`, {
      method: "PUT",
      body: JSON.stringify(params),
    });
  },

  async adminDeleteHost(hostId: number): Promise<{ message: string }> {
    return request<{ message: string }>(`/admin/hosts/${hostId}`, {
      method: "DELETE",
    });
  },

  async adminTestHostConnection(params: {
    endpoint_url: string;
    driver?: string;
    token?: string | null;
    expected_fingerprint?: string | null;
    host_id?: number | null;
  }): Promise<TestConnectionResponse> {
    return request<TestConnectionResponse>("/admin/hosts/verify-connection", {
      method: "POST",
      body: JSON.stringify(params),
    });
  },

  async adminVerifyHostConnection(hostId: number): Promise<TestConnectionResponse> {
    return request<TestConnectionResponse>(`/admin/hosts/${hostId}/verify-connection`, {
      method: "POST",
    });
  },

  async adminGetHostResources(hostId: number): Promise<HostResourcesResponse> {
    return request<HostResourcesResponse>(`/admin/hosts/${hostId}/resources`);
  },

  async adminSyncHostResources(hostId: number): Promise<{ message: string; host: HostNode }> {
    return request<{ message: string; host: HostNode }>(`/admin/hosts/${hostId}/sync-resources`, {
      method: "POST",
    });
  },

  async adminRetrustHost(hostId: number, token: string): Promise<{ message: string; host: HostNode }> {
    return request<{ message: string; host: HostNode }>(`/admin/hosts/${hostId}/retrust`, {
      method: "POST",
      body: JSON.stringify({ token }),
    });
  },

  async adminConnectWithToken(params: {
    name: string;
    token: string;
    driver?: string;
    region?: string | null;
    is_active?: boolean;
    total_cpu_cores?: number;
    total_ram_mb?: number;
    total_storage_gb?: number;
    cpu_overcommit?: number;
    ram_overcommit?: number;
    disk_overcommit?: number;
    settings?: Record<string, unknown> | null;
    auto_attach_images?: boolean;
    images?: ImageSelectionPayload;
  }): Promise<{ message: string; host: HostNode }> {
    return request<{ message: string; host: HostNode }>("/admin/hosts/connect-token", {
      method: "POST",
      body: JSON.stringify(params),
    });
  },

  async adminAddWithCertificate(params: {
    name: string;
    address: string;
    expected_fingerprint: string;
    driver?: string;
    region?: string | null;
    is_active?: boolean;
    total_cpu_cores?: number;
    total_ram_mb?: number;
    total_storage_gb?: number;
    cpu_overcommit?: number;
    ram_overcommit?: number;
    disk_overcommit?: number;
    settings?: Record<string, unknown> | null;
    auto_attach_images?: boolean;
    images?: ImageSelectionPayload;
  }): Promise<{ message: string; host: HostNode }> {
    return request<{ message: string; host: HostNode }>("/admin/hosts/add-certificate", {
      method: "POST",
      body: JSON.stringify(params),
    });
  },

  async adminPreviewHostImageDetach(
    hostId: number,
    images: ImageSelectionPayload
  ): Promise<PreviewImageDetachResponse> {
    return request<PreviewImageDetachResponse>(
      `/admin/hosts/${hostId}/images/preview-detach`,
      {
        method: "POST",
        body: JSON.stringify({ images }),
      }
    );
  },

  async adminGetPanelCertificate(): Promise<PanelCertificateInfo> {
    return request<PanelCertificateInfo>("/admin/incus/certificate");
  },

  async adminDownloadPanelCertificate(): Promise<void> {
    const token =
      typeof window !== "undefined" ? localStorage.getItem("auth_token") : null;
    const url = `${API_BASE}/admin/incus/certificate/download`;

    const response = await fetch(url, {
      method: "GET",
      headers: {
        Accept: "application/x-x509-ca-cert, application/json",
        ...(token ? { Authorization: `Bearer ${token}` } : {}),
      },
    });

    if (!response.ok) {
      const errorData = await response.json().catch(() => ({}));
      throw new ApiError(
        response.status,
        (errorData as { message?: string }).message || "Failed to download panel certificate"
      );
    }

    const blob = await response.blob();
    const downloadUrl = window.URL.createObjectURL(blob);
    const a = document.createElement("a");
    a.href = downloadUrl;
    a.download = "incus-panel.crt";
    document.body.appendChild(a);
    a.click();
    a.remove();
    window.URL.revokeObjectURL(downloadUrl);
  },

  async adminRotatePanelCertificate(days: number = 365): Promise<{
    message: string;
    certificate: unknown;
    pending_retrust_hosts_count: number;
  }> {
    return request<{
      message: string;
      certificate: unknown;
      pending_retrust_hosts_count: number;
    }>("/admin/incus/certificate/rotate", {
      method: "POST",
      body: JSON.stringify({ days }),
    });
  },

  async adminRevokeOldPanelCertificate(): Promise<{
    message: string;
    cleanup_instruction: string;
  }> {
    return request<{ message: string; cleanup_instruction: string }>(
      "/admin/incus/certificate/revoke-old",
      {
        method: "POST",
      }
    );
  },
};
