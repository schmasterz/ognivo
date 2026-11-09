import { request } from "./client";
import {
  VmSnapshotsResponse,
  SnapshotListResponse,
  CreateSnapshotPayload,
  SnapshotActionResponse,
} from "./types";

/**
 * Virtual Machine & Container Snapshots API Methods.
 */
export const snapshotsApi = {
  /**
   * List all snapshots across virtual machines (tenant-isolated or platform-wide for admin).
   */
  async getAllSnapshots(
    params?: {
      vm_id?: number | null;
      status?: string | null;
      source?: string | null;
      search?: string | null;
      user_id?: number | null;
      page?: number;
      per_page?: number;
    },
    options?: { signal?: AbortSignal }
  ): Promise<SnapshotListResponse> {
    const searchParams = new URLSearchParams();
    if (params?.vm_id) searchParams.set("vm_id", String(params.vm_id));
    if (params?.status) searchParams.set("status", params.status);
    if (params?.source) searchParams.set("source", params.source);
    if (params?.search) searchParams.set("search", params.search);
    if (params?.user_id) searchParams.set("user_id", String(params.user_id));
    if (params?.page) searchParams.set("page", String(params.page));
    if (params?.per_page) searchParams.set("per_page", String(params.per_page));
    const query = searchParams.toString() ? `?${searchParams.toString()}` : "";
    return request<SnapshotListResponse>(`/snapshots${query}`, {
      signal: options?.signal,
    });
  },

  /**
   * List all snapshots and quota usage metadata for a specific virtual machine.
   */
  async getSnapshots(
    vmId: number,
    options?: { signal?: AbortSignal }
  ): Promise<VmSnapshotsResponse> {
    return request<VmSnapshotsResponse>(`/vms/${vmId}/snapshots`, {
      signal: options?.signal,
    });
  },

  /**
   * Request asynchronous snapshot creation for a virtual machine.
   */
  async createSnapshot(
    vmId: number,
    payload: CreateSnapshotPayload
  ): Promise<SnapshotActionResponse> {
    return request<SnapshotActionResponse>(`/vms/${vmId}/snapshots`, {
      method: "POST",
      body: JSON.stringify(payload),
    });
  },

  /**
   * Request asynchronous restore of a snapshot on a virtual machine.
   */
  async restoreSnapshot(
    vmId: number,
    snapshotId: number | string
  ): Promise<SnapshotActionResponse> {
    return request<SnapshotActionResponse>(
      `/vms/${vmId}/snapshots/${snapshotId}/restore`,
      {
        method: "POST",
      }
    );
  },

  /**
   * Request asynchronous deletion of a snapshot.
   */
  async deleteSnapshot(
    vmId: number,
    snapshotId: number | string
  ): Promise<SnapshotActionResponse> {
    return request<SnapshotActionResponse>(
      `/vms/${vmId}/snapshots/${snapshotId}`,
      {
        method: "DELETE",
      }
    );
  },
};
