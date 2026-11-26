import { describe, it } from "node:test";
import assert from "node:assert/strict";
import { sanitizeNextPath } from "../../../src/lib/auth/sanitizeNextPath.ts";

describe("sanitizeNextPath", () => {
  it("accepts valid relative in-app paths", () => {
    assert.equal(sanitizeNextPath("/instances?x=1"), "/instances?x=1");
    assert.equal(sanitizeNextPath("/"), "/");
    assert.equal(sanitizeNextPath("/networking?vm=12&vm_tab=console"), "/networking?vm=12&vm_tab=console");
  });

  it("returns fallback for null or empty input", () => {
    assert.equal(sanitizeNextPath(null), "/instances");
    assert.equal(sanitizeNextPath(""), "/instances");
    assert.equal(sanitizeNextPath(null, "/"), "/");
    assert.equal(sanitizeNextPath("", "/"), "/");
  });

  it("rejects protocol-relative URLs", () => {
    assert.equal(sanitizeNextPath("//evil.com"), "/instances");
    assert.equal(sanitizeNextPath("///evil.com"), "/instances");
  });

  it("rejects absolute URLs and schemes", () => {
    assert.equal(sanitizeNextPath("https://evil.com"), "/instances");
    assert.equal(sanitizeNextPath("http://evil.com/instances"), "/instances");
    assert.equal(sanitizeNextPath("javascript:alert(1)"), "/instances");
    assert.equal(sanitizeNextPath("/javascript:alert(1)"), "/instances");
  });

  it("rejects backslashes and paths not starting with a slash", () => {
    assert.equal(sanitizeNextPath("/\\evil"), "/instances");
    assert.equal(sanitizeNextPath("instances"), "/instances");
    assert.equal(sanitizeNextPath("/instances\n"), "/instances");
  });
});
