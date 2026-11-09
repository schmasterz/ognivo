/**
 * Unified API Client for LXD / Incus Control Plane.
 *
 * Provides a modular, domain-driven API structure while preserving
 * seamless backward compatibility for existing component imports.
 */

import { authApi } from "./auth";
import { vmsApi } from "./vms";
import { snapshotsApi } from "./snapshots";
import { networkingApi } from "./networking";
import { quotasApi } from "./quotas";
import { hostsApi } from "./hosts";
import { adminApi } from "./admin";
import { imagesApi } from "./images";
import { auditApi } from "./audit";

export * from "./types";
export * from "./client";
export * from "./auth";
export * from "./vms";
export * from "./snapshots";
export * from "./networking";
export * from "./quotas";
export * from "./hosts";
export * from "./admin";
export * from "./images";
export * from "./audit";

/**
 * Global API Client object maintaining complete backward compatibility.
 */
export const api = {
  ...authApi,
  ...vmsApi,
  ...snapshotsApi,
  ...networkingApi,
  ...quotasApi,
  ...hostsApi,
  ...adminApi,
  ...imagesApi,
  ...auditApi,
};
