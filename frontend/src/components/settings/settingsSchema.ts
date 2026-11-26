/**
 * Pure validation helpers, SMTP port defaults, and payload builders
 * for the admin system settings modal.
 */

export type SmtpEncryption = "tls" | "ssl" | "none";

export const DEFAULT_SMTP_PORTS: Record<SmtpEncryption, number> = {
  tls: 587,
  ssl: 465,
  none: 25,
};

export interface AdminSettingsFormValues {
  registrationEnabled: boolean;
  allowVm: boolean;
  allowContainers: boolean;
  frontendUrl: string;
  smtpEnabled: boolean;
  smtpHost: string;
  smtpPort: number | string;
  smtpEncryption: SmtpEncryption;
  smtpUsername: string;
  smtpPassword: string;
  smtpFromAddress: string;
  smtpFromName: string;
}

/** Returns the default SMTP port for a given encryption mode. */
export function getDefaultPortForEncryption(enc: SmtpEncryption): number {
  return DEFAULT_SMTP_PORTS[enc];
}

/**
 * Determines whether a virtualization mode toggle can be flipped without leaving
 * both VMs and containers disabled.
 */
export function canToggleVirtualizationMode(
  currentModeEnabled: boolean,
  otherModeEnabled: boolean
): boolean {
  return !(currentModeEnabled && !otherModeEnabled);
}

/** Validates that at least one virtualization mode (VM or container) is enabled. */
export function isVirtualizationConfigValid(
  allowVm: boolean,
  allowContainers: boolean
): boolean {
  return Boolean(allowVm || allowContainers);
}

/** Builds the normalized payload expected by `api.updateAdminSettings`. */
export function buildAdminSettingsPayload(values: AdminSettingsFormValues) {
  return {
    registration_enabled: values.registrationEnabled,
    allow_virtual_machines: values.allowVm,
    allow_containers: values.allowContainers,
    frontend_url: values.frontendUrl.trim() || undefined,
    smtp: {
      enabled: values.smtpEnabled,
      host: values.smtpHost.trim() || null,
      port: Number(values.smtpPort) || 587,
      encryption: values.smtpEncryption,
      username: values.smtpUsername.trim() || null,
      password: values.smtpPassword ? values.smtpPassword : null,
      from_address: values.smtpFromAddress.trim() || null,
      from_name: values.smtpFromName.trim() || null,
    },
  };
}
