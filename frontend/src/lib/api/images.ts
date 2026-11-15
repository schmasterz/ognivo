import { request } from "./client";
import { AdminImagesResponse, ImageItem } from "./types";

/**
 * Image allowlist and catalog API methods.
 */
export const imagesApi = {
  /**
   * Fetch approved and active images for workload provisioning.
   */
  async getImages(params?: {
    host_id?: number | null;
    instance_type?: string;
  }): Promise<{ data: ImageItem[] }> {
    const queryParams = new URLSearchParams();
    if (params?.host_id) queryParams.set("host_id", String(params.host_id));
    if (params?.instance_type) queryParams.set("instance_type", params.instance_type);
    const query = queryParams.toString() ? `?${queryParams.toString()}` : "";

    return request<{ data: ImageItem[] }>(`/images${query}`);
  },

  /**
   * Administrator: List all catalog images with optional filters.
   */
  async adminGetImages(
    params?: {
      search?: string;
      os_type?: string;
      protocol?: string;
      enabled?: boolean;
      host_id?: number | null;
      attachment?: "all" | "attached" | "detached";
      cursor?: string | null;
      per_page?: number;
    },
    options?: { signal?: AbortSignal }
  ): Promise<AdminImagesResponse> {
    const queryParams = new URLSearchParams();
    if (params?.search) queryParams.set("search", params.search);
    if (params?.os_type) queryParams.set("os_type", params.os_type);
    if (params?.protocol) queryParams.set("protocol", params.protocol);
    if (params?.enabled !== undefined) queryParams.set("enabled", String(params.enabled));
    if (params?.host_id !== undefined && params?.host_id !== null) queryParams.set("host_id", String(params.host_id));
    if (params?.attachment) queryParams.set("attachment", params.attachment);
    if (params?.cursor) queryParams.set("cursor", params.cursor);
    if (params?.per_page !== undefined && params?.per_page !== null) queryParams.set("per_page", String(params.per_page));
    const query = queryParams.toString() ? `?${queryParams.toString()}` : "";

    return request<AdminImagesResponse>(`/admin/images${query}`, { signal: options?.signal });
  },

  /**
   * Administrator: Add an approved image with compute node associations.
   */
  async adminCreateImage(params: {
    name: string;
    source: string;
    protocol?: string;
    os_type?: string;
    enabled?: boolean;
    host_ids?: number[];
  }): Promise<{ message: string; image: ImageItem }> {
    return request<{ message: string; image: ImageItem }>("/admin/images", {
      method: "POST",
      body: JSON.stringify(params),
    });
  },

  /**
   * Administrator: Update image properties and synchronize node associations.
   */
  async adminUpdateImage(
    id: number,
    params: {
      name?: string;
      source?: string;
      protocol?: string;
      os_type?: string;
      enabled?: boolean;
      host_ids?: number[];
    }
  ): Promise<{ message: string; image: ImageItem }> {
    return request<{ message: string; image: ImageItem }>(`/admin/images/${id}`, {
      method: "PUT",
      body: JSON.stringify(params),
    });
  },

  /**
   * Administrator: Remove an image from the allowlist.
   */
  async adminDeleteImage(id: number): Promise<{ message: string }> {
    return request<{ message: string }>(`/admin/images/${id}`, {
      method: "DELETE",
    });
  },
};
