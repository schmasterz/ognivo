import { request } from "./client";
import { VirtualMachine, PaginatedResponse } from "./types";

/**
 * Virtual Machines and Containers Lifecycle API Methods.
 */
export const vmsApi = {
  async getVms(
    filters?: {
      user_id?: number | null;
      host_id?: number | null;
      status?: string | null;
      search?: string | null;
      instance_type?: string | null;
      page?: number | null;
      per_page?: number | null;
    },
    options?: { signal?: AbortSignal }
  ): Promise<PaginatedResponse<VirtualMachine>> {
    const params = new URLSearchParams();
    if (filters?.user_id) params.set("user_id", String(filters.user_id));
    if (filters?.host_id) params.set("host_id", String(filters.host_id));
    if (filters?.status) params.set("status", filters.status);
    if (filters?.search) params.set("search", filters.search);
    if (filters?.instance_type) params.set("instance_type", filters.instance_type);
    if (filters?.page) params.set("page", String(filters.page));
    if (filters?.per_page) params.set("per_page", String(filters.per_page));

    const query = params.toString() ? `?${params.toString()}` : "";
    return request<PaginatedResponse<VirtualMachine>>(`/vms${query}`, { signal: options?.signal });
  },

  async createVm(params: {
    name: string;
    instance_type?: "virtual-machine" | "container";
    cpu_cores: number;
    ram_mb: number;
    disk_gb: number;
    os_image: string;
    host_id?: number | null;
  }): Promise<{ message: string; virtual_machine: VirtualMachine }> {
    return request<{ message: string; virtual_machine: VirtualMachine }>("/vms", {
      method: "POST",
      body: JSON.stringify(params),
    });
  },

  async vmAction(
    vmId: number,
    action: "start" | "stop" | "restart" | "suspend" | "delete" | "reinstall",
    force: boolean = false,
    options?: {
      os_image?: string;
      cloud_init?: { "user-data"?: string };
    }
  ): Promise<{ message: string; virtual_machine: VirtualMachine }> {
    return request<{ message: string; virtual_machine: VirtualMachine }>(`/vms/${vmId}/action`, {
      method: "POST",
      body: JSON.stringify({ action, force, ...options }),
    });
  },

  async action(
    vmId: number,
    action: "start" | "stop" | "restart" | "suspend" | "delete" | "reinstall",
    payload?:
      | {
          force?: boolean;
          os_image?: string;
          cloud_init?: { "user-data"?: string };
        }
      | boolean
  ): Promise<{ message: string; virtual_machine: VirtualMachine }> {
    const body =
      typeof payload === "boolean"
        ? { action, force: payload }
        : { action, ...payload };
    return request<{ message: string; virtual_machine: VirtualMachine }>(`/vms/${vmId}/action`, {
      method: "POST",
      body: JSON.stringify(body),
    });
  },

  async reinstall(
    vmId: number,
    payload?: {
      os_image?: string;
      cloud_init?: { "user-data"?: string };
    }
  ): Promise<{ message: string; virtual_machine: VirtualMachine }> {
    return request<{ message: string; virtual_machine: VirtualMachine }>(`/vms/${vmId}/action`, {
      method: "POST",
      body: JSON.stringify({ action: "reinstall", ...payload }),
    });
  },

  async getConsoleLog(
    vmId: number
  ): Promise<{ virtual_machine_id: number; name: string; output: string }> {
    return request<{ virtual_machine_id: number; name: string; output: string }>(
      `/vms/${vmId}/console-log`
    );
  },

  async getConsoleSession(
    vmId: number,
    type: "console" | "vga" = "console"
  ): Promise<{
    virtual_machine_id: number;
    ticket: string;
    websocket_path: string;
    expires_at: string;
    type: "console" | "vga";
  }> {
    return request<{
      virtual_machine_id: number;
      ticket: string;
      websocket_path: string;
      expires_at: string;
      type: "console" | "vga";
    }>(`/vms/${vmId}/console?type=${type}`, {
      method: "POST",
    });
  },
};
