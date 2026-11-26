import { describe, it } from "node:test";
import assert from "node:assert/strict";
import { purgeLegacyAuthStorage } from "../../../src/lib/auth/legacyStoragePurge.ts";

function createWriteOnlyMockStorage() {
  const removedKeys: string[] = [];
  return {
    removedKeys,
    getItem(): string | null {
      throw new Error("getItem must never be called during legacy storage purge");
    },
    setItem(): void {
      throw new Error("setItem must never be called during legacy storage purge");
    },
    removeItem(key: string): void {
      removedKeys.push(key);
    },
  };
}

describe("purgeLegacyAuthStorage", () => {
  it("calls removeItem only for legacy keys and never calls getItem or setItem", () => {
    const localStore = createWriteOnlyMockStorage();
    const sessionStore = createWriteOnlyMockStorage();

    purgeLegacyAuthStorage(localStore, sessionStore);

    assert.deepEqual(localStore.removedKeys, [
      "auth_token",
      "impersonated_user",
      "admin_token_backup",
    ]);
    assert.deepEqual(sessionStore.removedKeys, [
      "impersonated_user",
      "admin_token_backup",
    ]);
  });

  it("swallows storage errors gracefully", () => {
    const throwingStore = {
      getItem(): string | null {
        throw new Error("getItem must never be called");
      },
      removeItem(): void {
        throw new Error("SecurityError: storage disabled");
      },
    };

    assert.doesNotThrow(() => {
      purgeLegacyAuthStorage(throwingStore, throwingStore);
    });
  });

  it("is a safe no-op when window is undefined", () => {
    assert.doesNotThrow(() => {
      purgeLegacyAuthStorage();
    });
  });
});
