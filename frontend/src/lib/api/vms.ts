import { API_BASE, ApiError, apiFetch, request } from "./client.ts";
import type {
  MetaPaginatedResponse,
  PaginatedResponse,
  PlanChangePayload,
  VirtualMachine,
  VmMetricsRangeKey,
  VmMetricsResponse,
  VpsPlanChange,
  VpsPlanChangePreview,
  VpsPlanChangeTarget,
} from "./types.ts";

export interface CreateVmPlanPayload {
  name: string;
  os_image: string;
  vps_plan_id: number;
  host_id?: number | null;
  cloud_init?: { "user-data"?: string };
}

export interface CreateVmCustomPayload {
  name: string;
  os_image: string;
  instance_type?: "virtual-machine" | "container";
  cpu_cores: number;
  ram_mb: number;
  disk_gb: number;
  host_id?: number | null;
  cloud_init?: { "user-data"?: string };
}

export type CreateVmPayload = CreateVmPlanPayload | CreateVmCustomPayload;

export interface ConsoleSessionResponse {
  virtual_machine_id: number;
  session_id: string;
  ticket: string;
  websocket_path: string;
  expires_at: string;
  type: "console" | "vga";
  driver: "exec" | "console";
  fallback_reason?: string | null;
  idle_timeout?: number;
}

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

  async createVm(
    params: CreateVmPayload,
    options?: { idempotencyKey?: string }
  ): Promise<{ message: string; virtual_machine: VirtualMachine }> {
    const headers: Record<string, string> = {};
    if (options?.idempotencyKey) {
      headers["Idempotency-Key"] = options.idempotencyKey;
    }

    return request<{ message: string; virtual_machine: VirtualMachine }>("/vms", {
      method: "POST",
      headers,
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
    options?:
      | {
          type?: "console" | "vga";
          cols?: number;
          rows?: number;
          driver?: "auto" | "exec" | "console";
        }
      | "console"
      | "vga"
  ): Promise<ConsoleSessionResponse> {
    const opts = typeof options === "string" ? { type: options } : options;
    return request<ConsoleSessionResponse>(`/vms/${vmId}/console`, {
      method: "POST",
      body: JSON.stringify({
        type: opts?.type ?? "console",
        cols: opts?.cols ?? 80,
        rows: opts?.rows ?? 24,
        driver: opts?.driver,
      }),
    });
  },

  async closeConsoleSession(
    vmId: number,
    sessionId: string,
    reason: string = "client_disconnect",
    options?: { keepalive?: boolean }
  ): Promise<{ closed: boolean }> {
    return request<{ closed: boolean }>(`/vms/${vmId}/console/${encodeURIComponent(sessionId)}`, {
      method: "DELETE",
      body: JSON.stringify({ reason }),
      keepalive: options?.keepalive,
    });
  },

  async getVmMetrics(
    vmId: number,
    options?: {
      range?: VmMetricsRangeKey;
      metrics?: string[];
      maxPoints?: number;
      etag?: string | null;
      signal?: AbortSignal;
      passive?: boolean;
    }
  ): Promise<
    | { status: 200; data: VmMetricsResponse; etag: string | null }
    | { status: 304; data: null; etag: string | null }
  > {
    const params = new URLSearchParams();
    if (options?.range) params.set("range", options.range);
    if (options?.metrics && options.metrics.length > 0) {
      params.set("metrics", options.metrics.join(","));
    }
    if (options?.maxPoints !== undefined) {
      params.set("max_points", String(options.maxPoints));
    }
    const qs = params.toString() ? `?${params.toString()}` : "";

    const headers: Record<string, string> = {
      Accept: "application/json",
    };
    if (options?.etag) {
      headers["If-None-Match"] = options.etag;
    }

    let response: Response;
    try {
      response = await apiFetch(`${API_BASE}/vms/${vmId}/metrics${qs}`, {
        method: "GET",
        headers,
        signal: options?.signal,
        passive: options?.passive ?? false,
      });
    } catch (fetchError) {
      if (
        fetchError instanceof TypeError &&
        (fetchError.message?.includes("fetch failed") || fetchError.name === "TypeError")
      ) {
        throw new ApiError(0, fetchError.message, null);
      }
      throw fetchError;
    }

    if (response.status === 304) {
      return {
        status: 304,
        data: null,
        etag: response.headers.get("ETag") ?? options?.etag ?? null,
      };
    }

    if (!response.ok) {
      const errData = await response.json().catch(() => ({}));
      const retryAfterSeconds = parseInt(response.headers.get("Retry-After") || "0");
      if (retryAfterSeconds > 0 && response.status === 429) {
        throw new ApiError(
          response.status,
          errData.message || "Too Many Requests",
          errData.errors || null,
          retryAfterSeconds
        );
      }
      const message = errData.message || "An unexpected error occurred.";
      throw new ApiError(response.status, message, errData.errors);
    }

    const data = (await response.json()) as VmMetricsResponse;
    return {
      status: 200,
      data,
      etag: response.headers.get("ETag"),
    };
  },

  async getPlanChangeTargets(
    vmId: number,
    options?: { signal?: AbortSignal }
  ): Promise<VpsPlanChangeTarget[]> {
    const res = await request<{ data: VpsPlanChangeTarget[] }>(
      `/vms/${vmId}/plan-change/targets`,
      { signal: options?.signal }
    );
    return res.data;
  },

  async previewPlanChange(
    vmId: number,
    payload: PlanChangePayload,
    options?: { signal?: AbortSignal }
  ): Promise<VpsPlanChangePreview> {
    const res = await request<{ data: VpsPlanChangePreview }>(
      `/vms/${vmId}/plan-change/preview`,
      {
        method: "POST",
        body: JSON.stringify(payload),
        signal: options?.signal,
      }
    );
    return res.data;
  },

  async requestPlanChange(
    vmId: number,
    payload: PlanChangePayload,
    options?: { idempotencyKey?: string; signal?: AbortSignal }
  ): Promise<VpsPlanChange> {
    const headers: Record<string, string> = {};
    if (options?.idempotencyKey) {
      headers["Idempotency-Key"] = options.idempotencyKey;
    }

    const res = await request<{ data: VpsPlanChange }>(`/vms/${vmId}/plan-change`, {
      method: "POST",
      headers,
      body: JSON.stringify(payload),
      signal: options?.signal,
    });
    return res.data;
  },

  async getPlanChanges(
    vmId: number,
    filters?: { page?: number | null; per_page?: number | null },
    options?: { signal?: AbortSignal }
  ): Promise<MetaPaginatedResponse<VpsPlanChange>> {
    const params = new URLSearchParams();
    if (filters?.page) params.set("page", String(filters.page));
    if (filters?.per_page) params.set("per_page", String(filters.per_page));
    const qs = params.toString() ? `?${params.toString()}` : "";
    return request<MetaPaginatedResponse<VpsPlanChange>>(
      `/vms/${vmId}/plan-changes${qs}`,
      { signal: options?.signal }
    );
  },

  async getPlanChange(
    vmId: number,
    publicId: string,
    options?: { signal?: AbortSignal }
  ): Promise<VpsPlanChange> {
    const res = await request<{ data: VpsPlanChange }>(
      `/vms/${vmId}/plan-changes/${encodeURIComponent(publicId)}`,
      { signal: options?.signal }
    );
    return res.data;
  },

  async cancelPlanChange(vmId: number, publicId: string): Promise<VpsPlanChange> {
    const res = await request<{ data: VpsPlanChange }>(
      `/vms/${vmId}/plan-changes/${encodeURIComponent(publicId)}/cancel`,
      {
        method: "POST",
      }
    );
    return res.data;
  },
};
