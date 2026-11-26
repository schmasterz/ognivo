// Removal-only cleanup of pre-cookie auth data. Delete this file and its call in one of the next releases.

export interface StorageRemovalLike {
  removeItem(key: string): void;
}

const LOCAL_STORAGE_KEYS = [
  "auth_token",
  "impersonated_user",
  "admin_token_backup",
] as const;

const SESSION_STORAGE_KEYS = [
  "impersonated_user",
  "admin_token_backup",
] as const;

/**
 * Purges pre-cookie authentication keys from browser storage without reading or parsing any values.
 */
export function purgeLegacyAuthStorage(
  localStore?: StorageRemovalLike,
  sessionStore?: StorageRemovalLike
): void {
  try {
    const local =
      localStore ??
      (typeof window !== "undefined" ? window.localStorage : undefined);
    if (local) {
      for (const key of LOCAL_STORAGE_KEYS) {
        try {
          local.removeItem(key);
        } catch {
          // Ignore storage access errors
        }
      }
    }
  } catch {
    // Ignore storage access errors
  }

  try {
    const session =
      sessionStore ??
      (typeof window !== "undefined" ? window.sessionStorage : undefined);
    if (session) {
      for (const key of SESSION_STORAGE_KEYS) {
        try {
          session.removeItem(key);
        } catch {
          // Ignore storage access errors
        }
      }
    }
  } catch {
    // Ignore storage access errors
  }
}
