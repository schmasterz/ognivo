/**
 * Shared Domain Types and DTO Interfaces for LXD / Incus Control Plane.
 */

export interface TestConnectionResponse {
  state?: "ok" | "unreachable" | "fingerprint_mismatch" | "not_trusted" | "error";
  success: boolean;
  message: string;
  latency_ms?: number;
  server?: string;
  server_version?: string;
  auth?: string;
  is_trusted?: boolean;
  status_code?: number;
  details?: Record<string, unknown>;
}

export interface PanelCertificateMetadata {
  id: number;
  fingerprint: string;
  not_before: string;
  not_after: string;
  status: "active" | "retiring" | "revoked";
  is_expired?: boolean;
  is_expiring_soon?: boolean;
  days_until_expiration?: number;
}

export interface PanelCertificateInfo {
  certificate: PanelCertificateMetadata | null;
  retiring_certificate?: {
    id: number;
    fingerprint: string;
    status: string;
    not_after: string;
  } | null;
  pending_retrust_hosts_count: number;
}

export interface TenantHost {
  id: number;
  name: string;
  region?: string | null;
  driver: "incus" | "lxd" | "fake" | string;
  is_active: boolean;
}

export interface HostNode extends TenantHost {
  endpoint_url?: string;
  server_fingerprint?: string | null;
  status?: "ok" | "unreachable" | "fingerprint_mismatch" | "not_trusted" | "error" | string;
  needs_retrust?: boolean;
  last_checked_at?: string | null;
  has_token?: boolean;
  token?: string | null;
  total_cpu_cores: number;
  total_ram_mb: number;
  total_storage_gb: number;
  virtual_machines_count?: number;
  ip_pools_count?: number;
  created_at?: string;
  updated_at?: string;
}

export interface QuotaGroup {
  id: number;
  name: string;
  description: string | null;
  is_default: boolean;
  max_vms: number;
  max_cpu_cores: number;
  max_ram_mb: number;
  max_storage_gb: number;
  max_ips: number;
  max_snapshots: number;
  users_count?: number;
  created_at: string;
}

export interface User {
  id: number;
  name: string;
  email: string;
  status?: "active" | "suspended" | "inactive" | "pending" | "deleting" | "deletion_failed";
  email_verified_at?: string | null;
  project_name?: string | null;
  quota_group_id?: number | null;
  quota_group?: QuotaGroup | null;
  roles?: { id?: number; name: string }[];
  created_at: string;
}

export interface UserQuota {
  id?: number;
  user_id?: number;
  max_vms: number;
  max_cpu_cores: number;
  max_ram_mb: number;
  max_storage_gb: number;
  max_ips: number;
  max_snapshots: number;
}

export interface QuotaResponse {
  user?: {
    id: number;
    name: string;
    email: string;
  };
  quota: UserQuota;
  usage: {
    vms: number;
    cpu_cores: number;
    ram_mb: number;
    disk_gb: number;
    snapshots?: number;
  };
  available: {
    vms: number;
    cpu_cores: number;
    ram_mb: number;
    disk_gb: number;
    snapshots?: number;
  };
  filters?: {
    host_id?: number | null;
    status?: string | null;
    search?: string | null;
  };
}

export interface VmNetworkPrimary {
  address: string | null;
  family: number;
  status: "assigned" | "observed" | "unverified" | "mismatch" | "unassigned" | null;
  mac_address?: string | null;
}

export interface VmNetworkSettings {
  prefix?: number | null;
  gateway?: string | null;
  dns?: string[] | null;
  mode?: string | null;
}

export interface VmNetwork {
  primary: VmNetworkPrimary;
  settings: VmNetworkSettings;
  observed_at: string | null;
}

export interface VirtualMachine {
  id: number;
  user_id: number;
  host_id: number | null;
  host?: HostNode | null;
  name: string;
  incus_project: string;
  instance_type: "virtual-machine" | "container";
  status: string;
  cpu_cores: number;
  ram_mb: number;
  disk_gb: number;
  os_image: string;
  ipv4_address: string | null;
  ipv6_address: string | null;
  mac_address?: string | null;
  network_status?: "assigned" | "observed" | "unverified" | "mismatch" | null;
  network?: VmNetwork;
  created_at: string;
  updated_at: string;
  user?: {
    id: number;
    name: string;
    email: string;
  };
}

export type VmSnapshotStatus = "creating" | "ready" | "restoring" | "deleting" | "failed";

export interface VmSnapshot {
  id: number;
  virtual_machine_id?: number;
  virtual_machine?: {
    id: number;
    name: string;
    incus_project?: string;
    status?: string;
  };
  user_id?: number;
  user?: {
    id: number;
    name: string;
    email: string;
  };
  name: string;
  source: "manual" | "scheduled" | string;
  status: VmSnapshotStatus | string;
  stateful: boolean;
  size_bytes: number | null;
  error?: string | null;
  created_at?: string;
  updated_at?: string;
}

export interface VmSnapshotMeta {
  used: number;
  max_snapshots: number;
  remaining: number;
  current_page?: number;
  last_page?: number;
  per_page?: number;
  total?: number;
}

export interface VmSnapshotsResponse {
  data: VmSnapshot[];
  meta: VmSnapshotMeta;
  current_page?: number;
  last_page?: number;
  per_page?: number;
  total?: number;
}

export interface SnapshotListResponse {
  data: VmSnapshot[];
  meta?: VmSnapshotMeta;
  current_page?: number;
  last_page?: number;
  per_page?: number;
  total?: number;
}

export interface CreateSnapshotPayload {
  name: string;
  stateful?: boolean;
}

export interface SnapshotActionResponse {
  message: string;
  snapshot: VmSnapshot;
  data: VmSnapshot;
}

export interface PaginationMeta {
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
  from?: number | null;
  to?: number | null;
}

export interface PaginatedResponse<T> {
  data: T[];
  meta?: PaginationMeta;
  current_page: number;
  last_page: number;
  per_page?: number;
  total: number;
}

export interface TenantIpPool {
  id: number;
  name: string;
  host_id: number | null;
  free: boolean;
}

export interface IpPool {
  id: number;
  name: string;
  cidr?: string;
  subnet?: string;
  first_usable?: string;
  last_usable?: string;
  parent_interface?: string;
  network_mode?: string;
  gateway?: string | null;
  dns_servers?: string[] | null;
  excluded_ips?: string[] | null;
  ip_version?: number;
  is_public?: boolean;
  active?: boolean;
  cooldown_seconds?: number;
  host_id?: number | null;
  host?: HostNode | null;
  vlan_id?: number | null;
  description?: string | null;
  free?: boolean;
  utilization?: {
    total: number;
    allocated: number;
    cooling: number;
    free: number;
    percent: number;
  };
  total_ips?: number;
  free_ips?: number;
  allocated_ips?: number;
  cooling_ips?: number;
}

export interface IpAllocation {
  id: number;
  pool_id: number;
  virtual_machine_id: number | null;
  user_id: number | null;
  address: string;
  ip_string?: string;
  mac_address: string;
  status: "allocated" | "cooling" | "released";
  cooldown_remaining_seconds?: number;
  allocated_at?: string | null;
  released_at?: string | null;
  pool?: IpPool;
  virtual_machine?: VirtualMachine | null;
  user?: {
    id: number;
    name: string;
    email: string;
  } | null;
  created_at?: string;
  updated_at?: string;
}

export interface AdminUserItem {
  id: number;
  name: string;
  email: string;
  status: "active" | "suspended" | "inactive" | "pending" | "deleting" | "deletion_failed";
  email_verified_at?: string | null;
  project_name?: string | null;
  created_at: string;
  roles: { id: number; name: string }[];
  quota_group_id?: number | null;
  quota_group?: QuotaGroup | null;
  quota?: UserQuota;
  virtual_machines_count?: number;
  ip_allocations_count?: number;
}

export interface AdminStats {
  total_users: number;
  active_hosts: number;
  total_vms: number;
  running_vms: number;
  allocated_cpu_cores: number;
  allocated_ram_mb: number;
  allocated_disk_gb: number;
  total_ips: number;
  allocated_ips: number;
  total_snapshots?: number;
}

export interface SmtpSettings {
  enabled: boolean;
  host: string | null;
  port: number;
  encryption: "tls" | "ssl" | "none";
  username: string | null;
  password?: string | null;
  has_password?: boolean;
  from_address: string | null;
  from_name: string | null;
}

export interface SystemSettings {
  registration_enabled: boolean;
  allow_virtual_machines: boolean;
  allow_containers: boolean;
  frontend_url?: string;
  smtp?: SmtpSettings;
}

export interface UpdateSystemSettingsPayload {
  registration_enabled?: boolean;
  allow_virtual_machines?: boolean;
  allow_containers?: boolean;
  frontend_url?: string;
  smtp?: Partial<SmtpSettings>;
}

export interface ImageItem {
  id: number;
  name: string;
  source: string;
  protocol: string;
  os_type: "linux" | "windows" | "bsd" | "other";
  enabled: boolean;
  hosts?: HostNode[];
  host_ids?: number[];
  created_at?: string;
  updated_at?: string;
}

export interface CursorPage<T> {
  data: T[];
  next_cursor: string | null;
  per_page?: number;
  total?: number;
}

export interface AuditActor {
  kind: "you" | "platform_admin" | "system";
  impersonator?: string;
  impersonator_label?: string;
}

export interface AuditEntry {
  id: number;
  occurred_at: string;
  action: string;
  outcome: "success" | "denied" | "failed";
  subject_type: string | null;
  subject_id: string | null;
  subject_label: string | null;
  actor: AuditActor;
  ip: string | null;
  user_agent: string | null;
  context: Record<string, unknown> | null;
}

export interface AdminAuditEntry {
  id: number;
  occurred_at: string;
  request_id: string | null;
  operation_id: number | null;
  actor_type: "user" | "system" | "job" | string;
  actor_id: number | null;
  actor_label: string | null;
  impersonator_id: number | null;
  tenant_user_id: number | null;
  action: string;
  outcome: "success" | "denied" | "failed";
  subject_type: string | null;
  subject_id: string | null;
  subject_label: string | null;
  ip: string | null;
  user_agent: string | null;
  visibility: "tenant" | "admin" | string;
  context: Record<string, unknown> | null;
}

export interface AuditFilters {
  category?: string;
  action?: string;
  outcome?: "success" | "denied" | "failed" | "";
  from?: string;
  to?: string;
  actor_id?: number | null;
  tenant_user_id?: number | null;
  ip?: string;
  request_id?: string;
  per_page?: number;
  cursor?: string | null;
}

export interface AuditVerifyResponse {
  ok: boolean;
  checked: number;
  first_failed_id: number | null;
}
