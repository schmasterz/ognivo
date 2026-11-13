/**
 * Impersonation session storage manager.
 *
 * Provides pure, testable operations for managing authentication tokens
 * and impersonation metadata across localStorage and sessionStorage without
 * persisting sensitive administrative backup tokens in client storage.
 */

export interface ImpersonatedUserInfo {
  id: number;
  name: string;
  email: string;
}

export interface StorageLike {
  getItem(key: string): string | null;
  setItem(key: string, value: string): void;
  removeItem(key: string): void;
}

export const AUTH_TOKEN_KEY = "auth_token";
export const IMPERSONATED_USER_KEY = "impersonated_user";
export const LEGACY_ADMIN_BACKUP_KEY = "admin_token_backup";

function resolveStores(
  localOverride?: StorageLike,
  sessionOverride?: StorageLike
): { local: StorageLike | undefined; session: StorageLike | undefined } {
  const local =
    localOverride ?? (typeof window !== "undefined" ? window.localStorage : undefined);
  const session =
    sessionOverride ?? (typeof window !== "undefined" ? window.sessionStorage : undefined);
  return { local, session };
}

/**
 * Remove legacy administrative backup tokens from both localStorage and sessionStorage.
 */
export function cleanupLegacyStorage(
  localStore?: StorageLike,
  sessionStore?: StorageLike
): void {
  const { local, session } = resolveStores(localStore, sessionStore);
  local?.removeItem(LEGACY_ADMIN_BACKUP_KEY);
  session?.removeItem(LEGACY_ADMIN_BACKUP_KEY);
}

/**
 * Initiate an impersonation session.
 *
 * Replaces the current authentication token with the temporary impersonation token,
 * stores non-sensitive user metadata in sessionStorage, and ensures no admin token backup
 * is written or retained in client storage.
 */
export function startImpersonationStorage(
  impersonationToken: string,
  metadata: ImpersonatedUserInfo,
  localStore?: StorageLike,
  sessionStore?: StorageLike
): void {
  const { local, session } = resolveStores(localStore, sessionStore);

  // Ensure legacy backup tokens are completely purged
  local?.removeItem(LEGACY_ADMIN_BACKUP_KEY);
  session?.removeItem(LEGACY_ADMIN_BACKUP_KEY);

  // Store impersonation token as the active authentication token
  local?.setItem(AUTH_TOKEN_KEY, impersonationToken);

  // Store non-secret display metadata in sessionStorage (and clear any legacy entry in localStorage)
  local?.removeItem(IMPERSONATED_USER_KEY);
  session?.setItem(IMPERSONATED_USER_KEY, JSON.stringify(metadata));
}

/**
 * Terminate an impersonation session and restore the fresh administrative token returned by the server.
 *
 * Purges impersonation metadata and clears legacy backups.
 */
export function stopImpersonationStorage(
  restoredAdminToken: string | null | undefined,
  localStore?: StorageLike,
  sessionStore?: StorageLike
): void {
  const { local, session } = resolveStores(localStore, sessionStore);

  if (restoredAdminToken) {
    local?.setItem(AUTH_TOKEN_KEY, restoredAdminToken);
  } else {
    local?.removeItem(AUTH_TOKEN_KEY);
  }

  // Clear impersonation state and legacy backup entries
  local?.removeItem(IMPERSONATED_USER_KEY);
  session?.removeItem(IMPERSONATED_USER_KEY);
  local?.removeItem(LEGACY_ADMIN_BACKUP_KEY);
  session?.removeItem(LEGACY_ADMIN_BACKUP_KEY);
}

/**
 * Clear all authentication tokens, impersonation state, and legacy backups.
 */
export function clearAllAuthStorage(
  localStore?: StorageLike,
  sessionStore?: StorageLike
): void {
  const { local, session } = resolveStores(localStore, sessionStore);
  local?.removeItem(AUTH_TOKEN_KEY);
  local?.removeItem(IMPERSONATED_USER_KEY);
  session?.removeItem(IMPERSONATED_USER_KEY);
  local?.removeItem(LEGACY_ADMIN_BACKUP_KEY);
  session?.removeItem(LEGACY_ADMIN_BACKUP_KEY);
}

/**
 * Retrieve non-secret impersonated user display metadata.
 */
export function getImpersonatedUserMetadata(
  sessionStore?: StorageLike,
  localStore?: StorageLike
): ImpersonatedUserInfo | null {
  const { local, session } = resolveStores(localStore, sessionStore);
  const raw = session?.getItem(IMPERSONATED_USER_KEY) || local?.getItem(IMPERSONATED_USER_KEY);
  if (!raw) return null;

  try {
    const parsed = JSON.parse(raw) as ImpersonatedUserInfo;
    if (parsed && typeof parsed.id === "number" && typeof parsed.email === "string") {
      return parsed;
    }
    return null;
  } catch {
    session?.removeItem(IMPERSONATED_USER_KEY);
    local?.removeItem(IMPERSONATED_USER_KEY);
    return null;
  }
}
