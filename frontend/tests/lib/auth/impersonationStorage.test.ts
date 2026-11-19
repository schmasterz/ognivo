import { describe, it } from "node:test";
import assert from "node:assert/strict";
import {
  AUTH_TOKEN_KEY,
  IMPERSONATED_USER_KEY,
  LEGACY_ADMIN_BACKUP_KEY,
  cleanupLegacyStorage,
  startImpersonationStorage,
  stopImpersonationStorage,
  clearAllAuthStorage,
  getImpersonatedUserMetadata,
} from "../../../src/lib/auth/impersonationStorage.ts";
import type { StorageLike, ImpersonatedUserInfo } from "../../../src/lib/auth/impersonationStorage.ts";

function createMockStorage(initialData: Record<string, string> = {}): StorageLike & {
  data: Record<string, string>;
} {
  const data: Record<string, string> = { ...initialData };
  return {
    data,
    getItem(key: string): string | null {
      return Object.prototype.hasOwnProperty.call(data, key) ? data[key] : null;
    },
    setItem(key: string, value: string): void {
      data[key] = String(value);
    },
    removeItem(key: string): void {
      delete data[key];
    },
  };
}

describe("Impersonation Storage Unit Tests", () => {
  it("start never leaves an admin token in storage or backup", () => {
    const adminToken = "admin-secret-token-xyz";
    const impersonationToken = "impersonation-token-abc";
    const tenantUser: ImpersonatedUserInfo = {
      id: 42,
      name: "Tenant Alice",
      email: "alice@tenant.example.com",
    };

    const localStore = createMockStorage({
      [AUTH_TOKEN_KEY]: adminToken,
      [LEGACY_ADMIN_BACKUP_KEY]: "stale-admin-backup",
    });
    const sessionStore = createMockStorage({
      [LEGACY_ADMIN_BACKUP_KEY]: "stale-session-backup",
    });

    startImpersonationStorage(impersonationToken, tenantUser, localStore, sessionStore);

    // 1. localStorage active token is replaced by impersonation token
    assert.equal(localStore.getItem(AUTH_TOKEN_KEY), impersonationToken);

    // 2. Admin token is NOT in localStorage or sessionStorage anywhere
    assert.equal(localStore.getItem(LEGACY_ADMIN_BACKUP_KEY), null);
    assert.equal(sessionStore.getItem(LEGACY_ADMIN_BACKUP_KEY), null);
    assert.equal(Object.values(localStore.data).includes(adminToken), false);
    assert.equal(Object.values(sessionStore.data).includes(adminToken), false);

    // 3. Impersonated user display metadata is kept in sessionStorage only
    assert.equal(localStore.getItem(IMPERSONATED_USER_KEY), null);
    const storedMeta = JSON.parse(sessionStore.getItem(IMPERSONATED_USER_KEY)!);
    assert.deepEqual(storedMeta, tenantUser);
  });

  it("stop restores from the server response token and clears impersonation state", () => {
    const impersonationToken = "impersonation-token-abc";
    const freshAdminTokenFromServer = "fresh-admin-session-token-999";

    const localStore = createMockStorage({
      [AUTH_TOKEN_KEY]: impersonationToken,
      [LEGACY_ADMIN_BACKUP_KEY]: "leftover-local-backup",
    });
    const sessionStore = createMockStorage({
      [IMPERSONATED_USER_KEY]: JSON.stringify({ id: 42, name: "Alice", email: "alice@example.com" }),
      [LEGACY_ADMIN_BACKUP_KEY]: "leftover-session-backup",
    });

    stopImpersonationStorage(freshAdminTokenFromServer, localStore, sessionStore);

    // 1. auth_token is restored from server response
    assert.equal(localStore.getItem(AUTH_TOKEN_KEY), freshAdminTokenFromServer);

    // 2. Impersonation metadata is purged
    assert.equal(localStore.getItem(IMPERSONATED_USER_KEY), null);
    assert.equal(sessionStore.getItem(IMPERSONATED_USER_KEY), null);

    // 3. Backup keys are completely purged
    assert.equal(localStore.getItem(LEGACY_ADMIN_BACKUP_KEY), null);
    assert.equal(sessionStore.getItem(LEGACY_ADMIN_BACKUP_KEY), null);
  });

  it("stop without restored token removes auth_token", () => {
    const localStore = createMockStorage({
      [AUTH_TOKEN_KEY]: "some-token",
    });
    const sessionStore = createMockStorage({
      [IMPERSONATED_USER_KEY]: JSON.stringify({ id: 42, name: "Alice", email: "alice@example.com" }),
    });

    stopImpersonationStorage(null, localStore, sessionStore);

    assert.equal(localStore.getItem(AUTH_TOKEN_KEY), null);
    assert.equal(sessionStore.getItem(IMPERSONATED_USER_KEY), null);
  });

  it("legacy admin_token_backup is removed on cleanup", () => {
    const localStore = createMockStorage({
      [AUTH_TOKEN_KEY]: "current-user-token",
      [LEGACY_ADMIN_BACKUP_KEY]: "legacy-admin-token",
    });
    const sessionStore = createMockStorage({
      [LEGACY_ADMIN_BACKUP_KEY]: "legacy-admin-token",
    });

    cleanupLegacyStorage(localStore, sessionStore);

    assert.equal(localStore.getItem(LEGACY_ADMIN_BACKUP_KEY), null);
    assert.equal(sessionStore.getItem(LEGACY_ADMIN_BACKUP_KEY), null);
    // Active token is preserved
    assert.equal(localStore.getItem(AUTH_TOKEN_KEY), "current-user-token");
  });

  it("clearAllAuthStorage removes all authentication and impersonation entries", () => {
    const localStore = createMockStorage({
      [AUTH_TOKEN_KEY]: "token-123",
      [IMPERSONATED_USER_KEY]: "user-123",
      [LEGACY_ADMIN_BACKUP_KEY]: "backup-123",
    });
    const sessionStore = createMockStorage({
      [IMPERSONATED_USER_KEY]: "user-123",
      [LEGACY_ADMIN_BACKUP_KEY]: "backup-123",
    });

    clearAllAuthStorage(localStore, sessionStore);

    assert.equal(localStore.getItem(AUTH_TOKEN_KEY), null);
    assert.equal(localStore.getItem(IMPERSONATED_USER_KEY), null);
    assert.equal(localStore.getItem(LEGACY_ADMIN_BACKUP_KEY), null);
    assert.equal(sessionStore.getItem(IMPERSONATED_USER_KEY), null);
    assert.equal(sessionStore.getItem(LEGACY_ADMIN_BACKUP_KEY), null);
  });

  it("getImpersonatedUserMetadata safely retrieves metadata or cleans up malformed data", () => {
    const validMeta: ImpersonatedUserInfo = { id: 7, name: "Bob", email: "bob@example.com" };
    const sessionStore = createMockStorage({
      [IMPERSONATED_USER_KEY]: JSON.stringify(validMeta),
    });
    const localStore = createMockStorage();

    const retrieved = getImpersonatedUserMetadata(sessionStore, localStore);
    assert.deepEqual(retrieved, validMeta);

    // Malformed JSON should return null and remove the bad entry
    const corruptedSession = createMockStorage({
      [IMPERSONATED_USER_KEY]: "{ invalid json ...",
    });
    const resultCorrupted = getImpersonatedUserMetadata(corruptedSession, localStore);
    assert.equal(resultCorrupted, null);
    assert.equal(corruptedSession.getItem(IMPERSONATED_USER_KEY), null);
  });
});
