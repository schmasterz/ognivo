/**
 * Shared Domain Types and DTO Interfaces for LXD / Incus Control Plane.
 */

export type HostState = "ok" | "unreachable" | "not_trusted" | "fingerprint_mismatch" | "error";

export interface TestConnectionResponse {
  state?: HostState;
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

export interface HostMaxInstanceLimits {
  cpu_cores: number;
  ram_mb: number;
  disk_gb: number;
}

export interface TenantHost {
  id: number;
  name: string;
  region?: string | null;
  driver: "incus" | "lxd" | "fake" | string;
  is_active: boolean;
  status?: HostState;
  max_instance?: HostMaxInstanceLimits;
}

export interface HostDiscoveredNetwork {
  name: string;
  type: string;
  managed: boolean;
  addresses: string[];
  recommended_modes: string[];
}

export interface HostDiscoveredStoragePool {
  name: string;
  driver: string;
  status: string;
}

export interface HostResourcesResponse {
  reachable: boolean;
  networks: HostDiscoveredNetwork[];
  storage_pools: HostDiscoveredStoragePool[];
}

export interface HostResourceCapacityMetrics {
  total: number;
  committed: number;
  free: number;
  overcommit?: number;
  max_allocatable?: number;
}

export interface HostCapacityDetails {
  source: "discovered" | "manual" | "unknown" | string;
  synced_at?: string | null;
  cpu: HostResourceCapacityMetrics;
  ram: HostResourceCapacityMetrics;
  disk: HostResourceCapacityMetrics;
}

export interface HostMetricsHealth {
  last_success_at: string | null;
  last_error_at: string | null;
  last_error: string | null;
  last_duration_ms: number | null;
  consecutive_failures: number;
  backoff_until: string | null;
}

export interface HostNode extends TenantHost {
  endpoint_url?: string;
  server_fingerprint?: string | null;
  status?: HostState;
  needs_retrust?: boolean;
  last_checked_at?: string | null;
  metrics_health?: HostMetricsHealth | null;
  has_token?: boolean;
  token?: string | null;
  total_cpu_cores: number;
  total_ram_mb: number;
  total_storage_gb: number;
  cpu_overcommit?: number;
  ram_overcommit?: number;
  disk_overcommit?: number;
  capacity_source?: "discovered" | "manual" | "unknown" | string;
  capacity_synced_at?: string | null;
  capacity?: HostCapacityDetails;
  virtual_machines_count?: number;
  ip_pools_count?: number;
  auto_attach_images?: boolean;
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
    ips?: number;
  };
  available: {
    vms: number;
    cpu_cores: number;
    ram_mb: number;
    disk_gb: number;
    snapshots?: number;
    ips?: number;
  };
  filters?: {
    host_id?: number | null;
    status?: string | null;
    search?: string | null;
  };
}

export type VmStatus =
  | "provisioning"
  | "running"
  | "stopped"
  | "suspended"
  | "deleting"
  | "deleted"
  | "failed";

export type VmNetworkStatus =
  | "assigned"
  | "waiting"
  | "observed"
  | "unverified"
  | "mismatch"
  | "unassigned";

export interface VmNetworkPrimary {
  address: string | null;
  family: number;
  status: VmNetworkStatus | null;
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
  status: VmStatus;
  failure_reason?: string | null;
  cpu_cores: number;
  ram_mb: number;
  disk_gb: number;
  vps_plan?: {
    id: number;
    name: string;
    slug: string;
    version_id: number;
    version: number;
    is_current_version: boolean;
  } | null;
  network_limits?: {
    download_mbps: number | null;
    upload_mbps: number | null;
    applicable: boolean;
  } | null;
  os_image: string;
  os_image_alias?: string;
  ipv4_address: string | null;
  ipv6_address: string | null;
  mac_address?: string | null;
  network_status?: VmNetworkStatus | null;
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

export interface MetaPaginatedResponse<T> {
  data: T[];
  meta: PaginationMeta;
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
  total_usable?: number;
  free_ips?: number;
  allocated_ips?: number;
  cooling_ips?: number;
}

export type IpPoolAddressState = "free" | "allocated" | "cooling" | "excluded";

export interface IpPoolAddressAllocation {
  id: number;
  mac_address: string | null;
  status: string;
  allocated_at: string | null;
  released_at: string | null;
  virtual_machine: { id: number; name: string; status: string } | null;
  user: { id: number; name: string; email: string } | null;
}

export interface IpPoolAddressEntry {
  ip: string;
  state: IpPoolAddressState;
  is_gateway: boolean;
  cooldown_remaining_seconds: number;
  allocation: IpPoolAddressAllocation | null;
}

export interface IpPoolAddressesResponse {
  pool: IpPool;
  data: IpPoolAddressEntry[];
  meta: PaginationMeta;
}

export interface IpAllocation {
  id: number;
  pool_id: number;
  virtual_machine_id: number | null;
  user_id: number | null;
  address: string;
  ip_string?: string;
  mac_address: string;
  status: "allocated" | "cooling";
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
  total_pools?: number;
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
  metrics_enabled?: boolean;
  metrics_interval_seconds?: number;
  metrics_available_ranges?: Array<"1h" | "6h" | "24h" | "7d" | "30d">;
  metrics_retention?: {
    raw_hours: number;
    rollup_5m_days: number;
    rollup_1h_days: number;
  };
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
  alias: string;
  source: string;
  protocol: string;
  os_type: "linux" | "windows" | "bsd" | "other";
  enabled: boolean;
  active_vms_count?: number;
  hosts?: HostNode[];
  host_ids?: number[];
  attached?: boolean;
  created_at?: string;
  updated_at?: string;
}

export interface RemoteImageValidationResult {
  valid: boolean;
  error?: string | null;
  architectures?: string[];
  matched_alias?: string | null;
  metadata?: Record<string, unknown>;
}

export interface CursorPage<T> {
  data: T[];
  next_cursor: string | null;
  per_page?: number;
  total?: number;
}

export interface ImageCatalogFilter {
  search?: string;
  os_type?: string;
  protocol?: string;
  enabled?: boolean;
}

export interface ImageFilterOp {
  action: "attach" | "detach";
  filter: ImageCatalogFilter;
}

export type ImageSelectionPayload =
  | { mode: "all" }
  | { mode: "selected"; ids: number[] }
  | { mode: "filter"; filter: ImageCatalogFilter; exclude_ids?: number[] }
  | {
      mode: "delta";
      filter_ops?: ImageFilterOp[];
      attach_ids?: number[];
      detach_ids?: number[];
    };

export interface ImageCatalogCounts {
  filtered: {
    catalog: number;
    attached: number | null;
  };
  overall: {
    catalog: number;
    attached: number | null;
  };
}

export interface AdminImagesResponse extends CursorPage<ImageItem> {
  per_page: number;
  counts: ImageCatalogCounts;
}

export interface PreviewImageDetachItem {
  id: number;
  name: string;
  instance_count: number;
}

export interface PreviewImageDetachResponse {
  attached: number;
  detached: number;
  total_instances: number;
  images: PreviewImageDetachItem[];
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

export type VmMetricsRangeKey = "1h" | "6h" | "24h" | "7d" | "30d";

export interface VmMetricPointDTO {
  ts: number;
  avg: number | null;
  min: number | null;
  max: number | null;
  reason: "gap" | "unavailable" | null;
}

export interface VmMetricSingleSeriesDTO {
  unit: string;
  available: boolean;
  unavailable_reason: string | null;
  points: VmMetricPointDTO[];
}

export interface VmMetricDiskIoSeriesDTO {
  unit: string;
  available: boolean;
  unavailable_reason: string | null;
  read: VmMetricPointDTO[];
  write: VmMetricPointDTO[];
}

export interface VmMetricNetworkSeriesDTO {
  unit: string;
  available: boolean;
  unavailable_reason: string | null;
  rx: VmMetricPointDTO[];
  tx: VmMetricPointDTO[];
}

export interface VmMetricsTotalsDTO {
  cpu_cores: number;
  memory_total_bytes: number;
  disk_total_bytes: number;
}

export interface VmMetricsResponse {
  virtual_machine_id: number;
  instance_type: string;
  status: string;
  range: VmMetricsRangeKey;
  tier: "raw" | "rollup_5m" | "rollup_1h";
  bucket_width_seconds: number;
  refresh_interval_seconds: number;
  available_ranges: VmMetricsRangeKey[];
  last_sample_at: string | null;
  totals: VmMetricsTotalsDTO;
  series: {
    cpu?: VmMetricSingleSeriesDTO;
    memory?: VmMetricSingleSeriesDTO;
    disk_space?: VmMetricSingleSeriesDTO;
    disk_io?: VmMetricDiskIoSeriesDTO;
    network?: VmMetricNetworkSeriesDTO;
  };
}

export type VpsPlanStatus = "draft" | "published" | "archived";

export type VpsPlanVersionStatus = "draft" | "published" | "superseded" | "archived";

export type VpsPlanInstanceType = "virtual-machine" | "container";

export interface VpsPlanCatalogVersion {
  id: number;
  version: number;
  cpu_cores: number;
  ram_mb: number;
  disk_gb: number;
  instance_type: VpsPlanInstanceType;
  net_download_mbps: number | null;
  net_upload_mbps: number | null;
}

export interface VpsPlanCatalogItem {
  id: number;
  name: string;
  slug: string;
  description: string | null;
  sort_order: number;
  current_version: VpsPlanCatalogVersion | null;
}

export interface VpsPlanVersion {
  id: number;
  vps_plan_id: number;
  version: number | null;
  status: VpsPlanVersionStatus | string;
  cpu_cores: number;
  ram_mb: number;
  disk_gb: number;
  instance_type: VpsPlanInstanceType;
  net_download_mbps: number | null;
  net_upload_mbps: number | null;
  change_summary: string | null;
  active_vms_count?: number;
  created_by: number | null;
  published_by: number | null;
  created_at: string | null;
  published_at: string | null;
}

export interface AdminVpsPlan {
  id: number;
  name: string;
  slug: string;
  description: string | null;
  status: VpsPlanStatus | string;
  sort_order: number;
  current_version_id: number | null;
  current_version?: VpsPlanVersion | null;
  draft_version?: VpsPlanVersion | null;
  created_by: number | null;
  updated_by: number | null;
  created_at: string | null;
  updated_at: string | null;
}

export interface VpsPlanUsageItem {
  vps_plan_version_id: number;
  version: number | null;
  status: VpsPlanVersionStatus | string;
  active_vms_count: number;
}

export interface VpsPlanUsageResponse {
  vps_plan_id: number;
  total_active_vms: number;
  versions: VpsPlanUsageItem[];
}

export interface VpsPlanProfilePayload {
  cpu_cores: number;
  ram_mb: number;
  disk_gb: number;
  instance_type: VpsPlanInstanceType;
  net_download_mbps?: number | null;
  net_upload_mbps?: number | null;
  change_summary?: string | null;
}

export interface CreateVpsPlanPayload extends VpsPlanProfilePayload {
  name: string;
  slug?: string | null;
  description?: string | null;
  sort_order?: number;
}

export interface UpdateVpsPlanPayload {
  name?: string;
  slug?: string;
  description?: string | null;
  sort_order?: number;
}

export interface AdminVpsPlanFilters {
  status?: "all" | VpsPlanStatus | string | null;
  search?: string | null;
  page?: number | null;
  per_page?: number | null;
}

export type VpsPlanChangeDirection =
  | "unchanged"
  | "increase"
  | "decrease"
  | "set"
  | "remove";

export type VpsPlanChangeStatus =
  | "pending"
  | "awaiting_authorization"
  | "applying"
  | "completed"
  | "failed"
  | "cancelled"
  | "reconciliation_required";

export type VpsPlanChangeStage =
  | "authorization"
  | "validating"
  | "applying"
  | "verifying"
  | "committing";

export interface VpsPlanChangeBlockingReason {
  code: string;
  message: string;
  kind?: string;
}

export interface VpsPlanChangeChanges {
  cpu: VpsPlanChangeDirection | string;
  ram: VpsPlanChangeDirection | string;
  disk: VpsPlanChangeDirection | string;
  net_download: VpsPlanChangeDirection | string;
  net_upload: VpsPlanChangeDirection | string;
}

export interface VpsPlanChangeTarget {
  id: number;
  name: string;
  slug: string;
  description: string | null;
  sort_order: number;
  current_version: VpsPlanCatalogVersion | null;
  selectable: boolean;
  requires_shutdown: boolean;
  changes: VpsPlanChangeChanges | Record<string, string>;
  warnings: string[];
  blocking_reasons: VpsPlanChangeBlockingReason[];
}

export interface VpsPlanChangePreviewEndpoint {
  vps_plan_id: number | null;
  vps_plan_version_id: number | null;
  plan_name: string | null;
  plan_slug: string | null;
  version: number | null;
  cpu_cores: number;
  ram_mb: number;
  disk_gb: number;
  instance_type: VpsPlanInstanceType | string;
  net_download_mbps: number | null;
  net_upload_mbps: number | null;
}

export interface VpsPlanChangePreview {
  can_submit: boolean;
  requires_shutdown: boolean;
  from: VpsPlanChangePreviewEndpoint;
  to: VpsPlanChangePreviewEndpoint | null;
  changes: VpsPlanChangeChanges | Record<string, string>;
  warnings: string[];
  blocking_reasons: VpsPlanChangeBlockingReason[];
}

export interface PlanChangePayload {
  vps_plan_id?: number | null;
  vps_plan_version_id?: number | null;
}

export interface VpsPlanChangeVersionSummary {
  vps_plan_id: number;
  vps_plan_version_id: number;
  plan_name: string | null;
  plan_slug: string | null;
  version: number | null;
}

export interface VpsPlanChangeResourcesSnapshot {
  cpu_cores: number;
  ram_mb: number;
  disk_gb: number;
  instance_type?: VpsPlanInstanceType | string;
  net_download_mbps: number | null;
  net_upload_mbps: number | null;
}

export interface VpsPlanChange {
  public_id: string;
  virtual_machine_id: number;
  status: VpsPlanChangeStatus | string;
  stage: VpsPlanChangeStage | string | null;
  from_version_id: number | null;
  from: VpsPlanChangeVersionSummary | null;
  to_version_id: number;
  to: VpsPlanChangeVersionSummary | null;
  old_resources: VpsPlanChangeResourcesSnapshot;
  new_resources: VpsPlanChangeResourcesSnapshot;
  error_code: string | null;
  error_message: string | null;
  requested_at: string | null;
  started_at: string | null;
  completed_at: string | null;
  failed_at: string | null;
  created_at: string | null;
}

export interface AdminVpsPlanChangeVersionSummary {
  id: number;
  vps_plan_id: number;
  version: number | null;
  plan_name: string | null;
  plan_slug: string | null;
}

export interface AdminVpsPlanChange {
  id: number;
  public_id: string;
  virtual_machine_id: number;
  user_id: number;
  initiated_by: number | null;
  from_version_id: number | null;
  to_version_id: number;
  old_resources: VpsPlanChangeResourcesSnapshot;
  new_resources: VpsPlanChangeResourcesSnapshot;
  status: VpsPlanChangeStatus | string;
  stage: VpsPlanChangeStage | string | null;
  authorization_ref: string | null;
  driver_name: string | null;
  driver_operation_id: string | null;
  error_code: string | null;
  error_message: string | null;
  requested_at: string | null;
  started_at: string | null;
  completed_at: string | null;
  failed_at: string | null;
  apply_deadline: string | null;
  created_at: string | null;
  updated_at: string | null;
  virtual_machine: {
    id: number;
    name: string;
    incus_project: string;
    status: string;
    user_id: number;
  } | null;
  user: {
    id: number;
    name: string;
    email: string;
  } | null;
  initiator: {
    id: number;
    name: string;
    email: string;
  } | null;
  from_version: AdminVpsPlanChangeVersionSummary | null;
  to_version: AdminVpsPlanChangeVersionSummary | null;
}

export interface AdminVpsPlanChangeFilters {
  status?: "all" | VpsPlanChangeStatus | string | null;
  user_id?: number | null;
  vm_id?: number | null;
  page?: number | null;
  per_page?: number | null;
}

export type ResolveAdminPlanChangeAction = "complete" | "fail" | "retry";

export interface ResolveAdminPlanChangePayload {
  action: ResolveAdminPlanChangeAction;
  note?: string | null;
}
