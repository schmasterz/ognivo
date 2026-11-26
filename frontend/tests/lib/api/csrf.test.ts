import { describe, it, beforeEach } from "node:test";
import assert from "node:assert/strict";
import {
  ensureCsrfCookie,
  isUnsafeMethod,
  readXsrfToken,
  resetCsrfCookiePromise,
} from "../../../src/lib/api/csrf.ts";
import {
  PASSIVE_ACTIVITY_HEADER,
  apiFetch,
} from "../../../src/lib/api/client.ts";

interface RecordedFetchCall {
  input: string;
  init: RequestInit | undefined;
}

describe("CSRF & apiFetch Unit Tests", () => {
  beforeEach(() => {
    resetCsrfCookiePromise();
  });

  describe("readXsrfToken", () => {
    it("returns null when cookie string is empty or XSRF-TOKEN is missing", () => {
      assert.equal(readXsrfToken(""), null);
      assert.equal(readXsrfToken("session=abc123; theme=dark"), null);
      assert.equal(readXsrfToken("MY_XSRF-TOKEN=should-not-match"), null);
      assert.equal(readXsrfToken("XSRF-TOKEN="), null);
    });

    it("reads and URL-decodes XSRF-TOKEN from cookie string", () => {
      const encoded = encodeURIComponent("abc+def/123==");
      const cookie = `ognivo_session=xyz; XSRF-TOKEN=${encoded}; path=/`;
      assert.equal(readXsrfToken(cookie), "abc+def/123==");
    });

    it("preserves raw value when percent-encoding is malformed", () => {
      assert.equal(readXsrfToken("XSRF-TOKEN=%E0%A4%A"), "%E0%A4%A");
    });
  });

  describe("isUnsafeMethod", () => {
    it("treats GET, HEAD, OPTIONS (case-insensitive) and omitted method as safe", () => {
      assert.equal(isUnsafeMethod(), false);
      assert.equal(isUnsafeMethod(undefined), false);
      assert.equal(isUnsafeMethod(""), false);
      assert.equal(isUnsafeMethod("GET"), false);
      assert.equal(isUnsafeMethod("get"), false);
      assert.equal(isUnsafeMethod("HEAD"), false);
      assert.equal(isUnsafeMethod("head"), false);
      assert.equal(isUnsafeMethod("OPTIONS"), false);
      assert.equal(isUnsafeMethod("options"), false);
    });

    it("treats POST, PUT, PATCH, DELETE (case-insensitive) as unsafe", () => {
      assert.equal(isUnsafeMethod("POST"), true);
      assert.equal(isUnsafeMethod("post"), true);
      assert.equal(isUnsafeMethod("PUT"), true);
      assert.equal(isUnsafeMethod("PATCH"), true);
      assert.equal(isUnsafeMethod("DELETE"), true);
    });
  });

  describe("ensureCsrfCookie", () => {
    it("memoizes /sanctum/csrf-cookie fetch until resetCsrfCookiePromise is called", async () => {
      const calls: RecordedFetchCall[] = [];
      const mockFetch = async (input: string, init?: RequestInit) => {
        calls.push({ input, init });
        return new Response(null, { status: 204 });
      };

      await Promise.all([ensureCsrfCookie(mockFetch), ensureCsrfCookie(mockFetch)]);
      await ensureCsrfCookie(mockFetch);

      assert.equal(calls.length, 1);
      assert.equal(calls[0].input, "/sanctum/csrf-cookie");
      assert.equal(calls[0].init?.credentials, "same-origin");

      resetCsrfCookiePromise();
      await ensureCsrfCookie(mockFetch);
      assert.equal(calls.length, 2);
    });
  });

  describe("apiFetch CSRF headers", () => {
    it("adds X-XSRF-TOKEN only for unsafe methods and always sets same-origin credentials", async () => {
      const calls: RecordedFetchCall[] = [];
      const mockFetch = async (input: string, init?: RequestInit) => {
        calls.push({ input, init });
        return new Response(JSON.stringify({ ok: true }), { status: 200 });
      };

      await apiFetch("/api/v1/vms", { method: "GET" }, {
        fetchFn: mockFetch,
        readToken: () => "token-123",
      });
      await apiFetch("/api/v1/vms", { method: "HEAD" }, {
        fetchFn: mockFetch,
        readToken: () => "token-123",
      });
      await apiFetch("/api/v1/vms", { method: "OPTIONS" }, {
        fetchFn: mockFetch,
        readToken: () => "token-123",
      });
      await apiFetch("/api/v1/vms", { method: "POST" }, {
        fetchFn: mockFetch,
        readToken: () => "token-123",
      });

      assert.equal(calls.length, 4);
      for (const call of calls) {
        assert.equal(call.init?.credentials, "same-origin");
      }

      assert.equal(new Headers(calls[0].init?.headers).has("X-XSRF-TOKEN"), false);
      assert.equal(new Headers(calls[1].init?.headers).has("X-XSRF-TOKEN"), false);
      assert.equal(new Headers(calls[2].init?.headers).has("X-XSRF-TOKEN"), false);
      assert.equal(new Headers(calls[3].init?.headers).get("X-XSRF-TOKEN"), "token-123");
    });

    it("fetches /sanctum/csrf-cookie when XSRF-TOKEN is missing on unsafe requests", async () => {
      let currentToken: string | null = null;
      const calls: RecordedFetchCall[] = [];

      const mockFetch = async (input: string, init?: RequestInit) => {
        calls.push({ input, init });
        if (input === "/sanctum/csrf-cookie") {
          currentToken = "fresh-csrf-token";
          return new Response(null, { status: 204 });
        }
        return new Response(JSON.stringify({ ok: true }), { status: 200 });
      };

      await apiFetch(
        "/api/v1/vms",
        { method: "POST", body: "{}" },
        {
          fetchFn: mockFetch,
          readToken: () => currentToken,
        }
      );

      assert.equal(calls.length, 2);
      assert.equal(calls[0].input, "/sanctum/csrf-cookie");
      assert.equal(calls[1].input, "/api/v1/vms");
      assert.equal(
        new Headers(calls[1].init?.headers).get("X-XSRF-TOKEN"),
        "fresh-csrf-token"
      );
    });
  });

  describe("apiFetch passive activity header", () => {
    it("adds X-Ognivo-Activity: passive only for passive GET requests", async () => {
      const calls: RecordedFetchCall[] = [];
      const mockFetch = async (input: string, init?: RequestInit) => {
        calls.push({ input, init });
        return new Response(JSON.stringify({ ok: true }), { status: 200 });
      };

      await apiFetch(
        "/api/v1/me",
        { method: "GET", passive: true },
        { fetchFn: mockFetch, readToken: () => "tok" }
      );
      await apiFetch(
        "/api/v1/me",
        { method: "GET" },
        { fetchFn: mockFetch, readToken: () => "tok" }
      );
      await apiFetch(
        "/api/v1/vms",
        { method: "POST", passive: true },
        { fetchFn: mockFetch, readToken: () => "tok" }
      );

      assert.equal(
        new Headers(calls[0].init?.headers).get("X-Ognivo-Activity"),
        PASSIVE_ACTIVITY_HEADER["X-Ognivo-Activity"]
      );
      assert.equal(
        new Headers(calls[1].init?.headers).has("X-Ognivo-Activity"),
        false
      );
      assert.equal(
        new Headers(calls[2].init?.headers).has("X-Ognivo-Activity"),
        false
      );
    });
  });

  describe("apiFetch 419 retry behavior", () => {
    it("resets CSRF cookie and retries once on 419 response", async () => {
      let currentToken = "stale-token";
      let apiCallCount = 0;
      const calls: RecordedFetchCall[] = [];

      const mockFetch = async (input: string, init?: RequestInit) => {
        calls.push({ input, init });
        if (input === "/sanctum/csrf-cookie") {
          currentToken = "rotated-token";
          return new Response(null, { status: 204 });
        }
        apiCallCount += 1;
        if (apiCallCount === 1) {
          return new Response(JSON.stringify({ message: "CSRF mismatch" }), {
            status: 419,
          });
        }
        return new Response(JSON.stringify({ ok: true }), { status: 200 });
      };

      const res = await apiFetch(
        "/api/v1/vms",
        { method: "POST", body: "{}" },
        { fetchFn: mockFetch, readToken: () => currentToken }
      );

      assert.equal(res.status, 200);
      assert.equal(calls.length, 3);
      assert.equal(calls[0].input, "/api/v1/vms");
      assert.equal(
        new Headers(calls[0].init?.headers).get("X-XSRF-TOKEN"),
        "stale-token"
      );
      assert.equal(calls[1].input, "/sanctum/csrf-cookie");
      assert.equal(calls[2].input, "/api/v1/vms");
      assert.equal(
        new Headers(calls[2].init?.headers).get("X-XSRF-TOKEN"),
        "rotated-token"
      );
    });

    it("does not retry more than once when retry also returns 419", async () => {
      let apiCallCount = 0;
      const calls: RecordedFetchCall[] = [];

      const mockFetch = async (input: string, init?: RequestInit) => {
        calls.push({ input, init });
        if (input === "/sanctum/csrf-cookie") {
          return new Response(null, { status: 204 });
        }
        apiCallCount += 1;
        return new Response(JSON.stringify({ message: "CSRF mismatch" }), {
          status: 419,
        });
      };

      const res = await apiFetch(
        "/api/v1/vms",
        { method: "POST", body: "{}" },
        { fetchFn: mockFetch, readToken: () => "tok" }
      );

      assert.equal(res.status, 419);
      assert.equal(apiCallCount, 2);
      assert.equal(calls.length, 3);
    });
  });

  describe("apiFetch 401 unauthenticated event", () => {
    it("dispatches ognivo:unauthenticated on 401 for /me but NOT for /login or /register", async () => {
      const dispatchedEvents: string[] = [];
      const originalWindow = (globalThis as { window?: unknown }).window;

      (globalThis as { window?: unknown }).window = {
        dispatchEvent(event: Event): boolean {
          dispatchedEvents.push(event.type);
          return true;
        },
      };

      try {
        const mock401 = async () =>
          new Response(JSON.stringify({ message: "Unauthenticated" }), {
            status: 401,
          });

        await apiFetch(
          "/api/v1/me",
          { method: "GET" },
          { fetchFn: mock401, readToken: () => "tok" }
        );
        await apiFetch(
          "/api/v1/login",
          { method: "POST" },
          { fetchFn: mock401, readToken: () => "tok" }
        );
        await apiFetch(
          "/api/v1/register",
          { method: "POST" },
          { fetchFn: mock401, readToken: () => "tok" }
        );

        assert.deepEqual(dispatchedEvents, ["ognivo:unauthenticated"]);
      } finally {
        if (originalWindow === undefined) {
          delete (globalThis as { window?: unknown }).window;
        } else {
          (globalThis as { window?: unknown }).window = originalWindow;
        }
      }
    });
  });
});
